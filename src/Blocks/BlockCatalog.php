<?php
/**
 * Block Catalog
 *
 * Computes the set of block types the current (authenticated) user is allowed
 * to use, in a compact serialisable form. MCP requests run wp_set_current_user()
 * (via TokenValidator) before abilities execute, so the current user is correct
 * when this runs from a tool or resource read.
 *
 * @package    Albert
 * @subpackage Blocks
 * @since      1.2.0
 */

namespace Albert\Blocks;

use WP_Block_Editor_Context;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the allowed-block catalog for the current user.
 *
 * The list of allowed blocks is resolved through the block editor's
 * get_allowed_block_types() — the same gate the block editor itself uses — so
 * any allow/deny lists set by themes or plugins (via the
 * allowed_block_types_all filter) are honoured. Results are cached per request,
 * keyed by context, because the registry and allow-list do not change within a
 * single request.
 *
 * @since 1.2.0
 */
class BlockCatalog {

	/**
	 * Block schema reader.
	 *
	 * @since 1.2.0
	 * @var BlockSchema
	 */
	private BlockSchema $schema;

	/**
	 * Per-request cache of allowed block names, keyed by context signature.
	 *
	 * @since 1.2.0
	 * @var array<string, array<int, string>>
	 */
	private array $allowed_cache = [];

	/**
	 * Constructor.
	 *
	 * @param BlockSchema|null $schema Optional schema reader (injectable for tests).
	 *
	 * @since 1.2.0
	 */
	public function __construct( ?BlockSchema $schema = null ) {
		$this->schema = $schema ?? new BlockSchema();
	}

	/**
	 * Get the allowed block names for the current user in a given context.
	 *
	 * Builds a WP_Block_Editor_Context (optionally carrying a post) and asks
	 * get_allowed_block_types() what the current user may use. That function
	 * returns either `true` (all registered blocks are allowed) or an array of
	 * block names. The `true` case is expanded to every registered block name;
	 * the array case is intersected with the registry so unregistered names in
	 * an allow-list are dropped. The result is sorted and de-duplicated.
	 *
	 * @param string|null $post_type Optional post type used to build the context post.
	 * @param int|null    $post_id   Optional post ID used to build the context post.
	 *
	 * @return array<int, string> Allowed, registered block names, sorted.
	 * @since 1.2.0
	 */
	public function allowed_block_names( ?string $post_type = null, ?int $post_id = null ): array {
		$cache_key = $this->cache_key( $post_type, $post_id );

		if ( isset( $this->allowed_cache[ $cache_key ] ) ) {
			return $this->allowed_cache[ $cache_key ];
		}

		$registered = $this->schema->block_names();
		$context    = $this->build_context( $post_type, $post_id );
		$allowed    = function_exists( 'get_allowed_block_types' )
			? get_allowed_block_types( $context )
			: true;

		if ( $allowed === true ) {
			// All registered blocks are allowed.
			$names = $registered;
		} elseif ( is_array( $allowed ) ) {
			// Intersect the allow-list with the registry; an allow-list may name
			// blocks that are not actually registered.
			$names = array_values( array_intersect( $registered, array_map( 'strval', $allowed ) ) );
		} else {
			// Defensive: an unexpected return type means "nothing extra allowed".
			$names = [];
		}

		$names = array_values( array_unique( $names ) );
		sort( $names );

		$this->allowed_cache[ $cache_key ] = $names;

		return $names;
	}

	/**
	 * Build the compact catalog for the current user.
	 *
	 * Each entry has the shape:
	 *   [ 'name' => string, 'title' => string, 'category' => string, 'isDynamic' => bool ]
	 *
	 * @param string|null $post_type Optional post type for the editor context.
	 * @param int|null    $post_id   Optional post ID for the editor context.
	 *
	 * @return array<int, array{name: string, title: string, category: string, isDynamic: bool}>
	 * @since 1.2.0
	 */
	public function catalog( ?string $post_type = null, ?int $post_id = null ): array {
		$catalog = [];

		foreach ( $this->allowed_block_names( $post_type, $post_id ) as $name ) {
			$block_type = $this->schema->block_type( $name );

			if ( $block_type === null ) {
				continue;
			}

			$catalog[] = [
				'name'      => $name,
				'title'     => $this->block_title( $block_type, $name ),
				'category'  => $this->block_category( $block_type ),
				'isDynamic' => $this->schema->is_dynamic( $name ),
			];
		}

		return $catalog;
	}

	/**
	 * Get the full detail for a single block, or null when not registered.
	 *
	 * @param string $name Block name (e.g. 'core/paragraph').
	 *
	 * @return array{name: string, title: string, category: string, isDynamic: bool, attributes: array<string, mixed>, supports: array<string, mixed>}|null
	 * @since 1.2.0
	 */
	public function block( string $name ): ?array {
		if ( ! $this->schema->is_registered( $name ) ) {
			return null;
		}

		$block_type = $this->schema->block_type( $name );

		if ( $block_type === null ) {
			return null;
		}

		$schema = $this->schema->block_schema( $name ) ?? [];

		return [
			'name'       => $name,
			'title'      => $this->block_title( $block_type, $name ),
			'category'   => $this->block_category( $block_type ),
			'isDynamic'  => $this->schema->is_dynamic( $name ),
			'attributes' => is_array( $schema['attributes'] ?? null ) ? $schema['attributes'] : [],
			'supports'   => is_array( $schema['supports'] ?? null ) ? $schema['supports'] : [],
		];
	}

	/**
	 * Whether a block is allowed for the current user in a given context.
	 *
	 * Exposed for the write-time enforcement pass so it reuses exactly the same
	 * allow-list resolution as the catalog.
	 *
	 * @param string      $name      Block name.
	 * @param string|null $post_type Optional post type for the editor context.
	 * @param int|null    $post_id   Optional post ID for the editor context.
	 *
	 * @return bool True when the block is allowed and registered.
	 * @since 1.2.0
	 */
	public function is_allowed( string $name, ?string $post_type = null, ?int $post_id = null ): bool {
		return in_array( $name, $this->allowed_block_names( $post_type, $post_id ), true );
	}

	/**
	 * Build a block editor context, optionally carrying a post.
	 *
	 * A post is attached when a post ID resolves to a real post, or when only a
	 * post type is given (a lightweight stub object so context-aware allow-list
	 * filters can read post_type). When neither is available the context is
	 * post-less, mirroring the site/global editor context.
	 *
	 * @param string|null $post_type Optional post type.
	 * @param int|null    $post_id   Optional post ID.
	 *
	 * @return WP_Block_Editor_Context The editor context.
	 * @since 1.2.0
	 */
	private function build_context( ?string $post_type, ?int $post_id ): WP_Block_Editor_Context {
		$settings = [];

		if ( $post_id !== null && $post_id > 0 && function_exists( 'get_post' ) ) {
			$post = get_post( $post_id );

			if ( $post ) {
				$settings['post'] = $post;
			}
		}

		if ( ! isset( $settings['post'] ) && $post_type !== null && $post_type !== '' ) {
			// Provide a minimal post-like object so context-aware filters can
			// branch on post_type without us inventing a real post.
			$settings['post'] = (object) [ 'post_type' => $post_type ];
		}

		return new WP_Block_Editor_Context( $settings );
	}

	/**
	 * Resolve a human-readable title for a block type.
	 *
	 * @param object $block_type The WP_Block_Type instance.
	 * @param string $name       Block name, used as a fallback title.
	 *
	 * @return string The block title.
	 * @since 1.2.0
	 */
	private function block_title( object $block_type, string $name ): string {
		$title = $block_type->title ?? '';

		return is_string( $title ) && $title !== '' ? $title : $name;
	}

	/**
	 * Resolve the category slug for a block type.
	 *
	 * @param object $block_type The WP_Block_Type instance.
	 *
	 * @return string The category slug, or an empty string.
	 * @since 1.2.0
	 */
	private function block_category( object $block_type ): string {
		$category = $block_type->category ?? '';

		return is_string( $category ) ? $category : '';
	}

	/**
	 * Build a stable cache key for a context.
	 *
	 * @param string|null $post_type Optional post type.
	 * @param int|null    $post_id   Optional post ID.
	 *
	 * @return string The cache key.
	 * @since 1.2.0
	 */
	private function cache_key( ?string $post_type, ?int $post_id ): string {
		return ( $post_type ?? '' ) . '|' . ( $post_id ?? 0 );
	}
}
