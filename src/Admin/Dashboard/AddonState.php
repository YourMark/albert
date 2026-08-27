<?php
/**
 * Add-on installation state
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.4.0
 */

namespace Albert\Admin\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Whether an add-on is running, merely installed, or not here at all.
 *
 * Two states are not enough. Asking "does the class exist" answers *running or
 * not*, and everything that is not running then gets sold to. Somebody who
 * bought Premium, installed it, and left it switched off is told to go and buy
 * it, which is the same mistake as recommending an add-on to a site that
 * already has it: the screen is describing the product's state rather than the
 * person's.
 *
 * The three states each want different words. Absent is an offer, inactive is a
 * reminder, active says nothing at all.
 *
 * @since 1.4.0
 */
class AddonState {

	/**
	 * Running: its code is loaded.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	public const ACTIVE = 'active';

	/**
	 * Present on disk but switched off.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	public const INACTIVE = 'inactive';

	/**
	 * Not installed.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	public const ABSENT = 'absent';

	/**
	 * Classify one add-on.
	 *
	 * Activity is decided by the symbol rather than by the plugin list, because
	 * the symbol is the thing that actually matters: a plugin can be "active"
	 * in the list and still not have loaded its classes, and a must-use plugin
	 * never appears in the list at all.
	 *
	 * Installation is decided by the plugin list, which is the only place a
	 * deactivated plugin can be seen from.
	 *
	 * @since 1.4.0
	 *
	 * @param string $symbol      A class the add-on defines when it loads.
	 * @param string $plugin_file Its plugin file, e.g. `albert-premium-service/albert-premium-service.php`.
	 *
	 * @return string One of the state constants.
	 */
	public static function of( string $symbol, string $plugin_file ): string {
		if ( $symbol !== '' && class_exists( $symbol ) ) {
			return self::ACTIVE;
		}

		if ( $plugin_file !== '' && array_key_exists( $plugin_file, self::installed() ) ) {
			return self::INACTIVE;
		}

		return self::ABSENT;
	}

	/**
	 * Where to go to switch a plugin on.
	 *
	 * Deliberately the Plugins screen rather than a one-click activation link.
	 * Activating from a dashboard card is a state change two clicks from
	 * somewhere the owner was only reading, and the Plugins screen is where a
	 * person expects to confirm what they are switching on.
	 *
	 * @since 1.4.0
	 *
	 * @param string $name The add-on's name, used to pre-filter the list.
	 *
	 * @return string
	 */
	public static function activation_url( string $name ): string {
		return add_query_arg(
			[
				's'             => rawurlencode( $name ),
				'plugin_status' => 'inactive',
			],
			admin_url( 'plugins.php' )
		);
	}

	/**
	 * The installed plugins, keyed by file.
	 *
	 * `get_plugins()` lives in an admin include that is not always loaded, and
	 * it scans the plugins directory, so the result is held for the request.
	 *
	 * @since 1.4.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function installed(): array {
		static $plugins = null;

		if ( $plugins !== null ) {
			return $plugins;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();

		return $plugins;
	}
}
