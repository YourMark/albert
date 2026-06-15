<?php
/**
 * Block Reader
 *
 * Parses raw WordPress block markup (post_content) into a compact, shaped
 * block tree that is consistent across every read ability and easy for an
 * AI assistant to reason about.
 *
 * @package    Albert
 * @subpackage Blocks
 * @since      1.2.0
 */

namespace Albert\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Reads post content into a structured block tree.
 *
 * Each node has the shape:
 *   [
 *     'name'        => string|null,             // e.g. 'core/paragraph' (null for classic/freeform content)
 *     'attributes'  => array<string, mixed>,    // block attributes
 *     'innerBlocks' => array<int, array>,       // recursive list of the same shape
 *     'plaintext'   => string,                  // human-readable text derived from innerHTML
 *   ]
 *
 * @since 1.2.0
 */
class BlockReader {

	/**
	 * Parse post content into the shaped block tree.
	 *
	 * Empty freeform separators emitted by parse_blocks() (a null block name
	 * with only whitespace inner HTML) are skipped. Genuine classic content
	 * (a null block name with real text) is preserved with name === null.
	 *
	 * @param string $post_content Raw block markup.
	 * @return array<int, array<string, mixed>> Shaped block tree.
	 *
	 * @since 1.2.0
	 */
	public function read( string $post_content ): array {
		if ( trim( $post_content ) === '' ) {
			return [];
		}

		$parsed = parse_blocks( $post_content );

		return $this->shape_blocks( $parsed );
	}

	/**
	 * Map a list of parsed blocks into the shaped tree, skipping empty freeform.
	 *
	 * The key type is int|string to match the shape parse_blocks() declares in
	 * the WordPress stub; numeric string keys never actually occur at runtime.
	 *
	 * @param array<int|string, array<string, mixed>> $blocks Parsed blocks from parse_blocks().
	 * @return array<int, array<string, mixed>> Shaped block tree.
	 *
	 * @since 1.2.0
	 */
	private function shape_blocks( array $blocks ): array {
		$shaped = [];

		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;

			// Skip the empty whitespace "freeform" separators parse_blocks emits
			// between real blocks. Keep genuine classic content (null name + text).
			if ( $name === null && trim( (string) ( $block['innerHTML'] ?? '' ) ) === '' ) {
				continue;
			}

			$shaped[] = $this->shape_block( $block );
		}

		return $shaped;
	}

	/**
	 * Shape a single parsed block (recursing into inner blocks).
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return array<string, mixed> Shaped block node.
	 *
	 * @since 1.2.0
	 */
	private function shape_block( array $block ): array {
		$inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] )
			? $block['innerBlocks']
			: [];

		return [
			'name'        => $block['blockName'] ?? null,
			'attributes'  => isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [],
			'innerBlocks' => $this->shape_blocks( $inner ),
			'plaintext'   => $this->plaintext( (string) ( $block['innerHTML'] ?? '' ) ),
		];
	}

	/**
	 * Derive a trimmed plaintext rendering of a block's inner HTML.
	 *
	 * @param string $inner_html The block's inner HTML.
	 * @return string Plaintext.
	 *
	 * @since 1.2.0
	 */
	private function plaintext( string $inner_html ): string {
		return trim( wp_strip_all_tags( $inner_html ) );
	}

	/**
	 * Aggregate the plaintext of a shaped block tree into one string.
	 *
	 * Walks the tree depth-first, joining each block's plaintext (and its inner
	 * blocks' plaintext) with blank lines so the result reads as the post body.
	 *
	 * @param array<int, array<string, mixed>> $blocks Shaped block tree from {@see read()}.
	 * @return string Concatenated plaintext.
	 *
	 * @since 1.2.0
	 */
	public static function plaintext_of( array $blocks ): string {
		$parts = [];

		foreach ( $blocks as $block ) {
			$text = isset( $block['plaintext'] ) ? trim( (string) $block['plaintext'] ) : '';
			if ( $text !== '' ) {
				$parts[] = $text;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$inner = self::plaintext_of( $block['innerBlocks'] );
				if ( $inner !== '' ) {
					$parts[] = $inner;
				}
			}
		}

		return implode( "\n\n", $parts );
	}
}
