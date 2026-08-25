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

/**
 * Registers the Albert → Skills admin page.
 *
 * View-only, on purpose: 1.4.0 ships visibility of the skills Albert and
 * official add-ons ship, nothing else. No enable/disable, no edit, no import, no
 * settings this screen could write. See docs/features/23-skills.md.
 *
 * @since 1.4.0
 */
class SkillsPage extends AbstractReactPage {

	/**
	 * Admin page slug.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const PAGE_SLUG = 'albert-skills';

	/**
	 * {@inheritDoc}
	 */
	protected function asset_key(): string {
		return 'skills';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function menu_position(): int {
		return Menu::POSITION_SKILLS;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function screen_title(): string {
		return __( 'Skills', 'albert-ai-butler' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function root_id(): string {
		return 'albert-skills-root';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function js_global(): string {
		return 'albertSkills';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function rest_suffix(): string {
		return 'skills';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function stylesheet(): array {
		return [
			'handle' => 'albert-skills',
			'path'   => 'assets/css/admin-skills.css',
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function needs_dataviews_css(): bool {
		return true;
	}
}
