<?php
/**
 * Abilities Admin Page
 *
 * Mounts the Albert → Abilities screen: a React app built on @wordpress/dataviews
 * that lists every registered ability in a flat, filterable list with per-row
 * enable/disable, bulk actions, and a detail fly-in. The PHP side only renders
 * the mount point and enqueues the compiled bundle; all data flows over REST
 * (see Admin\Rest\AbilitiesController).
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.1.0
 */

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\Core\AbilitiesState;
use Albert\Core\Plugin;

/**
 * AbilitiesPage class
 *
 * Registers the Albert → Abilities admin page and enqueues the DataViews app
 * bundle for it. The page is entirely client-rendered; persistence happens
 * through the REST controller, not the Settings API.
 *
 * @since 1.1.0
 */
class AbilitiesPage implements Hookable {

	/**
	 * Option name for storing the disabled-abilities blocklist.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const DISABLED_ABILITIES_OPTION = AbilitiesState::OPTION;

	/**
	 * Admin page slug.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const PAGE_SLUG = 'albert-abilities';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ], Menu::POSITION_ABILITIES );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Register the submenu page under Albert.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			'albert',
			__( 'Abilities', 'albert-ai-butler' ),
			__( 'Abilities', 'albert-ai-butler' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Render the admin page.
	 *
	 * Renders the React mount point, or an actionable notice when the Abilities
	 * API is unavailable (WordPress < 6.9) or the compiled bundle is missing
	 * (a dev checkout that hasn't run the build).
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'albert-ai-butler' ) );
		}
		?>
		<div class="wrap albert-wrap">
			<?php
			if ( ! function_exists( 'wp_get_abilities' ) ) {
				?>
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<div class="notice notice-error">
					<p>
						<strong><?php esc_html_e( 'WordPress 6.9+ Required', 'albert-ai-butler' ); ?></strong>
						<?php esc_html_e( 'The Abilities API requires WordPress 6.9 or later. Please update WordPress to use this feature.', 'albert-ai-butler' ); ?>
					</p>
				</div>
				<?php
			} elseif ( ! self::build_exists() ) {
				?>
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<div class="notice notice-error">
					<p>
						<strong><?php esc_html_e( 'Abilities screen not built', 'albert-ai-butler' ); ?></strong>
						<?php
						printf(
							/* translators: %s: build command. */
							esc_html__( 'The compiled assets are missing. Run %s in the plugin directory to build the Abilities screen.', 'albert-ai-butler' ),
							'<code>npm install &amp;&amp; npm run build</code>'
						);
						?>
					</p>
				</div>
				<?php
			} else {
				?>
				<div id="albert-abilities-root"></div>
				<noscript><p><?php esc_html_e( 'This screen requires JavaScript.', 'albert-ai-butler' ); ?></p></noscript>
				<?php
			}
			?>
		</div>
		<?php
	}

	/**
	 * Get currently disabled abilities.
	 *
	 * On fresh install returns the default blocklist (Albert write abilities).
	 * Thin wrapper over {@see AbilitiesState::disabled()}, kept for existing callers.
	 *
	 * @return array<int, string>
	 * @since 1.1.0
	 */
	public static function get_disabled_abilities(): array {
		return AbilitiesState::disabled();
	}

	/**
	 * Whether the compiled DataViews bundle is present.
	 *
	 * @return bool
	 * @since 1.3.0
	 */
	private static function build_exists(): bool {
		return file_exists( ALBERT_PLUGIN_DIR . 'assets/build/js/abilities.asset.php' );
	}

	/**
	 * Enqueue admin assets for this page only.
	 *
	 * Reads the wp-scripts asset manifest (dependencies + version) emitted to
	 * assets/build/js/abilities.asset.php. @wordpress/* deps load from core's
	 * registered handles; @wordpress/dataviews is bundled into the script and its
	 * stylesheet is shipped as assets/build/css/dataviews.css. Our authored styles
	 * live in assets/css/admin-abilities.css. Bails quietly if the build is absent
	 * (render_page shows the build notice in that case).
	 *
	 * @param string $hook Current admin page hook.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'albert_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		$asset_file = ALBERT_PLUGIN_DIR . 'assets/build/js/abilities.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset   = require $asset_file;
		$version = $asset['version'] ?? ALBERT_VERSION;

		// Component chrome (Button, ToggleControl, …).
		wp_enqueue_style( 'wp-components' );

		// DataViews ships its own stylesheet and core registers no handle for it,
		// so we ship the vendor copy emitted by the build (CopyWebpackPlugin).
		$dataviews_css     = ALBERT_PLUGIN_DIR . 'assets/build/css/dataviews.css';
		$has_dataviews_css = file_exists( $dataviews_css );
		if ( $has_dataviews_css ) {
			wp_enqueue_style(
				'albert-dataviews',
				ALBERT_PLUGIN_URL . 'assets/build/css/dataviews.css',
				[ 'wp-components' ],
				$version
			);
		}

		// Our authored screen styles, loaded last so they can override the above.
		wp_enqueue_style(
			'albert-abilities',
			ALBERT_PLUGIN_URL . 'assets/css/admin-abilities.css',
			[ Assets::PRIMITIVES_HANDLE, $has_dataviews_css ? 'albert-dataviews' : 'wp-components' ],
			Assets::version( 'assets/css/admin-abilities.css' )
		);

		wp_enqueue_script(
			'albert-abilities-app',
			ALBERT_PLUGIN_URL . 'assets/build/js/abilities.js',
			$asset['dependencies'] ?? [],
			$version,
			true
		);

		wp_add_inline_script(
			'albert-abilities-app',
			'window.albertAbilities = ' . wp_json_encode(
				[
					// REST root for this controller; the app builds its paths from
					// here rather than hardcoding the namespace in JS. api-fetch
					// wires the REST root URL and wp_rest nonce automatically.
					'restBase' => Plugin::rest_namespace() . '/abilities',
				]
			) . ';',
			'before'
		);
	}
}
