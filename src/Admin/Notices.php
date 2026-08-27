<?php
/**
 * Admin Notices
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.4.0
 */

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The one place every Albert screen renders its queued `add_settings_error()`
 * notices, so a confirmation or warning looks and behaves identically wherever
 * it appears.
 *
 * Two things this does that a bare `settings_errors()` call does not.
 *
 * **It stays where the screen put it.** `wp-admin/js/common.js` runs
 * `$( 'div.updated, div.error, div.notice' ).not( '.inline, .below-h2' )
 * .insertAfter( $headerEnd )` on load, which hoists any notice out of its
 * container and drops it after the page header. A first version of this class
 * wrapped `settings_errors()` and was silently defeated by that: the notice
 * rendered inside the wrapper server-side and core moved it out before anyone
 * saw it. Emitting the markup here, with `inline`, is what opts out of the
 * hoist.
 *
 * **It is actually announced.** The wrapper carries `aria-live="polite"`, which
 * only works if the notice is inside it when the announcement matters — see
 * above. Core's own notice output carries no live region at all.
 *
 * The classes are core's (`notice`, `notice-{type}`, `is-dismissible`) on
 * purpose: an admin notice should look and behave like every other WordPress
 * admin notice, and `is-dismissible` is wired by core's JS wherever the node
 * sits. Only the emission is ours.
 *
 * @since 1.4.0
 */
class Notices {

	/**
	 * Render the queued notices for one settings-error group.
	 *
	 * @since 1.4.0
	 *
	 * @param string $group The `add_settings_error()` slug to render (e.g.
	 *                       `albert_settings`, `albert_connections`).
	 *
	 * @return void
	 */
	public static function render( string $group ): void {
		// Reads the transient the redirect left behind when `settings-updated`
		// is present, exactly as settings_errors() would.
		$errors = get_settings_errors( $group );

		echo '<div class="albert-notices" aria-live="polite">';

		foreach ( $errors as $error ) {
			if ( ! is_array( $error ) || empty( $error['message'] ) ) {
				continue;
			}

			// Core maps its own 'updated' alias onto the success style; do the
			// same so a caller can use either spelling.
			$type = isset( $error['type'] ) && is_string( $error['type'] ) ? $error['type'] : 'error';
			if ( $type === 'updated' ) {
				$type = 'success';
			}

			printf(
				'<div id="setting-error-%1$s" class="notice notice-%2$s settings-error inline is-dismissible"><p><strong>%3$s</strong></p></div>',
				esc_attr( isset( $error['code'] ) && is_string( $error['code'] ) ? $error['code'] : '' ),
				esc_attr( $type ),
				esc_html( (string) $error['message'] )
			);
		}

		echo '</div>';
	}
}
