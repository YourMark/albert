<?php
/**
 * Database installer / migrator.
 *
 * @package Albert
 * @subpackage Database
 * @since      1.2.0
 */

namespace Albert\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Installer class
 *
 * Owns creation and versioned upgrades of every Albert table (the ability log
 * and the OAuth tables). One DB version, one upgrade path:
 *
 *  - {@see self::install()} runs on activation — creates/updates all tables.
 *  - {@see self::maybe_upgrade()} runs on every load (`plugins_loaded`) and is
 *    a cheap no-op until {@see self::DB_VERSION} moves ahead of the stored
 *    version, at which point it re-runs the idempotent `dbDelta` so updates
 *    apply without re-activating the plugin.
 *
 * Bump {@see self::DB_VERSION} whenever a CREATE TABLE statement changes.
 *
 * @since 1.2.0
 */
class Installer {

	/**
	 * Current schema version. Bump on any DDL change.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Option storing the installed schema version.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const DB_VERSION_OPTION = 'albert_db_version';

	/**
	 * Create or update all tables and record the schema version.
	 *
	 * Idempotent — safe to call on every activation. `dbDelta` only issues the
	 * ALTER/CREATE statements needed to reach the declared schema.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function install(): void {
		self::create_tables();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Run the migration only when the stored version is behind the code version.
	 *
	 * Cheap enough for `plugins_loaded`: a single option read + version compare;
	 * the `dbDelta` runs only when an upgrade is pending.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function maybe_upgrade(): void {
		$installed = (string) get_option( self::DB_VERSION_OPTION, '0' );

		if ( version_compare( $installed, self::DB_VERSION, '<' ) ) {
			self::install();
		}
	}

	/**
	 * Drop every table and forget the schema version. Uninstall only.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function uninstall(): void {
		global $wpdb;

		foreach ( Tables::all() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema change required for uninstall.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}

		delete_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Run dbDelta over every table's CREATE statement.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	private static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = self::ability_log_sql( $charset_collate )
			. self::oauth_clients_sql( $charset_collate )
			. self::oauth_access_tokens_sql( $charset_collate )
			. self::oauth_refresh_tokens_sql( $charset_collate )
			. self::oauth_auth_codes_sql( $charset_collate );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Ability-execution log table DDL.
	 *
	 * @param string $charset_collate Charset/collation clause.
	 *
	 * @return string CREATE TABLE statement.
	 * @since 1.2.0
	 */
	private static function ability_log_sql( string $charset_collate ): string {
		$table = Tables::ability_log();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ability_name varchar(191) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			status varchar(20) NOT NULL DEFAULT 'success',
			error_code varchar(100) DEFAULT NULL,
			error_message longtext DEFAULT NULL,
			duration_ms int(10) unsigned DEFAULT NULL,
			ip_address varchar(45) DEFAULT NULL,
			user_agent text DEFAULT NULL,
			referrer text DEFAULT NULL,
			request_id varchar(36) DEFAULT NULL,
			input longtext DEFAULT NULL,
			output longtext DEFAULT NULL,
			client_id varchar(80) DEFAULT NULL,
			client_name varchar(255) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY ability_created (ability_name, created_at),
			KEY ability_status (ability_name, status, created_at)
		) $charset_collate;\n\n";
	}

	/**
	 * OAuth clients table DDL.
	 *
	 * @param string $charset_collate Charset/collation clause.
	 *
	 * @return string CREATE TABLE statement.
	 * @since 1.2.0
	 */
	private static function oauth_clients_sql( string $charset_collate ): string {
		$table = Tables::oauth()['clients'];

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			client_id varchar(80) NOT NULL,
			client_secret varchar(255) DEFAULT NULL,
			name varchar(255) NOT NULL,
			redirect_uri text NOT NULL,
			user_id bigint(20) unsigned DEFAULT NULL,
			is_confidential tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY client_id (client_id),
			KEY user_id (user_id)
		) $charset_collate;\n\n";
	}

	/**
	 * OAuth access tokens table DDL.
	 *
	 * @param string $charset_collate Charset/collation clause.
	 *
	 * @return string CREATE TABLE statement.
	 * @since 1.2.0
	 */
	private static function oauth_access_tokens_sql( string $charset_collate ): string {
		$table = Tables::oauth()['access_tokens'];

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token_id varchar(100) NOT NULL,
			client_id varchar(80) NOT NULL,
			user_id bigint(20) unsigned DEFAULT NULL,
			scopes text DEFAULT NULL,
			revoked tinyint(1) NOT NULL DEFAULT 0,
			expires_at datetime NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY token_id (token_id),
			KEY client_id (client_id),
			KEY user_id (user_id),
			KEY revoked (revoked),
			KEY expires_at (expires_at)
		) $charset_collate;\n\n";
	}

	/**
	 * OAuth refresh tokens table DDL.
	 *
	 * @param string $charset_collate Charset/collation clause.
	 *
	 * @return string CREATE TABLE statement.
	 * @since 1.2.0
	 */
	private static function oauth_refresh_tokens_sql( string $charset_collate ): string {
		$table = Tables::oauth()['refresh_tokens'];

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token_id varchar(100) NOT NULL,
			access_token_id varchar(100) NOT NULL,
			revoked tinyint(1) NOT NULL DEFAULT 0,
			expires_at datetime NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY token_id (token_id),
			KEY access_token_id (access_token_id),
			KEY revoked (revoked),
			KEY expires_at (expires_at)
		) $charset_collate;\n\n";
	}

	/**
	 * OAuth authorization codes table DDL.
	 *
	 * @param string $charset_collate Charset/collation clause.
	 *
	 * @return string CREATE TABLE statement.
	 * @since 1.2.0
	 */
	private static function oauth_auth_codes_sql( string $charset_collate ): string {
		$table = Tables::oauth()['auth_codes'];

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			code_id varchar(100) NOT NULL,
			client_id varchar(80) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			scopes text DEFAULT NULL,
			revoked tinyint(1) NOT NULL DEFAULT 0,
			expires_at datetime NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY code_id (code_id),
			KEY client_id (client_id),
			KEY user_id (user_id),
			KEY revoked (revoked),
			KEY expires_at (expires_at)
		) $charset_collate;\n\n";
	}
}
