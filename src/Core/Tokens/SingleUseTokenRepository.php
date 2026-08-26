<?php
/**
 * Single-Use Token Repository
 *
 * @package Albert
 * @subpackage Core\Tokens
 * @since      1.4.0
 */

namespace Albert\Core\Tokens;

defined( 'ABSPATH' ) || exit;

use Albert\Database\Tables;

/**
 * SingleUseTokenRepository class
 *
 * Database access for the generic single-use hashed token table. Never reads
 * or stores a raw token — every lookup and write is keyed on a hash supplied
 * by the caller (see {@see TokenService}).
 *
 * @since 1.4.0
 */
class SingleUseTokenRepository {

	/**
	 * Insert a new token row.
	 *
	 * @param string               $token_hash SHA-256 hash of the raw token.
	 * @param string               $purpose    Consumer-defined partition key.
	 * @param int                  $user_id    The issuing user.
	 * @param array<string, mixed> $payload    Consumer-defined data, stored as JSON.
	 * @param string               $expires_at MySQL datetime (UTC) the token expires at.
	 *
	 * @return bool Whether the insert succeeded.
	 * @since 1.4.0
	 */
	public function insert( string $token_hash, string $purpose, int $user_id, array $payload, string $expires_at ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table, no caching needed.
		$inserted = $wpdb->insert(
			Tables::single_use_tokens(),
			[
				'token_hash' => $token_hash,
				'purpose'    => $purpose,
				'user_id'    => $user_id,
				'payload'    => wp_json_encode( $payload ),
				'expires_at' => $expires_at,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			],
			[ '%s', '%s', '%d', '%s', '%s', '%s' ]
		);

		return $inserted !== false;
	}

	/**
	 * Find a token row by its hash and purpose, regardless of state.
	 *
	 * @param string $token_hash SHA-256 hash of the raw token.
	 * @param string $purpose    Consumer-defined partition key.
	 *
	 * @return object{id: string, token_hash: string, purpose: string, user_id: string, payload: string|null, expires_at: string, redeemed_at: string|null, created_at: string}|null
	 * @since 1.4.0
	 */
	public function find( string $token_hash, string $purpose ): ?object {
		global $wpdb;

		$table = Tables::single_use_tokens();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, token_hash, purpose, user_id, payload, expires_at, redeemed_at, created_at FROM %i WHERE token_hash = %s AND purpose = %s',
				$table,
				$token_hash,
				$purpose
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Atomically mark a row redeemed, guarding against a concurrent redemption.
	 *
	 * The `redeemed_at IS NULL` clause makes this a compare-and-set: only one
	 * of two concurrent callers racing the same token can ever affect a row.
	 *
	 * @param int $id The row id.
	 *
	 * @return bool Whether this call was the one that redeemed the row.
	 * @since 1.4.0
	 */
	public function mark_redeemed( int $id ): bool {
		global $wpdb;

		$table = Tables::single_use_tokens();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET redeemed_at = %s WHERE id = %d AND redeemed_at IS NULL',
				$table,
				gmdate( 'Y-m-d H:i:s' ),
				$id
			)
		);

		return (int) $updated === 1;
	}

	/**
	 * Delete rows that expired more than a day ago, redeemed or not.
	 *
	 * The grace period is just for troubleshooting; nothing relies on it for correctness.
	 *
	 * @return int Number of rows deleted.
	 * @since 1.4.0
	 */
	public function cleanup_expired(): int {
		global $wpdb;

		$table = Tables::single_use_tokens();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$result = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE expires_at < %s',
				$table,
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
			)
		);

		return (int) $result;
	}
}
