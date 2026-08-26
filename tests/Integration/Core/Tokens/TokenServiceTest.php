<?php
/**
 * Integration tests for the generic TokenService primitive.
 *
 * Covers issue/redeem, the plaintext-token-never-persisted guarantee, and
 * every redemption failure mode: expired, already used, unknown, and
 * cross-purpose.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Core\Tokens;

use Albert\Core\Tokens\SingleUseTokenRepository;
use Albert\Core\Tokens\TokenService;
use Albert\Database\Installer;
use Albert\Database\Tables;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * TokenService integration tests.
 *
 * @covers \Albert\Core\Tokens\TokenService
 */
class TokenServiceTest extends TestCase {

	const PURPOSE = 'media_upload';

	/**
	 * Service under test.
	 *
	 * @var TokenService
	 */
	private TokenService $service;

	/**
	 * Reset the single_use_tokens table before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::install();
		$this->service = new TokenService();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test reset.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Tables::single_use_tokens() ) );
	}

	/**
	 * issue() returns a usable raw token and an expiry.
	 *
	 * @return void
	 */
	public function test_issue_returns_token_and_expiry(): void {
		$issued = $this->service->issue( self::PURPOSE, 1, [ 'x' => 1 ], 600 );

		$this->assertIsArray( $issued );
		$this->assertNotEmpty( $issued['token'] );
		$this->assertNotSame( '', $issued['expires_at'] );
	}

	/**
	 * The raw token is never stored — only its SHA-256 hash. This is the
	 * "same discipline as a password" requirement from docs/features/32.
	 *
	 * @return void
	 */
	public function test_raw_token_is_never_persisted(): void {
		global $wpdb;

		$issued = $this->service->issue( self::PURPOSE, 1, [], 600 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test verification.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', Tables::single_use_tokens() ), ARRAY_A );

		$this->assertCount( 1, $rows );
		$row = $rows[0];

		// The raw token appears nowhere in the row.
		foreach ( $row as $column => $value ) {
			$this->assertStringNotContainsString( $issued['token'], (string) $value, "Column {$column} leaked the raw token" );
		}

		$this->assertSame( hash( 'sha256', $issued['token'] ), $row['token_hash'] );
	}

	/**
	 * redeem() returns the issuing user and payload for a fresh token.
	 *
	 * @return void
	 */
	public function test_redeem_returns_user_and_payload(): void {
		$issued = $this->service->issue( self::PURPOSE, 42, [ 'foo' => 'bar' ], 600 );

		$result = $this->service->redeem( $issued['token'], self::PURPOSE );

		$this->assertIsArray( $result );
		$this->assertSame( 42, $result['user_id'] );
		$this->assertSame( [ 'foo' => 'bar' ], $result['payload'] );
	}

	/**
	 * A second redemption of the same token fails — single-use holds.
	 *
	 * @return void
	 */
	public function test_second_redemption_fails(): void {
		$issued = $this->service->issue( self::PURPOSE, 1, [], 600 );

		$first  = $this->service->redeem( $issued['token'], self::PURPOSE );
		$second = $this->service->redeem( $issued['token'], self::PURPOSE );

		$this->assertIsArray( $first );
		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( 'token_already_used', $second->get_error_code() );
	}

	/**
	 * A token is still consumed even when the request that redeemed it is
	 * about to fail downstream — single-use must hold even if the caller's
	 * own processing then fails.
	 *
	 * @return void
	 */
	public function test_redeem_marks_used_before_caller_processing_can_fail(): void {
		$issued = $this->service->issue( self::PURPOSE, 1, [], 600 );

		// Simulate the caller's redemption succeeding, then failing further
		// downstream — the token must already be burned regardless.
		$this->service->redeem( $issued['token'], self::PURPOSE );

		$retry = $this->service->redeem( $issued['token'], self::PURPOSE );

		$this->assertInstanceOf( WP_Error::class, $retry );
		$this->assertSame( 'token_already_used', $retry->get_error_code() );
	}

	/**
	 * An expired token is rejected with a distinct error code.
	 *
	 * @return void
	 */
	public function test_expired_token_is_rejected(): void {
		global $wpdb;

		$issued = $this->service->issue( self::PURPOSE, 1, [], 600 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup: force expiry.
		$wpdb->update(
			Tables::single_use_tokens(),
			[ 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ],
			[ 'token_hash' => hash( 'sha256', $issued['token'] ) ]
		);

		$result = $this->service->redeem( $issued['token'], self::PURPOSE );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'token_expired', $result->get_error_code() );
	}

	/**
	 * An unknown token returns the same error as an already-used one — an
	 * invalid token must not be distinguishable from a spent one.
	 *
	 * @return void
	 */
	public function test_unknown_token_is_rejected_like_an_already_used_one(): void {
		$result = $this->service->redeem( 'not-a-real-token', self::PURPOSE );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'token_already_used', $result->get_error_code() );
	}

	/**
	 * A token issued under one purpose cannot be redeemed under another.
	 *
	 * @return void
	 */
	public function test_token_is_scoped_to_its_purpose(): void {
		$issued = $this->service->issue( self::PURPOSE, 1, [], 600 );

		$result = $this->service->redeem( $issued['token'], 'a_different_purpose' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'token_already_used', $result->get_error_code() );
	}

	/**
	 * An empty token is rejected outright.
	 *
	 * @return void
	 */
	public function test_empty_token_is_rejected(): void {
		$result = $this->service->redeem( '', self::PURPOSE );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'token_already_used', $result->get_error_code() );
	}

	/**
	 * A custom repository can be injected — proves the service depends on
	 * the abstraction, not a concrete `new SingleUseTokenRepository()` call.
	 *
	 * @return void
	 */
	public function test_accepts_an_injected_repository(): void {
		$service = new TokenService( new SingleUseTokenRepository() );

		$issued = $service->issue( self::PURPOSE, 1, [], 600 );

		$this->assertIsArray( $service->redeem( $issued['token'], self::PURPOSE ) );
	}
}
