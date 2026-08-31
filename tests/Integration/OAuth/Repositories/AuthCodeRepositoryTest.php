<?php
/**
 * Integration tests for the OAuth AuthCodeRepository.
 *
 * Authorization codes are single-use by protocol. The fail-safe revocation
 * default (unknown code → treated as revoked) is what stops a code from
 * being redeemable after deletion.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\OAuth\Repositories;

use Albert\Database\Installer;
use Albert\Database\Tables;
use Albert\OAuth\Entities\AuthCodeEntity;
use Albert\OAuth\Entities\ClientEntity;
use Albert\OAuth\Entities\ScopeEntity;
use Albert\OAuth\Repositories\AuthCodeRepository;
use Albert\Tests\TestCase;
use DateTimeImmutable;
use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * AuthCodeRepository integration tests.
 *
 * @covers \Albert\OAuth\Repositories\AuthCodeRepository
 */
class AuthCodeRepositoryTest extends TestCase {

	/**
	 * Repository under test.
	 *
	 * @var AuthCodeRepository
	 */
	private AuthCodeRepository $repository;

	/**
	 * Reset the auth_codes table before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::install();
		$this->repository = new AuthCodeRepository();

		global $wpdb;
		$tables = Tables::oauth();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test reset.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $tables['auth_codes'] ) );
	}

	/**
	 * Stores the code fields after persist.
	 *
	 * @return void
	 */
	public function test_persist_stores_code_fields(): void {
		$this->repository->persistNewAuthCode( $this->build_code( 'code_1', 'cli', 11 ) );

		$this->assertFalse( $this->repository->isAuthCodeRevoked( 'code_1' ) );
	}

	/**
	 * Flips the revoked flag on an existing code.
	 *
	 * @return void
	 */
	public function test_revoke_marks_code_revoked(): void {
		$this->repository->persistNewAuthCode( $this->build_code( 'code_rev', 'cli', 1 ) );

		$this->repository->revokeAuthCode( 'code_rev' );

		$this->assertTrue( $this->repository->isAuthCodeRevoked( 'code_rev' ) );
	}

	/**
	 * Unknown codes are treated as revoked — enforces single-use.
	 *
	 * A code that was deleted/cleaned up must not be redeemable as if it
	 * were still fresh. Lock this in.
	 *
	 * @return void
	 */
	public function test_unknown_code_is_considered_revoked(): void {
		$this->assertTrue( $this->repository->isAuthCodeRevoked( 'code_missing' ) );
	}

	/**
	 * A failed insert must be loud, not silent.
	 *
	 * Previously the return value of $wpdb->insert() was discarded: the browser
	 * would still redirect with a code that could never be exchanged, and the
	 * only visible symptom was an unrelated-looking invalid_grant at the token
	 * endpoint. Forces a real insert failure via the code_id unique key rather
	 * than mocking $wpdb, so this exercises the actual failure path.
	 *
	 * @return void
	 */
	public function test_persist_throws_when_insert_fails(): void {
		global $wpdb;

		$code = $this->build_code( 'code_dup', 'cli', 1 );
		$this->repository->persistNewAuthCode( $code );

		$this->expectException( OAuthServerException::class );

		// Same code_id again — violates the UNIQUE KEY and forces $wpdb->insert()
		// to return false. Suppressed: the resulting duplicate-entry error is
		// expected, not a sign anything is wrong.
		$wpdb->suppress_errors( true );
		try {
			$this->repository->persistNewAuthCode( $code );
		} finally {
			$wpdb->suppress_errors( false );
		}
	}

	/**
	 * Removes expired rows during cleanup.
	 *
	 * @return void
	 */
	public function test_cleanup_removes_only_expired_codes(): void {
		$this->repository->persistNewAuthCode( $this->build_code( 'code_old', 'cli', 1, '-1 hour' ) );
		$this->repository->persistNewAuthCode( $this->build_code( 'code_new', 'cli', 1, '+1 hour' ) );

		$this->repository->cleanupExpiredCodes();

		$this->assertTrue( $this->repository->isAuthCodeRevoked( 'code_old' ) );
		$this->assertFalse( $this->repository->isAuthCodeRevoked( 'code_new' ) );
	}

	/**
	 * Build a persistable AuthCodeEntity.
	 *
	 * @param string $code_id    Code identifier.
	 * @param string $client_id  Client identifier.
	 * @param int    $user_id    User identifier.
	 * @param string $expires_in Relative DateTime modifier.
	 *
	 * @return AuthCodeEntity
	 */
	private function build_code(
		string $code_id,
		string $client_id,
		int $user_id,
		string $expires_in = '+10 minutes'
	): AuthCodeEntity {
		$client = new ClientEntity();
		$client->setIdentifier( $client_id );

		$code = new AuthCodeEntity();
		$code->setIdentifier( $code_id );
		$code->setClient( $client );
		$code->setUserIdentifier( (string) $user_id );
		$code->setExpiryDateTime( new DateTimeImmutable( $expires_in ) );
		$code->addScope( new ScopeEntity( 'default' ) );

		return $code;
	}
}
