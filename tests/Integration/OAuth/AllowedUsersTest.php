<?php
/**
 * Integration tests for AllowedUsers.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\OAuth;

use Albert\OAuth\AllowedUsers;
use Albert\Tests\TestCase;

/**
 * AllowedUsers integration tests.
 *
 * @covers \Albert\OAuth\AllowedUsers
 */
class AllowedUsersTest extends TestCase {

	/**
	 * A clean option before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		delete_option( AllowedUsers::OPTION );
	}

	/**
	 * A fresh add records `added_at` and leaves `authorised_at` unset.
	 *
	 * @return void
	 */
	public function test_add_records_added_at_and_no_authorised_at(): void {
		$user_id = self::factory()->user->create();

		$this->assertTrue( AllowedUsers::add( $user_id ) );

		$this->assertTrue( AllowedUsers::is_allowed( $user_id ) );
		$this->assertEqualsWithDelta( time(), AllowedUsers::added_at( $user_id ), 5 );
		$this->assertNull( AllowedUsers::authorised_at( $user_id ) );
		$this->assertFalse( AllowedUsers::has_authorised( $user_id ) );
	}

	/**
	 * Adding somebody already on the list is a no-op and does not reset
	 * `added_at`.
	 *
	 * @return void
	 */
	public function test_add_is_a_noop_for_an_existing_entry(): void {
		$user_id = self::factory()->user->create();

		AllowedUsers::add( $user_id );
		$first_added_at = AllowedUsers::added_at( $user_id );

		$this->assertFalse( AllowedUsers::add( $user_id ) );
		$this->assertSame( $first_added_at, AllowedUsers::added_at( $user_id ) );
	}

	/**
	 * Removing drops the entry entirely.
	 *
	 * @return void
	 */
	public function test_remove_drops_the_entry(): void {
		$user_id = self::factory()->user->create();

		AllowedUsers::add( $user_id );
		AllowedUsers::remove( $user_id );

		$this->assertFalse( AllowedUsers::is_allowed( $user_id ) );
		$this->assertSame( [], AllowedUsers::ids() );
	}

	/**
	 * Records the first-use timestamp for somebody on the list.
	 *
	 * @return void
	 */
	public function test_mark_authorised_records_the_timestamp(): void {
		$user_id = self::factory()->user->create();

		AllowedUsers::add( $user_id );
		AllowedUsers::mark_authorised( $user_id );

		$this->assertTrue( AllowedUsers::has_authorised( $user_id ) );
		$this->assertEqualsWithDelta( time(), AllowedUsers::authorised_at( $user_id ), 5 );
	}

	/**
	 * Only ever records the *first* time: a distinction the invitation-expiry
	 * sweep depends on to exempt somebody for good the moment they exercise
	 * the invitation, not just the most recent time.
	 *
	 * @return void
	 */
	public function test_mark_authorised_keeps_the_first_timestamp(): void {
		$user_id = self::factory()->user->create();

		AllowedUsers::add( $user_id );
		AllowedUsers::mark_authorised( $user_id );
		$first = AllowedUsers::authorised_at( $user_id );

		// A later authorisation (e.g. a second connection) must not move it.
		AllowedUsers::mark_authorised( $user_id );

		$this->assertSame( $first, AllowedUsers::authorised_at( $user_id ) );
	}

	/**
	 * A no-op for somebody not on the list at all (e.g. removed between being
	 * granted access and completing the OAuth flow).
	 *
	 * @return void
	 */
	public function test_mark_authorised_is_a_noop_when_not_allowed(): void {
		$user_id = self::factory()->user->create();

		AllowedUsers::mark_authorised( $user_id );

		$this->assertFalse( AllowedUsers::is_allowed( $user_id ) );
	}

	/**
	 * Reflects whether the list is empty.
	 *
	 * @return void
	 */
	public function test_has_any(): void {
		$this->assertFalse( AllowedUsers::has_any() );

		AllowedUsers::add( self::factory()->user->create() );

		$this->assertTrue( AllowedUsers::has_any() );
	}

	/**
	 * A legacy flat array of ids (pre-1.4.0 shape) is read defensively: ids
	 * resolve, but with no added_at/authorised_at to report.
	 *
	 * @return void
	 */
	public function test_reads_a_legacy_flat_array_defensively(): void {
		$user_id = self::factory()->user->create();

		update_option( AllowedUsers::OPTION, [ $user_id ] );

		$this->assertTrue( AllowedUsers::is_allowed( $user_id ) );
		$this->assertSame( [ $user_id ], AllowedUsers::ids() );
		$this->assertNull( AllowedUsers::added_at( $user_id ) );
	}
}
