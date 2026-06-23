<?php
/**
 * Abstract Remove Block Ability
 *
 * Operation base for the "remove one block" ability (posts + pages). Deletes the
 * block at `path`; all other blocks are preserved byte-for-byte.
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
 * Remove-one-block operation base. Subclasses bind it to a post type.
 *
 * @since 1.2.0
 */
abstract class AbstractRemoveBlock extends AbstractBlockEdit {

	/**
	 * Build the input schema for the remove operation.
	 *
	 * @return array<string, mixed> Input JSON schema.
	 * @since 1.2.0
	 */
	protected function remove_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => $this->common_properties(),
			'required'   => [ 'id', 'path' ],
		];
	}

	/**
	 * Remove introduces no new block, so it runs no allowed-block policy check.
	 *
	 * @return bool Always false.
	 * @since 1.2.0
	 */
	protected function enforces_policy(): bool {
		return false;
	}

	/**
	 * Apply the removal to the tree.
	 *
	 * @param BlockTreeEditor      $editor Tree editor.
	 * @param array<int, mixed>    $tree   Parsed block tree.
	 * @param array<string, mixed> $args   Ability input.
	 * @return array<int, mixed>|WP_Error New tree, or an error.
	 * @since 1.2.0
	 */
	protected function apply( BlockTreeEditor $editor, array $tree, array $args ): array|WP_Error {
		return $editor->remove( $tree, $this->read_path( $args ), $this->read_expect( $args ) );
	}
}
