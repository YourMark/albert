<?php
/**
 * Settings field lock
 *
 * @package Albert
 * @subpackage Settings
 * @since      1.4.0
 */

namespace Albert\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Whether a settings field's value is out of the owner's hands right now.
 *
 * One question, asked from two places that must never disagree: the renderer,
 * deciding whether to draw the control read-only, and the save loop, deciding
 * whether to skip the field. A locked control is not submitted by the browser,
 * so if the save loop reached a different verdict it would sanitise the field
 * from a missing `$_POST` key, which for a bounded number means its minimum,
 * silently overwriting whatever the owner last chose.
 *
 * They had a copy each, differing only in whether they had the override to hand
 * or re-fetched it. That is a divergence waiting to happen rather than one that
 * had happened, and this is a small enough thing to simply not have twice.
 *
 * @since 1.4.0
 */
class Lock {

	/**
	 * Whether this field renders read-only and is skipped on save.
	 *
	 * Two ways that happens: something in code owns the value (a constant, a
	 * filter, see {@see Value}), or the field declares its own `disabled`
	 * condition for a state the resolver cannot see, such as a licence or a
	 * network policy. `disabled` may be a bool or a callable returning one, so a
	 * field declares *when* it applies rather than what happened to be true when
	 * the schema was built.
	 *
	 * @since 1.4.0
	 *
	 * @param array<string, mixed>                                   $field    Normalised field definition.
	 * @param array{source: string, value: mixed, name: string}|null $override Active override, if any.
	 *
	 * @return bool
	 */
	public static function is_locked( array $field, ?array $override = null ): bool {
		if ( $override !== null ) {
			return true;
		}

		$disabled = $field['disabled'] ?? false;

		if ( is_callable( $disabled ) ) {
			return (bool) call_user_func( $disabled );
		}

		return (bool) $disabled;
	}
}
