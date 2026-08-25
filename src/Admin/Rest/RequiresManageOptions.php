<?php
/**
 * Shared REST permission check for Albert's admin-screen controllers.
 *
 * @package Albert
 * @subpackage Admin\Rest
 * @since      1.4.0
 */

namespace Albert\Admin\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * `manage_options`-gates every route on a controller.
 *
 * Abilities, Skills and Context each serve one admin screen a
 * `manage_options` user can reach, and each had its own byte-identical
 * `check_permission()`. One trait instead of three copies, so a future
 * change to who may manage these screens (a capability filter, a narrower
 * role) is one edit, not three kept in sync by hand.
 *
 * @since 1.4.0
 */
trait RequiresManageOptions {

	/**
	 * Permission check for every route on this controller.
	 *
	 * @return bool True for users who may manage options.
	 * @since 1.4.0
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}
}
