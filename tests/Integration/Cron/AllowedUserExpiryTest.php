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
		delete_option( AllowedUsers::EXPIRY_OPTION );

		$this->cron = new AllowedUserExpiry();
	}

	/**
	 * Seed an entry with an `expires_at` computed from `$added_days_ago` and
	 * `$expiry_days`, the way `AllowedUsers::add()` would have at the time.
	 * The sweep only ever reads the stored `expires_at`, never the live
	 * setting, so tests seed it directly rather than relying on the option.
	 *
	 * @param int  $user_id        The user id.
	 * @param int  $added_days_ago How many days ago they were added.
	 * @param int  $expiry_days    The window in force when they were added. 0 = never expires.
	 * @param bool $authorised     Whether to also mark them as having authorised.
	 *
	 * @return void
	 */
	private function seed( int $user_id, int $added_days_ago, int $expiry_days, bool $authorised = false ): void {
		$added_at = time() - ( $added_days_ago * DAY_IN_SECONDS );

		$all             = get_option( AllowedUsers::OPTION, [] );
		$all[ $user_id ] = [
			'added_at'      => gmdate( 'Y-m-d H:i:s', $added_at ),
			'authorised_at' => $authorised ? gmdate( 'Y-m-d H:i:s', $added_at + HOUR_IN_SECONDS ) : null,
			'expires_at'    => $expiry_days > 0 ? gmdate( 'Y-m-d H:i:s', $added_at + ( $expiry_days * DAY_IN_SECONDS ) ) : null,
		];
		update_option( AllowedUsers::OPTION, $all );
	}

	/**
	 * No stored `expires_at` (expiry was off when the invitation was
	 * granted) is never swept, however old the invitation is.
	 *
	 * @return void
	 */
	public function test_no_expires_at_is_never_swept(): void {
		$user_id = self::factory()->user->create();
		$this->seed( $user_id, 365, 0 );

		$this->cron->run();

		$this->assertTrue( AllowedUsers::is_allowed( $user_id ) );
	}

	/**
	 * A never-authorised invitation whose stored `expires_at` has passed is
	 * removed, and the removal is logged.
	 *
	 * @return void
	 */
	public function test_removes_an_entry_whose_expires_at_has_passed(): void {
		$user_id = self::factory()->user->create();
		$this->seed( $user_id, 15, 14 );

		$this->cron->run();

		$this->assertFalse( AllowedUsers::is_allowed( $user_id ) );

		$logged = ( new LoggingRepository() )->latest_for_ability( 'albert/allowed-user-expired' );
		$this->assertNotNull( $logged );
		$this->assertSame( (string) $user_id, (string) $logged->user_id );
		$this->assertSame( 'success', $logged->status );
	}

	/**
	 * An entry whose `expires_at` is still in the future is left alone.
	 *
	 * @return void
	 */
	public function test_leaves_an_entry_inside_its_window(): void {
		$user_id = self::factory()->user->create();
		$this->seed( $user_id, 5, 14 );

		$this->cron->run();

		$this->assertTrue( AllowedUsers::is_allowed( $user_id ) );
	}

	/**
	 * Somebody who authorised at least once is never swept for going unused,
	 * however far in the past their `expires_at` is: the invitation was
	 * exercised, that is what it was for.
	 *
	 * @return void
	 */
	public function test_never_sweeps_somebody_who_has_authorised(): void {
		$user_id = self::factory()->user->create();
		$this->seed( $user_id, 365, 1, true );

		$this->cron->run();

		$this->assertTrue( AllowedUsers::is_allowed( $user_id ) );
	}

	/**
	 * A mixed sweep only removes the never-authorised entries whose
	 * `expires_at` has passed; everyone else on the list is untouched.
	 *
	 * @return void
	 */
	public function test_sweeps_only_the_entries_that_qualify(): void {
		$expired_id    = self::factory()->user->create();
		$fresh_id      = self::factory()->user->create();
		$authorised_id = self::factory()->user->create();

		$this->seed( $expired_id, 30, 14 );
		$this->seed( $fresh_id, 1, 14 );
		$this->seed( $authorised_id, 30, 14, true );

		$this->cron->run();

		$this->assertFalse( AllowedUsers::is_allowed( $expired_id ) );
		$this->assertTrue( AllowedUsers::is_allowed( $fresh_id ) );
		$this->assertTrue( AllowedUsers::is_allowed( $authorised_id ) );
	}
}
