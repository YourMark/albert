<?php
/**
 * Base for Albert's React-driven admin screens.
 *
 * Abilities, Skills, and Context each follow the identical shape: a submenu
 * entry, a render_page() that shows either an actionable notice or a bare
 * React mount point, and an enqueue_assets() that loads the compiled bundle
 * plus the shared token/primitive/DataViews stylesheets. Subclasses supply
 * only what differs between screens.
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.4.1
 */

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\Core\Plugin;

/**
 * AbstractReactPage class
 *
 * @since 1.4.1
 */
abstract class AbstractReactPage implements Hookable {

	/**
	 * Admin page slug. PHP has no abstract constants; every subclass must
	 * redeclare this with its own value, this placeholder exists only so
	 * `static::PAGE_SLUG` resolves for static analysis.
	 *
	 * @since 1.4.1
	 * @var string
	 */
	protected const PAGE_SLUG = '';

	/**
	 * Compiled bundle basename, e.g. 'skills' for assets/build/js/skills.js
	 * and assets/build/js/skills.asset.php.
	 *
	 * @return string
	 * @since 1.4.1
	 */
	abstract protected function asset_key(): string;

	/**
	 * `admin_menu` priority this screen registers its submenu at.
	 *
	 * @return int
	 * @since 1.4.1
	 */
	abstract protected function menu_position(): int;

	/**
	 * Translated screen title, used for the submenu label and in notices.
	 *
	 * @return string
	 * @since 1.4.1
	 */
	abstract protected function screen_title(): string;

	/**
	 * `id` attribute of the React mount point.
	 *
	 * @return string
	 * @since 1.4.1
	 */
	abstract protected function root_id(): string;

	/**
	 * The `window.albert*` global the enqueued script reads its REST base
	 * from, e.g. 'albertSkills' for `window.albertSkills.restBase`.
	 *
	 * @return string
	 * @since 1.4.1
	 */
	abstract protected function js_global(): string;

	/**
	 * REST sub-namespace this screen's data lives under, e.g. 'skills' for
	 * `{namespace}/skills`.
	 *
	 * @return string
	 * @since 1.4.1
	 */
	abstract protected function rest_suffix(): string;

	/**
	 * Handle and plugin-relative path for this screen's own authored
	 * stylesheet.
	 *
	 * @return array{handle: string, path: string}
	 * @since 1.4.1
	 */
	abstract protected function stylesheet(): array;

	/**
	 * Whether this screen ships @wordpress/dataviews and needs its
	 * stylesheet as a dependency.
	 *
	 * @return bool
	 * @since 1.4.1
	 */
	protected function needs_dataviews_css(): bool {
		return false;
	}

	/**
	 * Whether to wire `wp_set_script_translations()` for this screen's
	 * script.
	 *
	 * @return bool
	 * @since 1.4.1
	 */
	protected function needs_translations(): bool {
		return true;
	}

	/**
	 * An unmet environment requirement to show instead of the normal
	 * build/mount-point flow, or null when there is none.
	 *
	 * @return array{title: string, message: string}|null
	 * @since 1.4.1
	 */
	protected function unmet_requirement(): ?array {
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ], $this->menu_position() );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Register the submenu page under Albert.
	 *
	 * @return void
	 * @since 1.4.1
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			Menu::PARENT_SLUG,
			$this->screen_title(),
			$this->screen_title(),
			'manage_options',
			static::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Render the admin page.
	 *
	 * Renders the React mount point, or an actionable notice when an
	 * environment requirement is unmet or the compiled bundle is missing (a
	 * dev checkout that hasn't run the build).
	 *
	 * @return void
	 * @since 1.4.1
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'albert-ai-butler' ) );
		}

		echo '<div class="wrap albert-wrap">';

		$unmet = $this->unmet_requirement();

		if ( $unmet !== null ) {
			$this->render_notice( $unmet['title'], $unmet['message'] );
		} elseif ( ! $this->build_exists() ) {
			$this->render_notice(
				sprintf(
					/* translators: %s: screen name, e.g. "Skills". */
					__( '%s screen not built', 'albert-ai-butler' ),
					$this->screen_title()
				),
				sprintf(
					/* translators: 1: build command, 2: screen name, e.g. "Skills". */
					__( 'The compiled assets are missing. Run %1$s in the plugin directory to build the %2$s screen.', 'albert-ai-butler' ),
					'<code>npm install &amp;&amp; npm run build</code>',
					$this->screen_title()
				)
			);
		} else {
			printf( '<div id="%s"></div>', esc_attr( $this->root_id() ) );
			echo '<noscript><p>' . esc_html__( 'This screen requires JavaScript.', 'albert-ai-butler' ) . '</p></noscript>';
		}

		echo '</div>';
	}

	/**
	 * Render a page-title heading followed by a standard error notice.
	 *
	 * @param string $title   Notice heading, translated but not yet escaped.
	 * @param string $message Notice body, translated but not yet escaped; may
	 *                        contain safe inline HTML (e.g. `<code>`).
	 *
	 * @return void
	 * @since 1.4.1
	 */
	private function render_notice( string $title, string $message ): void {
		printf( '<h1>%s</h1>', esc_html( get_admin_page_title() ) );
		echo '<div class="notice notice-error"><p>';
		printf( '<strong>%s</strong> ', esc_html( $title ) );
		echo wp_kses_post( $message );
		echo '</p></div>';
	}

	/**
	 * Whether the compiled bundle is present.
	 *
	 * @return bool True when the compiled bundle is on disk.
	 * @since 1.4.1
	 */
	protected function build_exists(): bool {
		return file_exists( ALBERT_PLUGIN_DIR . 'assets/build/js/' . $this->asset_key() . '.asset.php' );
	}

	/**
	 * Enqueue this page's assets.
	 *
	 * Reads the wp-scripts asset manifest (dependencies + version) emitted to
	 * assets/build/js/{asset_key}.asset.php. @wordpress/* deps load from
	 * core's registered handles; @wordpress/dataviews (where used) is bundled
	 * into the script and its stylesheet is shipped as
	 * assets/build/css/dataviews.css. Bails quietly if the build is absent
	 * (render_page shows the build notice in that case).
	 *
	 * @param string $hook Current admin page hook.
	 *
	 * @return void
	 * @since 1.4.1
	 */
	public function enqueue_assets( string $hook ): void {
		if ( Menu::PARENT_SLUG . '_page_' . static::PAGE_SLUG !== $hook ) {
			return;
		}

		$asset_file = ALBERT_PLUGIN_DIR . 'assets/build/js/' . $this->asset_key() . '.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset   = require $asset_file;
		$version = $asset['version'] ?? ALBERT_VERSION;

		// Component chrome (Button, ToggleControl, …).
		wp_enqueue_style( 'wp-components' );

		// DataViews ships its own stylesheet and core registers no handle for
		// it, so we ship the vendor copy emitted by the build
		// (CopyWebpackPlugin), where this screen uses DataViews at all.
		$style_dependency = 'wp-components';

		if ( $this->needs_dataviews_css() ) {
			$dataviews_css = ALBERT_PLUGIN_DIR . 'assets/build/css/dataviews.css';

			if ( file_exists( $dataviews_css ) ) {
				wp_enqueue_style(
					'albert-dataviews',
					ALBERT_PLUGIN_URL . 'assets/build/css/dataviews.css',
					[ 'wp-components' ],
					$version
				);
				$style_dependency = 'albert-dataviews';
			}
		}

		// Our authored screen styles, loaded last so they can override the above.
		$stylesheet = $this->stylesheet();

		wp_enqueue_style(
			$stylesheet['handle'],
			ALBERT_PLUGIN_URL . $stylesheet['path'],
			[ Assets::PRIMITIVES_HANDLE, $style_dependency ],
			Assets::version( $stylesheet['path'] )
		);

		$script_handle = 'albert-' . $this->asset_key() . '-app';

		wp_enqueue_script(
			$script_handle,
			ALBERT_PLUGIN_URL . 'assets/build/js/' . $this->asset_key() . '.js',
			$asset['dependencies'] ?? [],
			$version,
			true
		);

		wp_add_inline_script(
			$script_handle,
			'window.' . $this->js_global() . ' = ' . wp_json_encode(
				[
					// REST root for this controller; the app builds its paths
					// from here rather than hardcoding the namespace in JS.
					'restBase' => Plugin::rest_namespace() . '/' . $this->rest_suffix(),
				]
			) . ';',
			'before'
		);

		if ( $this->needs_translations() ) {
			wp_set_script_translations( $script_handle, 'albert-ai-butler' );
		}
	}
}
