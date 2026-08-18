<?php
/**
 * Context Admin Page
 *
 * Mounts the Albert → Context screen: a React app where the site owner writes
 * what connected assistants should know about this site, sees what each detected
 * section costs, and reads the exact payload that will be sent. The PHP side
 * renders the mount point and enqueues the bundle; everything else flows over
 * REST (see Admin\Rest\ContextController).
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.4.0
 */

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\Core\Plugin;

/**
 * Registers the Albert → Context admin page.
 *
 * A React app rather than a Settings-API form, for the reason the screen exists:
 * its value is the live feedback. A token meter that recomputes as you type, a
 * preview of the exact text the assistant receives, and section switches that
 * change both, none of that survives a save-and-reload form, and a form is what
 * it would have to be.
 *
 * @since 1.4.0
 */
class ContextPage implements Hookable {

	/**
	 * Admin page slug.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const PAGE_SLUG = 'albert-context';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ], Menu::POSITION_CONTEXT );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Register the submenu page under Albert.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			Menu::PARENT_SLUG,
			__( 'Context', 'albert-ai-butler' ),
			__( 'Context', 'albert-ai-butler' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Render the admin page.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'albert-ai-butler' ) );
		}
		?>
		<div class="wrap albert-wrap">
			<?php if ( ! self::build_exists() ) : ?>
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<div class="notice notice-error">
					<p>
						<strong><?php esc_html_e( 'Context screen not built', 'albert-ai-butler' ); ?></strong>
						<?php
						printf(
							/* translators: %s: build command. */
							esc_html__( 'The compiled assets are missing. Run %s in the plugin directory to build the Context screen.', 'albert-ai-butler' ),
							'<code>npm install &amp;&amp; npm run build</code>'
						);
						?>
					</p>
				</div>
			<?php else : ?>
				<div id="albert-context-root"></div>
				<noscript><p><?php esc_html_e( 'This screen requires JavaScript.', 'albert-ai-butler' ); ?></p></noscript>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Whether the compiled bundle is present.
	 *
	 * @return bool True when the compiled bundle is on disk.
	 * @since 1.4.0
	 */
	private static function build_exists(): bool {
		return file_exists( ALBERT_PLUGIN_DIR . 'assets/build/js/context.asset.php' );
	}

	/**
	 * Enqueue this page's assets.
	 *
	 * @param string $hook Current admin page hook.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function enqueue_assets( string $hook ): void {
		if ( Menu::PARENT_SLUG . '_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		$asset_file = ALBERT_PLUGIN_DIR . 'assets/build/js/context.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset   = require $asset_file;
		$version = $asset['version'] ?? ALBERT_VERSION;

		wp_enqueue_style( 'wp-components' );

		wp_enqueue_style(
			'albert-context',
			ALBERT_PLUGIN_URL . 'assets/css/admin-context.css',
			[ Assets::PRIMITIVES_HANDLE, 'wp-components' ],
			Assets::version( 'assets/css/admin-context.css' )
		);

		wp_enqueue_script(
			'albert-context-app',
			ALBERT_PLUGIN_URL . 'assets/build/js/context.js',
			$asset['dependencies'] ?? [],
			$version,
			true
		);

		wp_add_inline_script(
			'albert-context-app',
			'window.albertContext = ' . wp_json_encode(
				[
					'restBase' => Plugin::rest_namespace() . '/context',
				]
			) . ';',
			'before'
		);

		wp_set_script_translations( 'albert-context-app', 'albert-ai-butler' );
	}
}
