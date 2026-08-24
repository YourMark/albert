<?php
/**
 * Connection Retention
 *
 * @package Albert
 * @subpackage OAuth
 * @since      1.4.0
 */

namespace Albert\OAuth;

defined( 'ABSPATH' ) || exit;

use Albert\OAuth\Repositories\ClientRepository;

/**
 * ConnectionRetention class
 *
 * Two automatic cleanups for connections that are pure standing risk for
 * zero benefit, the same shape as an unused allowed-user invitation
 * (docs/features/31-connections.md §4, §5), one layer later: after
 * authorisation rather than before it.
 *
 * - **Never-used**: a client that completed authorisation and holds a live
 *   token, but has never made a single real call with it.
 * - **Idle**: a client that *was* used at some point, but not for a while.
 *
 * Both read the exact same list {@see \Albert\Admin\Connections} shows as
 * "Connected assistants" ({@see ClientRepository::getLiveConnections()}), so
 * a connection is never dropped while an owner can still see it on screen as
 * connected, and "used" means what the screen already means by it:
 * {@see \Albert\OAuth\Server\TokenValidator} touches a client's
 * `last_used_at` on every authenticated API call. That does not yet
 * distinguish a real tool invocation from routine discovery traffic (e.g.
 * `tools/list`), which is why idle expiry defaults to off: unlike never-used
 * (an unambiguous zero, nothing to misread), a wrong idle threshold could
 * drop a connection somebody is quietly still relying on.
 *
 * Dropping means both: revoking every token the client holds
 * ({@see ClientRepository::revokeAllTokens()}) and deleting the client
 * registration itself ({@see ClientRepository::deleteClient()}). Revoking
 * alone would leave a tokenless client row behind indefinitely, the same
 * kind of dead row {@see \Albert\Cron\TokenCleanup} exists to stop
 * accumulating at the token level.
 *
 * @since 1.4.0
 */
class ConnectionRetention {

	/**
	 * Option name for the never-used window, in days. 0 = never.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const NEVER_USED_OPTION = 'albert_connection_never_used_days';

	/**
	 * Option name for the idle window, in days. 0 = never.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const IDLE_OPTION = 'albert_connection_idle_days';

	/**
	 * Default never-used window, in days: short, because an approval nobody
	 * has ever exercised is unambiguous standing risk for zero benefit.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	const DEFAULT_NEVER_USED_DAYS = 14;

	/**
	 * Default idle window, in days. Off by default: whether `last_used_at`
	 * reliably tracks real use, as opposed to routine background traffic, is
	 * unverified against real client behaviour (see class docblock). An
	 * owner who has watched their own site's pattern can tighten this once
	 * they trust the signal; nothing should be dropped on an unverified one.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	const DEFAULT_IDLE_DAYS = 0;

	/**
	 * Drop every connection that has a live token but has never made a
	 * single real call with it, and its window has passed.
	 *
	 * @return array<int, array<string, mixed>> The connections dropped.
	 * @since 1.4.0
	 */
	public static function sweep_never_used(): array {
		$days = (int) get_option( self::NEVER_USED_OPTION, self::DEFAULT_NEVER_USED_DAYS );

		if ( $days <= 0 ) {
			return [];
		}

		$cutoff  = time() - ( $days * DAY_IN_SECONDS );
		$repo    = new ClientRepository();
		$dropped = [];

		foreach ( $repo->getLiveConnections() as $connection ) {
			if ( $connection['last_used_ts'] > 0 ) {
				continue; // Has been used at least once; not this sweep's concern.
			}

			if ( $connection['created_ts'] > $cutoff ) {
				continue; // Too recent: still inside the grace window.
			}

			self::drop( $repo, $connection['client_id'] );
			$dropped[] = $connection;
		}

		return $dropped;
	}

	/**
	 * Drop every connection that was used before, but not within its window.
	 *
	 * @return array<int, array<string, mixed>> The connections dropped.
	 * @since 1.4.0
	 */
	public static function sweep_idle(): array {
		$days = (int) get_option( self::IDLE_OPTION, self::DEFAULT_IDLE_DAYS );

		if ( $days <= 0 ) {
			return [];
		}

		$cutoff  = time() - ( $days * DAY_IN_SECONDS );
		$repo    = new ClientRepository();
		$dropped = [];

		foreach ( $repo->getLiveConnections() as $connection ) {
			if ( $connection['last_used_ts'] <= 0 ) {
				continue; // Never used at all; that's sweep_never_used()'s concern.
			}

			if ( $connection['last_used_ts'] > $cutoff ) {
				continue; // Used recently enough.
			}

			self::drop( $repo, $connection['client_id'] );
			$dropped[] = $connection;
		}

		return $dropped;
	}

	/**
	 * Revoke a client's tokens and delete its registration.
	 *
	 * @param ClientRepository $repo      The repository to act through.
	 * @param string           $client_id The client to drop.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private static function drop( ClientRepository $repo, string $client_id ): void {
		$repo->revokeAllTokens( $client_id );
		$repo->deleteClient( $client_id );
	}
}
