<?php
/**
 * Third-party plugin detection.
 *
 * @package Albert
 * @subpackage Context
 * @since      1.4.0
 */

namespace Albert\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Answers "is this plugin running" from a symbol it defines.
 *
 * The context readers name a handful of plugins (ACF, Pods, Polylang, WPML)
 * and each needs the same question answered. Asking it in one place is worth a
 * class for two reasons beyond tidiness.
 *
 * A symbol, never a path. `is_plugin_active()` and a folder name both fail on
 * the very common case of a plugin installed under a renamed directory, and
 * `is_plugin_active()` additionally is not loaded outside the admin. A class or
 * function the plugin declares is the thing that actually tells us its code ran.
 *
 * A class *or* a function, without the caller having to know which. Whether ACF
 * announces itself with a class or a function is ACF's business and has changed
 * between releases; a reader that hardcoded the answer would silently stop
 * detecting it. Checking both costs one extra call and removes a whole category
 * of quiet breakage.
 *
 * @since 1.4.0
 */
class Symbols {

	/**
	 * Whether a symbol is defined, as either a class or a function.
	 *
	 * @param string $symbol Fully-qualified class name or function name.
	 *
	 * @return bool True when the symbol exists in this request.
	 * @since 1.4.0
	 */
	public static function defined( string $symbol ): bool {
		return class_exists( $symbol ) || function_exists( $symbol );
	}
}
