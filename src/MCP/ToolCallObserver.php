<?php
/**
 * MCP Tool Call Observer
 *
 * @package Albert
 * @subpackage MCP
 * @since      1.2.0
 */

namespace Albert\MCP;

defined( 'ABSPATH' ) || exit;

use Albert\Logging\ExecutionLogMarker;
use WP_Error;

/**
 * ToolCallObserver class
 *
 * Hooks the mcp-adapter's `mcp_adapter_tool_call_result` filter to do two
 * things for failed ability tool calls:
 *
 *  1. Replace opaque/verbose error text with a clear, actionable message the
 *     LLM can act on (e.g. "Missing required parameter: `title`."). The WP
 *     Abilities API rejects bad input *before* the ability runs, producing a
 *     wordy `ability_invalid_input` error; this rewrites it.
 *  2. Record the failure in the activity log. Input rejected by schema
 *     validation never reaches {@see \Albert\Abstracts\BaseAbility::guarded_execute()},
 *     so `albert/abilities/after_execute` never fired and nothing was logged.
 *     For those pre-execute failures we fire `after_execute` ourselves so the
 *     row lands through the normal logging path (input, identity, error). The
 *     {@see ExecutionLogMarker} prevents double-logging an ability that *did*
 *     execute and was already logged.
 *
 * @since 1.2.0
 */
class ToolCallObserver {

	/**
	 * Meta-tool prefix that is not a real ability and must be ignored.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const META_TOOL_PREFIX = 'mcp-adapter/';

	/**
	 * Register the adapter result filter.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function register_hooks(): void {
		add_filter( 'mcp_adapter_tool_call_result', [ $this, 'handle' ], 10, 3 );
	}

	/**
	 * Improve and log a failed tool-call result.
	 *
	 * @param mixed  $result    The tool execution result (may be WP_Error).
	 * @param mixed  $args      The tool arguments used.
	 * @param string $tool_name The tool (ability) name that was called.
	 *
	 * @return mixed The (possibly improved) result.
	 * @since 1.2.0
	 */
	public function handle( $result, $args, string $tool_name ) {
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}

		// The adapter's own meta-tools are not real abilities.
		if ( str_starts_with( $tool_name, self::META_TOOL_PREFIX ) ) {
			return $result;
		}

		$improved = $this->improve_message( $result, $tool_name );

		// If the ability never executed (input rejected by schema validation
		// before guarded_execute ran), after_execute never fired and nothing
		// was logged. Surface it through the normal logging path. Abilities that
		// did execute are already marked, so we never double-log them here.
		if ( ! ExecutionLogMarker::has( $tool_name ) ) {
			do_action(
				'albert/abilities/after_execute',
				$tool_name,
				is_array( $args ) ? $args : [],
				$improved,
				get_current_user_id()
			);
		}

		return $improved;
	}

	/**
	 * Build a clear, non-empty error from a raw tool-call WP_Error.
	 *
	 * @param WP_Error $error     The raw error.
	 * @param string   $tool_name The ability name.
	 *
	 * @return WP_Error A new error carrying the original code/data and a clearer message.
	 * @since 1.2.0
	 */
	private function improve_message( WP_Error $error, string $tool_name ): WP_Error {
		$code    = (string) $error->get_error_code();
		$raw     = trim( (string) $error->get_error_message() );
		$message = '';

		if ( in_array( $code, [ 'ability_invalid_input', 'rest_invalid_param', 'rest_missing_callback_param' ], true ) ) {
			$message = $this->format_validation_message( $raw );
		}

		if ( $message === '' ) {
			// Never hand the LLM a blank/opaque error (the "unknown error" cause).
			$message = $raw !== ''
				? $raw
				: sprintf(
					/* translators: %s: ability identifier */
					__( 'The "%s" operation could not be completed.', 'albert-ai-butler' ),
					$tool_name
				);
		}

		return new WP_Error( $code === '' ? 'albert_tool_error' : $code, $message, $error->get_error_data() );
	}

	/**
	 * Turn the Abilities API validation message into actionable guidance.
	 *
	 * The core format is `Ability "X" has invalid input. Reason: <reason>`,
	 * where <reason> is e.g. `title is a required property of input.`
	 *
	 * @param string $raw The raw validation message.
	 *
	 * @return string A concise, LLM-friendly message (empty if not parseable).
	 * @since 1.2.0
	 */
	private function format_validation_message( string $raw ): string {
		$reason = $raw;
		if ( preg_match( '/Reason:\s*(.+)$/s', $raw, $m ) ) {
			$reason = trim( $m[1] );
		}

		if ( $reason === '' ) {
			return '';
		}

		if ( preg_match_all( '/`?([\w-]+)`?\s+is a required property/', $reason, $mm ) ) {
			$fields = array_map(
				static function ( string $field ): string {
					return '`' . $field . '`';
				},
				$mm[1]
			);

			$template = count( $fields ) === 1
				/* translators: %s: parameter name */
				? __( 'Missing required parameter: %s.', 'albert-ai-butler' )
				/* translators: %s: comma-separated list of parameter names */
				: __( 'Missing required parameters: %s.', 'albert-ai-butler' );

			return sprintf( $template, implode( ', ', $fields ) );
		}

		return sprintf(
			/* translators: %s: validation reason */
			__( 'Invalid parameters: %s', 'albert-ai-butler' ),
			rtrim( $reason, '.' ) . '.'
		);
	}
}
