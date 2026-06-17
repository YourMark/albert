<?php
/**
 * View Page Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Pages
 * @since      1.0.0
 */

namespace Albert\Abilities\WordPress\Pages;

use Albert\Abstracts\BaseAbility;
use Albert\Blocks\ContentFormatter;
use Albert\Blocks\EditorMode;
use Albert\Core\Annotations;
use WP_Error;
use WP_REST_Request;

/**
 * View Page Ability class
 *
 * Allows AI assistants to view a single WordPress page by ID.
 *
 * @since 1.0.0
 */
class ViewPage extends BaseAbility {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'albert/view-page';
		$this->label       = __( 'View Page', 'albert-ai-butler' );
		$this->description = __( 'Retrieve a single WordPress page by ID.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'pages';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::read(),
		];

		parent::__construct();
	}

	/**
	 * Get the input schema for this ability.
	 *
	 * @return array<string, mixed> JSON Schema array.
	 * @since 1.0.0
	 */
	private function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'     => [
					'type'        => 'integer',
					'description' => 'The page ID to retrieve.',
					'minimum'     => 1,
				],
				'format' => ContentFormatter::input_schema_property(),
			],
			'required'   => [ 'id' ],
		];
	}

	/**
	 * Get the output schema for this ability.
	 *
	 * @return array<string, mixed> JSON Schema array.
	 * @since 1.0.0
	 */
	private function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'page' => [
					'type'        => 'object',
					'description' => 'The requested page object.',
					'properties'  => [
						'content'    => [
							'type'        => 'string',
							'description' => 'Raw block markup (<!-- wp:... --> comment-delimited). Included when "content" is requested (default).',
						],
						'blocks'     => [
							'type'        => 'array',
							'description' => 'The page content parsed into a structured block tree. Each node has { name, attributes, innerBlocks, plaintext }; innerBlocks is the same shape recursively. Included when "blocks" is requested (default).',
							'items'       => [ 'type' => 'object' ],
						],
						'plaintext'  => [
							'type'        => 'string',
							'description' => 'Human-readable plain text of the whole page content. Included when "plaintext" is requested (default).',
						],
						'html'       => [
							'type'        => 'string',
							'description' => 'Rendered HTML produced by do_blocks() — reflects dynamic/server-rendered block output. Only included when "html" is requested.',
						],
						'markdown'   => [
							'type'        => 'string',
							'description' => 'Markdown rendering of the page content. Only included when "markdown" is requested.',
						],
						'editor'     => [
							'type'        => 'string',
							'enum'        => [ 'block', 'classic' ],
							'description' => 'Which editor this page uses. "block" means send structured "blocks" when writing; "classic" means send plain HTML in "content" instead.',
						],
						'has_blocks' => [
							'type'        => 'boolean',
							'description' => 'Whether the stored content actually contains block markup. May differ from "editor" (e.g. classic content saved on a block-editor type).',
						],
					],
				],
			],
		];
	}

	/**
	 * Check if the current user has permission to execute this ability.
	 *
	 * @return bool|WP_Error True if permitted, WP_Error with details otherwise.
	 * @since 1.0.0
	 */
	public function check_permission(): bool|WP_Error {
		return $this->require_capability( 'read' );
	}

	/**
	 * Execute the ability.
	 *
	 * @param array<string, mixed> $args Ability arguments.
	 * @return array<string, mixed>|WP_Error Result array or error.
	 * @since 1.0.0
	 */
	public function execute( array $args ): array|WP_Error {
		$page_id = absint( $args['id'] );
		$page    = get_post( $page_id );

		if ( ! $page || $page->post_type !== 'page' ) {
			return new WP_Error(
				'page_not_found',
				sprintf(
					/* translators: %d: Page ID */
					__( 'Page with ID %d not found.', 'albert-ai-butler' ),
					$page_id
				)
			);
		}

		// Check if user can read this specific page.
		if ( ! current_user_can( 'read_post', $page_id ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to view this page.', 'albert-ai-butler' ) );
		}

		$format  = isset( $args['format'] ) && is_array( $args['format'] ) ? $args['format'] : [];
		$content = ContentFormatter::build( $page->post_content, $format );

		return [
			'page' => array_merge(
				[
					'id'    => $page->ID,
					'title' => $page->post_title,
				],
				$content,
				EditorMode::signal( $page ),
				[
					'excerpt'        => $page->post_excerpt,
					'status'         => $page->post_status,
					'author_id'      => (int) $page->post_author,
					'date'           => $page->post_date,
					'modified'       => $page->post_modified,
					'slug'           => $page->post_name,
					'permalink'      => get_permalink( $page ),
					'parent_id'      => (int) $page->post_parent,
					'menu_order'     => (int) $page->menu_order,
					'featured_image' => get_post_thumbnail_id( $page ) ? get_post_thumbnail_id( $page ) : null,
				]
			),
		];
	}
}
