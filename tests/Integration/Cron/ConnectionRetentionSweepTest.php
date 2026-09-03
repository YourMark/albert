<?php
/**
 * Integration tests for the connection-retention sweep cron.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Cron;

use Albert\Cron\ConnectionRetentionSweep;
use Albert\Database\Installer;
use Albert\Database\Tables;
use Albert\Logging\Repository as LoggingRepository;
use Albert\OAuth\ConnectionRetention;
use Albert\OAuth\Entities\AccessTokenEntity;
use Albert\OAuth\Entities\ClientEntity;
use Albert\OAuth\Entities\ScopeEntity;
use Albert\OAuth\Repositories\AccessTokenRepository;
use Albert\OAuth\Repositories\ClientRepository;
use Albert\Tests\TestCase;
use DateTimeImmutable;

/**
 * ConnectionRetentionSweep integration tests.
 *
 * @covers \Albert\Cron\ConnectionRetentionSweep
 */
class ConnectionRetentionSweepTest extends TestCase {

	/**
	 * The cron under test.
	 *
	 * @var ConnectionRetentionSweep
	 */
	private ConnectionRetentionSweep $cron;

	/**
	 * Fresh tables and cleared options before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::install();
		( new LoggingRepository() )->truncate();

		global $wpdb;
		$tables = Tables::oauth();
		foreach ( [ 'clients', 'access_tokens', 'refresh_tokens' ] as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test reset.
			$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $tables[ $key ] ) );
		}

		delete_option( ConnectionRetention::NEVER_USED_OPTION );
		delete_option( ConnectionRetention::IDLE_OPTION );

		$this->cron = new ConnectionRetentionSweep();
	}

	/**
	 * Create a live connection with a controllable `created_at`/`last_used_at`.
	 *
	 * @param int      $created_days_ago   How many days ago it was approved.
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

	/**
	 * A dropped never-used connection is logged with its client id and name.
	 *
	 * @return void
	 */
	public function test_run_logs_a_dropped_never_used_connection(): void {
		$client_id = $this->create_connection( 20, null );

		update_option( ConnectionRetention::NEVER_USED_OPTION, 14 );
		$this->cron->run();

		$logged = ( new LoggingRepository() )->latest_for_ability( 'albert/connection-dropped-unused' );

		$this->assertNotNull( $logged );
		$this->assertSame( '1', (string) $logged->user_id );
		$this->assertSame( 'success', $logged->status );

		$this->assertNull( ( new ClientRepository() )->getClientEntity( $client_id ) );
	}

	/**
	 * A dropped idle connection is logged separately from a never-used one.
	 *
	 * @return void
	 */
	public function test_run_logs_a_dropped_idle_connection(): void {
		$this->create_connection( 30, 20 );

		update_option( ConnectionRetention::IDLE_OPTION, 14 );
		$this->cron->run();

		$logged = ( new LoggingRepository() )->latest_for_ability( 'albert/connection-expired-idle' );

		$this->assertNotNull( $logged );
		$this->assertSame( 'success', $logged->status );
	}

	/**
	 * With both settings off (the default), nothing is dropped or logged.
	 *
	 * @return void
	 */
	public function test_run_does_nothing_with_both_settings_off(): void {
		$this->create_connection( 365, null );

		update_option( ConnectionRetention::NEVER_USED_OPTION, 0 );
		$this->cron->run();

		$this->assertNull( ( new LoggingRepository() )->latest_for_ability( 'albert/connection-dropped-unused' ) );
		$this->assertNotNull( ( new ClientRepository() )->getLiveConnections() );
		$this->assertCount( 1, ( new ClientRepository() )->getLiveConnections() );
	}
}
