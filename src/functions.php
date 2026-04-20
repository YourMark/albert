<?php
/**
 * Global Helper Functions
 *
 * @package Albert
 * @since   1.1.0
 */

// Guard against loading outside WordPress (e.g. Composer dump-autoload).
use Albert\Abstracts\AbstractAddon;

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

if ( ! function_exists( 'albert_register_settings_section' ) ) {
	/**
	 * Register a settings section with the unified Albert settings page.
	 *
	 * Call this from the `albert/settings/register` action. See
	 * `docs/settings-api.md` for the full schema.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $section Section configuration.
	 *
	 * @return void
	 */
	function albert_register_settings_section( array $section ): void {
		\Albert\Admin\SettingsRegistry::instance()->register_section( $section );
	}
}

if ( ! function_exists( 'albert_register_setting' ) ) {
	/**
	 * Register a single setting on the shared Albert &rarr; Settings page.
	 *
	 * Simplified add-on API. The first call lazily creates a synthetic
	 * `albert/settings` section that collects all settings registered by
	 * add-ons, then appends the field to it. See `docs/settings-api.md` for
	 * the full schema.
	 *
	 * Required keys:
	 *  - `title`       (string)  — visible label above the input.
	 *  - `option_name` (string)  — `wp_options` key (also doubles as the
	 *                              internal field id).
	 *  - `type`        (string)  — one of text|url|number|textarea|select|checkbox.
	 *
	 * Optional keys: `description`, `default`, `options` (required for select),
	 * `attributes` (min/max/step/placeholder), `badge`.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $setting Field definition (simplified schema).
	 *
	 * @return void
	 */
	function albert_register_setting( array $setting ): void {
		$allowed_types = [ 'text', 'url', 'number', 'textarea', 'select', 'checkbox' ];

		$title       = isset( $setting['title'] ) && is_string( $setting['title'] ) ? $setting['title'] : '';
		$option_name = isset( $setting['option_name'] ) && is_string( $setting['option_name'] ) ? $setting['option_name'] : '';
		$type        = isset( $setting['type'] ) && is_string( $setting['type'] ) ? $setting['type'] : '';

		if ( $title === '' || $option_name === '' || $type === '' ) {
			_doing_it_wrong(
				'albert_register_setting',
				esc_html__( 'albert_register_setting() requires non-empty "title", "option_name", and "type" keys.', 'albert-ai-butler' ),
				'1.1.0'
			);
			return;
		}

		if ( ! in_array( $type, $allowed_types, true ) ) {
			_doing_it_wrong(
				'albert_register_setting',
				sprintf(
					/* translators: 1: option name, 2: comma-separated list of allowed types */
					esc_html__( 'albert_register_setting( "%1$s" ): "type" must be one of %2$s.', 'albert-ai-butler' ),
					esc_html( $option_name ),
					esc_html( implode( ', ', $allowed_types ) )
				),
				'1.1.0'
			);
			return;
		}

		if ( $type === 'select' ) {
			$options = $setting['options'] ?? null;
			if ( ! is_array( $options ) || empty( $options ) ) {
				_doing_it_wrong(
					'albert_register_setting',
					sprintf(
						/* translators: %s: option name */
						esc_html__( 'albert_register_setting( "%s" ): select settings require a non-empty "options" array.', 'albert-ai-butler' ),
						esc_html( $option_name )
					),
					'1.1.0'
				);
				return;
			}
		}

		// Translate the simplified schema to the internal field schema.
		$internal = [
			'id'          => $option_name,
			'option_name' => $option_name,
			'type'        => $type,
			'label'       => $title,
		];

		if ( isset( $setting['description'] ) && is_string( $setting['description'] ) ) {
			$internal['description'] = $setting['description'];
		}
		if ( array_key_exists( 'default', $setting ) ) {
			$internal['default'] = $setting['default'];
		}
		if ( isset( $setting['options'] ) && is_array( $setting['options'] ) ) {
			$internal['options'] = $setting['options'];
		}
		if ( isset( $setting['attributes'] ) && is_array( $setting['attributes'] ) ) {
			$internal['attributes'] = $setting['attributes'];
		}
		if ( isset( $setting['badge'] ) && is_string( $setting['badge'] ) ) {
			$internal['badge'] = $setting['badge'];
		}

		$registry = \Albert\Admin\SettingsRegistry::instance();
		$registry->ensure_section_exists(
			[
				'id'       => 'albert/settings',
				'title'    => __( 'Settings', 'albert-ai-butler' ),
				'priority' => 50,
				'icon'     => 'admin-generic',
			]
		);
		$registry->append_field_to_section( 'albert/settings', $internal );
	}
}

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
		$addons      = AbstractAddon::get_registered_addons();
		if ( isset( $addons[ $slug ]['option_slug'] ) ) {
			$option_slug = $addons[ $slug ]['option_slug'];
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
