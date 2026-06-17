<?php
/**
 * Add Page Block Ability
 *
 * @package    Albert
 * @subpackage Abilities\WordPress\Pages
 * @since      1.2.0
 */

namespace Albert\Abilities\WordPress\Pages;

use Albert\Abstracts\AbstractAddBlock;
use Albert\Core\Annotations;

defined( 'ABSPATH' ) || exit;

/**
 * Insert one block into a page relative to a position, preserving every other block.
 *
 * @since 1.2.0
 */
class AddBlock extends AbstractAddBlock {

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 */
	public function __construct() {
		$this->id          = 'albert/add-page-block';
		$this->label       = __( 'Add Page Block', 'albert-ai-butler' );
		$this->description = __( 'Insert a single block into a page before/after a block at a given position (path), or inside it. Every other block is left untouched. Read the page first to get block paths.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'pages';

		$this->input_schema  = $this->add_input_schema();
		$this->output_schema = $this->shared_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::update(),
		];

		parent::__construct();
	}

	/**
	 * The post type this ability targets.
	 *
	 * @return string Post type slug.
	 * @since 1.2.0
	 */
	protected function post_type(): string {
		return 'page';
	}

	/**
	 * The REST base for persistence.
	 *
	 * @return string REST base.
	 * @since 1.2.0
	 */
	protected function rest_base(): string {
		return 'pages';
	}

	/**
	 * The capability gate.
	 *
	 * @return string Capability.
	 * @since 1.2.0
	 */
	protected function edit_capability(): string {
		return 'edit_pages';
	}
}
