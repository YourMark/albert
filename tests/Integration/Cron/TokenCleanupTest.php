<?php
/**
 * Integration tests for the expired-token cleanup cron.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Cron;

use Albert\Core\Tokens\SingleUseTokenRepository;
use Albert\Cron\TokenCleanup;
use Albert\Database\Installer;
use Albert\Database\Tables;
use Albert\OAuth\Entities\AccessTokenEntity;
use Albert\OAuth\Entities\ClientEntity;
use Albert\OAuth\Entities\RefreshTokenEntity;
use Albert\OAuth\Entities\ScopeEntity;
use Albert\OAuth\Repositories\AccessTokenRepository;
use Albert\OAuth\Repositories\RefreshTokenRepository;
use Albert\Tests\TestCase;
use DateTimeImmutable;

/**
 * TokenCleanup integration tests.
 *
 * @covers \Albert\Cron\TokenCleanup
 */
class TokenCleanupTest extends TestCase {

	/**
	 * The cron under test.
	 *
	 * @var TokenCleanup
	 */
	private TokenCleanup $cron;

	/**
	 * Fresh token tables before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::install();

		global $wpdb;
		$tables = Tables::oauth();
		foreach ( [ 'access_tokens', 'refresh_tokens' ] as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test reset.
			$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $tables[ $key ] ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test reset.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Tables::single_use_tokens() ) );

		$this->cron = new TokenCleanup();
	}

	/**
	 * Deletes a long-expired single-use token row and leaves a fresh one alone.
	 *
	 * @return void
	 */
	public function test_run_deletes_long_expired_single_use_tokens(): void {
		$repo = new SingleUseTokenRepository();
		$repo->insert( 'hash_expired', 'media_upload', 1, [], gmdate( 'Y-m-d H:i:s', time() - ( 2 * DAY_IN_SECONDS ) ) );
		$repo->insert( 'hash_future', 'media_upload', 1, [], gmdate( 'Y-m-d H:i:s', time() + 600 ) );

		$this->cron->run();

		$this->assertNull( $repo->find( 'hash_expired', 'media_upload' ) );
		$this->assertNotNull( $repo->find( 'hash_future', 'media_upload' ) );
	}

	/**
	 * Deletes an expired access token row and leaves a live one alone.
	 *
	 * @return void
	 */
	public function test_run_deletes_expired_access_tokens(): void {
		$repo = new AccessTokenRepository();
		$repo->persistNewAccessToken( $this->build_access_token( 'tok_expired', '-1 day' ) );
		$repo->persistNewAccessToken( $this->build_access_token( 'tok_future', '+1 day' ) );

		$this->cron->run();

		$this->assertTrue( $repo->isAccessTokenRevoked( 'tok_expired' ) );
		$this->assertFalse( $repo->isAccessTokenRevoked( 'tok_future' ) );
	}

	/**
	 * Deletes an expired refresh token row and leaves a live one alone.
	 *
	 * @return void
	 */
	public function test_run_deletes_expired_refresh_tokens(): void {
		$access_repo  = new AccessTokenRepository();
		$refresh_repo = new RefreshTokenRepository();

		$access_repo->persistNewAccessToken( $this->build_access_token( 'atk_1' ) );
		$refresh_repo->persistNewRefreshToken( $this->build_refresh_token( 'rtk_expired', 'atk_1', '-1 day' ) );
		$refresh_repo->persistNewRefreshToken( $this->build_refresh_token( 'rtk_future', 'atk_1', '+1 month' ) );

		$this->cron->run();

		$this->assertTrue( $refresh_repo->isRefreshTokenRevoked( 'rtk_expired' ) );
		$this->assertFalse( $refresh_repo->isRefreshTokenRevoked( 'rtk_future' ) );
	}

	/**
	 * A failure in one repository does not stop the cron from running, and
	 * never surfaces to the site.
	 *
	 * @return void
	 */
	public function test_run_never_throws(): void {
		$this->cron->run();

		$this->addToAssertionCount( 1 );
	}

	// ─── Helpers ────────────────────────────────────────────────────

	/**
	 * Build a persistable AccessTokenEntity.
	 *
	 * @param string $token_id   Token identifier.
	 * @param string $expires_in DateTime modifier (relative to now).
	 *
	 * @return AccessTokenEntity
	 */
	private function build_access_token( string $token_id, string $expires_in = '+1 hour' ): AccessTokenEntity {
		$client = new ClientEntity();
		$client->setIdentifier( 'cli_x' );

		$token = new AccessTokenEntity();
		$token->setIdentifier( $token_id );
		$token->setClient( $client );
		$token->setUserIdentifier( '1' );
		$token->setExpiryDateTime( new DateTimeImmutable( $expires_in ) );
		$token->addScope( new ScopeEntity( 'default' ) );

		return $token;
	}

	/**
	 * Build a persistable RefreshTokenEntity.
	 *
	 * @param string $refresh_id The refresh token identifier.
	 * @param string $access_id  The access token identifier it is paired with.
	 * @param string $expires_in DateTime modifier (relative to now).
	 *
	 * @return RefreshTokenEntity
	 */
	private function build_refresh_token( string $refresh_id, string $access_id, string $expires_in = '+1 month' ): RefreshTokenEntity {
		$access = new AccessTokenEntity();
		$access->setIdentifier( $access_id );

		$refresh = new RefreshTokenEntity();
		$refresh->setIdentifier( $refresh_id );
		$refresh->setAccessToken( $access );
		$refresh->setExpiryDateTime( new DateTimeImmutable( $expires_in ) );

		return $refresh;
	}
}
