<?php
/**
 * Tests for the BlockCatalog.
 *
 * @package Albert\Tests\Unit\Blocks
 */

namespace Albert\Tests\Unit\Blocks;

use Albert\Blocks\BlockCatalog;
use PHPUnit\Framework\TestCase;

// Load WordPress function stubs (incl. get_allowed_block_types,
// WP_Block_Editor_Context) and the registry stub.
require_once dirname( __DIR__, 2 ) . '/wp-function-stubs.php';
require_once dirname( __DIR__ ) . '/stubs/WP_Block_Type_Registry.php';

/**
 * BlockCatalog unit tests.
 *
 * Verifies the allowed-block resolution (true => all registered, array =>
 * intersection), the compact catalog shape, single-block detail, the unknown
 * name path and is_allowed().
 */
class BlockCatalogTest extends TestCase {

	/**
	 * Seed a controllable registry before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_block_types'] = [
			'core/paragraph'    => (object) [
				'title'           => 'Paragraph',
				'category'        => 'text',
				'attributes'      => [ 'content' => [ 'type' => 'string' ] ],
				'supports'        => [ 'anchor' => true ],
				'render_callback' => null,
			],
			'core/heading'      => (object) [
				'title'           => 'Heading',
				'category'        => 'text',
				'attributes'      => [ 'level' => [ 'type' => 'number' ] ],
				'supports'        => [],
				'render_callback' => null,
			],
			'core/latest-posts' => (object) [
				'title'           => 'Latest Posts',
				'category'        => 'widgets',
				'attributes'      => [],
				'supports'        => [],
				'render_callback' => static function () {
					return '';
				},
			],
		];
	}

	/**
	 * Clean up globals after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['albert_test_block_types'],
			$GLOBALS['albert_test_allowed_block_types'],
			$GLOBALS['albert_test_posts']
		);
		parent::tearDown();
	}

	public function test_allowed_true_returns_all_registered_sorted(): void {
		// No allow-list global => get_allowed_block_types() returns true.
		$names = ( new BlockCatalog() )->allowed_block_names();

		$this->assertSame(
			[ 'core/heading', 'core/latest-posts', 'core/paragraph' ],
			$names
		);
	}

	public function test_allowed_array_intersects_with_registry(): void {
		// An allow-list that names a block which is not registered: it is dropped.
		$GLOBALS['albert_test_allowed_block_types'] = [ 'core/paragraph', 'core/not-real' ];

		$names = ( new BlockCatalog() )->allowed_block_names();

		$this->assertSame( [ 'core/paragraph' ], $names );
	}

	public function test_catalog_shape(): void {
		$GLOBALS['albert_test_allowed_block_types'] = [ 'core/paragraph', 'core/latest-posts' ];

		$catalog = ( new BlockCatalog() )->catalog();

		$this->assertCount( 2, $catalog );
		$this->assertSame(
			[
				'name'      => 'core/latest-posts',
				'title'     => 'Latest Posts',
				'category'  => 'widgets',
				'isDynamic' => true,
			],
			$catalog[0]
		);
		$this->assertSame(
			[
				'name'      => 'core/paragraph',
				'title'     => 'Paragraph',
				'category'  => 'text',
				'isDynamic' => false,
			],
			$catalog[1]
		);
	}

	public function test_block_returns_single_detail(): void {
		$block = ( new BlockCatalog() )->block( 'core/paragraph' );

		$this->assertNotNull( $block );
		$this->assertSame( 'core/paragraph', $block['name'] );
		$this->assertSame( 'Paragraph', $block['title'] );
		$this->assertSame( 'text', $block['category'] );
		$this->assertFalse( $block['isDynamic'] );
		$this->assertSame( [ 'content' => [ 'type' => 'string' ] ], $block['attributes'] );
		$this->assertSame( [ 'anchor' => true ], $block['supports'] );
	}

	public function test_block_dynamic_flag(): void {
		$block = ( new BlockCatalog() )->block( 'core/latest-posts' );

		$this->assertNotNull( $block );
		$this->assertTrue( $block['isDynamic'] );
	}

	public function test_block_unknown_name_returns_null(): void {
		$this->assertNull( ( new BlockCatalog() )->block( 'foo/bar' ) );
	}

	public function test_is_allowed_true_case(): void {
		$catalog = new BlockCatalog();

		// Default allow-list is "true" => everything registered is allowed.
		$this->assertTrue( $catalog->is_allowed( 'core/heading' ) );
		$this->assertFalse( $catalog->is_allowed( 'foo/bar' ) );
	}

	public function test_is_allowed_array_case(): void {
		$GLOBALS['albert_test_allowed_block_types'] = [ 'core/paragraph' ];

		$catalog = new BlockCatalog();

		$this->assertTrue( $catalog->is_allowed( 'core/paragraph' ) );
		$this->assertFalse( $catalog->is_allowed( 'core/heading' ) );
	}

	public function test_catalog_excludes_unregistered_block_in_allow_list(): void {
		$GLOBALS['albert_test_allowed_block_types'] = [ 'core/paragraph', 'core/not-real' ];

		$catalog = ( new BlockCatalog() )->catalog();

		$names = array_column( $catalog, 'name' );
		$this->assertSame( [ 'core/paragraph' ], $names );
	}
}
