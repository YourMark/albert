<?php
/**
 * Unit tests for the block-types MCP resource builder.
 *
 * Verifies the resource handler returns the allowed-block catalog (the same data
 * the albert/list-block-types tool returns) as a JSON text content item, and that
 * the permission gate mirrors the tool's edit_posts capability check.
 *
 * @package Albert\Tests\Unit\MCP
 */

namespace Albert\Tests\Unit\MCP;

// WordPress + sanitisation stubs and the controllable block registry.
require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__, 2 ) . '/wp-function-stubs.php';
require_once dirname( __DIR__ ) . '/stubs/WP_Block_Type_Registry.php';

use Albert\Blocks\BlockCatalog;
use Albert\MCP\BlockTypesResource;
use PHPUnit\Framework\TestCase;

/**
 * BlockTypesResource handler/permission tests.
 */
class BlockTypesResourceTest extends TestCase {

	/**
	 * Seed a controllable registry before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_block_types'] = [
			'core/paragraph' => (object) [
				'title'           => 'Paragraph',
				'category'        => 'text',
				'attributes'      => [ 'content' => [ 'type' => 'string' ] ],
				'supports'        => [ 'anchor' => true ],
				'render_callback' => null,
			],
			'core/image'     => (object) [
				'title'           => 'Image',
				'category'        => 'media',
				'attributes'      => [ 'url' => [ 'type' => 'string' ] ],
				'supports'        => [],
				'render_callback' => null,
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
			$GLOBALS['albert_test_allowed_block_types']
		);
		parent::tearDown();
	}

	public function test_handler_returns_catalog_as_json_text_content(): void {
		$GLOBALS['albert_test_allowed_block_types'] = [ 'core/paragraph' ];

		$contents = ( new BlockTypesResource( new BlockCatalog() ) )->handler();

		$this->assertIsArray( $contents );
		$this->assertCount( 1, $contents );

		$item = $contents[0];
		$this->assertSame( BlockTypesResource::URI, $item['uri'] );
		$this->assertSame( 'application/json', $item['mimeType'] );

		$decoded = json_decode( $item['text'], true );
		$this->assertSame( 1, $decoded['total'] );
		$this->assertSame( 'core/paragraph', $decoded['blocks'][0]['name'] );
		$this->assertSame(
			[ 'name', 'title', 'category', 'isDynamic' ],
			array_keys( $decoded['blocks'][0] )
		);
	}

	public function test_handler_returns_full_allowed_set(): void {
		// No allow-list global => every registered block is allowed.
		$contents = ( new BlockTypesResource( new BlockCatalog() ) )->handler();

		$decoded = json_decode( $contents[0]['text'], true );
		$this->assertSame( 2, $decoded['total'] );
		$this->assertSame(
			[ 'core/image', 'core/paragraph' ],
			array_column( $decoded['blocks'], 'name' )
		);
	}

	public function test_permission_mirrors_edit_posts_capability(): void {
		$resource = new BlockTypesResource( new BlockCatalog() );

		// $GLOBALS['albert_test_caps'] is the list of granted capability names.
		$GLOBALS['albert_test_caps'] = [ 'edit_posts' ];
		$this->assertTrue( $resource->permission() );

		$GLOBALS['albert_test_caps'] = [ 'read' ];
		$this->assertFalse( $resource->permission() );

		unset( $GLOBALS['albert_test_caps'] );
	}
}
