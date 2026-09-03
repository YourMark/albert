<?php
/**
 * Block Types MCP Resource builder.
 *
 * Builds the `albert://blocks/types` MCP resource — a read-only mirror of the
 * `albert/list-block-types` tool that publishes the full set of block types the
 * current user may use. Tool and resource share one source of truth: the
 * {@see BlockCatalog} service.
 *
 * @package    Albert
 * @subpackage MCP
 * @since      1.2.0
 */

namespace Albert\MCP;

use Albert\Blocks\BlockCatalog;
use WP\MCP\Domain\Resources\McpResource;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the block-types MCP resource.
 *
 * A resource read runs in the authenticated MCP request (TokenValidator has
 * already called wp_set_current_user()), so the catalog and permission gate
 * resolve against the calling user — exactly like the equivalent tool.
 *
 * @since 1.2.0
 */
class BlockTypesResource {

	/**
	 * The MCP resource URI this resource is published on.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const URI = 'albert://blocks/types';

	/**
	 * Block catalog service.
	 *
	 * @since 1.2.0
	 * @var BlockCatalog
	 */
	private BlockCatalog $catalog;

	/**
	 * Constructor.
	 *
	 * @param BlockCatalog|null $catalog Optional catalog service (injectable for tests).
	 *
	 * @since 1.2.0
	 */
	public function __construct( ?BlockCatalog $catalog = null ) {
		$this->catalog = $catalog ?? new BlockCatalog();
	}

	/**
	 * Build the McpResource instance for the block-types resource.
	 *
	 * @return McpResource|WP_Error The built resource, or WP_Error when the
	 *                              adapter rejects the configuration.
	 * @since 1.2.0
	 */
	public function build(): McpResource|WP_Error {
		return McpResource::fromArray(
			[
				'uri'         => self::URI,
				'name'        => 'block-types',
				'title'       => __( 'Block types', 'albert-ai-butler' ),
				'description' => __( 'The block types this user may use, with title, category and whether each block renders dynamically.', 'albert-ai-butler' ),
				'mimeType'    => 'application/json',
				'handler'     => [ $this, 'handler' ],
				'permission'  => [ $this, 'permission' ],
				'annotations' => [
					'audience' => [ 'assistant' ],
					'priority' => 0.5,
				],
			]
		);
	}

	/**
	 * Resource read handler — return the allowed-block catalog for the current user.
	 *
	 * Returns a single text resource content item whose body is the JSON-encoded
	 * catalog (same data the `albert/list-block-types` tool returns). The adapter
	 * normalises this array of content items into the resource read result.
	 *
	 * @return array<int, array{uri: string, mimeType: string, text: string}> Content items.
	 * @since 1.2.0
	 */
	public function handler(): array {
		$blocks  = $this->catalog->catalog();
		$payload = [
			'blocks' => $blocks,
			'total'  => count( $blocks ),
		];

		$text = wp_json_encode( $payload );

		return [
			[
				'uri'      => self::URI,
				'mimeType' => 'application/json',
				'text'     => is_string( $text ) ? $text : '{}',
			],
		];
	}

	/**
	 * Permission gate — mirror the tool's edit-content capability check.
	 *
	 * @return bool True when the current user may read the block-types resource.
	 * @since 1.2.0
	 */
	public function permission(): bool {
		return current_user_can( 'edit_posts' );
	}
}
