<?php
/**
 * Integration tests for what the daily token sweep is allowed to delete.
 *
 * `refresh_tokens` carries only `access_token_id`, never `client_id`, so the
 * access-token row is the only path from a refresh token back to the client
 * holding it. The sweep used to delete every expired access token, which cut
 * that path for any connection whose access token had lapsed while its refresh
 * token was still good. An access token lives about an hour and a refresh token
 * thirty days, so that is the ordinary state of a quiet assistant rather than
 * an edge case.
 *
 * The result was a connection that still worked and could no longer be seen:
 * absent from {@see ClientRepository::getLiveConnections()}, and therefore from
 * the Connections screen, the Dashboard count and both retention sweeps. An
 * owner could not revoke what they could not see.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\OAuth;

use Albert\Database\Tables;
use Albert\OAuth\Entities\AccessTokenEntity;
use Albert\OAuth\Entities\ClientEntity;
use Albert\OAuth\Entities\RefreshTokenEntity;
use Albert\OAuth\Entities\ScopeEntity;
use Albert\OAuth\Repositories\AccessTokenRepository;
use Albert\OAuth\Repositories\ClientRepository;
use Albert\OAuth\Repositories\RefreshTokenRepository;
use Albert\Tests\TestCase;
use DateTimeImmutable;

/**
 * What the sweep may collect, and what it must leave alone.
 *
 * @covers \Albert\OAuth\Repositories\AccessTokenRepository::cleanupExpiredTokens
 */
class TokenCleanupVisibilityTest extends TestCase {

	/**
	 * Start from no clients and no tokens.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		$tables = Tables::oauth();

		foreach ( [ 'clients', 'access_tokens', 'refresh_tokens' ] as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test reset.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $tables[ $key ] ) );
		}
	}

	/**
	 * A quiet assistant survives the sweep and stays visible.
	 *
	 * Its access token lapsed an hour ago; its refresh token is good for
	 * another month, so it can still come back at any moment. Collecting the
	 * access-token row would hide a connection that is genuinely live.
	 *
	 * @return void
	 */
	public function test_an_expired_token_with_a_live_refresh_token_is_kept(): void {
		$this->create_connection( 'Quiet assistant', '-1 hour', '+30 days' );

		$deleted = ( new AccessTokenRepository() )->cleanupExpiredTokens();

		$this->assertSame( 0, $deleted, 'Nothing should have been collected.' );
		$this->assertCount(
			1,
			( new ClientRepository() )->getLiveConnections(),
			'The connection must still be listed, and therefore still revocable.'
		);
	}

	/**
	 * Once nothing live points at it, the row is collected as before.
	 *
	 * @return void
	 */
	public function test_an_expired_token_with_an_expired_refresh_token_is_collected(): void {
		$this->create_connection( 'Dead assistant', '-2 hours', '-1 hour' );

		$deleted = ( new AccessTokenRepository() )->cleanupExpiredTokens();

		$this->assertSame( 1, $deleted );
		$this->assertSame( [], ( new ClientRepository() )->getLiveConnections() );
	}

	/**
	 * A revoked refresh token does not hold its access token open either.
	 * Revoking is the owner ending the connection; the row has no one left to
	 * be attributed to.
	 *
	 * @return void
	 */
	public function test_a_revoked_refresh_token_does_not_keep_the_row(): void {
		$client_id = $this->create_connection( 'Revoked assistant', '-2 hours', '+30 days' );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup.
		$wpdb->update(
			Tables::oauth()['refresh_tokens'],
			[ 'revoked' => 1 ],
			[ 'access_token_id' => 'tok_' . $client_id ]
		);

		$this->assertSame( 1, ( new AccessTokenRepository() )->cleanupExpiredTokens() );
	}

	/**
	 * A token that has not expired is never touched, whatever its refresh
	 * token is doing.
	 *
	 * @return void
	 */
	public function test_a_live_access_token_is_untouched(): void {
		$this->create_connection( 'Busy assistant', '+1 hour', '+30 days' );

		$this->assertSame( 0, ( new AccessTokenRepository() )->cleanupExpiredTokens() );
		$this->assertCount( 1, ( new ClientRepository() )->getLiveConnections() );
	}

	/**
	 * Create a client with one access token and one refresh token, each given
	 * a `strtotime()`-style expiry so a test can place it either side of now.
	 *
	 * @param string $name           App-reported client name.
	 * @param string $access_expiry  Access token expiry, e.g. `-1 hour`.
	 * @param string $refresh_expiry Refresh token expiry, e.g. `+30 days`.
	 *
	 * @return string The client id.
	 */
	private function create_connection( string $name, string $access_expiry, string $refresh_expiry ): string {
		$created   = ( new ClientRepository() )->createClient( $name, 'https://example.test/cb', true, 1 );
		$client_id = (string) $created['client_id'];

		$client_entity = new ClientEntity();
		$client_entity->setIdentifier( $client_id );

		$access = new AccessTokenEntity();
		$access->setIdentifier( 'tok_' . $client_id );
		$access->setClient( $client_entity );
		$access->setUserIdentifier( '1' );
		$access->setExpiryDateTime( new DateTimeImmutable( $access_expiry ) );
		$access->addScope( new ScopeEntity( 'default' ) );

		( new AccessTokenRepository() )->persistNewAccessToken( $access );

		$refresh = new RefreshTokenEntity();
		$refresh->setIdentifier( 'ref_' . $client_id );
		$refresh->setAccessToken( $access );
		$refresh->setExpiryDateTime( new DateTimeImmutable( $refresh_expiry ) );

		( new RefreshTokenRepository() )->persistNewRefreshToken( $refresh );

		return $client_id;
	}
}
