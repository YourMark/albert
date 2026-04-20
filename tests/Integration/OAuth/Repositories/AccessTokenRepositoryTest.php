<?php
/**
 * Integration tests for the OAuth AccessTokenRepository.
 *
 * Covers the security-critical revoked-check path — isAccessTokenRevoked()
 * must return true for unknown tokens, otherwise a forged or deleted token
 * would appear valid.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\OAuth\Repositories;

use Albert\OAuth\Database\Installer;
use Albert\OAuth\Entities\AccessTokenEntity;
use Albert\OAuth\Entities\ClientEntity;
use Albert\OAuth\Entities\ScopeEntity;
use Albert\OAuth\Repositories\AccessTokenRepository;
use Albert\Tests\TestCase;
use DateTimeImmutable;

/**
 * AccessTokenRepository integration tests.
 *
 * @covers \Albert\OAuth\Repositories\AccessTokenRepository
 */
class AccessTokenRepositoryTest extends TestCase {

	/**
	 * Repository under test.
	 *
	 * @var AccessTokenRepository
	 */
	private AccessTokenRepository $repository;

	/**
	 * Reset the access_tokens table before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::install();
		$this->repository = new AccessTokenRepository();

		global $wpdb;
		$tables = Installer::get_table_names();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test reset.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $tables['access_tokens'] ) );
	}

	// ─── Persistence ────────────────────────────────────────────────

	/**
	 * Stores the token with its key fields after persist.
	 *
	 * @return void
	 */
	public function test_persist_stores_token_fields(): void {
		$token = $this->build_token( 'tok_persist', 'cli_abc', 7 );
		$this->repository->persistNewAccessToken( $token );

		$row = $this->fetch_row( 'tok_persist' );

		$this->assertNotNull( $row );
		$this->assertSame( 'cli_abc', $row->client_id );
		$this->assertSame( 7, (int) $row->user_id );
		$this->assertSame( 0, (int) $row->revoked );
	}

	// ─── Revocation semantics (security-critical) ──────────────────

	/**
	 * A freshly persisted token is not revoked.
	 *
	 * @return void
	 */
	public function test_fresh_token_is_not_revoked(): void {
		$token = $this->build_token( 'tok_fresh', 'cli', 1 );
		$this->repository->persistNewAccessToken( $token );

		$this->assertFalse( $this->repository->isAccessTokenRevoked( 'tok_fresh' ) );
	}

	/**
	 * Flips the revoked flag on an existing token.
	 *
	 * @return void
	 */
	public function test_revoke_marks_token_revoked(): void {
		$token = $this->build_token( 'tok_revoke', 'cli', 1 );
		$this->repository->persistNewAccessToken( $token );

		$this->repository->revokeAccessToken( 'tok_revoke' );

		$this->assertTrue( $this->repository->isAccessTokenRevoked( 'tok_revoke' ) );
	}

	/**
	 * Unknown tokens are treated as revoked — fail-safe default.
	 *
	 * This is the single most important assertion in this file: it guards
	 * against a forged or deleted token appearing valid because the lookup
	 * returned null.
	 *
	 * @return void
	 */
	public function test_unknown_token_is_considered_revoked(): void {
		$this->assertTrue( $this->repository->isAccessTokenRevoked( 'tok_nonexistent' ) );
	}

	// ─── Deletion & cleanup ────────────────────────────────────────

	/**
	 * Removes the row entirely on delete.
	 *
	 * @return void
	 */
	public function test_delete_removes_token(): void {
		$token = $this->build_token( 'tok_delete', 'cli', 1 );
		$this->repository->persistNewAccessToken( $token );

		$this->assertTrue( $this->repository->deleteAccessToken( 'tok_delete' ) );
		$this->assertTrue( $this->repository->isAccessTokenRevoked( 'tok_delete' ) );
	}

	/**
	 * Removes only expired rows during cleanup.
	 *
	 * @return void
	 */
	public function test_cleanup_removes_only_expired_tokens(): void {
		$expired = $this->build_token( 'tok_expired', 'cli', 1, '-1 day' );
		$future  = $this->build_token( 'tok_future', 'cli', 1, '+1 day' );

		$this->repository->persistNewAccessToken( $expired );
		$this->repository->persistNewAccessToken( $future );

		$deleted = $this->repository->cleanupExpiredTokens();

		$this->assertGreaterThanOrEqual( 1, $deleted );
		$this->assertNull( $this->fetch_row( 'tok_expired' ) );
		$this->assertNotNull( $this->fetch_row( 'tok_future' ) );
	}

	// ─── Helpers ────────────────────────────────────────────────────

	/**
	 * Build a persistable AccessTokenEntity.
	 *
	 * @param string $token_id   Token identifier.
	 * @param string $client_id  Client identifier.
	 * @param int    $user_id    User identifier.
	 * @param string $expires_in DateTime modifier (relative to now).
	 *
	 * @return AccessTokenEntity
	 */
	private function build_token(
		string $token_id,
		string $client_id,
		int $user_id,
		string $expires_in = '+1 hour'
	): AccessTokenEntity {
		$client = new ClientEntity();
		$client->setIdentifier( $client_id );

		$token = new AccessTokenEntity();
		$token->setIdentifier( $token_id );
		$token->setClient( $client );
		$token->setUserIdentifier( (string) $user_id );
		$token->setExpiryDateTime( new DateTimeImmutable( $expires_in ) );
		$token->addScope( new ScopeEntity( 'default' ) );

		return $token;
	}

	/**
	 * Fetch an access-token row by token_id.
	 *
	 * @param string $token_id Token identifier.
	 *
	 * @return object|null
	 */
	private function fetch_row( string $token_id ): ?object {
		global $wpdb;

		$tables = Installer::get_table_names();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test helper.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE token_id = %s',
				$tables['access_tokens'],
				$token_id
			)
		);

		return $row ? $row : null;
	}
}
