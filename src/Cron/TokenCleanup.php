<?php
/**
 * Expired Token Cleanup Cron
 *
 * @package Albert
 * @subpackage Cron
 * @since      1.4.0
 */

namespace Albert\Cron;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\Core\Tokens\SingleUseTokenRepository;
use Albert\OAuth\Repositories\AccessTokenRepository;
use Albert\OAuth\Repositories\RefreshTokenRepository;

/**
 * TokenCleanup class
 *
 * Daily WP-Cron job that deletes expired OAuth access/refresh token rows and
 * expired single-use tokens (media upload tickets and, from 1.5.0, doc 40's
 * pending-action confirmations). The OAuth repositories already implement
 * `cleanupExpiredTokens()` (docs/features/31-connections.md §4), but nothing
 * has ever called them outside tests, so dead rows accumulate forever.
 *
 * Pure hygiene, not a security boundary in either case: an expired OAuth
 * token already cannot authenticate anything whether or not this has run —
 * access tokens are signed JWTs verified cryptographically against the `exp`
 * claim, and refresh tokens are checked against their own encrypted expiry
 * before the `revoked` column is even read. A single-use token is likewise
 * already rejected by {@see \Albert\Core\Tokens\TokenService::redeem()} once
 * past its `expires_at`. This job exists only so the tables do not grow
 * without bound.
 *
 * @since 1.4.0
 */
class TokenCleanup implements Hookable {

	/**
	 * Cron hook name.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const HOOK = 'albert_cleanup_expired_tokens';

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
	 * Delete expired access and refresh token rows.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function run(): void {
		try {
			( new AccessTokenRepository() )->cleanupExpiredTokens();
			( new RefreshTokenRepository() )->cleanupExpiredTokens();
			( new SingleUseTokenRepository() )->cleanup_expired();
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Never let a cron failure surface to the site.
		}
	}

	/**
	 * Schedule the daily cleanup if not already scheduled.
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
	 * Unschedule the daily cleanup.
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
