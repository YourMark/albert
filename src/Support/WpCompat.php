<?php
/**
 * WordPress version-capability detection.
 *
 * @package Albert
 * @subpackage Support
 * @since      1.4.0
 */

namespace Albert\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Central detection of WordPress 7.1 Abilities-API capabilities.
 *
 * WordPress 7.1 reshaped the Abilities API — client-facing schema preparation,
 * a filtering pipeline for `wp_get_abilities()`, execution lifecycle filters and
 * an invocation audit action. Albert targets 7.1 as its primary path while
 * staying fully functional on 6.9/7.0, so every 7.1-only branch is gated behind
 * a single capability check here rather than scattered `function_exists()` /
 * `version_compare()` calls.
 *
 * Detection is by feature (`function_exists`), never by version number: a host
 * that back-ports a function, or filters one away, is then handled for what it
 * actually offers. When 6.9/7.0 support is eventually dropped, each fallback
 * collapses onto the 7.1 path by deleting these guards and the `else` branch at
 * every call site — grep `6.9 fallback — removable` to find them.
 *
 * @since 1.4.0
 */
class WpCompat {

	/**
	 * Whether WordPress can prepare a JSON schema for client consumption.
	 *
	 * WordPress 7.1 adds `wp_prepare_json_schema_for_client()`, which strips the
	 * server-only keys from a schema (validation/sanitisation callbacks, argument
	 * options) before it is handed to an API client. Albert runs its MCP tool
	 * schemas through it at the output boundary; below 7.1 the canonical schema
	 * is emitted unchanged.
	 *
	 * @return bool True on WordPress 7.1+.
	 * @since 1.4.0
	 */
	public static function supports_client_schema_prep(): bool {
		return function_exists( 'wp_prepare_json_schema_for_client' );
	}
}
