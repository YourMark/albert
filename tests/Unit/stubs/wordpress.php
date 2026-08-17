<?php
/**
 * Minimal WordPress function and class stubs for unit testing.
 *
 * Provides stub implementations of WordPress functions used by Albert classes.
 * Each stub records its calls to $GLOBALS['albert_test_hooks'] so tests can
 * assert correct hook names and parameters.
 *
 * @package Albert\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

// Class stubs live in their own files so this file can stay
// functions-only (keeps PHPCS's OO/function separation rule happy).
require_once __DIR__ . '/WP_Error.php';
require_once __DIR__ . '/WP_REST_Request.php';
require_once __DIR__ . '/WP_REST_Response.php';
require_once __DIR__ . '/WP.php';
require_once __DIR__ . '/WP_Abilities_Registry.php';

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Stub is_wp_error that mirrors the WordPress implementation.
	 *
	 * @param mixed $thing Value to check.
	 *
	 * @return bool
	 */
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

/**
 * Global hook-call tracker.
 *
 * Each entry: [ 'type' => 'action'|'filter', 'hook' => string, 'args' => array ]
 *
 * @var array<int, array<string, mixed>>
 */
$GLOBALS['albert_test_hooks'] = [];

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Stub do_action that records calls.
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  ...$args   Hook arguments.
	 */
	function do_action( string $hook_name, ...$args ): void {
		$GLOBALS['albert_test_hooks'][] = [
			'type' => 'action',
			'hook' => $hook_name,
			'args' => $args,
		];
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Stub add_action that records the registration.
	 *
	 * Recorded under `type => registration` rather than `action`, so a test
	 * asserting that a hook *fired* is never satisfied by a hook merely being
	 * *registered*. The accepted-argument count is recorded because getting it
	 * wrong is a silent failure: WordPress defaults to 1, which would hand a
	 * three-argument callback only its first argument.
	 *
	 * @param string   $hook_name     Hook name.
	 * @param callable $callback      Callback to register.
	 * @param int      $priority      Hook priority.
	 * @param int      $accepted_args Number of arguments the callback accepts.
	 */
	function add_action( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['albert_test_hooks'][] = [
			'type'          => 'registration',
			'hook'          => $hook_name,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		];
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Stub apply_filters that records calls and returns the value unmodified.
	 *
	 * Tests can simulate a filter callback by setting
	 * $GLOBALS['albert_test_filter_returns'][$hook_name]; when that key is
	 * present the stub returns its value instead of the passed-in $value.
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  $value     Value to filter.
	 * @param mixed  ...$args   Additional arguments.
	 *
	 * @return mixed The (optionally overridden) value.
	 */
	function apply_filters( string $hook_name, mixed $value, ...$args ): mixed {
		$GLOBALS['albert_test_hooks'][] = [
			'type' => 'filter',
			'hook' => $hook_name,
			'args' => array_merge( [ $value ], $args ),
		];

		if ( isset( $GLOBALS['albert_test_filter_returns'][ $hook_name ] ) ) {
			return $GLOBALS['albert_test_filter_returns'][ $hook_name ];
		}

		return $value;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Stub get_option that reads from $GLOBALS['albert_test_options'].
	 *
	 * @param string $option   Option name.
	 * @param mixed  $fallback Value returned when the option is not set.
	 *
	 * @return mixed
	 */
	function get_option( string $option, mixed $fallback = false ): mixed {
		return $GLOBALS['albert_test_options'][ $option ] ?? $fallback;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Stub get_current_user_id that reads from $GLOBALS['albert_test_user_id'].
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return $GLOBALS['albert_test_user_id'] ?? 1;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Stub current_user_can that reads from $GLOBALS['albert_test_caps'].
	 *
	 * Defaults to `true` when no cap map is configured so legacy tests that
	 * do not set the global keep passing. When `$GLOBALS['albert_test_caps']`
	 * is set (array of allowed capability names), only those return true.
	 *
	 * @param string $capability Capability name.
	 *
	 * @return bool
	 */
	function current_user_can( string $capability ): bool {
		if ( ! isset( $GLOBALS['albert_test_caps'] ) ) {
			return true;
		}

		return in_array( $capability, (array) $GLOBALS['albert_test_caps'], true );
	}
}

if ( ! function_exists( 'wp_get_abilities' ) ) {
	/**
	 * Stub wp_get_abilities that reads from $GLOBALS['albert_test_abilities'].
	 *
	 * Returns an array of ability-like objects that expose get_name() and
	 * get_meta(). Tests populate the global with test doubles.
	 *
	 * @return array<int, object>
	 */
	function wp_get_abilities(): array {
		return (array) ( $GLOBALS['albert_test_abilities'] ?? [] );
	}
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
	/**
	 * Stub rest_ensure_response: wrap non-response values in a WP_REST_Response.
	 *
	 * @param mixed $response Response data or object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	function rest_ensure_response( $response ) {
		if ( $response instanceof WP_REST_Response || $response instanceof WP_Error ) {
			return $response;
		}

		return new WP_REST_Response( $response );
	}
}

if ( ! function_exists( 'wp_get_ability' ) ) {
	/**
	 * Stub wp_get_ability that looks a test double up by name in
	 * $GLOBALS['albert_test_abilities'].
	 *
	 * @param string $ability_id Ability ID.
	 *
	 * @return object|null The matching double, or null when not registered.
	 */
	function wp_get_ability( string $ability_id ): ?object {
		foreach ( (array) ( $GLOBALS['albert_test_abilities'] ?? [] ) as $ability ) {
			if ( is_object( $ability ) && method_exists( $ability, 'get_name' ) && $ability->get_name() === $ability_id ) {
				return $ability;
			}
		}

		return null;
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	/**
	 * Stub wp_register_ability that records calls to $GLOBALS['albert_test_registered_abilities'].
	 *
	 * @param string               $name Ability name.
	 * @param array<string, mixed> $args Ability arguments.
	 */
	function wp_register_ability( string $name, array $args ): void {
		if ( ! isset( $GLOBALS['albert_test_registered_abilities'] ) ) {
			$GLOBALS['albert_test_registered_abilities'] = [];
		}

		$GLOBALS['albert_test_registered_abilities'][ $name ] = $args;
	}
}

if ( ! function_exists( '_deprecated_function' ) ) {
	/**
	 * Stub _deprecated_function that records calls to $GLOBALS['albert_test_deprecated_calls'].
	 *
	 * @param string $function_name Function/method name being deprecated.
	 * @param string $version       Version the function was deprecated in.
	 * @param string $replacement   Replacement function/method.
	 */
	function _deprecated_function( string $function_name, string $version, string $replacement = '' ): void {
		if ( ! isset( $GLOBALS['albert_test_deprecated_calls'] ) ) {
			$GLOBALS['albert_test_deprecated_calls'] = [];
		}

		$GLOBALS['albert_test_deprecated_calls'][] = [
			'function_name' => $function_name,
			'version'       => $version,
			'replacement'   => $replacement,
		];
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Stub wp_unslash that mirrors WordPress's stripslashes_deep behaviour.
	 *
	 * @param mixed $value Value to unslash.
	 *
	 * @return mixed
	 */
	function wp_unslash( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}

		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Stub wp_parse_url that delegates to PHP's parse_url.
	 *
	 * @param string $url       The URL to parse.
	 * @param int    $component The component to retrieve, or -1 for the full array.
	 *
	 * @return mixed
	 */
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Stub translation function that returns the input string.
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain.
	 *
	 * @return string
	 */
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'wp_get_environment_type' ) ) {
	/**
	 * Stub wp_get_environment_type reading $GLOBALS['albert_test_environment_type'].
	 *
	 * Defaults to 'production' so environment-gated code paths (e.g. SSRF host
	 * checks) run their strict branch unless a test opts into 'local'/'development'.
	 *
	 * @return string
	 */
	function wp_get_environment_type(): string {
		return $GLOBALS['albert_test_environment_type'] ?? 'production';
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Stub update_option that writes to $GLOBALS['albert_test_options'].
	 *
	 * @param string    $option   Option name.
	 * @param mixed     $value    Option value.
	 * @param bool|null $autoload Whether to autoload (ignored by the stub; mirrors WP's signature).
	 *
	 * @return bool
	 */
	function update_option( string $option, mixed $value, ?bool $autoload = null ): bool {
		if ( ! isset( $GLOBALS['albert_test_options'] ) || ! is_array( $GLOBALS['albert_test_options'] ) ) {
			$GLOBALS['albert_test_options'] = [];
		}

		// Count writes per option so tests can assert churn-free no-op paths.
		if ( ! isset( $GLOBALS['albert_test_option_writes'] ) || ! is_array( $GLOBALS['albert_test_option_writes'] ) ) {
			$GLOBALS['albert_test_option_writes'] = [];
		}
		$GLOBALS['albert_test_option_writes'][ $option ] = ( $GLOBALS['albert_test_option_writes'][ $option ] ?? 0 ) + 1;

		$GLOBALS['albert_test_options'][ $option ] = $value;

		return true;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * Stub set_transient that writes to $GLOBALS['albert_test_transients'].
	 *
	 * @param string $transient  Transient name.
	 * @param mixed  $value      Transient value.
	 * @param int    $expiration Expiration in seconds (ignored by the stub).
	 *
	 * @return bool
	 */
	function set_transient( string $transient, mixed $value, int $expiration = 0 ): bool {
		if ( ! isset( $GLOBALS['albert_test_transients'] ) || ! is_array( $GLOBALS['albert_test_transients'] ) ) {
			$GLOBALS['albert_test_transients'] = [];
		}

		$GLOBALS['albert_test_transients'][ $transient ] = $value;

		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * Stub get_transient that reads from $GLOBALS['albert_test_transients'].
	 *
	 * @param string $transient Transient name.
	 *
	 * @return mixed Transient value, or false when not set.
	 */
	function get_transient( string $transient ): mixed {
		return $GLOBALS['albert_test_transients'][ $transient ] ?? false;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * Stub delete_transient that removes from $GLOBALS['albert_test_transients'].
	 *
	 * @param string $transient Transient name.
	 *
	 * @return bool
	 */
	function delete_transient( string $transient ): bool {
		unset( $GLOBALS['albert_test_transients'][ $transient ] );

		return true;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Stub esc_html.
	 *
	 * @param string $text Text to escape.
	 *
	 * @return string
	 */
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	/**
	 * Stub esc_html_e that echoes the escaped text.
	 *
	 * @param string $text   Text to escape and echo.
	 * @param string $domain Text domain.
	 *
	 * @return void
	 */
	function esc_html_e( string $text, string $domain = 'default' ): void {
		echo esc_html( $text );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Stub esc_url.
	 *
	 * @param string $url URL to escape.
	 *
	 * @return string
	 */
	function esc_url( string $url ): string {
		return (string) filter_var( $url, FILTER_SANITIZE_URL );
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	/**
	 * Stub number_format_i18n.
	 *
	 * @param int|float $number   Number to format.
	 * @param int       $decimals Decimal places.
	 *
	 * @return string
	 */
	function number_format_i18n( int|float $number, int $decimals = 0 ): string {
		return number_format( (float) $number, $decimals );
	}
}

if ( ! function_exists( 'menu_page_url' ) ) {
	/**
	 * Stub menu_page_url that returns a predictable admin URL.
	 *
	 * @param string $slug    Menu slug.
	 * @param bool   $display Whether to echo (ignored by the stub).
	 *
	 * @return string
	 */
	function menu_page_url( string $slug, bool $display = true ): string {
		return 'http://example.test/wp-admin/admin.php?page=' . $slug;
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	/**
	 * Stub is_admin that reads from $GLOBALS['albert_test_is_admin'].
	 *
	 * @return bool
	 */
	function is_admin(): bool {
		return (bool) ( $GLOBALS['albert_test_is_admin'] ?? false );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Stub sanitize_key mirroring WordPress's lowercase + charset filtering.
	 *
	 * @param string $key Key to sanitize.
	 *
	 * @return string
	 */
	function sanitize_key( string $key ): string {
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}
}

if ( ! function_exists( 'wp_unregister_ability' ) ) {
	/**
	 * Stub wp_unregister_ability that records unregistered IDs and drops the
	 * matching double from $GLOBALS['albert_test_abilities'].
	 *
	 * @param string $name Ability name.
	 *
	 * @return void
	 */
	function wp_unregister_ability( string $name ): void {
		if ( ! isset( $GLOBALS['albert_test_unregistered_abilities'] ) || ! is_array( $GLOBALS['albert_test_unregistered_abilities'] ) ) {
			$GLOBALS['albert_test_unregistered_abilities'] = [];
		}

		$GLOBALS['albert_test_unregistered_abilities'][] = $name;

		if ( isset( $GLOBALS['albert_test_abilities'] ) && is_array( $GLOBALS['albert_test_abilities'] ) ) {
			$GLOBALS['albert_test_abilities'] = array_values(
				array_filter(
					$GLOBALS['albert_test_abilities'],
					static function ( $ability ) use ( $name ): bool {
						return ! ( is_object( $ability ) && method_exists( $ability, 'get_name' ) && $ability->get_name() === $name );
					}
				)
			);
		}
	}
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// phpcs:enable
