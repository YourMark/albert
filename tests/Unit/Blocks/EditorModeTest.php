<?php
/**
 * Unit tests for the EditorMode detection helper.
 *
 * Covers block vs classic detection for new targets (post type only) and
 * existing targets (resolved post object), plus the fallback to the block
 * editor when the WordPress detection functions are unavailable.
 *
 * @package Albert\Tests\Unit\Blocks
 */

namespace Albert\Tests\Unit\Blocks;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__, 2 ) . '/wp-function-stubs.php';

use Albert\Blocks\EditorMode;
use PHPUnit\Framework\TestCase;

/**
 * EditorMode detection tests.
 */
class EditorModeTest extends TestCase {

	/**
	 * Reset detection globals and the per-request cache before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		EditorMode::reset_cache();
		unset(
			$GLOBALS['albert_test_block_editor_post_types'],
			$GLOBALS['albert_test_block_editor_posts'],
			$GLOBALS['albert_test_posts']
		);
	}

	/**
	 * Clean up globals after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		EditorMode::reset_cache();
		unset(
			$GLOBALS['albert_test_block_editor_post_types'],
			$GLOBALS['albert_test_block_editor_posts'],
			$GLOBALS['albert_test_posts']
		);
		parent::tearDown();
	}

	// =====================================================================
	// New target — post type only.
	// =====================================================================

	public function test_new_block_editor_post_type_is_block(): void {
		$GLOBALS['albert_test_block_editor_post_types'] = [ 'post' => true ];

		$this->assertTrue( EditorMode::is_block_editor( 'post' ) );
		$this->assertSame( 'block', EditorMode::editor( 'post' ) );
	}

	public function test_new_classic_post_type_is_classic(): void {
		$GLOBALS['albert_test_block_editor_post_types'] = [ 'post' => false ];

		$this->assertFalse( EditorMode::is_block_editor( 'post' ) );
		$this->assertSame( 'classic', EditorMode::editor( 'post' ) );
	}

	public function test_new_target_defaults_to_block_when_post_type_unmapped(): void {
		$GLOBALS['albert_test_block_editor_post_types'] = [ 'page' => false ];

		// 'post' is not in the map, so the default (block) applies.
		$this->assertTrue( EditorMode::is_block_editor( 'post' ) );
	}

	// =====================================================================
	// Existing target — resolved post object.
	// =====================================================================

	public function test_existing_post_uses_per_post_decision_block(): void {
		$GLOBALS['albert_test_posts']              = [
			10 => (object) [
				'ID'        => 10,
				'post_type' => 'post',
			],
		];
		$GLOBALS['albert_test_block_editor_posts'] = [ 10 => true ];
		// Post type says classic, but the per-post decision wins.
		$GLOBALS['albert_test_block_editor_post_types'] = [ 'post' => false ];

		$this->assertTrue( EditorMode::is_block_editor( 'post', 10 ) );
		$this->assertSame( 'block', EditorMode::editor( 'post', 10 ) );
	}

	public function test_existing_post_uses_per_post_decision_classic(): void {
		$GLOBALS['albert_test_posts']              = [
			11 => (object) [
				'ID'        => 11,
				'post_type' => 'page',
			],
		];
		$GLOBALS['albert_test_block_editor_posts'] = [ 11 => false ];
		// Post type says block, but the per-post decision wins.
		$GLOBALS['albert_test_block_editor_post_types'] = [ 'page' => true ];

		$this->assertFalse( EditorMode::is_block_editor( 'page', 11 ) );
		$this->assertSame( 'classic', EditorMode::editor( 'page', 11 ) );
	}

	public function test_existing_post_falls_back_to_post_type_when_unresolvable(): void {
		// No post seeded for id 99, so use_block_editor_for_post() cannot resolve;
		// detection falls back to the post type.
		$GLOBALS['albert_test_block_editor_post_types'] = [ 'post' => false ];

		$this->assertFalse( EditorMode::is_block_editor( 'post', 99 ) );
	}

	// =====================================================================
	// Caching.
	// =====================================================================

	public function test_decision_is_cached_per_request(): void {
		$GLOBALS['albert_test_block_editor_post_types'] = [ 'post' => false ];
		$this->assertFalse( EditorMode::is_block_editor( 'post' ) );

		// Change the map; the cached value must persist until reset.
		$GLOBALS['albert_test_block_editor_post_types'] = [ 'post' => true ];
		$this->assertFalse( EditorMode::is_block_editor( 'post' ) );

		EditorMode::reset_cache();
		$this->assertTrue( EditorMode::is_block_editor( 'post' ) );
	}
}
