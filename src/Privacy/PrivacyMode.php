<?php
/**
 * Privacy Mode
 *
 * @package Albert
 * @subpackage Privacy
 * @since      1.3.0
 */

namespace Albert\Privacy;

defined( 'ABSPATH' ) || exit;

use Albert\Admin\Settings\Value;

/**
 * PrivacyMode enum
 *
 * The three anonymisation modes and the precedence used to resolve the
 * active one:
 *
 *  1. `ALBERT_PRIVACY_MODE` constant (highest)
 *  2. `albert/privacy/mode` filter
 *  3. `albert_privacy_mode` option
 *  4. default `Balanced` (lowest)
 *
 * At each tier an unrecognised value is ignored and the next tier is
 * consulted; if none yields a valid mode the default applies.
 *
 * @since 1.3.0
 */
enum PrivacyMode: string {

	/**
	 * Personal data is always anonymised — never revealed.
	 */
	case Strict = 'strict';

	/**
	 * Anonymised by default; an authorised request may reveal it.
	 */
	case Balanced = 'balanced';

	/**
	 * No anonymisation (payment/card data is still always removed).
	 */
	case Off = 'off';

	/**
	 * Resolve the active privacy mode from its configuration sources.
	 *
	 * @since 1.3.0
	 *
	 * @return self
	 */
	public static function resolve(): self {
		// A layer whose value is not one of the three modes is skipped rather
		// than accepted: a typo in ALBERT_PRIVACY_MODE falls through to the
		// filter or the stored value, which is what this did before Value
		// existed and is the behaviour worth keeping.
		$validator = static function ( $value ): bool {
			return is_scalar( $value ) && self::normalize( (string) $value ) instanceof self;
		};

		// Constant -> albert/settings/value/albert_privacy_mode -> option.
		// `albert/privacy/mode` still works and is still the documented way to
		// set this in code; Settings\Overrides feeds it into the filter layer,
		// which is also what lets the Settings screen show it as in force.
		$value = Value::get( 'albert_privacy_mode', '', $validator );

		$mode = is_scalar( $value ) ? self::normalize( (string) $value ) : null;

		return $mode ?? self::Balanced;
	}

	/**
	 * Sanitize a raw value to a valid mode string (default on failure).
	 *
	 * Suitable as a settings `sanitize_callback`.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string One of `strict`, `balanced`, or `off`.
	 */
	public static function sanitize( $value ): string {
		$mode = is_string( $value ) ? self::normalize( $value ) : null;

		return ( $mode ?? self::Balanced )->value;
	}

	/**
	 * Normalise a raw string to a case, or null when unrecognised.
	 *
	 * @since 1.3.0
	 *
	 * @param string $value The raw value.
	 *
	 * @return self|null
	 */
	private static function normalize( string $value ): ?self {
		return self::tryFrom( strtolower( trim( $value ) ) );
	}
}
