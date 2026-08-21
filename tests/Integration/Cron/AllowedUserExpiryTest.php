<?php
/**
 * Integration tests for the invitation-expiry sweep.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Cron;

use Albert\Cron\AllowedUserExpiry;
use Albert\Database\Installer;
use Albert\Logging\Repository as LoggingRepository;
use Albert\OAuth\AllowedUsers;
use Albert\Tests\TestCase;

/**
 * AllowedUserExpiry integration tests.
 *
 * @covers \Albert\Cron\AllowedUserExpiry
 */
class AllowedUserExpiryTest extends TestCase {

	/**
	 * The cron under test.
	 *
	 * @var AllowedUserExpiry
	 */
	private AllowedUserExpiry $cron;

	/**
	 * A clean slate before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::install();
		( new LoggingRepository() )->truncate();

		delete_option( AllowedUsers::OPTION );
		delete_option( AllowedUserExpiry::OPTION );

		$this->cron = new AllowedUserExpiry();
	}

	/**
	 * Backdate an allowed-user entry's `added_at` without going through
	 * `add()`, which always stamps "now".
	 *
	 * @param int $user_id     The user id.
	 * @param int $days_ago    How many days ago they were added.
	 * @param bool $authorised Whether to also mark them as having authorised.
	 *
	 * @return void
	 */
	private function backdate( int $user_id, int $days_ago, bool $authorised = false ): void {
		AllowedUsers::add( $user_id );

		$all                              = get_option( AllowedUsers::OPTION, [] );
		$all[ $user_id ]['added_at']      = gmdate( 'Y-m-d H:i:s', time() - ( $days_ago * DAY_IN_SECONDS ) );
		$all[ $user_id ]['authorised_at'] = $authorised ? gmdate( 'Y-m-d H:i:s', time() - ( $days_ago * DAY_IN_SECONDS ) + HOUR_IN_SECONDS ) : null;
		update_option( AllowedUsers::OPTION, $all );
	}

	/**
	 * 0 (the default) disables the sweep entirely: nobody is removed, however
	 * old and unused their invitation is.
	 *
	 * @return void
	 */
	public function test_zero_disables_the_sweep(): void {
		$user_id = self::factory()->user->create();
		$this->backdate( $user_id, 365 );

		update_option( AllowedUserExpiry::OPTION, 0 );
		$this->cron->run();

		$this->assertTrue( AllowedUsers::is_allowed( $user_id ) );
	}

	/**
	 * A never-authorised invitation older than the configured window is
	 * removed, and the removal is logged.
	 *
	 * @return void
	 */
	public function test_removes_a_never_authorised_invitation_past_the_window(): void {
		$user_id = self::factory()->user->create();
		$this->backdate( $user_id, 15 );

		update_option( AllowedUserExpiry::OPTION, 14 );
		$this->cron->run();

		$this->assertFalse( AllowedUsers::is_allowed( $user_id ) );

		$logged = ( new LoggingRepository() )->latest_for_ability( 'albert/allowed-user-expired' );
		$this->assertNotNull( $logged );
		$this->assertSame( (string) $user_id, (string) $logged->user_id );
		$this->assertSame( 'success', $logged->status );
	}

	/**
	 * A never-authorised invitation still inside the window is left alone.
	 *
	 * @return void
	 */
	public function test_leaves_a_never_authorised_invitation_inside_the_window(): void {
		$user_id = self::factory()->user->create();
		$this->backdate( $user_id, 5 );

		update_option( AllowedUserExpiry::OPTION, 14 );
		$this->cron->run();

		$this->assertTrue( AllowedUsers::is_allowed( $user_id ) );
	}

	/**
	 * Somebody who authorised at least once is never swept for going unused,
	 * however old the invitation is or how small the window is set: the
	 * invitation was exercised, that is what it was for.
	 *
	 * @return void
	 */
	public function test_never_sweeps_somebody_who_has_authorised(): void {
		$user_id = self::factory()->user->create();
		$this->backdate( $user_id, 365, true );

		update_option( AllowedUserExpiry::OPTION, 1 );
		$this->cron->run();

		$this->assertTrue( AllowedUsers::is_allowed( $user_id ) );
	}

	/**
	 * A mixed sweep only removes the never-authorised, past-window entries;
	 * everyone else on the list is untouched.
	 *
	 * @return void
	 */
	public function test_sweeps_only_the_entries_that_qualify(): void {
		$expired_id    = self::factory()->user->create();
		$fresh_id      = self::factory()->user->create();
		$authorised_id = self::factory()->user->create();

		$this->backdate( $expired_id, 30 );
		$this->backdate( $fresh_id, 1 );
		$this->backdate( $authorised_id, 30, true );

		update_option( AllowedUserExpiry::OPTION, 14 );
		$this->cron->run();

		$this->assertFalse( AllowedUsers::is_allowed( $expired_id ) );
		$this->assertTrue( AllowedUsers::is_allowed( $fresh_id ) );
		$this->assertTrue( AllowedUsers::is_allowed( $authorised_id ) );
	}
}
