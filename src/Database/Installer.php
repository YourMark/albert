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
 * Owns creation and upgrades of every Albert table (the ability log and the
 * OAuth tables). Migrations are keyed on the plugin version, not a separate
 * schema number:
 *
 *  - {@see self::install()} runs on activation: creates the tables and stamps
 *    the current plugin version.
 *  - {@see self::maybe_upgrade()} runs on every load (`plugins_loaded`) and is a
 *    cheap no-op until the plugin version advances, at which point it re-runs
 *    the idempotent, additive `dbDelta` so updates apply without re-activating.
 *
 * Keying on the plugin version means there is no separate db-version to forget
 * to bump: every release advances the gate, and `dbDelta` only adds what is
 * missing, so the schema always converges. The cost is one harmless `dbDelta`
 * on releases that did not touch the schema, negligible at Albert's cadence.
 *
 * @since 1.2.0
 */
class Installer {

	/**
	 * Option holding the plugin version the schema was last built for.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const VERSION_OPTION = 'albert_db_version';

	/**
	 * Every option the plugin owns. Cleared wholesale on uninstall so deleting
	 * the plugin leaves no state behind, including OAuth key material.
	 *
	 * @since 1.2.0
	 * @var array<int, string>
	 */
	const OPTIONS = [
		self::VERSION_OPTION,
		'albert_installed_version',
		'albert_allowed_users',
		'albert_allowed_user_expiry_days',
		'albert_allowed_user_apply_expiry_to_existing',
		'albert_connection_never_used_days',
		'albert_connection_idle_days',
		'albert_disabled_abilities',
		'albert_abilities_saved',
		'albert_known_abilities',
		'albert_abilities_view_mode',
		'albert_rewrite_version',
		'albert_oauth_encryption_key',
		'albert_oauth_private_key',
		'albert_oauth_public_key',
		'albert_upload_link_max_mb',
		// Legacy options retired in earlier releases, cleared here for completeness.
		'albert_logging_db_version',
		'albert_oauth_db_version',
		'albert_external_url',
	];

	/**
	 * Every user meta key the plugin owns, cleared on uninstall alongside the
	 * options so no per-user state outlives the plugin.
	 *
	 * @since 1.4.0
	 * @var array<int, string>
	 */
	const USER_META = [
		'albert_dismissed_domain_host',
	];

	/**
	 * Create or update all tables and record the schema version.
	 *
	 * Idempotent: safe to call on every activation. `dbDelta` only issues the
	 * ALTER/CREATE statements needed to reach the declared schema.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function install(): void {
		self::create_tables();
		self::stamp_version();
	}

	/**
	 * Remove OAuth clients stored with the legacy `'*'` wildcard redirect URI, a
	 * pre-1.3.1 hole that let any redirect be accepted. Called once from
	 * {@see self::maybe_upgrade()} when upgrading from below 1.3.1; the version
	 * gate makes it a true one-time migration (no persistent flag needed), and the
	 * DELETE is idempotent besides. Only `'*'` rows are touched; properly
	 * registered clients are never affected. Runs silently: such a row is
	 * near-impossible in practice, and an affected connection simply reconnects.
	 *
	 * @return void
	 * @since 1.3.1
	 */
	private static function purge_wildcard_clients(): void {
		global $wpdb;

		$table = Tables::oauth()['clients'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot security cleanup on custom table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE redirect_uri = %s', $table, '*' ) );
	}

	/**
	 * Re-run the schema build when the plugin version has advanced.
	 *
	 * Cheap enough for `plugins_loaded`: a single option read + version compare;
	 * the idempotent `dbDelta` runs only on the first load after an update.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function maybe_upgrade(): void {
		$current = self::plugin_version();

		if ( $current === '' ) {
			return;
		}

		$stored = (string) get_option( self::VERSION_OPTION, '0' );

		if ( version_compare( $stored, $current, '<' ) ) {
			self::install();

			// Data migrations keyed on the version being upgraded *from*. The
			// legacy '*' wildcard clients only exist on installs older than 1.3.1.
			if ( version_compare( $stored, '1.3.1', '<' ) ) {
				self::purge_wildcard_clients();
			}

			// `albert_allowed_users` gained per-entry timestamps in 1.4.0.
			if ( version_compare( $stored, '1.4.0', '<' ) ) {
				self::migrate_allowed_users_shape();
			}
		}
	}

	/**
	 * Rewrap `albert_allowed_users` from a flat array of user ids into a
	 * structure carrying `added_at`, `authorised_at` and `expires_at` per
	 * entry. Called once from {@see self::maybe_upgrade()} when upgrading
	 * from below 1.4.0. This is the one, definitive migration off the
	 * pre-1.4.0 flat-array shape: every field the new shape needs is
	 * backfilled here in a single pass, not spread across several
	 * version-gated steps. See {@see \Albert\OAuth\AllowedUsers} for the
	 * shape and how it is read/written afterwards.
	 *
	 * Existing entries carry no record of when they were actually added, who
	 * they are, or whether they have ever connected anything, and there is
	 * no way to reconstruct any of that. So none of it is invented: every
	 * field is left `null`. A flat pre-1.4.0 entry could be someone who
	 * connected the day it was added, or someone who never got around to
	 * it; the option alone cannot tell those apart, and guessing either
	 * `added_at` (displayed verbatim as "Added X ago", a factual claim
	 * about a person actually reading it) or `authorised_at` (a real
	 * OAuth event that never happened) would be stating something as fact
	 * that is simply not known. `expires_at` staying `null` is what
	 * actually exempts these entries from the sweep on its own
	 * ({@see \Albert\OAuth\AllowedUsers::is_allowed()}: no `expires_at`
	 * means no expiry, independent of `authorised_at`), which is why leaving
	 * `authorised_at` null too costs nothing: nothing currently reads it
	 * for a migrated entry except that same already-satisfied check.
	 * Applying the new, tight default retroactively would risk locking out
	 * an already-working connection within a day of an unrelated plugin
	 * update, a far worse surprise than leaving a handful of legacy
	 * entries permanently unlabelled. Only invitations granted *after*
	 * this upgrade go through the real expire-if-unused lifecycle, with
	 * real timestamps to show for it.
	 *
	 * Idempotent, and safe to re-run from any intermediate state: an entry
	 * that is already an array (the new shape, or a partial one from an
	 * interrupted upgrade) is merged onto complete defaults rather than
	 * assumed whole, so a missing field is filled in without disturbing
	 * fields that are already correct.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private static function migrate_allowed_users_shape(): void {
		$raw = get_option( 'albert_allowed_users', [] );

		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return;
		}

		$defaults = [
			'added_at'      => null,
			'authorised_at' => null,
			'expires_at'    => null,
		];
		$migrated = [];

		foreach ( $raw as $key => $value ) {
			if ( is_array( $value ) ) {
				$id = (int) $key;
				if ( $id > 0 ) {
					$migrated[ $id ] = array_merge( $defaults, $value );
				}
				continue;
			}

			$id = (int) $value;
			if ( $id > 0 && ! isset( $migrated[ $id ] ) ) {
				$migrated[ $id ] = $defaults;
			}
		}

		update_option( 'albert_allowed_users', $migrated );
	}

	/**
	 * Stamp the running plugin version as the schema's built-for version.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	private static function stamp_version(): void {
		$current = self::plugin_version();

		if ( $current !== '' ) {
			update_option( self::VERSION_OPTION, $current );
		}
	}

	/**
	 * The running plugin version, or '' when unavailable (e.g. unit context).
	 *
	 * @return string
	 * @since 1.2.0
	 */
	private static function plugin_version(): string {
		return defined( 'ALBERT_VERSION' ) ? (string) ALBERT_VERSION : '';
	}

	/**
	 * Drop every table and delete every plugin option. Uninstall only.
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

		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}

		foreach ( self::USER_META as $meta_key ) {
			delete_metadata( 'user', 0, $meta_key, '', true );
		}
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
			. self::single_use_tokens_sql( $charset_collate )
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
	 * Generic single-use hashed token table DDL, backing {@see \Albert\Core\Tokens\TokenService}.
	 *
	 * @param string $charset_collate Charset/collation clause.
	 *
	 * @return string CREATE TABLE statement.
	 * @since 1.4.0
	 */
	private static function single_use_tokens_sql( string $charset_collate ): string {
		$table = Tables::single_use_tokens();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token_hash varchar(64) NOT NULL,
			purpose varchar(50) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			payload longtext DEFAULT NULL,
			expires_at datetime NOT NULL,
			redeemed_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY purpose_expires (purpose, expires_at),
			KEY user_id (user_id)
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
			origin varchar(20) DEFAULT NULL,
			label varchar(255) DEFAULT NULL,
			label_set_by bigint(20) unsigned DEFAULT NULL,
			label_set_at datetime DEFAULT NULL,
			connect_host varchar(255) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			last_used_at datetime DEFAULT NULL,
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
