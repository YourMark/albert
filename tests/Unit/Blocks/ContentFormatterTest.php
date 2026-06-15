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

/**
 * ContentFormatter unit tests.
 *
 * Proves the default representation set is backward-compatible and that only the
 * requested representations are produced.
 */
class ContentFormatterTest extends TestCase {

	private const MARKUP = '<!-- wp:heading --><h2 class="wp-block-heading">Title</h2><!-- /wp:heading -->'
		. '<!-- wp:paragraph --><p>Body text</p><!-- /wp:paragraph -->';

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
}
