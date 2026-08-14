<?php
/**
 * Stubs for WordPress 7.1 functions not yet shipped in php-stubs/wordpress-stubs.
 *
 * Albert targets the 7.1 Abilities API as its primary path (guarded at runtime by
 * {@see \Albert\Support\WpCompat}) while the analysis toolchain still resolves
 * against an older WordPress. These signatures let PHPStan type-check the 7.1
 * code paths; remove entries here once the installed stubs catch up.
 *
 * @package Albert
 */

/**
 * Prepares a JSON Schema for client consumption.
 *
 * Removes server-only keys (validation/sanitisation callbacks, argument options,
 * readonly markers) that must not be exposed to an API client. Introduced in
 * WordPress 7.1.
 *
 * @param array<string, mixed> $schema The canonical JSON Schema.
 *
 * @return array<string, mixed> The schema with server-only keys removed.
 */
function wp_prepare_json_schema_for_client( array $schema ): array {}
