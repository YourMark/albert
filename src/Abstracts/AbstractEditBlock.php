<?php
/**
 * Abstract Edit Block Ability
 *
 * Operation base for the "edit one block" ability (posts + pages). Replaces the
 * block at `path` with the complete submitted block spec; all other blocks are
 * preserved byte-for-byte.
 *
 * @package    Albert
 * @subpackage Abstracts
 * @since      1.2.0
 */

namespace Albert\Abstracts;

use Albert\Blocks\BlockIssues;
use Albert\Blocks\BlockSerializer;
use Albert\Blocks\BlockTreeEditor;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Edit-one-block operation base. Subclasses bind it to a post type.
 *
 * @since 1.2.0
 */
abstract class AbstractEditBlock extends AbstractBlockEdit {

	/**
	 * Build the input schema for the edit operation.
	 *
	 * @return array<string, mixed> Input JSON schema.
	 * @since 1.2.0
	 */
	protected function edit_input_schema(): array {
		$properties          = $this->common_properties();
		$properties['block'] = $this->block_property();

		return [
			'type'       => 'object',
			'properties' => $properties,
			'required'   => [ 'id', 'path', 'block' ],
		];
	}

	/**
	 * Apply the edit to the tree.
	 *
	 * @param BlockTreeEditor      $editor Tree editor.
	 * @param array<int, mixed>    $tree   Parsed block tree.
	 * @param array<string, mixed> $args   Ability input.
	 * @return array<int, mixed>|WP_Error New tree, or an error.
	 * @since 1.2.0
	 */
	protected function apply( BlockTreeEditor $editor, array $tree, array $args ): array|WP_Error {
		$spec = $args['block'] ?? null;
		if ( ! is_array( $spec ) ) {
			return new WP_Error(
				'block_required',
				__( 'The `block` parameter is required and must be a block spec object.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		$built = ( new BlockSerializer() )->build_one( $spec );
		if ( $built['block'] === null ) {
			// The spec produced no block (e.g. an unknown block name with no
			// content to preserve). Surface its issues as block_validation_failed
			// — the same fatal error the whole-content write path returns — so the
			// model gets the actionable, block-naming message rather than an opaque
			// "could not build" error.
			$error = BlockIssues::to_wp_error( $built['issues'] );

			return $error instanceof WP_Error ? $error : new WP_Error(
				'block_validation_failed',
				__( 'The submitted block could not be built. Check the block name and attributes.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		return $editor->edit( $tree, $this->read_path( $args ), $built['block'], $this->read_expect( $args ) );
	}
}
