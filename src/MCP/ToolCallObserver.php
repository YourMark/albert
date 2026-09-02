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

use Albert\Core\AbilitiesRegistry;
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
	 * Error codes for input rejected by schema validation before the ability ran.
	 *
	 * These are self-correcting: the assistant is handed an actionable message
	 * and retries with the fix. Their message is improved for the LLM, but they
	 * are intentionally NOT logged — a self-correcting rejection is noise in an
	 * owner-facing activity log.
	 *
	 * @since 1.2.0
	 * @var array<int, string>
	 */
	const VALIDATION_REJECTION_CODES = [ 'ability_invalid_input', 'rest_invalid_param', 'rest_missing_callback_param' ];

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
	public function handle( $result, $args, string $tool_name ): mixed {
		$args = is_array( $args ) ? $args : [];

		// The adapter's own meta-tools are not real abilities. They proxy one,
		// and report its failure inside a successful tool result rather than as
		// a WP_Error, so they take their own path.
		if ( AbilitiesRegistry::is_transport_ability( $tool_name ) ) {
			return $this->improve_proxied_result( $result, $args );
		}

		if ( ! is_wp_error( $result ) ) {
			return $result;
		}

		$ability_name = $this->resolve_ability_name( $result->get_error_message(), $tool_name );
		$improved     = $this->improve_message( $result, $ability_name, $args );

		// A missing/invalid-parameter rejection is self-correcting — the assistant
		// gets the improved message and retries — so it is NOT logged; doing so
		// would only add noise. Every other unmarked pre-execute failure still
		// logs through after_execute (abilities that did execute are already
		// marked, so we never double-log them here).
		$is_rejection = in_array( (string) $result->get_error_code(), self::VALIDATION_REJECTION_CODES, true );
		if ( ! $is_rejection && ! ExecutionLogMarker::has( $ability_name ) ) {
			do_action(
				'albert/abilities/after_execute',
				$ability_name,
				$args,
				$improved,
				get_current_user_id()
			);
		}

		return $improved;
	}

	/**
	 * Improve the validation message carried inside a meta-tool result.
	 *
	 * `mcp-adapter/execute-ability` calls the target ability itself and returns
	 * `[ 'success' => false, 'error' => <message> ]` on failure — an ordinary
	 * array, not a WP_Error, so the WP_Error path above never sees it. This is
	 * the path an assistant that discovered an ability through the meta-tools
	 * actually uses, and it was the one place the improved message never
	 * reached.
	 *
	 * Only a message that is recognisably the Abilities API's input-validation
	 * text is rewritten; every other failure is passed through untouched, since
	 * the meta-tool reports permission refusals and ability errors the same way.
	 *
	 * @param mixed                $result The raw meta-tool result.
	 * @param array<string, mixed> $args   The meta-tool arguments.
	 *
	 * @return mixed The result, with a clearer `error` when one applies.
	 * @since 1.4.0
	 */
	private function improve_proxied_result( $result, array $args ) {
		if ( ! is_array( $result ) || ( $result['success'] ?? null ) !== false ) {
			return $result;
		}

		$raw = isset( $result['error'] ) && is_string( $result['error'] ) ? $result['error'] : '';
		if ( $raw === '' || ! $this->is_validation_message( $raw ) ) {
			return $result;
		}

		$ability_name = $this->resolve_ability_name(
			$raw,
			isset( $args['ability_name'] ) && is_string( $args['ability_name'] ) ? $args['ability_name'] : ''
		);

		$supplied = isset( $args['parameters'] ) && is_array( $args['parameters'] ) ? $args['parameters'] : [];
		$improved = $this->format_validation_message( $raw, $ability_name, $supplied );

		if ( $improved !== '' ) {
			$result['error'] = $improved;
		}

		return $result;
	}

	/**
	 * Build a clear, non-empty error from a raw tool-call WP_Error.
	 *
	 * @param WP_Error             $error        The raw error.
	 * @param string               $ability_name The ability the call was for.
	 * @param array<string, mixed> $supplied     The input the caller sent.
	 *
	 * @return WP_Error A new error carrying the original code/data and a clearer message.
	 * @since 1.2.0
	 */
	private function improve_message( WP_Error $error, string $ability_name, array $supplied ): WP_Error {
		$code    = (string) $error->get_error_code();
		$raw     = trim( (string) $error->get_error_message() );
		$message = '';

		if ( in_array( $code, self::VALIDATION_REJECTION_CODES, true ) ) {
			$message = $this->format_validation_message( $raw, $ability_name, $supplied );
		}

		if ( $message === '' ) {
			// Never hand the LLM a blank/opaque error (the "unknown error" cause).
			$message = $raw !== ''
				? $raw
				: sprintf(
					/* translators: %s: ability identifier */
					__( 'The "%s" operation could not be completed.', 'albert-ai-butler' ),
					$ability_name
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
	 * The reason names what is missing and nothing else, which is a dead end for
	 * the commonest mistake of all: a parameter spelled the way a *different*
	 * tool spells it. `{"term_id": 76}` on an ability that takes `id` was told
	 * only that `id` was required — never that `term_id` had been sent and
	 * dropped — so the caller had no way to see its own error and either retried
	 * the same call or paid for a schema fetch. Anything the schema does not
	 * recognise is therefore named too, along with what the ability does accept.
	 *
	 * @param string               $raw          The raw validation message.
	 * @param string               $ability_name The ability the call was for.
	 * @param array<string, mixed> $supplied     The input the caller sent.
	 *
	 * @return string A concise, LLM-friendly message (empty if not parseable).
	 * @since 1.2.0
	 * @since 1.4.0 Names unrecognised input and the accepted parameters.
	 */
	private function format_validation_message( string $raw, string $ability_name = '', array $supplied = [] ): string {
		$reason = $raw;
		if ( preg_match( '/Reason:\s*(.+)$/s', $raw, $m ) ) {
			$reason = trim( $m[1] );
		}

		if ( $reason === '' ) {
			return '';
		}

		$properties = $this->get_input_properties( $ability_name );

		$lines = [ $this->describe_reason( $reason ) ];

		$unrecognised = $properties === []
			? []
			: array_diff( array_keys( $supplied ), array_keys( $properties ) );

		if ( $unrecognised !== [] ) {
			$lines[] = sprintf(
				/* translators: %s: comma-separated list of parameter names */
				__( 'Unrecognised parameters, ignored: %s.', 'albert-ai-butler' ),
				$this->name_list( $unrecognised )
			);
		}

		if ( $properties !== [] ) {
			$lines[] = sprintf(
				/* translators: %s: comma-separated list of accepted parameters */
				__( 'Accepted parameters: %s.', 'albert-ai-butler' ),
				implode( ', ', $properties )
			);
		}

		return implode( ' ', $lines );
	}

	/**
	 * Restate the validator's reason in the caller's own terms.
	 *
	 * @param string $reason The reason tail of the validation message.
	 *
	 * @return string A single sentence.
	 * @since 1.4.0
	 */
	private function describe_reason( string $reason ): string {
		if ( preg_match_all( '/`?([\w-]+)`?\s+is a required property/', $reason, $mm ) ) {
			$template = count( $mm[1] ) === 1
				/* translators: %s: parameter name */
				? __( 'Missing required parameter: %s.', 'albert-ai-butler' )
				/* translators: %s: comma-separated list of parameter names */
				: __( 'Missing required parameters: %s.', 'albert-ai-butler' );

			return sprintf( $template, $this->name_list( $mm[1] ) );
		}

		return sprintf(
			/* translators: %s: validation reason */
			__( 'Invalid parameters: %s', 'albert-ai-butler' ),
			rtrim( $reason, '.' ) . '.'
		);
	}

	/**
	 * Describe an ability's accepted input properties, keyed by name.
	 *
	 * Read from the registered schema rather than rebuilt, so the list can never
	 * describe something the validator does not enforce. An ability with no
	 * input schema, or one Albert cannot resolve, yields an empty list and the
	 * message simply says less.
	 *
	 * @param string $ability_name The ability identifier.
	 *
	 * @return array<string, string> Property name => rendered description.
	 * @since 1.4.0
	 */
	private function get_input_properties( string $ability_name ): array {
		if ( $ability_name === '' || ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( $ability_name ) ) {
			return [];
		}

		$ability = wp_get_ability( $ability_name );
		if ( $ability === null ) {
			return [];
		}

		$schema     = $ability->get_input_schema();
		$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : [];
		$required   = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : [];

		$described = [];
		foreach ( $properties as $name => $definition ) {
			$type = is_array( $definition ) && isset( $definition['type'] ) ? $definition['type'] : '';
			$type = is_array( $type ) ? implode( '|', $type ) : (string) $type;

			$notes = array_filter(
				[
					$type,
					in_array( $name, $required, true ) ? __( 'required', 'albert-ai-butler' ) : '',
				]
			);

			$described[ (string) $name ] = $notes === []
				? '`' . $name . '`'
				: sprintf( '`%s` (%s)', $name, implode( ', ', $notes ) );
		}

		return $described;
	}

	/**
	 * Render parameter names as a backticked, comma-separated list.
	 *
	 * @param array<int|string, mixed> $names The names to render.
	 *
	 * @return string The rendered list.
	 * @since 1.4.0
	 */
	private function name_list( array $names ): string {
		return implode(
			', ',
			array_map(
				static function ( $name ): string {
					return '`' . (string) $name . '`';
				},
				array_values( $names )
			)
		);
	}

	/**
	 * Whether a message is the Abilities API's input-validation text.
	 *
	 * @param string $message The message to inspect.
	 *
	 * @return bool True when the message came from input validation.
	 * @since 1.4.0
	 */
	private function is_validation_message( string $message ): bool {
		return (bool) preg_match( '/has invalid input\.\s*Reason:/', $message );
	}

	/**
	 * Work out which ability a failed tool call was for.
	 *
	 * The tool name on this filter is the MCP-sanitised spelling the client
	 * sent (`albert-view-term`), not the ability id (`albert/view-term`), so it
	 * cannot be used to look a schema up — or to attribute a log row. The
	 * Abilities API names the ability in its own message, which is the one
	 * spelling guaranteed to be the real id; the sanitised name is unpicked only
	 * as a fallback, and only when it resolves to a registered ability.
	 *
	 * @param string $message   The raw error message.
	 * @param string $tool_name The tool name as the client sent it.
	 *
	 * @return string The ability id, or the tool name when nothing better exists.
	 * @since 1.4.0
	 */
	private function resolve_ability_name( string $message, string $tool_name ): string {
		if ( preg_match( '/Ability "([^"]+)"/', $message, $m ) ) {
			return $m[1];
		}

		if ( $tool_name === '' || ! function_exists( 'wp_has_ability' ) ) {
			return $tool_name;
		}

		if ( wp_has_ability( $tool_name ) ) {
			return $tool_name;
		}

		// `albert-view-term` is `albert/view-term` sanitised: the namespace
		// separator is the first hyphen, and only the first.
		$unsanitised = preg_replace( '/-/', '/', $tool_name, 1 );
		if ( is_string( $unsanitised ) && wp_has_ability( $unsanitised ) ) {
			return $unsanitised;
		}

		return $tool_name;
	}
}
