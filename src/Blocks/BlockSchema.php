<?php
/**
 * Block Schema
 *
 * Reads the WordPress block type registry into a list of registered block
 * names and per-block attribute/supports schemas. Feeds the `enum` of allowed
 * block names in ability input schemas and the block-types MCP resource.
 *
 * @package    Albert
 * @subpackage Blocks
 * @since      1.2.0
 */

namespace Albert\Blocks;

use WP_Block_Type_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes the registered block types in a compact, serialisable form.
 *
 * Results are cached per-request because the registry does not change during
 * a single request once blocks are registered.
 *
 * @since 1.2.0
 */
class BlockSchema {

	/**
	 * Per-request cache of the shaped schema map.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $schemas = null;

	/**
	 * Per-request cache of the raw block type objects, keyed by block name.
	 *
	 * @var array<string, object>|null
	 */
	private ?array $block_types = null;

	/**
	 * Get the list of registered block names.
	 *
	 * @return array<int, string> Block names (e.g. 'core/paragraph').
	 *
	 * @since 1.2.0
	 */
	public function block_names(): array {
		return array_keys( $this->block_schemas() );
	}

	/**
	 * Get the full map of block name => schema.
	 *
	 * Each schema entry has the shape:
	 *   [ 'attributes' => array<string, mixed>, 'supports' => array<string, mixed> ]
	 *
	 * @return array<string, array<string, mixed>> Schema map keyed by block name.
	 *
	 * @since 1.2.0
	 */
	public function block_schemas(): array {
		if ( $this->schemas !== null ) {
			return $this->schemas;
		}

		$schemas     = [];
		$block_types = [];
		$registry    = WP_Block_Type_Registry::get_instance();

		foreach ( $registry->get_all_registered() as $name => $block_type ) {
			$schemas[ (string) $name ]     = [
				'attributes' => $this->normalize_array_property( $block_type, 'attributes' ),
				'supports'   => $this->normalize_array_property( $block_type, 'supports' ),
			];
			$block_types[ (string) $name ] = $block_type;
		}

		$this->schemas     = $schemas;
		$this->block_types = $block_types;

		return $this->schemas;
	}

	/**
	 * Get the raw block type object for a block, or null if not registered.
	 *
	 * @param string $name Block name.
	 * @return object|null The WP_Block_Type instance, or null.
	 *
	 * @since 1.2.0
	 */
	public function block_type( string $name ): ?object {
		// Populate the block-type cache as a side effect of reading schemas.
		$this->block_schemas();

		return $this->block_types[ $name ] ?? null;
	}

	/**
	 * Whether a registered block is dynamic (renders via a render_callback).
	 *
	 * Dynamic blocks store no innerHTML — their output is generated at render
	 * time — so they can be serialized safely as just a comment + attributes.
	 * Returns false for unregistered blocks.
	 *
	 * @param string $name Block name.
	 * @return bool True when the block declares a render_callback.
	 *
	 * @since 1.2.0
	 */
	public function is_dynamic( string $name ): bool {
		$block_type = $this->block_type( $name );

		if ( $block_type === null ) {
			return false;
		}

		$render_callback = $block_type->render_callback ?? null;

		return $render_callback !== null && is_callable( $render_callback );
	}

	/**
	 * Get the schema for a single block, or null if it is not registered.
	 *
	 * @param string $name Block name.
	 * @return array<string, mixed>|null Schema or null.
	 *
	 * @since 1.2.0
	 */
	public function block_schema( string $name ): ?array {
		return $this->block_schemas()[ $name ] ?? null;
	}

	/**
	 * Whether a block name is registered.
	 *
	 * @param string $name Block name.
	 * @return bool
	 *
	 * @since 1.2.0
	 */
	public function is_registered( string $name ): bool {
		return isset( $this->block_schemas()[ $name ] );
	}

	/**
	 * Safely read an array property from a block type object.
	 *
	 * @param object $block_type The block type instance.
	 * @param string $property   Property name ('attributes' or 'supports').
	 * @return array<string, mixed> The property value, or an empty array.
	 *
	 * @since 1.2.0
	 */
	private function normalize_array_property( object $block_type, string $property ): array {
		$value = $block_type->$property ?? null;

		return is_array( $value ) ? $value : [];
	}
}
