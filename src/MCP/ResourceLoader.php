<?php
/**
 * Resource loader — builds the MCP resources this server exposes.
 *
 * The counterpart to {@see SkillLoader}: it returns built McpResource instances
 * (resources are not WordPress abilities) so Server::get_resources() is a plain
 * delegation, mirroring how prompts are supplied.
 *
 * @package    Albert
 * @subpackage MCP
 * @since      1.2.0
 */

namespace Albert\MCP;

use WP\MCP\Domain\Resources\McpResource;

defined( 'ABSPATH' ) || exit;

/**
 * Collects the MCP resource instances to register.
 *
 * Built-in resources are assembled here (currently the `albert://blocks/types`
 * catalog); add-ons and themes can contribute their own via the
 * `albert/mcp/resources` filter. Anything that is not an McpResource instance
 * is dropped, so one bad contribution never breaks the resources channel.
 *
 * @since 1.2.0
 */
class ResourceLoader {

	/**
	 * Build the list of MCP resources to expose.
	 *
	 * @return list<McpResource> The resource instances.
	 *
	 * @since 1.2.0
	 */
	public function resources(): array {
		$resources = [];

		$block_types = ( new BlockTypesResource() )->build();
		if ( $block_types instanceof McpResource ) {
			$resources[] = $block_types;
		}

		/**
		 * Filters the MCP resources Albert registers.
		 *
		 * Add-ons and themes should **append** their own built McpResource
		 * instances to the provided list (e.g. `$resources[] = $my_resource;`).
		 * The filter is replace-capable (standard WordPress semantics): returning
		 * a list that omits the built-ins will drop them, so always extend the
		 * passed array rather than replacing it. Non-McpResource entries are
		 * dropped.
		 *
		 * @since 1.2.0
		 *
		 * @param array<int, McpResource> $resources Built-in resource instances.
		 */
		$filtered = apply_filters( 'albert/mcp/resources', $resources );

		if ( ! is_array( $filtered ) ) {
			return $resources;
		}

		return array_values(
			array_filter(
				$filtered,
				static function ( $entry ): bool {
					return $entry instanceof McpResource;
				}
			)
		);
	}
}
