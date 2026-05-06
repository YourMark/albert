<?php
/**
 * Minimal stub of the WordPress core WP class.
 *
 * Only the `$query_vars` property is reproduced — that's all
 * `parse_request` callbacks in our tests interact with.
 *
 * @package Albert\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

if ( ! class_exists( 'WP' ) ) {
	/**
	 * Minimal stub of the WordPress core WP class.
	 */
	class WP {

		/**
		 * Parsed query vars for the current request.
		 *
		 * @var array<string, mixed>
		 */
		public array $query_vars = [];
	}
}

// phpcs:enable
