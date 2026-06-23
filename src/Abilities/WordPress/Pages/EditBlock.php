<?php
/**
 * Edit Page Block Ability
 *
 * @package    Albert
 * @subpackage Abilities\WordPress\Pages
 * @since      1.2.0
 */

namespace Albert\Abilities\WordPress\Pages;

use Albert\Abstracts\AbstractEditBlock;
use Albert\Core\Annotations;

defined( 'ABSPATH' ) || exit;

/**
 * Replace one block in a page by its position, preserving every other block.
 *
 * @since 1.2.0
 */
class EditBlock extends AbstractEditBlock {

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 */
	public function __construct() {
		$this->id          = 'albert/edit-page-block';
		$this->label       = __( 'Edit Page Block', 'albert-ai-butler' );
		$this->description = __( 'Replace a single block in a page by its position (path), leaving every other block untouched. Read the page first to get each block\'s path.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'pages';

		$this->input_schema  = $this->edit_input_schema();
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
