<?php
/**
 * Deprecated OAuth installer shim.
 *
 * @package Albert
 * @subpackage OAuth\Database
 * @since      1.0.0
 */

namespace Albert\OAuth\Database;

defined( 'ABSPATH' ) || exit;

use Albert\Database\Installer as DatabaseInstaller;
use Albert\Database\Tables;

/**
 * Installer (deprecated shim)
 *
 * The OAuth tables' schema moved to {@see \Albert\Database\Installer} and their
 * names to {@see \Albert\Database\Tables} in 1.2.0. This thin forwarder is kept
 * only so add-ons compiled against the old API keep working until they update —
 * it holds no schema of its own. Remove once all add-ons target the new classes.
 *
 * @deprecated 1.2.0 Use Albert\Database\Tables / Albert\Database\Installer.
 * @since 1.0.0
 */
class Installer {

	/**
	 * The schema version the OAuth tables were last built for.
	 *
	 * @deprecated 1.2.0 The schema version is now unified in Database\Installer.
	 * @since 1.0.0
	 * @var string
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Option that stored the OAuth schema version.
	 *
	 * @deprecated 1.2.0 Superseded by the unified `albert_db_version` option.
	 * @since 1.0.0
	 * @var string
	 */
	const DB_VERSION_OPTION = 'albert_oauth_db_version';

	/**
	 * The OAuth table names, keyed by role.
	 *
	 * @return array<string, string>
	 * @deprecated 1.2.0 Use Albert\Database\Tables::oauth().
	 * @since 1.0.0
	 */
	public static function get_table_names(): array {
		return Tables::oauth();
	}

	/**
	 * Create/upgrade all tables.
	 *
	 * @return void
	 * @deprecated 1.2.0 Use Albert\Database\Installer::install().
	 * @since 1.0.0
	 */
	public static function install(): void {
		DatabaseInstaller::install();
	}

	/**
	 * Drop all tables.
	 *
	 * @return void
	 * @deprecated 1.2.0 Use Albert\Database\Installer::uninstall().
	 * @since 1.0.0
	 */
	public static function uninstall(): void {
		DatabaseInstaller::uninstall();
	}
}
