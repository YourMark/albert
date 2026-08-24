<?php
/**
 * Skills Admin Page
 *
 * Mounts the Albert → Skills screen: a read-only React library of every skill in
 * the doc-21 registry, so a site owner can see the task guides a connected
 * assistant follows and when each one applies. The PHP side renders the mount
 * point and enqueues the bundle; the data flows over REST (see
 * Admin\Rest\SkillsController).
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
 * Registers the Albert → Skills admin page.
 *
 * View-only, on purpose: 1.4.0 ships visibility of the skills Albert and
 * official add-ons ship, nothing else. No enable/disable, no edit, no import, no
 * settings this screen could write. See docs/features/23-skills.md.
 *
 * @since 1.4.0
 */
class SkillsPage implements Hookable {

	/**
	 * Admin page slug.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const PAGE_SLUG = 'albert-skills';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ], Menu::POSITION_SKILLS );
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
			__( 'Skills', 'albert-ai-butler' ),
			__( 'Skills', 'albert-ai-butler' ),
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
						<strong><?php esc_html_e( 'Skills screen not built', 'albert-ai-butler' ); ?></strong>
						<?php
						printf(
							/* translators: %s: build command. */
							esc_html__( 'The compiled assets are missing. Run %s in the plugin directory to build the Skills screen.', 'albert-ai-butler' ),
							'<code>npm install &amp;&amp; npm run build</code>'
						);
						?>
					</p>
				</div>
			<?php else : ?>
				<div id="albert-skills-root"></div>
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
		return file_exists( ALBERT_PLUGIN_DIR . 'assets/build/js/skills.asset.php' );
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

		$asset_file = ALBERT_PLUGIN_DIR . 'assets/build/js/skills.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset   = require $asset_file;
		$version = $asset['version'] ?? ALBERT_VERSION;

		wp_enqueue_style( 'wp-components' );

		// DataViews ships its own stylesheet and core registers no handle for it,
		// so we ship the vendor copy emitted by the build (CopyWebpackPlugin),
		// same as the Abilities screen.
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

		wp_enqueue_style(
			'albert-skills',
			ALBERT_PLUGIN_URL . 'assets/css/admin-skills.css',
			[ Assets::PRIMITIVES_HANDLE, $has_dataviews_css ? 'albert-dataviews' : 'wp-components' ],
			Assets::version( 'assets/css/admin-skills.css' )
		);

		wp_enqueue_script(
			'albert-skills-app',
			ALBERT_PLUGIN_URL . 'assets/build/js/skills.js',
			$asset['dependencies'] ?? [],
			$version,
			true
		);

		wp_add_inline_script(
			'albert-skills-app',
			'window.albertSkills = ' . wp_json_encode(
				[
					'restBase' => Plugin::rest_namespace() . '/skills',
				]
			) . ';',
			'before'
		);

		wp_set_script_translations( 'albert-skills-app', 'albert-ai-butler' );
	}
}
