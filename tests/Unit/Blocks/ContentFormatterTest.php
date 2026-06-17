<?php
/**
 * Tests for the ContentFormatter.
 *
 * @package Albert\Tests\Unit\Blocks
 */

namespace Albert\Tests\Unit\Blocks;

use Albert\Blocks\ContentFormatter;
use PHPUnit\Framework\TestCase;

// Load WordPress function stubs (parse_blocks, do_blocks, wp_strip_all_tags, ...).
require_once dirname( __DIR__, 2 ) . '/wp-function-stubs.php';
// apply_filters (used by the read_block_limit / read_max_bytes filters) lives here.
require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

/**
 * ContentFormatter unit tests.
 *
 * Proves the default representation set is backward-compatible and that only the
 * requested representations are produced.
 */
class ContentFormatterTest extends TestCase {

	private const MARKUP = '<!-- wp:heading --><h2 class="wp-block-heading">Title</h2><!-- /wp:heading -->'
		. '<!-- wp:paragraph --><p>Body text</p><!-- /wp:paragraph -->';

	/**
	 * Reset hook/filter globals so the read_block_limit / read_max_bytes filter
	 * stubs start clean for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['albert_test_hooks']          = [];
		$GLOBALS['albert_test_filter_returns'] = [];
	}

	/**
	 * Clear filter overrides after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$GLOBALS['albert_test_filter_returns'] = [];
		parent::tearDown();
	}

	/**
	 * Build a markup string of N simple paragraph blocks ("Block 0", "Block 1", ...).
	 *
	 * @param int $count Number of paragraph blocks.
	 * @return string Block markup.
	 */
	private function paragraphs( int $count ): string {
		$parts = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$parts[] = '<!-- wp:paragraph --><p>Block ' . $i . '</p><!-- /wp:paragraph -->';
		}

		return implode( "\n\n", $parts );
	}

	public function test_default_returns_content_blocks_plaintext(): void {
		$out = ContentFormatter::build( self::MARKUP );

		$this->assertSame( [ 'content', 'blocks', 'plaintext' ], array_keys( $out ) );
		$this->assertSame( self::MARKUP, $out['content'] );
		$this->assertIsArray( $out['blocks'] );
		$this->assertSame( "Title\n\nBody text", $out['plaintext'] );
	}

	public function test_empty_format_falls_back_to_default(): void {
		$out = ContentFormatter::build( self::MARKUP, [] );

		$this->assertSame( [ 'content', 'blocks', 'plaintext' ], array_keys( $out ) );
	}

	public function test_invalid_only_format_falls_back_to_default(): void {
		$out = ContentFormatter::build( self::MARKUP, [ 'bogus', 42, [ 'x' ] ] );

		$this->assertSame( [ 'content', 'blocks', 'plaintext' ], array_keys( $out ) );
	}

	public function test_single_format_returns_only_that_key(): void {
		$out = ContentFormatter::build( self::MARKUP, [ 'plaintext' ] );

		$this->assertSame( [ 'plaintext' ], array_keys( $out ) );
		$this->assertSame( "Title\n\nBody text", $out['plaintext'] );
	}

	public function test_html_and_markdown_only(): void {
		$out = ContentFormatter::build( self::MARKUP, [ 'html', 'markdown' ] );

		$this->assertSame( [ 'html', 'markdown' ], array_keys( $out ) );
		$this->assertArrayNotHasKey( 'content', $out );
		$this->assertArrayNotHasKey( 'blocks', $out );
		$this->assertArrayNotHasKey( 'plaintext', $out );
	}

	public function test_html_reflects_rendered_output(): void {
		$out = ContentFormatter::build( self::MARKUP, [ 'html' ] );

		// do_blocks() renders static blocks to their inner HTML.
		$this->assertStringContainsString( '<h2 class="wp-block-heading">Title</h2>', $out['html'] );
		$this->assertStringContainsString( '<p>Body text</p>', $out['html'] );
	}

	public function test_markdown_reflects_markdown_rendering(): void {
		$out = ContentFormatter::build( self::MARKUP, [ 'markdown' ] );

		$this->assertSame( "## Title\n\nBody text", $out['markdown'] );
	}

	public function test_output_key_order_is_deterministic_regardless_of_input_order(): void {
		$out = ContentFormatter::build( self::MARKUP, [ 'markdown', 'content', 'html' ] );

		// Ordered by FORMATS: content, html, markdown.
		$this->assertSame( [ 'content', 'html', 'markdown' ], array_keys( $out ) );
	}

	public function test_duplicates_are_collapsed(): void {
		$out = ContentFormatter::build( self::MARKUP, [ 'plaintext', 'plaintext' ] );

		$this->assertSame( [ 'plaintext' ], array_keys( $out ) );
	}

	public function test_normalize_returns_recognised_keys(): void {
		$this->assertSame( [ 'content', 'plaintext' ], ContentFormatter::normalize( [ 'plaintext', 'content', 'nope' ] ) );
		$this->assertSame( ContentFormatter::DEFAULT_FORMATS, ContentFormatter::normalize( 'not-an-array' ) );
	}

	// -- Backward compatibility (no options) --

	public function test_no_options_adds_no_meta_or_truncated(): void {
		$out = ContentFormatter::build( self::MARKUP );

		$this->assertSame( [ 'content', 'blocks', 'plaintext' ], array_keys( $out ) );
		$this->assertArrayNotHasKey( '_meta', $out );
		$this->assertArrayNotHasKey( 'truncated', $out );
	}

	public function test_no_options_large_content_adds_truncated_but_no_meta(): void {
		// A no-options call still byte-caps: the only post-1.2.0 addition to the
		// legacy call signature is a top-level `truncated` flag when a trim fires.
		$big = '<!-- wp:paragraph --><p>' . str_repeat( 'A', 60000 ) . '</p><!-- /wp:paragraph -->';

		$out = ContentFormatter::build( $big, [ 'content' ] );

		$this->assertTrue( $out['truncated'] );
		$this->assertArrayNotHasKey( '_meta', $out );
		$this->assertStringContainsString( '…[truncated,', $out['content'] );
	}

	public function test_find_path_small_content_has_no_truncated_flag(): void {
		$out = ContentFormatter::build( self::MARKUP, [], [ 'paginate' => false ] );

		$this->assertSame( [ 'content', 'blocks', 'plaintext' ], array_keys( $out ) );
		$this->assertArrayNotHasKey( 'truncated', $out );
		$this->assertArrayNotHasKey( '_meta', $out );
	}

	// -- Windowing (view-* paginate path) --

	public function test_window_slices_top_level_blocks(): void {
		$out = ContentFormatter::build(
			$this->paragraphs( 10 ),
			[ 'content', 'blocks', 'plaintext' ],
			[
				'paginate' => true,
				'offset'   => 2,
				'limit'    => 3,
			]
		);

		// Three blocks returned, in order, starting at index 2.
		$this->assertCount( 3, $out['blocks'] );
		$this->assertSame( 'Block 2', $out['blocks'][0]['plaintext'] );
		$this->assertSame( 'Block 4', $out['blocks'][2]['plaintext'] );

		// content is the re-serialized slice (only these three blocks).
		$this->assertStringContainsString( '<p>Block 2</p>', $out['content'] );
		$this->assertStringContainsString( '<p>Block 4</p>', $out['content'] );
		$this->assertStringNotContainsString( '<p>Block 1</p>', $out['content'] );
		$this->assertStringNotContainsString( '<p>Block 5</p>', $out['content'] );

		// plaintext reflects only the window.
		$this->assertSame( "Block 2\n\nBlock 3\n\nBlock 4", $out['plaintext'] );
	}

	public function test_window_meta_totals_and_next_offset(): void {
		$out  = ContentFormatter::build(
			$this->paragraphs( 10 ),
			[ 'content' ],
			[
				'paginate' => true,
				'offset'   => 0,
				'limit'    => 4,
			]
		);
		$meta = $out['_meta'];

		$this->assertSame( 10, $meta['total_blocks'] );
		$this->assertSame( 0, $meta['offset'] );
		$this->assertSame( 4, $meta['limit'] );
		$this->assertSame( 4, $meta['returned_blocks'] );
		$this->assertTrue( $meta['truncated'] );
		$this->assertSame( 4, $meta['next_offset'] );
		$this->assertStringContainsString( 'offset=4', $meta['note'] );
	}

	public function test_window_last_slice_has_no_next_offset_and_not_truncated(): void {
		$out  = ContentFormatter::build(
			$this->paragraphs( 6 ),
			[ 'content' ],
			[
				'paginate' => true,
				'offset'   => 4,
				'limit'    => 4,
			]
		);
		$meta = $out['_meta'];

		$this->assertSame( 6, $meta['total_blocks'] );
		$this->assertSame( 2, $meta['returned_blocks'] );
		$this->assertFalse( $meta['truncated'] );
		$this->assertArrayNotHasKey( 'next_offset', $meta );
		// A partial last slice must report its actual range, never "showing all".
		$this->assertStringContainsString( 'Showing blocks 4–5 of 6', $meta['note'] );
		$this->assertStringNotContainsString( 'all', $meta['note'] );
	}

	public function test_window_note_combines_more_blocks_and_byte_cap(): void {
		// A window that both leaves more blocks AND byte-caps its text must tell the
		// model both: where the next slice is, and that the current text is partial.
		$out  = ContentFormatter::build(
			$this->paragraphs( 10 ),
			[ 'plaintext' ],
			[
				'paginate'  => true,
				'offset'    => 0,
				'limit'     => 3,
				'max_bytes' => 5,
			]
		);
		$meta = $out['_meta'];

		$this->assertTrue( $meta['truncated'] );
		$this->assertSame( 3, $meta['next_offset'] );
		$this->assertStringContainsString( 'offset=3', $meta['note'] );
		$this->assertStringContainsString( 'byte-capped', $meta['note'] );
		$this->assertStringContainsString( '…[truncated,', $out['plaintext'] );
	}

	public function test_window_past_the_end_returns_empty_and_not_truncated(): void {
		$out  = ContentFormatter::build(
			$this->paragraphs( 3 ),
			[ 'content', 'blocks' ],
			[
				'paginate' => true,
				'offset'   => 10,
				'limit'    => 5,
			]
		);
		$meta = $out['_meta'];

		$this->assertSame( 3, $meta['total_blocks'] );
		$this->assertSame( 0, $meta['returned_blocks'] );
		$this->assertSame( [], $out['blocks'] );
		$this->assertSame( '', $out['content'] );
		// offset(10) + returned(0) is past total(3), so nothing "remains" → not truncated.
		$this->assertFalse( $meta['truncated'] );
		$this->assertArrayNotHasKey( 'next_offset', $meta );
		// The note must explain the empty window, not claim "showing all blocks".
		$this->assertStringContainsString( 'past the end', $meta['note'] );
	}

	public function test_window_meta_present_even_when_nothing_truncated(): void {
		$out = ContentFormatter::build( self::MARKUP, [ 'content' ], [ 'paginate' => true ] );

		$this->assertArrayHasKey( '_meta', $out );
		$this->assertFalse( $out['_meta']['truncated'] );
		$this->assertSame( 2, $out['_meta']['total_blocks'] );
		$this->assertSame( 2, $out['_meta']['returned_blocks'] );
	}

	public function test_single_block_returned_whole_on_paginate(): void {
		$out = ContentFormatter::build( '<!-- wp:paragraph --><p>Only one</p><!-- /wp:paragraph -->', [ 'content', 'plaintext' ], [ 'paginate' => true ] );

		$this->assertSame( 1, $out['_meta']['total_blocks'] );
		$this->assertSame( 1, $out['_meta']['returned_blocks'] );
		$this->assertFalse( $out['_meta']['truncated'] );
		$this->assertSame( 'Only one', $out['plaintext'] );
	}

	public function test_classic_no_block_content_is_windowed_as_single_block(): void {
		$out = ContentFormatter::build( 'Just classic text, no blocks.', [ 'plaintext' ], [ 'paginate' => true ] );

		$this->assertSame( 1, $out['_meta']['total_blocks'] );
		$this->assertSame( 'Just classic text, no blocks.', $out['plaintext'] );
		$this->assertFalse( $out['_meta']['truncated'] );
	}

	// -- Byte cap (both paths) --

	public function test_byte_cap_appends_marker_and_flags_truncation_on_find_path(): void {
		$big = '<!-- wp:paragraph --><p>' . str_repeat( 'A', 200 ) . '</p><!-- /wp:paragraph -->';

		$out = ContentFormatter::build(
			$big,
			[ 'plaintext' ],
			[
				'paginate'  => false,
				'max_bytes' => 50,
			]
		);

		$this->assertTrue( $out['truncated'] );
		$this->assertStringContainsString( '…[truncated,', $out['plaintext'] );
		// 50 bytes kept + a marker.
		$this->assertSame( 'A', substr( $out['plaintext'], 49, 1 ) );
	}

	public function test_byte_cap_under_limit_is_untouched(): void {
		$out = ContentFormatter::build(
			self::MARKUP,
			[ 'plaintext' ],
			[
				'paginate'  => false,
				'max_bytes' => 50000,
			]
		);

		$this->assertSame( "Title\n\nBody text", $out['plaintext'] );
		$this->assertArrayNotHasKey( 'truncated', $out );
	}

	public function test_byte_cap_does_not_touch_blocks_tree(): void {
		$big = '<!-- wp:paragraph --><p>' . str_repeat( 'A', 200 ) . '</p><!-- /wp:paragraph -->';

		$out = ContentFormatter::build(
			$big,
			[ 'blocks' ],
			[
				'paginate'  => false,
				'max_bytes' => 50,
			]
		);

		// blocks is structured data, never byte-capped, so no truncated flag from it.
		$this->assertArrayNotHasKey( 'truncated', $out );
		$this->assertSame( str_repeat( 'A', 200 ), $out['blocks'][0]['plaintext'] );
	}

	public function test_byte_cap_cuts_multibyte_on_valid_boundary(): void {
		// Each "é" is 2 bytes in UTF-8; cap at an odd byte to force a boundary cut.
		$text = str_repeat( 'é', 100 );
		$big  = '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';

		$out = ContentFormatter::build(
			$big,
			[ 'plaintext' ],
			[
				'paginate'  => false,
				'max_bytes' => 51,
			]
		);

		// The kept prefix (before the marker) must be valid UTF-8 — no broken bytes.
		$marker_pos = strpos( $out['plaintext'], '…[truncated,' );
		$this->assertNotFalse( $marker_pos );
		$kept = substr( $out['plaintext'], 0, $marker_pos );
		$this->assertSame( $kept, mb_convert_encoding( $kept, 'UTF-8', 'UTF-8' ) );
		// mb_strcut won't split the 2-byte char, so it keeps 25 chars (50 bytes), not 25.5.
		$this->assertSame( 25, mb_strlen( $kept, 'UTF-8' ) );
	}

	public function test_byte_cap_folds_into_window_meta_truncated(): void {
		$big = '<!-- wp:paragraph --><p>' . str_repeat( 'A', 200 ) . '</p><!-- /wp:paragraph -->';

		$out = ContentFormatter::build(
			$big,
			[ 'plaintext' ],
			[
				'paginate'  => true,
				'max_bytes' => 50,
			]
		);

		// Only one block, so no block-window overflow, but the byte cap fired →
		// _meta.truncated must reflect it.
		$this->assertTrue( $out['_meta']['truncated'] );
		$this->assertStringContainsString( '…[truncated,', $out['plaintext'] );
	}

	// -- Filters --

	public function test_read_block_limit_filter_is_honored(): void {
		$GLOBALS['albert_test_filter_returns']['albert/blocks/read_block_limit'] = 2;

		$out = ContentFormatter::build( $this->paragraphs( 10 ), [ 'blocks' ], [ 'paginate' => true ] );

		$this->assertSame( 2, $out['_meta']['limit'] );
		$this->assertSame( 2, $out['_meta']['returned_blocks'] );
		$this->assertTrue( $out['_meta']['truncated'] );
	}

	public function test_read_max_bytes_filter_is_honored(): void {
		$GLOBALS['albert_test_filter_returns']['albert/blocks/read_max_bytes'] = 50;
		$big = '<!-- wp:paragraph --><p>' . str_repeat( 'A', 200 ) . '</p><!-- /wp:paragraph -->';

		$out = ContentFormatter::build( $big, [ 'plaintext' ], [ 'paginate' => false ] );

		$this->assertTrue( $out['truncated'] );
		$this->assertStringContainsString( '…[truncated,', $out['plaintext'] );
	}

	public function test_explicit_options_override_filters(): void {
		$GLOBALS['albert_test_filter_returns']['albert/blocks/read_block_limit'] = 2;

		// An explicit limit must win over the filter default.
		$out = ContentFormatter::build(
			$this->paragraphs( 10 ),
			[ 'blocks' ],
			[
				'paginate' => true,
				'limit'    => 5,
			]
		);

		$this->assertSame( 5, $out['_meta']['limit'] );
		$this->assertSame( 5, $out['_meta']['returned_blocks'] );
	}
}
