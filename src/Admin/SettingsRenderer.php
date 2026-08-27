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

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

use Albert\Admin\Settings\Value;
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
		$option_name = $this->resolve_option_name( $field );

		// Whether something in code — a wp-config.php constant, a filter — owns
		// this value rather than the stored option. Asked once and passed down,
		// so the control, the value it shows and the note under it cannot
		// disagree about what is in force.
		$override = Value::override( $option_name );

		// A field may compute what it shows from something other than the stored
		// option. Applied before anything reads $current_value, so every branch
		// below sees the displayed value, not the stored one. An explicit
		// `display_value` wins over the automatic path: it is the escape hatch
		// for a value the generic resolver cannot express.
		if ( isset( $field['display_value'] ) && is_callable( $field['display_value'] ) ) {
			$current_value = call_user_func( $field['display_value'], $current_value );
		} elseif ( $override !== null ) {
			$current_value = $override['value'];
		}

		$type        = isset( $field['type'] ) && is_string( $field['type'] ) ? $field['type'] : 'text';
		$label       = isset( $field['label'] ) && is_string( $field['label'] ) ? $field['label'] : '';
		$description = isset( $field['description'] ) && is_string( $field['description'] ) ? $field['description'] : '';
		$badge       = isset( $field['badge'] ) && is_string( $field['badge'] ) ? $field['badge'] : '';
		$input_id    = 'albert-field-' . str_replace( '/', '_', $option_name );

		if ( $type === 'custom' || $type === 'radio-cards' ) {
			// Custom and radio-cards fields keep a single-column layout: a custom
			// render callback (licenses table, copy-to-clipboard widgets) or a stack
			// of full-width cards neither fit the two-column grid below.
			echo '<div class="albert-field-group albert-field-group--custom">';
			if ( $label !== '' ) {
				echo '<div class="albert-field-label-row">';
				echo '<label class="albert-field-label" for="' . esc_attr( $input_id ) . '">' . esc_html( $label );
				if ( $badge !== '' ) {
					echo ' <span class="albert-badge albert-badge--warning">' . esc_html( $badge ) . '</span>';
				}
				echo '</label>';
				$this->render_info( $field, $label, $input_id );
				echo '</div>';
			}
			if ( $description !== '' ) {
				echo '<p class="albert-field-description">' . esc_html( $description ) . '</p>';
			}
			if ( $type === 'radio-cards' ) {
				$this->render_radio_cards( $field, $current_value, $option_name, $this->is_disabled( $field, $override ) );
			} else {
				$this->render_custom( $field, $current_value );
			}
			$this->render_hint( $field, $override );
			echo '</div>';
			return;
		}

		// Two-column compact row layout for built-in input types.
		echo '<div class="albert-field-group">';
		echo '<div class="albert-field-label-wrap">';
		// The label and its info control share a row; the description sits under
		// both. Without the row the wrap's flex column puts the "(i)" on its own
		// line, orphaned between the label and the text it explains.
		echo '<div class="albert-field-label-row">';
		echo '<label class="albert-field-label" for="' . esc_attr( $input_id ) . '">' . esc_html( $label );
		if ( $badge !== '' ) {
			echo ' <span class="albert-badge albert-badge--warning">' . esc_html( $badge ) . '</span>';
		}
		echo '</label>';
		$this->render_info( $field, $label, $input_id );
		echo '</div>';
		if ( $description !== '' ) {
			echo '<p class="albert-field-description">' . esc_html( $description ) . '</p>';
		}
		echo '</div>';

		$suffix    = isset( $field['suffix'] ) && is_string( $field['suffix'] ) ? $field['suffix'] : '';
		$suffix_id = $input_id . '-suffix';

		// A field whose value is currently owned by something else — a filter, a
		// constant, a network policy — renders disabled and says why. Resolved
		// here rather than by the caller so a field only has to declare the
		// condition, not hand-roll an <input> to express it.
		// `min` and `max` are field-level declarations that feed both the control
		// and the sanitiser, so the browser and the stored value cannot disagree
		// about the range. Copied into attributes here rather than duplicated in
		// every field definition. Anything already in `attributes` wins, so a
		// field that predates this keeps whatever it set.
		foreach ( [ 'min', 'max' ] as $bound ) {
			if ( isset( $field[ $bound ] ) && is_numeric( $field[ $bound ] ) && ! isset( $field['attributes'][ $bound ] ) ) {
				$field['attributes'][ $bound ] = $field[ $bound ];
			}
		}

		if ( $this->is_disabled( $field, $override ) ) {
			$field['attributes']['disabled'] = true;
		}

		// A unit is part of what the value means, so it has to reach assistive
		// tech, not just the eye — "90" alone is not the same field as "90
		// days". Associating it by id rather than hiding it (or leaving it as
		// loose adjacent text, which is not reliably announced with the
		// control) is what makes it part of the field's description. Appended,
		// never assigned, so a field that already points at its own help text
		// keeps it.
		if ( $suffix !== '' ) {
			$existing                                = isset( $field['attributes']['aria-describedby'] ) && is_string( $field['attributes']['aria-describedby'] )
				? trim( $field['attributes']['aria-describedby'] ) . ' '
				: '';
			$field['attributes']['aria-describedby'] = $existing . $suffix_id;
		}

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

		// The unit that qualifies the value, on the same line as it: "90 days".
		// Not a <label> — the field already has one, and a second one would be
		// announced as a competing name for the same control.
		if ( $suffix !== '' ) {
			echo '<span class="albert-field-suffix" id="' . esc_attr( $suffix_id ) . '">' . esc_html( $suffix ) . '</span>';
		}

		$this->render_hint( $field, $override );

		echo '</div>';

		echo '</div>';
	}

	/**
	 * Render the field's info control, if it has one.
	 *
	 * The description under a label is one line. Anything a reader needs only
	 * occasionally — an edge case, a consequence, what 0 does — goes here
	 * instead of lengthening that line. The rule the design system already
	 * states holds: **the sentence must work without opening the tip.** Never
	 * put required information behind it.
	 *
	 * The control itself is {@see InfoTip}, shared with every other screen, so
	 * a settings field and a card heading get the same one.
	 *
	 * @since 1.4.0
	 *
	 * @param array<string, mixed> $field    Normalised field definition.
	 * @param string               $label    The field's visible label, for the accessible name.
	 * @param string               $input_id The control's id, used to derive the popover's.
	 *
	 * @return void
	 */
	private function render_info( array $field, string $label, string $input_id ): void {
		$info = isset( $field['info'] ) && is_string( $field['info'] ) ? $field['info'] : '';

		InfoTip::render( $info, $label, $input_id . '-info' );
	}

	/**
	 * Whether this field's control should render disabled.
	 *
	 * An active override disables the control on its own, with no declaration
	 * needed: a box somebody can type into and save, whose value a constant or
	 * filter then discards, is a lie about what the site does. Fields used to
	 * declare this themselves — the upload size limit carried a `disabled`
	 * callback purely to say "a filter is overriding this" — which meant every
	 * new overridable setting had to remember to.
	 *
	 * `disabled` may still be a bool or a callable returning one, for a
	 * condition the resolver cannot see (a network policy, a licence state).
	 *
	 * @since 1.4.0
	 *
	 * @param array<string, mixed>                                   $field    Normalised field definition.
	 * @param array{source: string, value: mixed, name: string}|null $override Active override, if any.
	 *
	 * @return bool
	 */
	private function is_disabled( array $field, ?array $override = null ): bool {
		if ( $override !== null ) {
			return true;
		}

		$disabled = $field['disabled'] ?? false;

		if ( is_callable( $disabled ) ) {
			return (bool) call_user_func( $disabled );
		}

		return (bool) $disabled;
	}

	/**
	 * Render a field's hint, if it has one right now.
	 *
	 * The hint sits under the control, inside the control column, because it
	 * explains the control rather than the setting — "a filter is overriding
	 * this" is about the box being greyed out, not about what the setting means.
	 * What the setting means is the description, beside the label.
	 *
	 * `hint` is a callable or an array `[ 'text' => string, 'tone' => string ]`.
	 * A callable may return null when there is nothing to say, which is the
	 * common case: most fields have a hint only in an unusual state.
	 *
	 * The text may contain `<code>`, and only `<code>`: these strings name
	 * filters and constants, and every one is built by the field's own author
	 * through sprintf(), never from user input.
	 *
	 * @since 1.4.0
	 *
	 * A field that says nothing while an override is active gets a generated
	 * hint naming the source, so a greyed-out control always explains itself.
	 * A field's own hint wins, because it can say more: the upload size limit
	 * gives the exact size in force, which the generic text cannot know.
	 *
	 * @param array<string, mixed>                                   $field    Normalised field definition.
	 * @param array{source: string, value: mixed, name: string}|null $override Active override, if any.
	 *
	 * @return void
	 */
	private function render_hint( array $field, ?array $override = null ): void {
		$hint = $field['hint'] ?? null;

		if ( is_callable( $hint ) ) {
			$hint = call_user_func( $hint );
		}

		if ( ( ! is_array( $hint ) || empty( $hint['text'] ) ) && $override !== null ) {
			$hint = $this->override_hint( $override );
		}

		if ( ! is_array( $hint ) || empty( $hint['text'] ) || ! is_string( $hint['text'] ) ) {
			return;
		}

		$tone = isset( $hint['tone'] ) && in_array( $hint['tone'], [ 'info', 'warning' ], true )
			? $hint['tone']
			: 'info';

		$icon = $tone === 'warning' ? 'warning' : 'info';

		echo '<div class="albert-hint albert-hint--' . esc_attr( $tone ) . ' albert-field-hint">';
		echo '<span class="dashicons dashicons-' . esc_attr( $icon ) . '" aria-hidden="true"></span>';
		echo '<p>' . wp_kses( $hint['text'], [ 'code' => [] ] ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses() with an explicit allowlist.
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
		// Read, never derive. The Settings screen stamps every field with its
		// resolved name in `Settings::resolve_option_names()`, and that is the
		// single derivation for both the input's `name` and the key the save
		// loop reads from $_POST.
		//
		// This used to fall back to `$field['id']` when `option_name` was
		// absent, which is where the privacy-mode bug lived: the save loop
		// derived `{section}_{field}` instead, so the two never matched and the
		// setting could not be saved at all. There is deliberately no fallback
		// now — a field arriving here without a name is a caller error, and a
		// control with `name=""` submits nothing, which is a visible failure
		// rather than a value silently reverting on every save.
		return isset( $field['option_name'] ) && is_string( $field['option_name'] )
			? $field['option_name']
			: '';
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
	 * Render a set of radio cards: one option per card, each with its own
	 * title, description, and an optional "Recommended" badge.
	 *
	 * `options` takes the same shape select's does — `value => label` — plus
	 * two optional per-option keys the plain `<select>` has no way to carry:
	 * `description` (shown under the title) and `recommended` (renders the
	 * badge). A bare string label works too, matching `select`'s shape.
	 *
	 * @since 1.4.0
	 *
	 * @param array<string, mixed> $field         Normalised field definition.
	 * @param mixed                $current_value Current value.
	 * @param string               $option_name   wp_options key.
	 * @param bool                 $disabled      Whether code owns this value.
	 *
	 * @return void
	 */
	private function render_radio_cards( array $field, $current_value, string $option_name, bool $disabled = false ): void {
		$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : [];
		$value   = $current_value === null ? '' : (string) $current_value;

		// Disabled rather than hidden: the choices stay readable, so somebody
		// can still see which one is in force and what the alternatives are.
		printf(
			'<div class="albert-radio-cards%s" role="radiogroup">',
			$disabled ? ' albert-radio-cards--disabled' : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static string.
		);

		foreach ( $options as $opt_value => $opt ) {
			$opt_value_str   = (string) $opt_value;
			$title           = is_array( $opt ) ? (string) ( $opt['label'] ?? $opt['title'] ?? '' ) : (string) $opt;
			$opt_description = is_array( $opt ) ? (string) ( $opt['description'] ?? '' ) : '';
			$recommended     = is_array( $opt ) && ! empty( $opt['recommended'] );
			$checked         = $value === $opt_value_str;
			$radio_id        = $option_name . '-' . sanitize_key( $opt_value_str );

			printf(
				'<label for="%1$s" class="albert-radio-card%2$s">',
				esc_attr( $radio_id ),
				$checked ? ' albert-radio-card--checked' : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static string.
			);
			printf(
				'<input type="radio" name="%1$s" id="%2$s" value="%3$s"%4$s%5$s />',
				esc_attr( $option_name ),
				esc_attr( $radio_id ),
				esc_attr( $opt_value_str ),
				checked( $checked, true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns a safe attribute string.
				disabled( $disabled, true, false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- disabled() returns a safe attribute string.
			);
			echo '<span class="albert-radio-card__body">';
			echo '<span class="albert-radio-card__title">' . esc_html( $title );
			if ( $recommended ) {
				echo ' <span class="albert-badge albert-badge--info">' . esc_html__( 'Recommended', 'albert-ai-butler' ) . '</span>';
			}
			echo '</span>';
			if ( $opt_description !== '' ) {
				echo '<span class="albert-radio-card__description">' . esc_html( $opt_description ) . '</span>';
			}
			echo '</span>';
			echo '</label>';
		}

		echo '</div>';
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

	/**
	 * The generated hint for a setting owned by code.
	 *
	 * Names the constant or filter, because "this is set elsewhere" without
	 * saying where leaves somebody grepping. Both names are developer-authored
	 * identifiers, never user input, and reach the page through the same
	 * `<code>`-only allowlist as any other hint.
	 *
	 * @since 1.4.0
	 *
	 * @param array{source: string, value: mixed, name: string} $override Active override.
	 *
	 * @return array{text: string, tone: string}
	 */
	private function override_hint( array $override ): array {
		$name = isset( $override['name'] ) && is_string( $override['name'] ) ? $override['name'] : '';

		if ( ( $override['source'] ?? '' ) === 'constant' ) {
			$text = sprintf(
				/* translators: 1: opening <code>, 2: closing </code> wrapping a PHP constant name */
				__( 'Set by the %1$s%3$s%2$s constant in this site\'s configuration, so it can\'t be changed here.', 'albert-ai-butler' ),
				'<code>',
				'</code>',
				$name
			);
		} else {
			$text = sprintf(
				/* translators: 1: opening <code>, 2: closing </code> wrapping a filter name */
				__( 'Set in code by the %1$s%3$s%2$s filter, so it can\'t be changed here.', 'albert-ai-butler' ),
				'<code>',
				'</code>',
				$name
			);
		}

		return [
			'text' => $text,
			'tone' => 'info',
		];
	}
}
