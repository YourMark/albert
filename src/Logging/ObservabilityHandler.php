<?php
/**
 * MCP Observability Handler
 *
 * @package Albert
 * @subpackage Logging
 * @since      1.2.0
 */

namespace Albert\Logging;

defined( 'ABSPATH' ) || exit;

use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;
use WP\MCP\Infrastructure\Observability\McpObservabilityHelperTrait;

/**
 * ObservabilityHandler class
 *
 * Secondary failure recorder for MCP-level errors that never reach
 * `albert/abilities/after_execute` — primarily permission-denied checks,
 * transport errors, and exceptions thrown before a BaseAbility runs.
 *
 * Acts only on `mcp.request` events with status=error where the tags carry
 * a real `ability_name` (i.e. not one of the three generic meta-tools). This
 * avoids double-counting inner-ability failures already captured by Logger via
 * the `albert/abilities/after_execute` hook.
 *
 * The Repository is instantiated inside `record_event()` because the adapter
 * resolves handler classes by name with no constructor args (mirrors
 * `ErrorLogMcpObservabilityHandler`). Repository is stateless — it uses the
 * global `$wpdb` and `Installer::get_table_name()`.
 *
 * @since 1.2.0
 */
class ObservabilityHandler implements McpObservabilityHandlerInterface {

	use McpObservabilityHelperTrait;

	/**
	 * Meta-tool prefix that must be excluded from observability logging.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const META_TOOL_PREFIX = 'mcp-adapter/';

	/**
	 * Record an MCP observability event.
	 *
	 * Only processes `mcp.request` events with status=error that carry a
	 * real ability name (not a meta-tool). All other events are silently
	 * ignored. The method is fully defensive — exceptions are swallowed so
	 * observability never disrupts the MCP request cycle.
	 *
	 * @param string               $event       The event name (e.g. 'mcp.request').
	 * @param array<string, mixed> $tags        Associative tags from the adapter router.
	 * @param float|null           $duration_ms Optional request duration in milliseconds.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function record_event( string $event, array $tags = [], ?float $duration_ms = null ): void {
		try {
			// Only act on MCP request errors.
			if ( $event !== 'mcp.request' ) {
				return;
			}

			if ( ( $tags['status'] ?? '' ) !== 'error' ) {
				return;
			}

			// Require a real ability_name (not present on meta-tool calls).
			$ability_name = $tags['ability_name'] ?? '';
			if ( ! is_string( $ability_name ) || $ability_name === '' ) {
				return;
			}

			// Skip the three generic meta-tools — their failures don't map to a
			// named inner ability and would produce misleading log entries.
			if ( str_starts_with( $ability_name, self::META_TOOL_PREFIX ) ) {
				return;
			}

			/**
			 * Filters whether Free's ability logging is enabled.
			 *
			 * @since 1.1.0
			 *
			 * @param bool $enabled Whether logging is enabled. Default true.
			 */
			if ( ! apply_filters( 'albert/logging/enabled', true ) ) {
				return;
			}

			$error_code = isset( $tags['error_code'] ) ? (string) $tags['error_code'] : null;
			$user_id    = get_current_user_id();

			( new Repository() )->insert( $ability_name, $user_id, 'error', $error_code );
		} catch ( \Throwable $e ) {
			// Never rethrow — observability must not break the MCP request cycle.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging.
				error_log( 'Albert ObservabilityHandler Error: ' . $e->getMessage() );
			}
		}
	}
}
