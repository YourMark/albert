<?php
/**
 * Client-facing JSON Schema preparation at the MCP boundary.
 *
 * @package Albert
 * @subpackage MCP
 * @since      1.4.0
 */

namespace Albert\MCP;

defined( 'ABSPATH' ) || exit;

use Albert\Support\WpCompat;
use Albert\Vendor\WP\MCP\Core\McpServer;
use Albert\Vendor\WP\McpSchema\Server\Tools\DTO\Tool;

/**
 * Runs every schema Albert emits to an MCP client through
 * `wp_prepare_json_schema_for_client()` (WordPress 7.1+).
 *
 * The Abilities API stores canonical, WordPress-style schemas that may carry
 * server-only keys — `sanitize_callback`, `validate_callback`, `arg_options`,
 * `readonly`. Those are meaningful to the server but noise (or worse) to a
 * client, and the adapter does not strip them itself. WordPress 7.1 added
 * `wp_prepare_json_schema_for_client()` to remove them; this class applies it at
 * the two points where a schema crosses to the client, leaving the registered
 * canonical schema (used for execution and validation) untouched:
 *
 *  1. `tools/list` — each tool's `inputSchema` / `outputSchema`, via the
 *     `mcp_adapter_tools_list` filter. The adapter has already shaped these into
 *     MCP object form, so preparation composes on top of that.
 *  2. `get-ability-info` — the `input_schema` / `output_schema` the meta-tool
 *     returns for a single ability, caught on the raw execution result (before
 *     the adapter wraps it into a protocol DTO) via `mcp_adapter_tool_call_result`.
 *
 * Below 7.1 both paths are inert and the canonical schema is emitted unchanged —
 * the honest degraded behaviour, since the preparation function does not exist.
 *
 * @since 1.4.0
 */
class SchemaPreparer {

	/**
	 * Raw name of the adapter's get-ability-info meta-tool, as seen on the
	 * `mcp_adapter_tool_call_result` filter (slash form, not client-sanitised).
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const ABILITY_INFO_TOOL = 'mcp-adapter/get-ability-info';

	/**
	 * Register the two boundary filters.
	 *
	 * `tools/list` preparation runs at priority 20 so it acts on the list after
	 * {@see Server::hide_unauthorized_tools()} (priority 10) has removed tools
	 * the user cannot run — no point preparing a schema that is about to be
	 * dropped.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function register_hooks(): void {
		add_filter( 'mcp_adapter_tools_list', [ $this, 'prepare_tools_list' ], 20, 2 );
		add_filter( 'mcp_adapter_tool_call_result', [ $this, 'prepare_ability_info_result' ], 10, 5 );
	}

	/**
	 * Prepare the `inputSchema` / `outputSchema` of every listed tool.
	 *
	 * `mcp_adapter_tools_list` is a global hook fired by any MCP server built on
	 * the adapter family (including WooCommerce's own bundled copy), so this acts
	 * only on Albert's own server, matching {@see Server::hide_unauthorized_tools()}.
	 *
	 * @param mixed $tools  Array of protocol Tool DTOs (passed through untouched
	 *                      if not an array, e.g. a foreign filter short-circuit).
	 * @param mixed $server The MCP server firing the filter.
	 *
	 * @return mixed The tools list with client-prepared schemas on 7.1+.
	 * @since 1.4.0
	 */
	public function prepare_tools_list( $tools, $server = null ) {
		if ( ! $server instanceof McpServer ) {
			return $tools;
		}

		if ( ! WpCompat::supports_client_schema_prep() || ! is_array( $tools ) ) {
			// 6.9 fallback — removable, see WpCompat: emit canonical schemas.
			return $tools;
		}

		foreach ( $tools as $index => $tool ) {
			if ( $tool instanceof Tool ) {
				$tools[ $index ] = $this->prepare_tool( $tool );
			}
		}

		return $tools;
	}

	/**
	 * Rebuild a single Tool DTO with its schemas prepared for the client.
	 *
	 * The Tool DTO is immutable from outside, so it is rebuilt from its array
	 * form with the prepared schemas swapped in.
	 *
	 * @param Tool $tool The tool to prepare.
	 *
	 * @return Tool A new Tool DTO with client-prepared schemas.
	 * @since 1.4.0
	 */
	private function prepare_tool( Tool $tool ): Tool {
		$data = $tool->toArray();

		foreach ( [ 'inputSchema', 'outputSchema' ] as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				$data[ $key ] = $this->prepare_schema( $data[ $key ] );
			}
		}

		return Tool::fromArray( $data );
	}

	/**
	 * Run one schema through client preparation.
	 *
	 * `wp_prepare_json_schema_for_client()` recurses only into arrays, but the
	 * Tool DTO renders nested sub-schemas as `stdClass`, so the tree is first
	 * normalised to a pure array tree. The normalisation is a manual walk rather
	 * than a `json_encode()`/`json_decode()` round-trip: a round-trip returns
	 * `false` for a value it cannot serialise, which would make this fall back to
	 * the raw, unstripped schema. Failing open is the wrong direction for a
	 * function whose whole job is to remove server-only keys, so the walk is used
	 * instead and cannot fail.
	 *
	 * @param array<string, mixed> $schema The schema to prepare.
	 *
	 * @return array<string, mixed> The client-prepared schema.
	 * @since 1.4.0
	 */
	private function prepare_schema( array $schema ): array {
		$normalised = array_map( [ $this, 'to_array_deep' ], $schema );

		return $this->restore_object_maps( wp_prepare_json_schema_for_client( $normalised ) );
	}

	/**
	 * Recursively convert `stdClass` nodes to arrays, leaving other values as-is.
	 *
	 * @param mixed $value The value to normalise.
	 *
	 * @return mixed The value with any `stdClass` nodes turned into arrays.
	 * @since 1.4.0
	 */
	private function to_array_deep( $value ) {
		if ( $value instanceof \stdClass ) {
			$value = (array) $value;
		}

		return is_array( $value ) ? array_map( [ $this, 'to_array_deep' ], $value ) : $value;
	}

	/**
	 * Re-object empty JSON Schema keyword maps so they serialise as `{}`, not `[]`.
	 *
	 * The array normalisation cannot distinguish an empty object from an empty
	 * list, so a keyword like `properties: {}` becomes an empty PHP array and
	 * would render as the JSON array `[]`, which is invalid where an object is
	 * required. This restores the standard subschema-map keywords to `stdClass`
	 * when they are empty. Non-empty maps already have string keys and serialise
	 * as objects correctly, so only the empty case needs fixing.
	 *
	 * @param array<string, mixed> $schema The prepared schema.
	 *
	 * @return array<string, mixed> The schema with empty keyword maps re-objected.
	 * @since 1.4.0
	 */
	private function restore_object_maps( array $schema ): array {
		$object_map_keywords = [ 'properties', 'patternProperties', 'dependentSchemas', 'definitions', '$defs' ];

		foreach ( $schema as $key => $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}

			$value = $this->restore_object_maps( $value );

			$schema[ $key ] = ( $value === [] && in_array( $key, $object_map_keywords, true ) )
				? new \stdClass()
				: $value;
		}

		return $schema;
	}

	/**
	 * Prepare the schemas embedded in a get-ability-info result.
	 *
	 * Fires on the raw execution result, before the adapter wraps it into a
	 * protocol DTO, so the `input_schema` / `output_schema` keys are plain
	 * arrays returned by the meta-tool. Every other tool, and every non-array
	 * result (e.g. a WP_Error), passes through untouched.
	 *
	 * `mcp_adapter_tool_call_result` is a global hook that any adapter-based MCP
	 * server fires (including WooCommerce's bundled copy, which registers a
	 * meta-tool of the same name), so this acts only on Albert's own server.
	 *
	 * @param mixed  $result    The raw tool execution result.
	 * @param mixed  $args      The tool arguments used (unused).
	 * @param string $tool_name The tool name that was called.
	 * @param mixed  $mcp_tool  The MCP tool instance (unused).
	 * @param mixed  $server    The MCP server firing the filter.
	 *
	 * @return mixed The result with client-prepared schemas on 7.1+.
	 * @since 1.4.0
	 */
	public function prepare_ability_info_result( $result, $args, string $tool_name, $mcp_tool = null, $server = null ) {
		if ( ! $server instanceof McpServer ) {
			return $result;
		}

		if ( ! WpCompat::supports_client_schema_prep() ) {
			// 6.9 fallback — removable, see WpCompat: emit canonical schemas.
			return $result;
		}

		if ( $tool_name !== self::ABILITY_INFO_TOOL || ! is_array( $result ) ) {
			return $result;
		}

		foreach ( [ 'input_schema', 'output_schema' ] as $key ) {
			if ( isset( $result[ $key ] ) && is_array( $result[ $key ] ) ) {
				$result[ $key ] = $this->prepare_schema( $result[ $key ] );
			}
		}

		return $result;
	}
}
