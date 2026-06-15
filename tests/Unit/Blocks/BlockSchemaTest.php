<?php
/**
 * Tests for the BlockSchema.
 *
 * @package Albert\Tests\Unit\Blocks
 */

namespace Albert\Tests\Unit\Blocks;

use Albert\Blocks\BlockSchema;
use PHPUnit\Framework\TestCase;

// Load WordPress function stubs and the registry stub.
require_once dirname( __DIR__, 2 ) . '/wp-function-stubs.php';
require_once dirname( __DIR__ ) . '/stubs/WP_Block_Type_Registry.php';

/**
 * BlockSchema unit tests.
 *
 * Verifies that the registry is read into a list of block names plus per-block
 * attribute/supports schemas used to feed input enums and resources.
 */
class BlockSchemaTest extends TestCase {

	/**
	 * Reset the controllable registry contents before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_block_types'] = [
			'core/paragraph' => (object) [
				'attributes' => [ 'content' => [ 'type' => 'string' ] ],
				'supports'   => [ 'anchor' => true ],
			],
			'core/heading'   => (object) [
				'attributes' => [
					'level' => [
						'type'    => 'number',
						'default' => 2,
					],
				],
				'supports'   => [],
			],
		];
	}

	/**
	 * Clean up after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['albert_test_block_types'] );
		parent::tearDown();
	}

	public function test_block_names_returns_registered_names(): void {
		$names = ( new BlockSchema() )->block_names();

		$this->assertContains( 'core/paragraph', $names );
		$this->assertContains( 'core/heading', $names );
		$this->assertCount( 2, $names );
	}

	public function test_block_schema_returns_attributes_and_supports(): void {
		$schema = ( new BlockSchema() )->block_schema( 'core/heading' );

		$this->assertNotNull( $schema );
		$this->assertSame(
			[
				'level' => [
					'type'    => 'number',
					'default' => 2,
				],
			],
			$schema['attributes']
		);
		$this->assertSame( [], $schema['supports'] );
	}

	public function test_unknown_block_schema_returns_null(): void {
		$this->assertNull( ( new BlockSchema() )->block_schema( 'foo/bar' ) );
	}

	public function test_block_schemas_returns_full_map(): void {
		$schemas = ( new BlockSchema() )->block_schemas();

		$this->assertArrayHasKey( 'core/paragraph', $schemas );
		$this->assertArrayHasKey( 'attributes', $schemas['core/paragraph'] );
		$this->assertArrayHasKey( 'supports', $schemas['core/paragraph'] );
	}

	public function test_is_registered(): void {
		$schema = new BlockSchema();

		$this->assertTrue( $schema->is_registered( 'core/paragraph' ) );
		$this->assertFalse( $schema->is_registered( 'foo/bar' ) );
	}
}
