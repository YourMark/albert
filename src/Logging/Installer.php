<?php
/**
 * Deprecated logging installer shim.
 *
 * @package Albert
 * @subpackage Logging
 * @since      1.1.0
 */

namespace Albert\Logging;

defined( 'ABSPATH' ) || exit;

use Albert\Database\Installer as DatabaseInstaller;
use Albert\Database\Tables;

/**
 * Installer (deprecated shim)
 *
 * The logging table's schema moved to {@see \Albert\Database\Installer} and its
 * name to {@see \Albert\Database\Tables} in 1.2.0. This thin forwarder is kept
 * only so add-ons compiled against the old API keep working until they update —
 * it holds no schema of its own. Remove once all add-ons target the new classes.
 *
 * @deprecated 1.2.0 Use Albert\Database\Tables / Albert\Database\Installer.
 * @since 1.1.0
 */
class Installer {

	/**
	 * The ability log table name.
	 *
	 * @return string
	 * @deprecated 1.2.0 Use Albert\Database\Tables::ability_log().
	 * @since 1.1.0
	 */
	public static function get_table_name(): string {
		return Tables::ability_log();
	}

	/**
	 * Create/upgrade all tables.
	 *
	 * @return void
	 * @deprecated 1.2.0 Use Albert\Database\Installer::install().
	 * @since 1.1.0
	 */
	public static function install(): void {
		DatabaseInstaller::install();
	}

	/**
	 * Drop all tables.
	 *
	 * @return void
	 * @deprecated 1.2.0 Use Albert\Database\Installer::uninstall().
	 * @since 1.1.0
	 */
	public static function uninstall(): void {
		DatabaseInstaller::uninstall();
	}
}
