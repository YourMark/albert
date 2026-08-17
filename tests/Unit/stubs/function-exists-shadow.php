<?php
/**
 * Namespaced shadow of function_exists() for Albert\Support.
 *
 * {@see \Albert\Support\WpCompat} answers each 7.1 capability question with an
 * unqualified `function_exists()` call. PHP resolves an unqualified function to
 * the caller's namespace before the global one, so this shadow — living in
 * `Albert\Support` — intercepts those calls and lets a test declare which
 * functions "exist" via $GLOBALS['albert_test_fn_exists']. Everything not listed
 * falls through to the real global function_exists(), so unrelated lookups
 * behave normally.
 *
 * Shared by every WpCompat-based test and required once (require_once) so the
 * function is declared a single time across the suite.
 *
 * @package Albert\Tests
 */

namespace Albert\Support;

/**
 * Test-controllable shadow of the global function_exists().
 *
 * @param string $function_name Function name being probed.
 * @return bool
 */
function function_exists( string $function_name ): bool {
	if ( isset( $GLOBALS['albert_test_fn_exists'][ $function_name ] ) ) {
		return (bool) $GLOBALS['albert_test_fn_exists'][ $function_name ];
	}

	return \function_exists( $function_name );
}

/**
 * Test-controllable shadow of the global class_exists().
 *
 * {@see \Albert\Support\WpCompat::supports_execution_lifecycle()} probes for a
 * 7.1-only class, and a unit run has no WordPress, so without this the method
 * could only ever be observed returning false.
 *
 * @param string $class_name Class name being probed.
 * @param bool   $autoload   Whether to autoload. Unused by the shadow.
 * @return bool
 */
function class_exists( string $class_name, bool $autoload = true ): bool {
	if ( isset( $GLOBALS['albert_test_class_exists'][ $class_name ] ) ) {
		return (bool) $GLOBALS['albert_test_class_exists'][ $class_name ];
	}

	return \class_exists( $class_name, $autoload );
}
