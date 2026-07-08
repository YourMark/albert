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
		if ( defined( 'ALBERT_PRIVACY_MODE' ) ) {
			$mode = self::normalize( (string) constant( 'ALBERT_PRIVACY_MODE' ) );
			if ( $mode instanceof self ) {
				return $mode;
			}
		}

		/**
		 * Filters the active privacy mode.
		 *
		 * Return one of `strict`, `balanced`, or `off` to override the stored
		 * option. Return null (the default) to defer to the option/default.
		 *
		 * @since 1.3.0
		 *
		 * @param string|null $mode The overriding mode value, or null to defer.
		 */
		$filtered = apply_filters( 'albert/privacy/mode', null );
		if ( is_string( $filtered ) && $filtered !== '' ) {
			$mode = self::normalize( $filtered );
			if ( $mode instanceof self ) {
				return $mode;
			}
		}

		$option = get_option( 'albert_privacy_mode', '' );
		if ( is_string( $option ) && $option !== '' ) {
			$mode = self::normalize( $option );
			if ( $mode instanceof self ) {
				return $mode;
			}
		}

		return self::Balanced;
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
