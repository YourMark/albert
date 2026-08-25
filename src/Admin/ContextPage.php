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
class ContextPage extends AbstractReactPage {

	/**
	 * Admin page slug.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const PAGE_SLUG = 'albert-context';

	/**
	 * {@inheritDoc}
	 */
	protected function asset_key(): string {
		return 'context';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function menu_position(): int {
		return Menu::POSITION_CONTEXT;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function screen_title(): string {
		return __( 'Context', 'albert-ai-butler' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function root_id(): string {
		return 'albert-context-root';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function js_global(): string {
		return 'albertContext';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function rest_suffix(): string {
		return 'context';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function stylesheet(): array {
		return [
			'handle' => 'albert-context',
			'path'   => 'assets/css/admin-context.css',
		];
	}
}
