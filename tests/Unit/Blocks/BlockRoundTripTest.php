<?php
/**
 * Round-trip tests for the block write → read pipeline.
 *
 * @package Albert\Tests\Unit\Blocks
 */

namespace Albert\Tests\Unit\Blocks;

use Albert\Blocks\BlockReader;
use Albert\Blocks\BlockSerializer;
use PHPUnit\Framework\TestCase;

// Load WordPress function stubs (parse_blocks, serialize_blocks, esc_*, etc.)
// and the block type registry stub used by the serializer's BlockSchema.
require_once dirname( __DIR__, 2 ) . '/wp-function-stubs.php';
require_once dirname( __DIR__ ) . '/stubs/WP_Block_Type_Registry.php';

/**
 * Block round-trip unit tests.
 *
 * These exercise the exact pipeline the write abilities (Create/Update for posts
 * and pages) use to turn caller input into stored post_content, and the read
 * abilities (ViewPost/ViewPage/FindPosts/FindPages) use to turn that stored
 * markup back into a structured block tree:
 *
 *   input (block specs OR string) -> BlockSerializer -> markup (post_content)
 *   markup -> BlockReader -> structured block tree
 *
 * It proves the create→read round trip preserves block structure, and that the
 * "blocks specs win over content string" precedence the abilities implement
 * produces the expected stored markup.
 */
class BlockRoundTripTest extends TestCase {

	/**
	 * Serializer instance under test.
	 *
	 * @var BlockSerializer
	 */
	private BlockSerializer $serializer;

	/**
	 * Reader instance under test.
	 *
	 * @var BlockReader
	 */
	private BlockReader $reader;

	/**
	 * Set up fresh instances for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Register the common core block types (plus a dynamic block) so the
		// serializer's registry checks classify untemplated names correctly.
		albert_test_register_default_block_types();

		$this->serializer = new BlockSerializer();
		$this->reader     = new BlockReader();
	}

	/**
	 * Reset the controllable registry contents after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['albert_test_block_types'] );
		parent::tearDown();
	}

	/**
	 * Mirror the abilities' precedence: blocks specs win over the content string.
	 *
	 * @param array<int, array<string, mixed>> $blocks  Block specs ("blocks" input).
	 * @param string                           $content Content string ("content" input).
	 * @return array{markup: string, issues: array<int, array{severity: string, message: string}>} Serialized result.
	 */
	private function serialize_with_precedence( array $blocks, string $content ): array {
		if ( ! empty( $blocks ) ) {
			return $this->serializer->serialize_with_issues( $blocks );
		}

		return $this->serializer->serialize_with_issues( $content );
	}

	public function test_create_then_read_round_trip_preserves_structure(): void {
		$specs = [
			[
				'name'       => 'core/heading',
				'attributes' => [
					'level'   => 2,
					'content' => 'Intro',
				],
			],
			[
				'name'       => 'core/paragraph',
				'attributes' => [ 'content' => 'Hello world.' ],
			],
		];

		// Write side: what the create ability would store as post_content.
		$serialized = $this->serializer->serialize_with_issues( $specs );
		$this->assertSame( [], $serialized['issues'], 'Clean specs should produce no issues.' );

		// Read side: what the view ability would return as `blocks`.
		$tree = $this->reader->read( $serialized['markup'] );

		$names = array_map( static fn ( $block ) => $block['name'], $tree );
		$this->assertSame( [ 'core/heading', 'core/paragraph' ], $names );

		$this->assertSame( 2, $tree[0]['attributes']['level'] );
		$this->assertSame( 'Intro', $tree[0]['plaintext'] );
		$this->assertSame( 'Hello world.', $tree[1]['plaintext'] );
	}

	public function test_nested_columns_round_trip(): void {
		$specs = [
			[
				'name'        => 'core/columns',
				'attributes'  => [],
				'innerBlocks' => [
					[
						'name'        => 'core/column',
						'attributes'  => [],
						'innerBlocks' => [
							[
								'name'       => 'core/paragraph',
								'attributes' => [ 'content' => 'Left' ],
							],
						],
					],
					[
						'name'        => 'core/column',
						'attributes'  => [],
						'innerBlocks' => [
							[
								'name'       => 'core/paragraph',
								'attributes' => [ 'content' => 'Right' ],
							],
						],
					],
				],
			],
		];

		$markup = $this->serializer->serialize( $specs );
		$tree   = $this->reader->read( $markup );

		$this->assertCount( 1, $tree );
		$this->assertSame( 'core/columns', $tree[0]['name'] );
		$this->assertCount( 2, $tree[0]['innerBlocks'] );
		$this->assertSame( 'core/column', $tree[0]['innerBlocks'][0]['name'] );
		$this->assertSame( 'Left', $tree[0]['innerBlocks'][0]['innerBlocks'][0]['plaintext'] );
		$this->assertSame( 'Right', $tree[0]['innerBlocks'][1]['innerBlocks'][0]['plaintext'] );
	}

	public function test_blocks_take_precedence_over_content_string(): void {
		$blocks = [
			[
				'name'       => 'core/paragraph',
				'attributes' => [ 'content' => 'From blocks' ],
			],
		];

		$result = $this->serialize_with_precedence( $blocks, 'From content string' );
		$tree   = $this->reader->read( $result['markup'] );

		$this->assertCount( 1, $tree );
		$this->assertSame( 'core/paragraph', $tree[0]['name'] );
		$this->assertSame( 'From blocks', $tree[0]['plaintext'] );
		$this->assertStringNotContainsString( 'From content string', $result['markup'] );
	}

	public function test_content_string_used_when_blocks_empty(): void {
		$result = $this->serialize_with_precedence( [], '<!-- wp:paragraph --><p>Plain</p><!-- /wp:paragraph -->' );
		$tree   = $this->reader->read( $result['markup'] );

		$this->assertCount( 1, $tree );
		$this->assertSame( 'core/paragraph', $tree[0]['name'] );
		$this->assertSame( 'Plain', $tree[0]['plaintext'] );
	}

	public function test_unknown_block_reports_error_and_still_round_trips(): void {
		$specs = [
			[
				'name'       => 'acme/fancy-widget',
				'attributes' => [],
				'plaintext'  => 'Fallback content',
			],
		];

		$serialized = $this->serializer->serialize_with_issues( $specs );

		$errors = array_filter(
			$serialized['issues'],
			static fn ( $issue ) => $issue['severity'] === 'error'
		);
		$this->assertNotEmpty( $errors, 'Unknown block should report an error-severity issue.' );

		// The markup is still usable (core/html fallback) even though the
		// ability will refuse to save because of the error.
		$tree = $this->reader->read( $serialized['markup'] );
		$this->assertCount( 1, $tree );
		$this->assertSame( 'core/html', $tree[0]['name'] );
	}
}
