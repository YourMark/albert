<?php
/**
 * Database table registry.
 *
 * @package Albert
 * @subpackage Database
 * @since      1.2.0
 */

namespace Albert\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Tables class
 *
 * Single source of truth for Albert's database table names. Runtime callers
 * (repositories, admin screens) resolve table names here; the schema/DDL lives
 * in {@see Installer}. Keeping names out of the domain folders means those
 * folders hold logging/OAuth logic only, never database plumbing.
 *
 * @since 1.2.0
 */
class Tables {

	/**
	 * The shared ability-execution log table.
	 *
	 * @return string Prefixed table name.
	 * @since 1.2.0
	 */
	public static function ability_log(): string {
		global $wpdb;

		return $wpdb->prefix . 'albert_ability_log';
	}

	/**
	 * The OAuth tables, keyed by role.
	 *
	 * @return array{clients: string, access_tokens: string, refresh_tokens: string, auth_codes: string}
	 * @since 1.2.0
	 */
	public static function oauth(): array {
		global $wpdb;

		return [
			'clients'        => $wpdb->prefix . 'albert_oauth_clients',
			'access_tokens'  => $wpdb->prefix . 'albert_oauth_access_tokens',
			'refresh_tokens' => $wpdb->prefix . 'albert_oauth_refresh_tokens',
			'auth_codes'     => $wpdb->prefix . 'albert_oauth_auth_codes',
		];
	}

	/**
	 * Every Albert table name, flat.
	 *
	 * @return array<int, string> All prefixed table names.
	 * @since 1.2.0
	 */
	public static function all(): array {
		return array_merge( [ self::ability_log() ], array_values( self::oauth() ) );
	}
}
