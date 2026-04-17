<?php
/**
 * Settings Sanitizer
 *
 * Maps each built-in field type to a default sanitizer and dispatches to the
 * field's `sanitize_callback` when one is provided.
 *
 * @package    Albert
 * @subpackage Admin
 * @since      1.1.0
 */

declare(strict_types=1);

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

use Throwable;

/**
 * SettingsSanitizer class.
 *
 * Stateless helper invoked by the save loop on the unified Settings page.
 *
 * @since 1.1.0
 */
class SettingsSanitizer {

	/**
	 * Sanitize a field value before saving it to wp_options.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $field     Normalised field definition.
	 * @param mixed                $raw_value Raw value pulled from $_POST.
	 *
	 * @return mixed Sanitized value.
	 */
	public function sanitize_field( array $field, $raw_value ) {
		$callback = isset( $field['sanitize_callback'] ) && is_callable( $field['sanitize_callback'] )
			? $field['sanitize_callback']
			: null;

		if ( $callback !== null ) {
			try {
				return call_user_func( $callback, $raw_value );
			} catch ( Throwable $e ) {
				$field_id = isset( $field['id'] ) && is_string( $field['id'] ) ? $field['id'] : '(unknown)';
				error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional error trail for addon authors.
					sprintf(
						'[Albert Settings] sanitize_callback for field "%s" threw: %s',
						$field_id,
						$e->getMessage()
					)
				);
				return array_key_exists( 'default', $field ) ? $field['default'] : null;
			}
		}

		$type = isset( $field['type'] ) && is_string( $field['type'] ) ? $field['type'] : 'text';

		switch ( $type ) {
			case 'url':
				$url = is_scalar( $raw_value ) ? esc_url_raw( (string) $raw_value ) : '';
				return rtrim( $url, '/' );

			case 'number':
				$attributes = isset( $field['attributes'] ) && is_array( $field['attributes'] ) ? $field['attributes'] : [];
				$step       = $attributes['step'] ?? null;
				$is_decimal = is_string( $step ) && strpos( $step, '.' ) !== false;
				if ( $is_decimal ) {
					return is_scalar( $raw_value ) ? (float) $raw_value : 0.0;
				}
				return is_scalar( $raw_value ) ? absint( $raw_value ) : 0;

			case 'textarea':
				return is_scalar( $raw_value ) ? sanitize_textarea_field( (string) $raw_value ) : '';

			case 'select':
				$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : [];
				$value   = is_scalar( $raw_value ) ? (string) $raw_value : '';
				if ( array_key_exists( $value, $options ) ) {
					return $value;
				}
				return array_key_exists( 'default', $field ) ? $field['default'] : '';

			case 'checkbox':
				return $raw_value === '1' || $raw_value === 1 || $raw_value === true;

			case 'custom':
				// Custom fields without a sanitize_callback are skipped at the save layer; if we
				// somehow arrive here, return the default rather than persisting unsanitized data.
				return array_key_exists( 'default', $field ) ? $field['default'] : null;

			case 'text':
			default:
				return is_scalar( $raw_value ) ? sanitize_text_field( (string) $raw_value ) : '';
		}
	}
}
