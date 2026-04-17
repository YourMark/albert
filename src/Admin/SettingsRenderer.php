<?php
/**
 * Settings Renderer
 *
 * Renders individual fields for the unified Albert Settings page.
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
 * SettingsRenderer class.
 *
 * Stateless helper that knows how to render each built-in field type
 * and how to safely invoke a custom field's render callback. The
 * renderer NEVER opens a `<form>` — that is owned by the page.
 *
 * @since 1.1.0
 */
class SettingsRenderer {

	/**
	 * Render a single field.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $field         Normalised field definition.
	 * @param mixed                $current_value Current saved value (or default).
	 *
	 * @return void
	 */
	public function render_field( array $field, $current_value ): void {
		$type        = isset( $field['type'] ) && is_string( $field['type'] ) ? $field['type'] : 'text';
		$label       = isset( $field['label'] ) && is_string( $field['label'] ) ? $field['label'] : '';
		$description = isset( $field['description'] ) && is_string( $field['description'] ) ? $field['description'] : '';
		$badge       = isset( $field['badge'] ) && is_string( $field['badge'] ) ? $field['badge'] : '';
		$option_name = $this->resolve_option_name( $field );
		$input_id    = 'albert-field-' . str_replace( '/', '_', $option_name );

		if ( $type === 'custom' ) {
			// Custom fields keep the legacy single-column layout so existing render
			// callbacks (licenses table, copy-to-clipboard widgets, etc.) don't need
			// to know about the new two-column grid.
			echo '<div class="albert-field-group albert-field-group--custom">';
			if ( $label !== '' ) {
				echo '<label class="albert-field-label" for="' . esc_attr( $input_id ) . '">' . esc_html( $label );
				if ( $badge !== '' ) {
					echo ' <span class="albert-field-badge">' . esc_html( $badge ) . '</span>';
				}
				echo '</label>';
			}
			if ( $description !== '' ) {
				echo '<p class="albert-field-description">' . esc_html( $description ) . '</p>';
			}
			$this->render_custom( $field, $current_value );
			echo '</div>';
			return;
		}

		// Two-column compact row layout for built-in input types.
		echo '<div class="albert-field-group">';
		echo '<div class="albert-field-label-wrap">';
		echo '<label class="albert-field-label" for="' . esc_attr( $input_id ) . '">' . esc_html( $label );
		if ( $badge !== '' ) {
			echo ' <span class="albert-field-badge">' . esc_html( $badge ) . '</span>';
		}
		echo '</label>';
		if ( $description !== '' ) {
			echo '<p class="albert-field-description">' . esc_html( $description ) . '</p>';
		}
		echo '</div>';

		echo '<div class="albert-field-input-wrap">';
		switch ( $type ) {
			case 'textarea':
				$this->render_textarea( $field, $current_value, $option_name, $input_id );
				break;
			case 'select':
				$this->render_select( $field, $current_value, $option_name, $input_id );
				break;
			case 'checkbox':
				$this->render_checkbox( $field, $current_value, $option_name, $input_id );
				break;
			case 'number':
				$this->render_input( $field, $current_value, $option_name, $input_id, 'number' );
				break;
			case 'url':
				$this->render_input( $field, $current_value, $option_name, $input_id, 'url' );
				break;
			case 'text':
			default:
				$this->render_input( $field, $current_value, $option_name, $input_id, 'text' );
				break;
		}
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Resolve the wp_options key for a field, honouring the optional override.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $field Normalised field definition.
	 *
	 * @return string
	 */
	private function resolve_option_name( array $field ): string {
		// Renderer is called with already-normalised fields, but be defensive in case
		// a caller passes a raw schema array directly.
		$override = isset( $field['option_name'] ) && is_string( $field['option_name'] ) && $field['option_name'] !== ''
			? $field['option_name']
			: null;
		if ( $override !== null ) {
			return $override;
		}
		return isset( $field['id'] ) && is_string( $field['id'] ) ? $field['id'] : '';
	}

	/**
	 * Build the HTML attribute string for a field's `attributes` array.
	 *
	 * Skips `name`, `id`, `type`, `value`, and `checked` — those are owned
	 * by the renderer for each input type.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $attributes Attribute => value pairs.
	 *
	 * @return string
	 */
	private function build_attributes( array $attributes ): string {
		$reserved = [ 'name', 'id', 'type', 'value', 'checked' ];
		$out      = '';
		foreach ( $attributes as $attr => $value ) {
			if ( ! is_string( $attr ) || $attr === '' ) {
				continue;
			}
			if ( in_array( $attr, $reserved, true ) ) {
				continue;
			}
			if ( is_bool( $value ) ) {
				if ( $value ) {
					$out .= ' ' . esc_attr( $attr );
				}
				continue;
			}
			if ( is_scalar( $value ) ) {
				$out .= ' ' . esc_attr( $attr ) . '="' . esc_attr( (string) $value ) . '"';
			}
		}
		return $out;
	}

	/**
	 * Render a text/url/number input.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $field         Normalised field definition.
	 * @param mixed                $current_value Current value.
	 * @param string               $option_name   wp_options key.
	 * @param string               $input_id      HTML id attribute.
	 * @param string               $html_type     HTML input type.
	 *
	 * @return void
	 */
	private function render_input( array $field, $current_value, string $option_name, string $input_id, string $html_type ): void {
		$value      = $current_value === null ? '' : (string) $current_value;
		$attributes = isset( $field['attributes'] ) && is_array( $field['attributes'] ) ? $field['attributes'] : [];

		printf(
			'<input type="%1$s" name="%2$s" id="%3$s" value="%4$s" class="albert-text-input"%5$s />',
			esc_attr( $html_type ),
			esc_attr( $option_name ),
			esc_attr( $input_id ),
			esc_attr( $value ),
			$this->build_attributes( $attributes ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped per attribute in build_attributes().
		);
	}

	/**
	 * Render a textarea.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $field         Normalised field definition.
	 * @param mixed                $current_value Current value.
	 * @param string               $option_name   wp_options key.
	 * @param string               $input_id      HTML id attribute.
	 *
	 * @return void
	 */
	private function render_textarea( array $field, $current_value, string $option_name, string $input_id ): void {
		$value      = $current_value === null ? '' : (string) $current_value;
		$attributes = isset( $field['attributes'] ) && is_array( $field['attributes'] ) ? $field['attributes'] : [];

		printf(
			'<textarea name="%1$s" id="%2$s" class="albert-textarea"%3$s>%4$s</textarea>',
			esc_attr( $option_name ),
			esc_attr( $input_id ),
			$this->build_attributes( $attributes ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped per attribute in build_attributes().
			esc_textarea( $value )
		);
	}

	/**
	 * Render a select dropdown.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $field         Normalised field definition.
	 * @param mixed                $current_value Current value.
	 * @param string               $option_name   wp_options key.
	 * @param string               $input_id      HTML id attribute.
	 *
	 * @return void
	 */
	private function render_select( array $field, $current_value, string $option_name, string $input_id ): void {
		$options    = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : [];
		$attributes = isset( $field['attributes'] ) && is_array( $field['attributes'] ) ? $field['attributes'] : [];
		$value      = $current_value === null ? '' : (string) $current_value;

		printf(
			'<select name="%1$s" id="%2$s" class="albert-select"%3$s>',
			esc_attr( $option_name ),
			esc_attr( $input_id ),
			$this->build_attributes( $attributes ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped per attribute in build_attributes().
		);

		foreach ( $options as $opt_value => $opt_label ) {
			$opt_value_str = (string) $opt_value;
			$opt_label_str = is_scalar( $opt_label ) ? (string) $opt_label : '';
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $opt_value_str ),
				selected( $value, $opt_value_str, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns a safe attribute string.
				esc_html( $opt_label_str )
			);
		}

		echo '</select>';
	}

	/**
	 * Render a checkbox with a paired hidden input so unchecked submits as "0".
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $field         Normalised field definition.
	 * @param mixed                $current_value Current value.
	 * @param string               $option_name   wp_options key.
	 * @param string               $input_id      HTML id attribute.
	 *
	 * @return void
	 */
	private function render_checkbox( array $field, $current_value, string $option_name, string $input_id ): void {
		$attributes = isset( $field['attributes'] ) && is_array( $field['attributes'] ) ? $field['attributes'] : [];
		$checked    = (bool) $current_value;

		// The hidden input is intentionally rendered FIRST so PHP only sees "0" when the
		// checkbox is unchecked — when checked, the visible input overrides it with "1".
		printf(
			'<input type="hidden" name="%1$s" value="0" />',
			esc_attr( $option_name )
		);
		printf(
			'<label class="albert-checkbox-wrap"><input type="checkbox" name="%1$s" id="%2$s" value="1"%3$s%4$s />',
			esc_attr( $option_name ),
			esc_attr( $input_id ),
			checked( $checked, true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns a safe attribute string.
			$this->build_attributes( $attributes ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped per attribute in build_attributes().
		);
		echo '</label>';
	}

	/**
	 * Invoke a custom render callback safely.
	 *
	 * Catches any throwable so a buggy addon callback can't take down the
	 * whole settings page.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $field         Normalised field definition.
	 * @param mixed                $current_value Current value.
	 *
	 * @return void
	 */
	private function render_custom( array $field, $current_value ): void {
		$callback = isset( $field['render_callback'] ) && is_callable( $field['render_callback'] ) ? $field['render_callback'] : null;
		if ( $callback === null ) {
			return;
		}

		try {
			call_user_func( $callback, $field, $current_value );
		} catch ( Throwable $e ) {
			$field_id = isset( $field['id'] ) && is_string( $field['id'] ) ? $field['id'] : '(unknown)';
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional error trail for addon authors.
				sprintf(
					'[Albert Settings] render_callback for field "%s" threw: %s',
					$field_id,
					$e->getMessage()
				)
			);
			echo '<div class="notice notice-error inline"><p>'
				. esc_html__( 'A settings field could not be rendered. Check the PHP error log for details.', 'albert-ai-butler' )
				. '</p></div>';
		}
	}
}
