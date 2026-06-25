<?php
/**
 * Unit tests for AbilitiesState — the single owner of the enabled/disabled
 * blocklist option.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Core;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\Core\AbilitiesState;
use PHPUnit\Framework\TestCase;

/**
 * AbilitiesState tests.
 *
 * @covers \Albert\Core\AbilitiesState
 */
class AbilitiesStateTest extends TestCase {

	/**
	 * Start each test with a clean, already-saved option store so the
	 * fresh-install default does not interfere with mutation assertions.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_hooks']   = [];
		$GLOBALS['albert_test_options'] = [
			AbilitiesState::OPTION       => [],
			AbilitiesState::SAVED_OPTION => true,
		];
	}

	// ─── disabled() / is_enabled() ──────────────────────────────────

	/**
	 * A saved, explicit blocklist is returned verbatim (as strings).
	 *
	 * @return void
	 */
	public function test_disabled_returns_saved_blocklist(): void {
		$GLOBALS['albert_test_options'][ AbilitiesState::OPTION ] = [ 'albert/create-post' ];

		$this->assertSame( [ 'albert/create-post' ], AbilitiesState::disabled() );
	}

	/**
	 * is_enabled() is false for a blocklisted ability, true otherwise.
	 *
	 * @return void
	 */
	public function test_is_enabled_reflects_blocklist(): void {
		$GLOBALS['albert_test_options'][ AbilitiesState::OPTION ] = [ 'albert/delete-post' ];

		$this->assertFalse( AbilitiesState::is_enabled( 'albert/delete-post' ) );
		$this->assertTrue( AbilitiesState::is_enabled( 'albert/find-posts' ) );
	}

	/**
	 * With no saved toggles and no abilities registered, the fresh-install
	 * default is empty and everything reads as enabled.
	 *
	 * @return void
	 */
	public function test_fresh_install_defaults_to_enabled(): void {
		$GLOBALS['albert_test_options'] = [];

		$this->assertSame( [], AbilitiesState::disabled() );
		$this->assertTrue( AbilitiesState::is_enabled( 'albert/find-posts' ) );
	}

	// ─── set_enabled() ──────────────────────────────────────────────

	/**
	 * Disabling an ability adds it to the blocklist and marks toggles saved.
	 *
	 * @return void
	 */
	public function test_set_enabled_false_adds_to_blocklist(): void {
		AbilitiesState::set_enabled( 'albert/create-post', false );

		$this->assertContains(
			'albert/create-post',
			$GLOBALS['albert_test_options'][ AbilitiesState::OPTION ]
		);
		$this->assertTrue( $GLOBALS['albert_test_options'][ AbilitiesState::SAVED_OPTION ] );
	}

	/**
	 * Enabling an ability removes it from the blocklist.
	 *
	 * @return void
	 */
	public function test_set_enabled_true_removes_from_blocklist(): void {
		$GLOBALS['albert_test_options'][ AbilitiesState::OPTION ] = [
			'albert/create-post',
			'albert/delete-post',
		];

		AbilitiesState::set_enabled( 'albert/create-post', true );

		$this->assertSame(
			[ 'albert/delete-post' ],
			$GLOBALS['albert_test_options'][ AbilitiesState::OPTION ]
		);
	}

	/**
	 * Disabling the same ability twice does not duplicate the entry.
	 *
	 * @return void
	 */
	public function test_set_enabled_false_is_idempotent(): void {
		AbilitiesState::set_enabled( 'albert/create-post', false );
		AbilitiesState::set_enabled( 'albert/create-post', false );

		$this->assertSame(
			[ 'albert/create-post' ],
			$GLOBALS['albert_test_options'][ AbilitiesState::OPTION ]
		);
	}

	// ─── set_enabled_bulk() ─────────────────────────────────────────

	/**
	 * Bulk-disabling adds every id once, even with duplicates in the input.
	 *
	 * @return void
	 */
	public function test_set_enabled_bulk_disables_and_dedupes(): void {
		AbilitiesState::set_enabled_bulk(
			[ 'albert/create-post', 'albert/create-post', 'albert/delete-post' ],
			false
		);

		$disabled = $GLOBALS['albert_test_options'][ AbilitiesState::OPTION ];

		$this->assertContains( 'albert/create-post', $disabled );
		$this->assertContains( 'albert/delete-post', $disabled );
		$this->assertSame( $disabled, array_unique( $disabled ) );
	}

	/**
	 * Bulk-enabling removes the given ids from the blocklist.
	 *
	 * @return void
	 */
	public function test_set_enabled_bulk_enables(): void {
		$GLOBALS['albert_test_options'][ AbilitiesState::OPTION ] = [
			'albert/create-post',
			'albert/delete-post',
			'albert/update-post',
		];

		AbilitiesState::set_enabled_bulk(
			[ 'albert/create-post', 'albert/update-post' ],
			true
		);

		$this->assertSame(
			[ 'albert/delete-post' ],
			$GLOBALS['albert_test_options'][ AbilitiesState::OPTION ]
		);
	}

	/**
	 * An empty id list is a no-op — it does not touch the option store.
	 *
	 * @return void
	 */
	public function test_set_enabled_bulk_empty_is_noop(): void {
		$GLOBALS['albert_test_options'] = [];

		AbilitiesState::set_enabled_bulk( [], false );

		$this->assertArrayNotHasKey(
			AbilitiesState::OPTION,
			$GLOBALS['albert_test_options']
		);
	}
}
