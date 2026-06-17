<?php
/**
 * Abstract Move Block Ability
 *
 * Operation base for the "move one block" ability (posts + pages). Reorders the
 * block at `path` among its siblings to `to_index`; all other blocks are
 * preserved byte-for-byte.
 *
 * @package    Albert
 * @subpackage Abstracts
 * @since      1.2.0
 */

namespace Albert\Abstracts;

use Albert\Blocks\BlockTreeEditor;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Move-one-block operation base. Subclasses bind it to a post type.
 *
 * @since 1.2.0
 */
abstract class AbstractMoveBlock extends AbstractBlockEdit {

	/**
	 * Build the input schema for the move operation.
	 *
	 * @return array<string, mixed> Input JSON schema.
	 * @since 1.2.0
	 */
	protected function move_input_schema(): array {
		$properties             = $this->common_properties();
		$properties['to_index'] = [
			'type'        => 'integer',
			'minimum'     => 0,
			'description' => 'New position among the block\'s siblings (same parent), as a logical index over the separator-filtered order: 0 = first. The block at `path` is moved there; other siblings shift to make room.',
		];

		return [
			'type'       => 'object',
			'properties' => $properties,
			'required'   => [ 'id', 'path', 'to_index' ],
		];
	}

	/**
	 * Move reorders an existing block, so it runs no allowed-block policy check.
	 *
	 * @return bool Always false.
	 * @since 1.2.0
	 */
	protected function enforces_policy(): bool {
		return false;
	}

	/**
	 * Apply the move to the tree.
	 *
	 * @param BlockTreeEditor      $editor Tree editor.
	 * @param array<int, mixed>    $tree   Parsed block tree.
	 * @param array<string, mixed> $args   Ability input.
	 * @return array<int, mixed>|WP_Error New tree, or an error.
	 * @since 1.2.0
	 */
	protected function apply( BlockTreeEditor $editor, array $tree, array $args ): array|WP_Error {
		$to_index = absint( $args['to_index'] ?? 0 );

		return $editor->move( $tree, $this->read_path( $args ), $to_index, $this->read_expect( $args ) );
	}
}
