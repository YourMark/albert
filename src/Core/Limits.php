<?php
/**
 * Plan Limits
 *
 * Enforces user and connection limits for the free tier.
 * Premium addon plugins can raise limits by registering a PlanResolver.
 *
 * @package Albert
 * @subpackage Core
 * @since      1.0.0
 */

namespace Albert\Core;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\PlanResolverInterface;
use Albert\OAuth\Database\Installer;

/**
 * Limits class
 *
 * Provides static methods for checking and retrieving plan limits.
 * Free-tier defaults are hardcoded as private constants and cannot
 * be changed via apply_filters. Only a registered PlanResolver can
 * override them.
 *
 * @since 1.0.0
 */
final class Limits {

	/**
	 * Free-tier limits.
	 *
	 * @since 1.0.0
	 * @var array<string, int>
	 */
	private const FREE_LIMITS = [
		'max_users'                => 2,
		'max_connections_per_user' => 1,
	];

	/**
	 * Registered plan resolver.
	 *
	 * @since 1.0.0
	 * @var PlanResolverInterface|null
	 */
	private static ?PlanResolverInterface $resolver = null;

	/**
	 * Register a plan resolver to override free-tier limits.
	 *
	 * @since 1.0.0
	 *
	 * @param PlanResolverInterface $resolver The plan resolver instance.
	 *
	 * @return void
	 */
	public static function register_plan_resolver( PlanResolverInterface $resolver ): void {
		self::$resolver = $resolver;
	}

	/**
	 * Get the current plan limits.
	 *
	 * If a resolver is registered and returns a valid limits array,
	 * its values are merged over the free-tier defaults.
	 *
	 * @since 1.0.0
	 * @return array<string, int> The effective limits.
	 */
	public static function get_limits(): array {
		if ( self::$resolver !== null ) {
			$overrides = self::$resolver->get_limits();

			if ( is_array( $overrides ) ) {
				return array_merge( self::FREE_LIMITS, $overrides );
			}
		}

		return self::FREE_LIMITS;
	}

	/**
	 * Get the maximum number of allowed users.
	 *
	 * @since 1.0.0
	 * @return int Maximum allowed users.
	 */
	public static function max_users(): int {
		return (int) self::get_limits()['max_users'];
	}

	/**
	 * Get the maximum number of connections per user.
	 *
	 * @since 1.0.0
	 * @return int Maximum connections per user.
	 */
	public static function max_connections_per_user(): int {
		return (int) self::get_limits()['max_connections_per_user'];
	}

	/**
	 * Check if another user can be added to the allowed users list.
	 *
	 * @since 1.0.0
	 * @return bool True if another user can be added.
	 */
	public static function can_add_user(): bool {
		$allowed_users = get_option( 'albert_allowed_users', [] );

		return count( $allowed_users ) < self::max_users();
	}

	/**
	 * Check if a user can add another connection.
	 *
	 * Counts distinct active (non-revoked) client connections for the user.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id The WordPress user ID.
	 *
	 * @return bool True if the user can add another connection.
	 */
	public static function can_add_connection( int $user_id ): bool {
		global $wpdb;

		$tables = Installer::get_table_names();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Limit check requires fresh count.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT client_id) FROM %i WHERE user_id = %d AND revoked = 0',
				$tables['access_tokens'],
				$user_id
			)
		);

		return $count < self::max_connections_per_user();
	}

	/**
	 * Get the current plan identifier.
	 *
	 * @since 1.0.0
	 * @return string Plan identifier. Returns 'free' when no resolver is registered.
	 */
	public static function get_plan(): string {
		if ( self::$resolver !== null ) {
			return self::$resolver->get_plan();
		}

		return 'free';
	}
}
