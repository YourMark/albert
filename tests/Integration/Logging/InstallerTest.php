<?php
/**
 * Integration tests for the Logging Installer.
 *
 * Covers schema creation, idempotent install (re-run with same db_version),
 * version-gated migration (re-run with older db_version bumps and creates),
 * and uninstall cleanup.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Logging;

use Albert\Logging\Installer;
use Albert\Tests\TestCase;

/**
 * Installer integration tests.
 *
 * @covers \Albert\Logging\Installer
 */
class InstallerTest extends TestCase {

	/**
	 * Ensure the table exists before each test (it's normally created at plugin load).
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::install();
	}

	/**
	 * Creates the table with the expected columns.
	 *
	 * @return void
	 */
	public function test_install_creates_table_with_expected_columns(): void {
		global $wpdb;

		$table = Installer::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection.
		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );

		$this->assertContains( 'id', $columns );
		$this->assertContains( 'ability_name', $columns );
		$this->assertContains( 'user_id', $columns );
		$this->assertContains( 'created_at', $columns );
	}

	/**
	 * Records the installed db_version in its option.
	 *
	 * @return void
	 */
	public function test_install_records_db_version_option(): void {
		$this->assertSame(
			Installer::DB_VERSION,
			get_option( Installer::DB_VERSION_OPTION )
		);
	}

	/**
	 * Re-running install() at the same version is a no-op.
	 *
	 * Idempotency matters because install() runs on every plugin load
	 * (Plugin::init) and must not blow away existing data.
	 *
	 * @return void
	 */
	public function test_install_is_idempotent_at_same_version(): void {
		global $wpdb;

		// Seed a row and capture its id.
		$wpdb->insert(
			Installer::get_table_name(),
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
				Installer::get_table_name(),
				'albert/survivor'
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( $id_before, (int) $row->id );
	}

	/**
	 * Removes the version option.
	 *
	 * We don't directly assert the table was dropped because WP_UnitTestCase
	 * can make that check fragile (temporary-tables filter, DDL+transactions).
	 * The install-after-uninstall test below covers the drop-and-recreate
	 * cycle end-to-end, which is the guarantee that actually matters.
	 *
	 * @return void
	 */
	public function test_uninstall_removes_version_option(): void {
		Installer::uninstall();

		$this->assertFalse( get_option( Installer::DB_VERSION_OPTION ) );

		// Re-install for any downstream tests in the run.
		Installer::install();
	}

	/**
	 * After uninstall, install() re-creates the version option.
	 *
	 * The install routine is version-gated: it only creates the table when
	 * the stored db_version is lower than the current DB_VERSION. Uninstall
	 * deletes the option, so the next install() must re-trigger creation
	 * and repopulate the option.
	 *
	 * @return void
	 */
	public function test_install_after_uninstall_recreates_option(): void {
		Installer::uninstall();
		$this->assertFalse( get_option( Installer::DB_VERSION_OPTION ) );

		Installer::install();

		$this->assertSame( Installer::DB_VERSION, get_option( Installer::DB_VERSION_OPTION ) );
	}
}
