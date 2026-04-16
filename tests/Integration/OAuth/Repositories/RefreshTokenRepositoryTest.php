<?php
/**
 * Integration tests for the OAuth RefreshTokenRepository.
 *
 * Mirrors the AccessTokenRepository shape — same fail-safe revocation
 * semantics plus the cascade revocation by access_token_id.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\OAuth\Repositories;

use Albert\OAuth\Database\Installer;
use Albert\OAuth\Entities\AccessTokenEntity;
use Albert\OAuth\Entities\ClientEntity;
use Albert\OAuth\Entities\RefreshTokenEntity;
use Albert\OAuth\Repositories\RefreshTokenRepository;
use Albert\Tests\TestCase;
use DateTimeImmutable;

/**
 * RefreshTokenRepository integration tests.
 *
 * @covers \Albert\OAuth\Repositories\RefreshTokenRepository
 */
class RefreshTokenRepositoryTest extends TestCase {

	/**
	 * Repository under test.
	 *
	 * @var RefreshTokenRepository
	 */
	private RefreshTokenRepository $repository;

	/**
	 * Reset the refresh_tokens table before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::install();
		$this->repository = new RefreshTokenRepository();

		global $wpdb;
		$tables = Installer::get_table_names();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test reset.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $tables['refresh_tokens'] ) );
	}

	/**
	 * Stores the token fields after persist.
	 *
	 * @return void
	 */
	public function test_persist_stores_token_fields(): void {
		$this->repository->persistNewRefreshToken( $this->build_refresh( 'rtk_1', 'atk_1' ) );

		$this->assertFalse( $this->repository->isRefreshTokenRevoked( 'rtk_1' ) );
	}

	/**
	 * Flips the revoked flag on an existing refresh token.
	 *
	 * @return void
	 */
	public function test_revoke_marks_token_revoked(): void {
		$this->repository->persistNewRefreshToken( $this->build_refresh( 'rtk_rev', 'atk_rev' ) );

		$this->repository->revokeRefreshToken( 'rtk_rev' );

		$this->assertTrue( $this->repository->isRefreshTokenRevoked( 'rtk_rev' ) );
	}

	/**
	 * Unknown refresh tokens are treated as revoked — fail-safe default.
	 *
	 * @return void
	 */
	public function test_unknown_token_is_considered_revoked(): void {
		$this->assertTrue( $this->repository->isRefreshTokenRevoked( 'rtk_unknown' ) );
	}

	/**
	 * Revoking by access_token_id cascades to every linked refresh token.
	 *
	 * This is the pattern an OAuth server uses when invalidating a
	 * session — one call must revoke all refresh tokens tied to the
	 * access token that's being dropped.
	 *
	 * @return void
	 */
	public function test_revoke_by_access_token_cascades(): void {
		$this->repository->persistNewRefreshToken( $this->build_refresh( 'rtk_a', 'atk_shared' ) );
		$this->repository->persistNewRefreshToken( $this->build_refresh( 'rtk_b', 'atk_shared' ) );
		$this->repository->persistNewRefreshToken( $this->build_refresh( 'rtk_c', 'atk_other' ) );

		$this->repository->revokeRefreshTokensByAccessToken( 'atk_shared' );

		$this->assertTrue( $this->repository->isRefreshTokenRevoked( 'rtk_a' ) );
		$this->assertTrue( $this->repository->isRefreshTokenRevoked( 'rtk_b' ) );
		$this->assertFalse( $this->repository->isRefreshTokenRevoked( 'rtk_c' ) );
	}

	/**
	 * Removes expired rows during cleanup.
	 *
	 * @return void
	 */
	public function test_cleanup_removes_only_expired_tokens(): void {
		$this->repository->persistNewRefreshToken(
			$this->build_refresh( 'rtk_expired', 'atk_e', '-1 day' )
		);
		$this->repository->persistNewRefreshToken(
			$this->build_refresh( 'rtk_future', 'atk_f', '+1 day' )
		);

		$this->repository->cleanupExpiredTokens();

		$this->assertTrue( $this->repository->isRefreshTokenRevoked( 'rtk_expired' ) );
		$this->assertFalse( $this->repository->isRefreshTokenRevoked( 'rtk_future' ) );
	}

	/**
	 * Build a RefreshTokenEntity wired to a minimal AccessTokenEntity.
	 *
	 * @param string $refresh_id Refresh token id.
	 * @param string $access_id  Access token id the refresh refers to.
	 * @param string $expires_in Relative DateTime modifier.
	 *
	 * @return RefreshTokenEntity
	 */
	private function build_refresh(
		string $refresh_id,
		string $access_id,
		string $expires_in = '+1 month'
	): RefreshTokenEntity {
		$client = new ClientEntity();
		$client->setIdentifier( 'cli_x' );

		$access = new AccessTokenEntity();
		$access->setIdentifier( $access_id );
		$access->setClient( $client );
		$access->setExpiryDateTime( new DateTimeImmutable( '+1 hour' ) );

		$refresh = new RefreshTokenEntity();
		$refresh->setIdentifier( $refresh_id );
		$refresh->setAccessToken( $access );
		$refresh->setExpiryDateTime( new DateTimeImmutable( $expires_in ) );

		return $refresh;
	}
}
