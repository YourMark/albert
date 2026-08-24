<?php
/**
 * Connection Retention Sweep Cron
 *
 * @package Albert
 * @subpackage Cron
 * @since      1.4.0
 */

namespace Albert\Cron;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\Logging\Repository as LoggingRepository;
use Albert\OAuth\ConnectionRetention;

/**
 * ConnectionRetentionSweep class
 *
 * Daily WP-Cron job that drops never-used and idle connections
 * (docs/features/31-connections.md §4), and logs every automatic removal:
 * "automatic" is fine, "silent" is not. An owner who notices a connection is
 * gone needs to be able to find out why, not be left guessing whether
 * something broke.
 *
 * @since 1.4.0
 */
class ConnectionRetentionSweep implements Hookable {

	/**
	 * Cron hook name.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const HOOK = 'albert_sweep_connections';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function register_hooks(): void {
		add_action( self::HOOK, [ $this, 'run' ] );
	}

	/**
	 * Sweep never-used and idle connections.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function run(): void {
		try {
			$logging = new LoggingRepository();

			foreach ( ConnectionRetention::sweep_never_used() as $connection ) {
				$this->log( $logging, 'albert/connection-dropped-unused', $connection );
			}

			foreach ( ConnectionRetention::sweep_idle() as $connection ) {
				$this->log( $logging, 'albert/connection-expired-idle', $connection );
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Never let a cron failure surface to the site.
		}
	}

	/**
	 * Log one automatic removal.
	 *
	 * @param LoggingRepository    $logging    The log repository.
	 * @param string               $ability    The synthetic ability id for this event.
	 * @param array<string, mixed> $connection The dropped connection, from
	 *                                          {@see \Albert\OAuth\Repositories\ClientRepository::getLiveConnections()}.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function log( LoggingRepository $logging, string $ability, array $connection ): void {
		$user_ids = (array) $connection['user_ids'];
		$user_id  = (int) ( $user_ids[0] ?? 0 );
		$name     = (string) ( $connection['label'] !== '' ? $connection['label'] : $connection['name'] );

		$logging->insert(
			$ability,
			$user_id,
			'success',
			null,
			[
				'client_id'   => (string) $connection['client_id'],
				'client_name' => $name,
			]
		);
	}

	/**
	 * Schedule the daily sweep if not already scheduled.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Unschedule the daily sweep.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );

		while ( $timestamp !== false ) {
			wp_unschedule_event( $timestamp, self::HOOK );
			$timestamp = wp_next_scheduled( self::HOOK );
		}
	}
}
