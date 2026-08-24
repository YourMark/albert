<?php
/**
 * Integration tests for ConnectionRetention.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\OAuth;

use Albert\Database\Installer;
use Albert\Database\Tables;
use Albert\OAuth\ConnectionRetention;
use Albert\OAuth\Entities\AccessTokenEntity;
use Albert\OAuth\Entities\ClientEntity;
use Albert\OAuth\Entities\ScopeEntity;
use Albert\OAuth\Repositories\AccessTokenRepository;
use Albert\OAuth\Repositories\ClientRepository;
use Albert\Tests\TestCase;
use DateTimeImmutable;

/**
 * ConnectionRetention integration tests.
 *
 * @covers \Albert\OAuth\ConnectionRetention
 */
class ConnectionRetentionTest extends TestCase {

	/**
	 * Fresh tables and cleared retention options before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::install();

		global $wpdb;
		$tables = Tables::oauth();
		foreach ( [ 'clients', 'access_tokens', 'refresh_tokens' ] as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test reset.
			$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $tables[ $key ] ) );
		}

		delete_option( ConnectionRetention::NEVER_USED_OPTION );
		delete_option( ConnectionRetention::IDLE_OPTION );
	}

	/**
	 * Create a live connection: a client with a controllable `created_at`
	 * and `last_used_at`, plus a live access token so
	 * {@see ClientRepository::getLiveConnections()} picks it up. Neither
	 * `createClient()` nor `touchLastUsed()` allow backdating directly (the
	 * latter is throttled and always stamps "now"), so both columns are set
	 * with a direct update afterwards.
	 *
	 * @param int      $created_days_ago   How many days ago the connection was approved.
	 * @param int|null $last_used_days_ago How many days ago it was last used, or null for never.
	 *
	 * @return string The client id.
	 */
	private function create_connection( int $created_days_ago, ?int $last_used_days_ago ): string {
		global $wpdb;
		$tables = Tables::oauth();

		$created   = ( new ClientRepository() )->createClient( 'Test Client', 'https://example.test/cb', true, 1 );
		$client_id = (string) $created['client_id'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup.
		$wpdb->update(
			$tables['clients'],
			[
				'created_at'   => gmdate( 'Y-m-d H:i:s', time() - ( $created_days_ago * DAY_IN_SECONDS ) ),
				'last_used_at' => $last_used_days_ago !== null
					? gmdate( 'Y-m-d H:i:s', time() - ( $last_used_days_ago * DAY_IN_SECONDS ) )
					: null,
			],
			[ 'client_id' => $client_id ]
		);

		$client_entity = new ClientEntity();
		$client_entity->setIdentifier( $client_id );

		$token = new AccessTokenEntity();
		$token->setIdentifier( 'tok_' . $client_id );
		$token->setClient( $client_entity );
		$token->setUserIdentifier( '1' );
		$token->setExpiryDateTime( new DateTimeImmutable( '+1 hour' ) );
		$token->addScope( new ScopeEntity( 'default' ) );

		( new AccessTokenRepository() )->persistNewAccessToken( $token );

		return $client_id;
	}

	// ─── sweep_never_used() ────────────────────────────────────────

	/**
	 * Drops a connection with no live token ever used, past its window.
	 *
	 * @return void
	 */
	public function test_sweep_never_used_drops_a_connection_past_its_window(): void {
		$client_id = $this->create_connection( 20, null );

		update_option( ConnectionRetention::NEVER_USED_OPTION, 14 );
		$dropped = ConnectionRetention::sweep_never_used();

		$this->assertCount( 1, $dropped );
		$this->assertSame( $client_id, $dropped[0]['client_id'] );
	}

	/**
	 * Leaves a recently approved, never-used connection alone: still inside
	 * its grace window.
	 *
	 * @return void
	 */
	public function test_sweep_never_used_leaves_a_recent_connection_alone(): void {
		$this->create_connection( 2, null );

		update_option( ConnectionRetention::NEVER_USED_OPTION, 14 );
		$dropped = ConnectionRetention::sweep_never_used();

		$this->assertCount( 0, $dropped );
	}

	/**
	 * Never drops a connection that has been used at least once, however
	 * old the approval is: that is the idle sweep's concern, not this one's.
	 *
	 * @return void
	 */
	public function test_sweep_never_used_never_drops_a_used_connection(): void {
		$this->create_connection( 20, 1 );

		update_option( ConnectionRetention::NEVER_USED_OPTION, 14 );
		$dropped = ConnectionRetention::sweep_never_used();

		$this->assertCount( 0, $dropped );
	}

	/**
	 * 0 disables the sweep entirely.
	 *
	 * @return void
	 */
	public function test_sweep_never_used_with_zero_disables_it(): void {
		$this->create_connection( 365, null );

		update_option( ConnectionRetention::NEVER_USED_OPTION, 0 );
		$dropped = ConnectionRetention::sweep_never_used();

		$this->assertCount( 0, $dropped );
	}

	/**
	 * Dropping revokes the client's tokens and deletes its registration, so
	 * it disappears from live connections and from the client list entirely.
	 *
	 * @return void
	 */
	public function test_sweep_never_used_drop_removes_the_client_and_its_tokens(): void {
		$client_id = $this->create_connection( 20, null );

		update_option( ConnectionRetention::NEVER_USED_OPTION, 14 );
		ConnectionRetention::sweep_never_used();

		$repo = new ClientRepository();
		$this->assertNull( $repo->getClientEntity( $client_id ) );
		$this->assertSame( [], $repo->getLiveConnections() );
	}

	// ─── sweep_idle() ───────────────────────────────────────────────

	/**
	 * Off by default: an old, idle connection is left alone with no
	 * setting saved.
	 *
	 * @return void
	 */
	public function test_sweep_idle_is_off_by_default(): void {
		$this->create_connection( 365, 200 );

		$dropped = ConnectionRetention::sweep_idle();

		$this->assertCount( 0, $dropped );
	}

	/**
	 * Drops a connection that was used before, but not within its window.
	 *
	 * @return void
	 */
	public function test_sweep_idle_drops_a_connection_past_its_window(): void {
		$client_id = $this->create_connection( 30, 20 );

		update_option( ConnectionRetention::IDLE_OPTION, 14 );
		$dropped = ConnectionRetention::sweep_idle();

		$this->assertCount( 1, $dropped );
		$this->assertSame( $client_id, $dropped[0]['client_id'] );
	}

	/**
	 * Leaves a recently used connection alone.
	 *
	 * @return void
	 */
	public function test_sweep_idle_leaves_a_recently_used_connection_alone(): void {
		$this->create_connection( 30, 1 );

		update_option( ConnectionRetention::IDLE_OPTION, 14 );
		$dropped = ConnectionRetention::sweep_idle();

		$this->assertCount( 0, $dropped );
	}

	/**
	 * Never touches a connection that has never been used at all: that is
	 * sweep_never_used()'s concern, not this one's.
	 *
	 * @return void
	 */
	public function test_sweep_idle_never_touches_a_never_used_connection(): void {
		$this->create_connection( 30, null );

		update_option( ConnectionRetention::IDLE_OPTION, 14 );
		$dropped = ConnectionRetention::sweep_idle();

		$this->assertCount( 0, $dropped );
	}
}
