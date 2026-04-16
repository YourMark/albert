<?php
/**
 * Integration tests for the Logger.
 *
 * Covers the filter gate (`albert/logging/enabled`) and the guarantee that
 * repository exceptions never bubble up to break ability execution.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Logging;

use Albert\Logging\Installer;
use Albert\Logging\Logger;
use Albert\Logging\Repository;
use Albert\Tests\TestCase;
use RuntimeException;

/**
 * Logger integration tests.
 *
 * @covers \Albert\Logging\Logger
 */
class LoggerTest extends TestCase {

	/**
	 * Test repository (shared with the hooked Logger).
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Reset the table before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::install();

		$this->repository = new Repository();
		$this->repository->truncate();
	}

	/**
	 * A successful ability execution writes one log row.
	 *
	 * Fires the wp_after_execute_ability action directly to verify the
	 * hooked Logger instance (registered during plugin bootstrap) writes
	 * the expected row.
	 *
	 * @return void
	 */
	public function test_hook_writes_log_row_on_ability_execution(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		do_action( 'wp_after_execute_ability', 'albert/create-post', [], [ 'id' => 1 ] );

		$row = $this->repository->latest_for_ability( 'albert/create-post' );

		$this->assertNotNull( $row );
		$this->assertSame( 'albert/create-post', $row->ability_name );
	}

	/**
	 * Returning false from `albert/logging/enabled` suppresses writes.
	 *
	 * @return void
	 */
	public function test_filter_disables_logging(): void {
		add_filter( 'albert/logging/enabled', '__return_false' );

		try {
			do_action( 'wp_after_execute_ability', 'albert/create-post', [], [ 'id' => 1 ] );
		} finally {
			remove_filter( 'albert/logging/enabled', '__return_false' );
		}

		$this->assertNull( $this->repository->latest_for_ability( 'albert/create-post' ) );
	}

	/**
	 * The filter is re-evaluated each time — flipping it during a request
	 * does not leave the logger stuck in a prior decision.
	 *
	 * @return void
	 */
	public function test_filter_is_evaluated_per_call(): void {
		// First call: disabled.
		add_filter( 'albert/logging/enabled', '__return_false' );
		do_action( 'wp_after_execute_ability', 'albert/first', [], [] );
		remove_filter( 'albert/logging/enabled', '__return_false' );

		$this->assertNull( $this->repository->latest_for_ability( 'albert/first' ) );

		// Second call: re-enabled.
		do_action( 'wp_after_execute_ability', 'albert/second', [], [] );

		$this->assertNotNull( $this->repository->latest_for_ability( 'albert/second' ) );
	}

	/**
	 * Repository exceptions are swallowed — ability execution must not break.
	 *
	 * Uses a dedicated Logger instance and a throwing Repository so the
	 * hook-registered real instance is not disturbed.
	 *
	 * @return void
	 */
	public function test_repository_exception_does_not_propagate(): void {
		$throwing_repo = new class() extends Repository {

			/**
			 * Always throws — simulates a wpdb failure during insert().
			 *
			 * @param string $ability_name Ability id.
			 * @param int    $user_id      User id.
			 *
			 * @throws RuntimeException Always.
			 */
			public function insert( string $ability_name, int $user_id ): void {
				throw new RuntimeException( 'simulated wpdb failure' );
			}
		};

		$logger = new Logger( $throwing_repo );

		// Should not throw.
		$logger->log_execution( 'albert/boom', [], [] );

		$this->assertTrue( true, 'Logger swallowed the exception.' );
	}
}
