<?php
/**
 * Ending a connection has to end what can revive it.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\OAuth;

use Albert\Admin\Settings;
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
 * What "disconnect" has to be worth.
 *
 * An access token lives about an hour, a refresh token thirty days, and the
 * refresh token is what mints replacements. Revoking access tokens alone ends
 * nothing: the assistant is back inside the hour.
 *
 * The defect this guards: the lookup from client to refresh token runs through
 * the access-token rows, and it filtered them on `revoked = 0`. Sign a
 * connection out first, which revokes every access token by design, and the
 * later full disconnect matched no rows, revoked no refresh token, and still
 * told the owner the assistant had to be approved again.
 *
 * @covers \Albert\Admin\Settings::revoke_user_tokens
 * @covers \Albert\OAuth\Repositories\ClientRepository::revokeAllTokens
 * @covers \Albert\OAuth\Repositories\RefreshTokenRepository::revokeForAccessTokens
 */
class ConnectionRevocationTest extends TestCase {

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
	 * The regression: disconnecting after a sign-out still kills the refresh token.
	 *
	 * Sign-out revokes the access tokens on purpose and leaves the refresh
	 * token, so the assistant comes back on its own. That is the state the full
	 * disconnect used to be unable to see past.
	 *
	 * @return void
	 */
	public function test_disconnecting_after_a_sign_out_revokes_the_refresh_token(): void {
		$client_id = $this->create_connection( 'Signed out then disconnected' );

		$this->sign_out( $client_id );

		$this->assertSame(
			1,
			$this->live_refresh_tokens( $client_id ),
			'Sign-out must leave the refresh token live; that is the difference between the two controls, '
				. 'and it is the state the full disconnect used to be unable to see past.'
		);

		( new ClientRepository() )->revokeAllTokens( $client_id );

		$this->assertSame(
			1,
			$this->revoked_refresh_tokens( $client_id ),
			'After a full disconnect the refresh token must be revoked, even though the access '
				. 'tokens were already revoked by the earlier sign-out.'
		);
	}

	/**
	 * The path that always worked, kept honest.
	 *
	 * @return void
	 */
	public function test_disconnecting_a_live_connection_revokes_the_refresh_token(): void {
		$client_id = $this->create_connection( 'Straight disconnect' );

		( new ClientRepository() )->revokeAllTokens( $client_id );

		$this->assertSame( 1, $this->revoked_refresh_tokens( $client_id ) );
		$this->assertSame( 0, $this->live_refresh_tokens( $client_id ) );
	}

	/**
	 * Signing out deliberately leaves the refresh token alone.
	 *
	 * Asserted so that "fixing" it later has to be a decision somebody writes
	 * down, rather than a side effect: the screen promises the assistant
	 * reconnects on its own within the hour.
	 *
	 * @return void
	 */
	public function test_signing_out_leaves_the_refresh_token_alone(): void {
		$client_id = $this->create_connection( 'Signed out only' );

		$this->sign_out( $client_id );

		$this->assertSame( 1, $this->live_refresh_tokens( $client_id ) );
	}

	/**
	 * Revoking one session revokes that session's refresh token, and no other.
	 *
	 * @return void
	 */
	public function test_revoking_one_access_token_revokes_only_its_own_refresh_token(): void {
		$client_id = $this->create_connection( 'Two sessions' );
		$this->add_session( $client_id, 'second' );

		( new RefreshTokenRepository() )->revokeForAccessTokens( [ 'tok_' . $client_id ] );

		$this->assertSame( 1, $this->revoked_refresh_tokens( $client_id ) );
		$this->assertSame( 1, $this->live_refresh_tokens( $client_id ) );
	}

	/**
	 * An empty set is a no-op, not a statement that revokes everything.
	 *
	 * @return void
	 */
	public function test_revoking_an_empty_set_touches_nothing(): void {
		$client_id = $this->create_connection( 'Untouched' );

		( new RefreshTokenRepository() )->revokeForAccessTokens( [] );

		$this->assertSame( 1, $this->live_refresh_tokens( $client_id ) );
	}


	/**
	 * Revoking a user's access revokes their refresh tokens too.
	 *
	 * This is what "Remove" on the allowed-users list and "revoke all sessions"
	 * both call. It had no test at all, and its body was rewritten to share
	 * revokeForAccessTokens(), so the behaviour is pinned here rather than
	 * assumed from the fact that nothing else went red.
	 *
	 * @return void
	 */
	public function test_revoking_a_users_access_revokes_their_refresh_tokens(): void {
		$client_id = $this->create_connection( 'User to be removed' );

		Settings::revoke_user_tokens( 1 );

		$this->assertSame( 1, $this->revoked_refresh_tokens( $client_id ) );
		$this->assertSame( 0, $this->live_refresh_tokens( $client_id ) );
	}

	/**
	 * It revokes that user's tokens and nobody else's.
	 *
	 * @return void
	 */
	public function test_revoking_one_users_access_leaves_another_users_alone(): void {
		$mine     = $this->create_connection( 'Mine' );
		$somebody = $this->create_connection( 'Somebody else' );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup.
		$wpdb->update(
			Tables::oauth()['access_tokens'],
			[ 'user_id' => 2 ],
			[ 'client_id' => $somebody ],
			[ '%d' ],
			[ '%s' ]
		);

		Settings::revoke_user_tokens( 1 );

		$this->assertSame( 1, $this->revoked_refresh_tokens( $mine ), "The removed user's token must go." );
		$this->assertSame( 1, $this->live_refresh_tokens( $somebody ), "Another user's must not." );
	}

	/**
	 * Revoke the access tokens only, which is what "sign it out" does.
	 *
	 * @param string $client_id The client.
	 *
	 * @return void
	 */
	private function sign_out( string $client_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup.
		$wpdb->update(
			Tables::oauth()['access_tokens'],
			[ 'revoked' => 1 ],
			[ 'client_id' => $client_id ],
			[ '%d' ],
			[ '%s' ]
		);
	}

	/**
	 * Refresh tokens for a client that are still usable.
	 *
	 * @param string $client_id The client.
	 *
	 * @return int
	 */
	private function live_refresh_tokens( string $client_id ): int {
		return $this->count_refresh_tokens( $client_id, 0 );
	}

	/**
	 * Refresh tokens for a client that have been revoked.
	 *
	 * @param string $client_id The client.
	 *
	 * @return int
	 */
	private function revoked_refresh_tokens( string $client_id ): int {
		return $this->count_refresh_tokens( $client_id, 1 );
	}

	/**
	 * Count a client's refresh tokens in one revocation state.
	 *
	 * Joined through the access-token rows, which is the only route: a refresh
	 * token records no client id of its own.
	 *
	 * @param string $client_id The client.
	 * @param int    $revoked   0 for live, 1 for revoked.
	 *
	 * @return int
	 */
	private function count_refresh_tokens( string $client_id, int $revoked ): int {
		global $wpdb;

		$tables = Tables::oauth();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i r
				 INNER JOIN %i a ON a.token_id = r.access_token_id
				 WHERE a.client_id = %s AND r.revoked = %d',
				$tables['refresh_tokens'],
				$tables['access_tokens'],
				$client_id,
				$revoked
			)
		);
	}

	/**
	 * A client with one access token and one refresh token behind it.
	 *
	 * @param string $name The client name.
	 *
	 * @return string The client id.
	 */
	private function create_connection( string $name ): string {
		$created   = ( new ClientRepository() )->createClient( $name, 'https://example.test/cb', true, 1 );
		$client_id = (string) $created['client_id'];

		$this->add_session( $client_id, '' );

		return $client_id;
	}

	/**
	 * Add another access + refresh token pair to an existing client.
	 *
	 * @param string $client_id The client.
	 * @param string $suffix    Distinguishes this pair's identifiers.
	 *
	 * @return void
	 */
	private function add_session( string $client_id, string $suffix ): void {
		$client_entity = new ClientEntity();
		$client_entity->setIdentifier( $client_id );

		$access = new AccessTokenEntity();
		$access->setIdentifier( 'tok_' . $suffix . $client_id );
		$access->setClient( $client_entity );
		$access->setUserIdentifier( '1' );
		$access->setExpiryDateTime( new DateTimeImmutable( '+1 hour' ) );
		$access->addScope( new ScopeEntity( 'default' ) );

		( new AccessTokenRepository() )->persistNewAccessToken( $access );

		$refresh = new RefreshTokenEntity();
		$refresh->setIdentifier( 'ref_' . $suffix . $client_id );
		$refresh->setAccessToken( $access );
		$refresh->setExpiryDateTime( new DateTimeImmutable( '+30 days' ) );

		( new RefreshTokenRepository() )->persistNewRefreshToken( $refresh );
	}
}
