<?php
/**
 * Settings Value resolution
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.4.0
 */

namespace Albert\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * What a setting's value actually is, and whether the site owner still owns it.
 *
 * Before this, every setting that could be overridden in code hand-rolled its
 * own precedence: {@see \Albert\Privacy\PrivacyMode::resolve()} was constant →
 * filter → option → default, {@see \Albert\Media\UploadLinks\UploadLinkService}
 * was filter → option → ceiling, and connection retention was option → default
 * with no override at all. Three chains, three spellings, and only one of them
 * told the Settings screen it was in force — so a site filtering the privacy
 * mode showed the *stored* value on screen and let somebody save over it,
 * which is a lie about what the site does.
 *
 * The chain, highest priority first:
 *
 * 1. **A PHP constant** named after the option in upper case, so
 *    `albert_privacy_mode` is set by `ALBERT_PRIVACY_MODE`. This is how a
 *    `wp-config.php` pins a value across a fleet.
 * 2. **The filter `albert/settings/value/{option_name}`**, which returns null
 *    to defer — the same idiom as `albert/privacy/mode` and
 *    `albert/media/upload_link_max_bytes`, both of which now feed this chain.
 * 3. **The stored option**, and failing that the caller's default.
 *
 * **Deliberately knows nothing about the settings schema.** Resolution happens
 * on MCP and front-end requests, and reading the schema means firing
 * `albert/settings/register`, which would run every add-on's registration
 * callback on requests that will never render a form. So this is a resolver
 * over an option *name*, nothing more: it does not type-check or sanitise an
 * override, and a caller that needs a closed vocabulary normalises the result
 * itself, exactly as `PrivacyMode` does. {@see Storage} is what makes the
 * *stored* value trustworthy; this is only about which layer wins.
 *
 * @since 1.4.0
 */
class Value {

	/**
	 * The value in force for one setting.
	 *
	 * @since 1.4.0
	 *
	 * @param string        $option_name   The option name.
	 * @param mixed         $default_value Returned when nothing is stored.
	 * @param callable|null $validator     Optional `fn( $value ): bool`. An
	 *                                     override it rejects is skipped and
	 *                                     resolution continues to the next layer.
	 *
	 * @return mixed
	 */
	public static function get( string $option_name, $default_value = null, ?callable $validator = null ) {
		$override = self::override( $option_name, $validator );

		if ( $override !== null ) {
			return $override['value'];
		}

		return get_option( $option_name, $default_value );
	}

	/**
	 * The override in force, if any: what it is and where it came from.
	 *
	 * One method rather than a pair, because the source and the value have to
	 * agree — asking twice invites a filter that answers differently in
	 * between, and the screen would then name one source while displaying
	 * another's value.
	 *
	 * @since 1.4.0
	 *
	 * A `$validator` makes a layer skippable. Without one, any non-null override
	 * wins; with one, an override the caller cannot use is passed over rather
	 * than taking the site down — a typo in a `wp-config.php` constant falls
	 * through to the filter or the stored value instead of resolving to
	 * nonsense. Callers with a closed vocabulary should pass one.
	 *
	 * @since 1.4.0
	 *
	 * @param string        $option_name The option name.
	 * @param callable|null $validator   Optional `fn( $value ): bool`.
	 *
	 * @return array{source: string, value: mixed, name: string}|null Null when the
	 *                                                                stored value wins.
	 */
	public static function override( string $option_name, ?callable $validator = null ): ?array {
		$constant = self::constant_name( $option_name );

		if ( $constant !== '' && defined( $constant ) ) {
			$value = constant( $constant );

			if ( $validator === null || (bool) $validator( $value ) ) {
				return [
					'source' => 'constant',
					'value'  => $value,
					'name'   => $constant,
				];
			}
		}

		$hook = self::filter_name( $option_name );

		/**
		 * Filters the value in force for one Albert setting.
		 *
		 * The hook name carries the option name, so
		 * `albert/settings/value/albert_privacy_mode` overrides the privacy
		 * mode. Return null (the default) to defer to the stored value.
		 *
		 * A setting under an active override renders read-only on the Settings
		 * screen, with a note saying where the value comes from: an owner is
		 * never shown a control that would not actually change anything.
		 *
		 * @since 1.4.0
		 *
		 * @param mixed $value The overriding value, or null to defer.
		 */
		$filtered = apply_filters( $hook, null );

		if ( $filtered !== null && ( $validator === null || (bool) $validator( $filtered ) ) ) {
			/**
			 * Filters the name reported as the source of an override.
			 *
			 * Only useful to something that answers the value filter on another
			 * hook's behalf. `albert/privacy/mode` is bridged onto this chain by
			 * {@see Overrides}, so without this the screen would tell an owner
			 * their value came from `albert/settings/value/albert_privacy_mode`
			 * — a hook nobody wrote, and one they would not find by grepping
			 * their own code. Return null to report the hook that answered.
			 *
			 * @since 1.4.0
			 *
			 * @param string|null $name The hook or constant to name, or null.
			 */
			$reported = apply_filters( 'albert/settings/value_source/' . $option_name, null );

			return [
				'source' => 'filter',
				'value'  => $filtered,
				'name'   => is_string( $reported ) && $reported !== '' ? $reported : $hook,
			];
		}

		return null;
	}

	/**
	 * Whether code, rather than the stored option, decides this setting.
	 *
	 * @since 1.4.0
	 *
	 * @param string        $option_name The option name.
	 * @param callable|null $validator   Optional `fn( $value ): bool`.
	 *
	 * @return bool
	 */
	public static function is_overridden( string $option_name, ?callable $validator = null ): bool {
		return self::override( $option_name, $validator ) !== null;
	}

	/**
	 * Where an override comes from: `constant`, `filter`, or null for neither.
	 *
	 * @since 1.4.0
	 *
	 * @param string        $option_name The option name.
	 * @param callable|null $validator   Optional `fn( $value ): bool`.
	 *
	 * @return string|null
	 */
	public static function override_source( string $option_name, ?callable $validator = null ): ?string {
		$override = self::override( $option_name, $validator );

		return $override === null ? null : $override['source'];
	}

	/**
	 * The filter that overrides one setting.
	 *
	 * @since 1.4.0
	 *
	 * @param string $option_name The option name.
	 *
	 * @return non-empty-string Always: the prefix alone guarantees it.
	 */
	public static function filter_name( string $option_name ): string {
		return 'albert/settings/value/' . $option_name;
	}

	/**
	 * The constant that overrides one setting, or '' when the option name
	 * cannot spell a legal constant.
	 *
	 * Option names are lower case by convention and already namespaced, so
	 * upper-casing is the whole rule: `albert_privacy_mode` →
	 * `ALBERT_PRIVACY_MODE`. Guarded because an option name is not required to
	 * be a legal PHP identifier, and `defined()` on a malformed name is a
	 * question worth not asking.
	 *
	 * @since 1.4.0
	 *
	 * @param string $option_name The option name.
	 *
	 * @return string
	 */
	public static function constant_name( string $option_name ): string {
		if ( preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $option_name ) !== 1 ) {
			return '';
		}

		return strtoupper( $option_name );
	}
}
