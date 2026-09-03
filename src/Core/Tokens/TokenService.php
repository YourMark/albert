<?php
/**
 * Single-Use Token Service
 *
 * @package Albert
 * @subpackage Core\Tokens
 * @since      1.4.0
 */

namespace Albert\Core\Tokens;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Reusable single-use hashed token primitive: mint an opaque token, hash it
 * before storage, redeem it exactly once. Deliberately generic — a consumer
 * supplies a `purpose` to partition its tokens and translates the generic
 * error codes into its own vocabulary.
 *
 * @since 1.4.0
 */
class TokenService {

	/**
	 * Raw token length in bytes, before hex-encoding (64 hex characters).
	 *
	 * @since 1.4.0
	 * @var int
	 */
	const TOKEN_BYTES = 32;

	/**
	 * The repository.
	 *
	 * @since 1.4.0
	 * @var SingleUseTokenRepository
	 */
	private SingleUseTokenRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param SingleUseTokenRepository|null $repository Optional repository override (tests).
	 *
	 * @since 1.4.0
	 */
	public function __construct( ?SingleUseTokenRepository $repository = null ) {
		$this->repository = $repository ?? new SingleUseTokenRepository();
	}

	/**
	 * Mint a new single-use token.
	 *
	 * @param string               $purpose     Consumer-defined partition key, e.g. 'media_upload'.
	 * @param int                  $user_id     The issuing user.
	 * @param array<string, mixed> $payload     Consumer-defined data, returned verbatim on redemption.
	 * @param int                  $ttl_seconds Seconds until the token expires.
	 *
	 * @return array{token: string, expires_at: string}|WP_Error The raw token (never persisted) and its
	 *                                                            expiry, or a WP_Error if issuing failed.
	 * @since 1.4.0
	 */
	public function issue( string $purpose, int $user_id, array $payload, int $ttl_seconds ): array|WP_Error {
		$token      = bin2hex( random_bytes( self::TOKEN_BYTES ) );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + max( 1, $ttl_seconds ) );

		$stored = $this->repository->insert( $this->hash( $token ), $purpose, $user_id, $payload, $expires_at );

		if ( ! $stored ) {
			return new WP_Error(
				'token_issue_failed',
				__( 'Could not issue a token.', 'albert-ai-butler' ),
				[ 'status' => 500 ]
			);
		}

		return [
			'token'      => $token,
			'expires_at' => $expires_at,
		];
	}

	/**
	 * Redeem a single-use token.
	 *
	 * Marks the token redeemed BEFORE returning its payload — a caller that
	 * fails partway through whatever it does with the payload must not be
	 * able to retry with the same token. Missing and already-used tokens
	 * return the same error code so an invalid token cannot be distinguished
	 * from a spent one.
	 *
	 * @param string $token   The raw token as issued.
	 * @param string $purpose Must match the purpose the token was issued under.
	 *
	 * @return array{user_id: int, payload: array<string, mixed>}|WP_Error The issuing user and payload
	 *                                                                     on success, WP_Error otherwise.
	 * @since 1.4.0
	 */
	public function redeem( string $token, string $purpose ): array|WP_Error {
		if ( $token === '' ) {
			return new WP_Error(
				'token_already_used',
				__( 'This token is invalid or has already been used.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		$row = $this->repository->find( $this->hash( $token ), $purpose );

		if ( ! $row ) {
			return new WP_Error(
				'token_already_used',
				__( 'This token is invalid or has already been used.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		if ( $row->redeemed_at !== null ) {
			return new WP_Error(
				'token_already_used',
				__( 'This token is invalid or has already been used.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		if ( strtotime( $row->expires_at ) < time() ) {
			return new WP_Error(
				'token_expired',
				__( 'This token has expired.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		// Compare-and-set: if another request redeemed this row first, treat
		// it exactly like "already used" rather than proceeding.
		if ( ! $this->repository->mark_redeemed( (int) $row->id ) ) {
			return new WP_Error(
				'token_already_used',
				__( 'This token is invalid or has already been used.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		$payload = json_decode( (string) $row->payload, true );

		return [
			'user_id' => (int) $row->user_id,
			'payload' => is_array( $payload ) ? $payload : [],
		];
	}

	/**
	 * Hash a raw token for storage/lookup. Never store the raw token itself.
	 *
	 * @param string $token The raw token.
	 *
	 * @return string The hash.
	 * @since 1.4.0
	 */
	private function hash( string $token ): string {
		return hash( 'sha256', $token );
	}
}
