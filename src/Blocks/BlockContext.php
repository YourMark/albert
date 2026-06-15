<?php
/**
 * Block Context
 *
 * Immutable value object carrying the inputs a per-block template needs to
 * render its innerHTML: the block attributes, its plaintext fallback, and the
 * dotted path used to qualify validation issue messages.
 *
 * @package    Albert
 * @subpackage Blocks
 * @since      1.2.0
 */

namespace Albert\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only inputs passed to a single block template.
 *
 * Passing one context object (instead of a fixed five-argument signature)
 * lets each template declare exactly the value it consumes, so no template
 * carries unused parameters.
 *
 * @since 1.2.0
 */
class BlockContext {

	/**
	 * Construct the context.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $plaintext  Plaintext fallback content.
	 * @param string               $path       Dotted path for issue messages.
	 *
	 * @since 1.2.0
	 */
	public function __construct(
		public readonly array $attributes,
		public readonly string $plaintext,
		public readonly string $path
	) {
	}
}
