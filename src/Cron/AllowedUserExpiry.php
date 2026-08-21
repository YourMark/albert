<?php
/**
 * Invitation Expiry Cron
 *
 * @package Albert
 * @subpackage Cron
 * @since      1.4.0
 */

namespace Albert\Cron;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\Logging\Repository as LoggingRepository;
use Albert\OAuth\AllowedUsers;

/**
 * AllowedUserExpiry class
 *
 * Daily WP-Cron job that removes anyone from the allowed-users list who was
 * added but never actually completed an authorisation within the configured
 * window (docs/features/31-connections.md §5, "Invitation expiry").
 *
 * A standing "this person could approve an assistant" grant that nobody is
 * relying on is the same pure-risk-zero-benefit shape as an unused
 * connection, one layer earlier. Somebody who *did* authorise at least once
 * is never swept for this reason, however long that connection lived or
 * whatever happened to it since: the invitation was exercised, that is what
 * it was for. See {@see \Albert\OAuth\AllowedUsers::mark_authorised()}.
 *
 * @since 1.4.0
 */
class AllowedUserExpiry implements Hookable {

	/**
	 * Cron hook name.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const HOOK = 'albert_sweep_allowed_users';

	/**
	 * Option name for the expiry window (in days). 0 = never.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const OPTION = 'albert_allowed_user_expiry_days';

	/**
	 * Default expiry window (in days). Off by default: a site upgrading to
	 * this feature should not start silently sweeping people until the owner
	 * has actually looked at the new setting and chosen a window.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	const DEFAULT_DAYS = 0;

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
	 * Sweep the allowed-users list.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function run(): void {
		try {
			$days = (int) get_option( self::OPTION, self::DEFAULT_DAYS );

			if ( $days < 1 ) {
				return;
			}

			$cutoff  = time() - ( $days * DAY_IN_SECONDS );
			$logging = new LoggingRepository();

			foreach ( AllowedUsers::all() as $user_id => $entry ) {
				if ( $entry['authorised_at'] !== null ) {
					continue;
				}

				$added_at = $entry['added_at'];

				if ( $added_at === null || $added_at > $cutoff ) {
					continue;
				}

				AllowedUsers::remove( $user_id );

				// Every automatic removal is logged (docs/features/31-connections.md
				// §4/§5): "automatic" is fine, "silent" is not. Surfaces on the
				// Dashboard's Recent Activity via the shared ability log table.
				$logging->insert( 'albert/allowed-user-expired', $user_id, 'success' );
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Never let a cron failure surface to the site.
		}
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
