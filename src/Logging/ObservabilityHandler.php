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

use Albert\Vendor\WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;
use Albert\Vendor\WP\MCP\Infrastructure\Observability\McpObservabilityHelperTrait;

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
 * global `$wpdb` and `Tables::ability_log()`.
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
	 * Validation-rejection error codes that are deliberately not logged.
	 *
	 * A missing/invalid-parameter rejection is self-correcting — the assistant
	 * gets an actionable message and retries — so it is excluded here as well as
	 * in {@see \Albert\MCP\ToolCallObserver}. Logging it would only add noise to
	 * an owner-facing activity log.
	 *
	 * @since 1.2.0
	 * @var array<int, string>
	 */
	const VALIDATION_REJECTION_CODES = [ 'ability_invalid_input', 'rest_invalid_param', 'rest_missing_callback_param' ];

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

			// Resolve the ability identifier. Tool-not-found failures (e.g. a
			// call to a disabled ability) carry only `tool_name` — no
			// `ability_name` — so fall back to it; a disabled ability's id is
			// still a real, attributable identifier worth logging.
			$ability_name = $tags['ability_name'] ?? '';
			if ( ! is_string( $ability_name ) || $ability_name === '' ) {
				$ability_name = isset( $tags['tool_name'] ) && is_string( $tags['tool_name'] ) ? $tags['tool_name'] : '';
			}
			if ( $ability_name === '' ) {
				return;
			}

			// Skip the three generic meta-tools — their failures don't map to a
			// named inner ability and would produce misleading log entries.
			if ( str_starts_with( $ability_name, self::META_TOOL_PREFIX ) ) {
				return;
			}

			// Skip a failure already recorded this request via the
			// `albert/abilities/after_execute` path (executed abilities and
			// pre-execute validation failures), so a call never double-logs.
			if ( ExecutionLogMarker::has( $ability_name ) ) {
				return;
			}

			/**
			 * Filters whether Free's ability log writers are active.
			 *
			 * Returning false suppresses Free's DB writes. Premium returns
			 * false here and owns writes to the shared table itself via its
			 * own extended ObservabilityHandler subclass.
			 *
			 * @since 1.1.0
			 *
			 * @param bool $enabled Whether Free's writers are active. Default true.
			 */
			if ( ! apply_filters( 'albert/logging/enabled', true ) ) {
				return;
			}

			$error_code = isset( $tags['error_code'] ) ? (string) $tags['error_code'] : null;

			// A self-correcting input rejection is not logged (noise); the
			// assistant still gets the improved message via ToolCallObserver.
			if ( $error_code !== null && in_array( $error_code, self::VALIDATION_REJECTION_CODES, true ) ) {
				return;
			}

			$error_message = self::extract_message( $tags );
			$user_id       = get_current_user_id();
			$context       = $error_message !== null ? [ 'error_message' => $error_message ] : [];

			( new Repository() )->insert( $ability_name, $user_id, 'error', $error_code, $context );
			ExecutionLogMarker::mark( $ability_name );
		} catch ( \Throwable $e ) {
			// Never rethrow — observability must not break the MCP request cycle.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging.
				error_log( 'Albert ObservabilityHandler Error: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Pull a human-readable error message out of the adapter's event tags.
	 *
	 * The router carries the message under `failure_reason` for both
	 * JSON-RPC and tool-result errors; `error_message` is accepted as a
	 * fallback for completeness.
	 *
	 * @param array<string, mixed> $tags Event tags from the adapter router.
	 *
	 * @return string|null The message, or null when none is present.
	 * @since 1.2.0
	 */
	protected static function extract_message( array $tags ): ?string {
		foreach ( [ 'failure_reason', 'error_message' ] as $key ) {
			if ( isset( $tags[ $key ] ) && is_string( $tags[ $key ] ) && $tags[ $key ] !== '' ) {
				return (string) $tags[ $key ];
			}
		}

		return null;
	}
}
