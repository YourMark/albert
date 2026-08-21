<?php
/**
 * Integration tests for the Database Installer.
 *
 * Covers schema creation (ability log + OAuth tables), the recorded schema
 * version, idempotent install / maybe_upgrade (safe to re-run on every load),
 * and uninstall cleanup.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Database;

use Albert\Database\Installer;
use Albert\Database\Tables;
use Albert\OAuth\AllowedUsers;
use Albert\Tests\TestCase;

/**
 * Database Installer integration tests.
 *
 * @covers \Albert\Database\Installer
 * @covers \Albert\Database\Tables
 */
class InstallerTest extends TestCase {

	/**
	 * Ensure the tables exist before each test (normally created at plugin load).
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::install();
	}

	/**
	 * Creates the ability log table with the full column set.
	 *
	 * @return void
	 */
	public function test_install_creates_ability_log_columns(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection.
		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Tables::ability_log() ) );

		foreach ( [ 'id', 'ability_name', 'user_id', 'created_at', 'status', 'error_code', 'error_message', 'input', 'output', 'client_id', 'client_name' ] as $column ) {
			$this->assertContains( $column, $columns, $column );
		}
	}

	/**
	 * Creates every OAuth table (each has an `id` column).
	 *
	 * @return void
	 */
	public function test_install_creates_oauth_tables(): void {
		global $wpdb;

		foreach ( Tables::oauth() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection.
			$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );

			$this->assertContains( 'id', $columns, $table );
		}
	}

	/**
	 * Records the installed schema version in its option.
	 *
	 * @return void
	 */
	public function test_install_records_db_version_option(): void {
		$this->assertSame(
			(string) ALBERT_VERSION,
			get_option( Installer::VERSION_OPTION )
		);
	}

	/**
	 * Re-running install() preserves existing rows (idempotent).
	 *
	 * @return void
	 */
	public function test_install_is_idempotent(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup.
		$wpdb->insert(
			Tables::ability_log(),
			[
				'ability_name' => 'albert/survivor',
				'user_id'      => 1,
			],
			[ '%s', '%d' ]
		);
		$id_before = (int) $wpdb->insert_id;

		Installer::install();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test verification.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE ability_name = %s',
				Tables::ability_log(),
				'albert/survivor'
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( $id_before, (int) $row->id );
	}

	/**
	 * A no-op once the stored version is current.
	 *
	 * @return void
	 */
	public function test_maybe_upgrade_is_noop_when_current(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup.
		$wpdb->insert(
			Tables::ability_log(),
			[
				'ability_name' => 'albert/keep',
				'user_id'      => 1,
			],
			[ '%s', '%d' ]
		);
		$id_before = (int) $wpdb->insert_id;

		Installer::maybe_upgrade();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test verification.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE ability_name = %s',
				Tables::ability_log(),
				'albert/keep'
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( $id_before, (int) $row->id );
	}

	/**
	 * Runs the schema build when the stored version is behind, advancing the
	 * version while preserving existing rows (additive migration).
	 *
	 * @return void
	 */
	public function test_maybe_upgrade_triggers_when_behind(): void {
		global $wpdb;

		// Pretend the site is on an older plugin version.
		update_option( Installer::VERSION_OPTION, '0.0.0' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup.
		$wpdb->insert(
			Tables::ability_log(),
			[
				'ability_name' => 'albert/sentinel',
				'user_id'      => 1,
			],
			[ '%s', '%d' ]
		);
		$id_before = (int) $wpdb->insert_id;

		Installer::maybe_upgrade();

		// The version advanced to the running plugin version.
		$this->assertSame( (string) ALBERT_VERSION, get_option( Installer::VERSION_OPTION ) );

		// The additive migration preserved the existing row.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test verification.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE ability_name = %s',
				Tables::ability_log(),
				'albert/sentinel'
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( $id_before, (int) $row->id );
	}

	/**
	 * Upgrading from below 1.4.0 rewraps a flat `albert_allowed_users` array
	 * into the new per-entry shape, backfilling `added_at` to the moment of
	 * *this* upgrade rather than a guessed historical date: there is no
	 * record of when a legacy entry was actually added, and starting
	 * everyone's clock at the upgrade is what guarantees a full grace window
	 * before an invitation-expiry sweep can remove anyone.
	 *
	 * @return void
	 */
	public function test_maybe_upgrade_backfills_added_at_for_legacy_allowed_users(): void {
		$one = self::factory()->user->create();
		$two = self::factory()->user->create();

		// The pre-1.4.0 shape: a flat array of ints, no timestamps at all.
		update_option( AllowedUsers::OPTION, [ $one, $two ] );
		update_option( Installer::VERSION_OPTION, '0.0.0' );

		Installer::maybe_upgrade();

		$this->assertSame( [ $one, $two ], AllowedUsers::ids() );

		foreach ( [ $one, $two ] as $user_id ) {
			$this->assertEqualsWithDelta( time(), AllowedUsers::added_at( $user_id ), 5 );
			$this->assertNull( AllowedUsers::authorised_at( $user_id ) );
		}
	}

	/**
	 * Re-running the migration (e.g. against a site already on the new shape)
	 * leaves existing entries untouched rather than resetting their clock.
	 *
	 * @return void
	 */
	public function test_allowed_users_migration_is_idempotent(): void {
		$user_id = self::factory()->user->create();

		update_option( Installer::VERSION_OPTION, '0.0.0' );
		Installer::maybe_upgrade();

		AllowedUsers::add( $user_id );
		$added_at = AllowedUsers::added_at( $user_id );

		// Pretend the site is behind again and re-run: the entry is already
		// the new shape, so it must be carried through unchanged.
		update_option( Installer::VERSION_OPTION, '0.0.0' );
		Installer::maybe_upgrade();

		$this->assertSame( $added_at, AllowedUsers::added_at( $user_id ) );
	}

	/**
	 * Uninstall removes the version option.
	 *
	 * @return void
	 */
	public function test_uninstall_removes_version_option(): void {
		Installer::uninstall();

		$this->assertFalse( get_option( Installer::VERSION_OPTION ) );

		// Re-install for any downstream tests in the run.
		Installer::install();
	}

	/**
	 * After uninstall, install() re-creates the version option.
	 *
	 * @return void
	 */
	public function test_install_after_uninstall_recreates_option(): void {
		Installer::uninstall();
		$this->assertFalse( get_option( Installer::VERSION_OPTION ) );

		Installer::install();

		$this->assertSame( (string) ALBERT_VERSION, get_option( Installer::VERSION_OPTION ) );
	}
}
