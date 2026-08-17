<?php
/**
 * Shared admin asset registration.
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.4.0
 */

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;

/**
 * Registers the assets every Albert admin screen shares.
 *
 * Today that is the design-token stylesheet: the single source of colour,
 * spacing, type, radius and motion for Albert's admin, in this plugin and in
 * every add-on.
 *
 * The tokens are **registered, never enqueued** here. Every screen that needs
 * them names {@see self::TOKENS_HANDLE} in its own stylesheet's dependency
 * array, and WordPress then enqueues the tokens first, automatically, wherever
 * a consumer appears. That is stronger than enqueueing them on every admin page:
 * a screen cannot forget the dependency and still render (its own custom
 * properties would be undefined), and Albert adds no stylesheet to admin pages
 * that are none of its business.
 *
 * Add-ons follow the same contract — declare `'albert-tokens'` as a dependency
 * rather than restating any value:
 *
 *     wp_enqueue_style( 'my-addon', $url, [ 'albert-tokens' ], $version );
 *
 * Registration runs at priority 1 so the handle exists before any screen's own
 * `admin_enqueue_scripts` callback runs at the default priority.
 *
 * @since 1.4.0
 */
class Assets implements Hookable {

	/**
	 * Stylesheet handle for the design tokens.
	 *
	 * Part of the public contract for add-ons. Treat as frozen.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	public const TOKENS_HANDLE = 'albert-tokens';

	/**
	 * Stylesheet handle for the shared primitives.
	 *
	 * Depends on {@see self::TOKENS_HANDLE}, so naming this one alone is enough
	 * — WordPress pulls the tokens in ahead of it. Part of the public contract
	 * for add-ons. Treat as frozen.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	public const PRIMITIVES_HANDLE = 'albert-primitives';

	/**
	 * Register the hook that registers the shared handles.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'register_shared_styles' ], 1 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_on_albert_screens' ], 2 );
	}

	/**
	 * Load the primitives on every Albert screen, including add-on pages.
	 *
	 * Registering alone is not enough for anything Albert renders *outside* a
	 * screen's own callback. {@see Menu::render_navigation()} runs on
	 * `in_admin_header` for every Albert screen — add-on pages included — so on
	 * a page owned by an add-on the navigation markup would appear with no
	 * stylesheet behind it and render as a run-together list of link text.
	 *
	 * Enqueueing here means any page under the Albert menu gets the shared
	 * layer whether or not its owner asked for it, which is the only way a
	 * cross-screen element can be styled reliably. Screens still name the handle
	 * in their own dependency array; a second enqueue of the same handle is a
	 * no-op.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function enqueue_on_albert_screens(): void {
		if ( ! Menu::is_albert_screen() ) {
			return;
		}

		wp_enqueue_style( self::PRIMITIVES_HANDLE );
	}

	/**
	 * Register the design-token stylesheet.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function register_shared_styles(): void {
		wp_register_style(
			self::TOKENS_HANDLE,
			ALBERT_PLUGIN_URL . 'assets/css/albert-tokens.css',
			[],
			ALBERT_VERSION
		);

		wp_register_style(
			self::PRIMITIVES_HANDLE,
			ALBERT_PLUGIN_URL . 'assets/css/albert-primitives.css',
			[ self::TOKENS_HANDLE ],
			ALBERT_VERSION
		);
	}
}
