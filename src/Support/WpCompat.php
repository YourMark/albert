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

	/**
	 * Whether the Abilities API exposes its execution lifecycle hooks.
	 *
	 * WordPress 7.1 rebuilt `WP_Ability::execute()` around a lifecycle: the
	 * `wp_ability_invoked` action (every call, whatever the outcome), the
	 * `wp_pre_execute_ability` short-circuit filter, `wp_ability_validate_input`
	 * / `wp_ability_validate_output`, and `wp_ability_execute_result`. They ship
	 * together, so one check covers the set.
	 *
	 * Detected through `WP_Filter_Sentinel` — the 7.1-only marker class the
	 * lifecycle's short-circuit filter uses as its "untouched" default. Actions
	 * and filters cannot be feature-detected directly (nothing registers them
	 * until they fire), and this class was introduced by, and only by, the same
	 * change; it is the closest thing to a real feature probe, and it beats
	 * reading `$wp_version`.
	 *
	 * Callers must stay correct when this returns false: below 7.1 the hooks
	 * simply never fire, so a listener registered against them is inert rather
	 * than broken.
	 *
	 * @return bool True on WordPress 7.1+.
	 * @since 1.4.0
	 */
	public static function supports_execution_lifecycle(): bool {
		return class_exists( '\WP_Filter_Sentinel' );
	}
}
