<?php
/**
 * Unit tests for ExecutionLogMarker — the request-scoped dedup marker.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Logging;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\Logging\ExecutionLogMarker;
use PHPUnit\Framework\TestCase;

/**
 * ExecutionLogMarker unit tests.
 *
 * @covers \Albert\Logging\ExecutionLogMarker
 */
class ExecutionLogMarkerTest extends TestCase {

	/**
	 * Reset the marker before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ExecutionLogMarker::reset();
	}

	/**
	 * Nothing is marked by default.
	 *
	 * @return void
	 */
	public function test_unmarked_by_default(): void {
		$this->assertFalse( ExecutionLogMarker::has( 'albert/create-post' ) );
	}

	/**
	 * Marking an ability makes has() return true for it only.
	 *
	 * @return void
	 */
	public function test_mark_sets_only_that_ability(): void {
		ExecutionLogMarker::mark( 'albert/create-post' );

		$this->assertTrue( ExecutionLogMarker::has( 'albert/create-post' ) );
		$this->assertFalse( ExecutionLogMarker::has( 'albert/update-post' ) );
	}

	/**
	 * An empty ability name is ignored.
	 *
	 * @return void
	 */
	public function test_empty_name_ignored(): void {
		ExecutionLogMarker::mark( '' );

		$this->assertFalse( ExecutionLogMarker::has( '' ) );
	}

	/**
	 * Resetting clears all marks.
	 *
	 * @return void
	 */
	public function test_reset_clears(): void {
		ExecutionLogMarker::mark( 'albert/create-post' );
		ExecutionLogMarker::reset();

		$this->assertFalse( ExecutionLogMarker::has( 'albert/create-post' ) );
	}
}
