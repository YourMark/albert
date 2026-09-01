<?php
/**
 * MCP adapter availability and conflict reporting.
 *
 * @package Albert
 * @subpackage MCP
 * @since      1.4.0
 */

namespace Albert\MCP;

defined( 'ABSPATH' ) || exit;

use Albert\Vendor\WP\MCP\Core\McpAdapter;

/**
 * AdapterStatus class
 *
 * Answers two questions about the MCP adapter that Albert previously answered
 * only by doing nothing: is our own scoped copy here at all, and is somebody
 * else's unscoped copy loaded alongside it.
 *
 * Both states end in the same symptom — `/albert/v1/mcp` returning
 * `401 rest_forbidden` — which is also the correct response for a healthy
 * install that was handed no token. There is no way to tell the three apart
 * from outside, so a site owner debugging it reaches for their token, their
 * OAuth client and their reverse proxy before they ever suspect the plugin
 * never registered a server. That is the failure this class exists to end.
 *
 * @since 1.4.0
 */
class AdapterStatus {

	/**
	 * Transient holding the foreign-copy scan result.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	private const SCAN_TRANSIENT = 'albert_mcp_foreign_adapters';

	/**
	 * How long to keep the scan result.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	private const SCAN_TTL = DAY_IN_SECONDS;

	/**
	 * Whether Albert's own Mozart-scoped adapter is loadable.
	 *
	 * False means MCP is switched off entirely and silently. It happens when
	 * the plugin is installed from source with `composer install --no-dev`:
	 * `wordpress/mcp-adapter` and the `coenjacobs/mozart` that scopes it are
	 * both dev requirements, so neither `vendor-prefixed/` nor the adapter
	 * package exists, and {@see \Albert\Core\Plugin::init()} skips
	 * `McpAdapter::instance()` without saying so.
	 *
	 * Release builds are unaffected — `release.yml` installs with dev
	 * dependencies, runs Mozart, and only then strips them — so this is a
	 * source-install state, which is exactly how the plugin gets evaluated.
	 *
	 * @return bool True when the scoped adapter is present.
	 * @since 1.4.0
	 */
	public static function scoped_adapter_available(): bool {
		return class_exists( McpAdapter::class );
	}

	/**
	 * Every active plugin shipping an unscoped `WP\MCP\Core\McpAdapter`.
	 *
	 * Deliberately a filesystem scan rather than a look at the loaded class.
	 * Reflection reports the one copy that won the autoload race, so on a site
	 * where several plugins bundle the library it names an arbitrary one — and
	 * if the winner happens to be a known-safe copy, the guard falls silent
	 * while the genuinely conflicting plugin goes unnamed. The question worth
	 * answering is "which plugins ship this", and only the filesystem knows.
	 *
	 * Cached for a day: plugin folders do not change between requests, and the
	 * result is only ever read on an admin screen or by Site Health.
	 *
	 * @return array<string, string> Plugin folder => path of the copy it ships.
	 * @since 1.4.0
	 */
	public static function foreign_copies(): array {
		$cached = get_transient( self::SCAN_TRANSIENT );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$found = [];
		$base  = wp_normalize_path( WP_PLUGIN_DIR );
		$ours  = wp_normalize_path( ALBERT_PLUGIN_DIR );

		foreach ( self::active_plugin_dirs() as $slug => $dir ) {
			if ( trailingslashit( $dir ) === trailingslashit( $ours ) ) {
				continue;
			}

			$file = self::find_adapter_in( $dir );

			if ( $file !== null ) {
				$found[ $slug ] = str_replace( trailingslashit( $base ), '', $file );
			}
		}

		set_transient( self::SCAN_TRANSIENT, $found, self::SCAN_TTL );

		return $found;
	}

	/**
	 * Forget the cached scan, so the next read re-checks the filesystem.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public static function flush(): void {
		delete_transient( self::SCAN_TRANSIENT );
	}

	/**
	 * Active plugins as folder => absolute directory.
	 *
	 * @return array<string, string>
	 * @since 1.4.0
	 */
	private static function active_plugin_dirs(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return [];
		}

		$active = (array) get_option( 'active_plugins', [] );

		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
		}

		$dirs = [];

		foreach ( $active as $entry ) {
			// strtok() returns false for an empty subject and a non-empty string
			// otherwise, so is_string() is the whole check.
			$slug = strtok( (string) $entry, '/' );

			if ( ! is_string( $slug ) ) {
				continue;
			}

			$dirs[ $slug ] = wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) . $slug );
		}

		return $dirs;
	}

	/**
	 * Locate an unscoped adapter class file inside one plugin directory.
	 *
	 * Matches on the path ending `WP/MCP/Core/McpAdapter.php`, which is the
	 * package's own layout, so a Mozart-scoped copy — which is rewritten to a
	 * different namespace but keeps the same tail — is distinguished by the
	 * namespace declared inside the file rather than by its path.
	 *
	 * @param string $dir Absolute plugin directory.
	 *
	 * @return string|null Path of the first unscoped copy, or null when there is none.
	 * @since 1.4.0
	 */
	private static function find_adapter_in( string $dir ): ?string {
		if ( ! is_dir( $dir ) ) {
			return null;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				static function ( \SplFileInfo $file ): bool {
					// Skip trees that cannot contain a Composer package and are
					// expensive to walk.
					return ! in_array( $file->getFilename(), [ 'node_modules', '.git', 'tests', 'assets' ], true );
				}
			),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $file ) {
			if ( $file->getFilename() !== 'McpAdapter.php' ) {
				continue;
			}

			$path = wp_normalize_path( $file->getPathname() );

			if ( substr( $path, -strlen( 'WP/MCP/Core/McpAdapter.php' ) ) !== 'WP/MCP/Core/McpAdapter.php' ) {
				continue;
			}

			// A scoped copy keeps the path but changes the namespace, and only
			// the unscoped `WP\MCP\Core` namespace collides with anyone.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local PHP file inside a plugin directory; wp_remote_get() is for URLs.
			$source = (string) file_get_contents( $path );

			if ( preg_match( '/^namespace\s+WP\\\\MCP\\\\Core\s*;/m', $source ) === 1 ) {
				return $path;
			}
		}

		return null;
	}
}
