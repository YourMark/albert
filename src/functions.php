<?php
/**
 * Global Helper Functions
 *
 * @package Albert
 * @since   1.1.0
 */

// Guard against loading outside WordPress (e.g. Composer dump-autoload).
if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * AJAX handler: return fresh licenses table HTML.
 *
 * Called by albert-licenses.js after the EDD SL SDK finishes
 * activating or deactivating a license.
 *
 * @since 1.1.0
 *
 * @return void
 */
function albert_refresh_licenses_table(): void {
	check_ajax_referer( 'albert_license_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'albert' ) ] );
	}

	ob_start();
	\Albert\Admin\Settings::render_licenses_table();
	wp_send_json_success( [ 'table_html' => ob_get_clean() ] );
}

add_action( 'wp_ajax_albert_refresh_licenses_table', 'albert_refresh_licenses_table' );

if ( ! function_exists( 'albert_has_valid_license' ) ) {
	/**
	 * Check if an addon has a valid license.
	 *
	 * Both Albert Free and the Addon SDK define this function.
	 * Both read the same wp_options — whichever loads first wins.
	 *
	 * The function resolves the option key via the addon registry so
	 * callers can use either the addon's display slug ('extended-service')
	 * or the full option slug ('albert-extended-service').
	 *
	 * @since 1.1.0
	 *
	 * @param string $slug The addon slug (e.g., 'extended-service').
	 *
	 * @return bool True if the addon has a valid license.
	 */
	function albert_has_valid_license( string $slug ): bool {
		// Resolve the option slug from the addon registry.
		$option_slug = $slug;
		if ( class_exists( '\Albert\Abstracts\AbstractAddon' ) ) {
			$addons = \Albert\Abstracts\AbstractAddon::get_registered_addons();
			if ( isset( $addons[ $slug ]['option_slug'] ) ) {
				$option_slug = $addons[ $slug ]['option_slug'];
			}
		}

		$license_data = get_option( "{$option_slug}_license", false );

		if ( ! is_object( $license_data ) ) {
			return false;
		}

		if ( ( $license_data->license ?? '' ) !== 'valid' ) {
			return false;
		}

		if ( isset( $license_data->expires ) && $license_data->expires !== 'lifetime' ) {
			if ( strtotime( $license_data->expires ) < time() ) {
				return false;
			}
		}

		return true;
	}
}
