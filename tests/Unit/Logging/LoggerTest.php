<?php
/**
 * Unit tests for the Logger class.
 *
 * Verifies status derivation, error_code extraction, the ability_failed
 * notification hook, and the storage gate — all without touching the DB.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Logging;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__ ) . '/stubs/StubAbility.php';

use Albert\Logging\Logger;
use Albert\Logging\Repository;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Logger unit tests.
 *
 * @covers \Albert\Logging\Logger
 */
class LoggerTest extends TestCase {

	/**
	 * Captured insert calls from the spy repository.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $inserts = [];

	/**
	 * Spy repository that records insert calls without touching the DB.
	 *
	 * @var Repository
	 */
	private Repository $spy_repository;

	/**
	 * Logger under test.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Reset state before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_hooks']          = [];
		$GLOBALS['albert_test_user_id']        = 42;
		$GLOBALS['albert_test_filter_returns'] = [];

		$this->inserts = [];

		// Spy repository captures insert args and never hits the DB.
		$inserts_ref          = &$this->inserts;
		$this->spy_repository = $this->getMockBuilder( Repository::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'insert' ] )
			->getMock();

		$this->spy_repository
			->expects( $this->any() )
			->method( 'insert' )
			->willReturnCallback(
				function ( string $ability_name, int $user_id, string $status = 'success', ?string $error_code = null, array $context = [] ) use ( &$inserts_ref ): void {
					$inserts_ref[] = [
						'ability_name' => $ability_name,
						'user_id'      => $user_id,
						'status'       => $status,
						'error_code'   => $error_code,
						'context'      => $context,
					];
				}
			);

		$this->logger = new Logger( $this->spy_repository );
	}

	// ─── Success path ────────────────────────────────────────────────

	/**
	 * A successful result (plain array) inserts with status='success'.
	 *
	 * @return void
	 */
	public function test_success_result_inserts_with_status_success(): void {
		$this->logger->log_execution( 'core/posts/list', [], [ 'posts' => [] ], 42 );

		$this->assertCount( 1, $this->inserts );
		$this->assertSame( 'success', $this->inserts[0]['status'] );
		$this->assertNull( $this->inserts[0]['error_code'] );
		$this->assertSame( 42, $this->inserts[0]['user_id'] );
	}

	// ─── Error path ──────────────────────────────────────────────────

	/**
	 * A WP_Error result inserts with status='error' and the correct error_code.
	 *
	 * @return void
	 */
	public function test_wp_error_result_inserts_with_status_error_and_code(): void {
		$error = new WP_Error( 'rest_forbidden', 'You do not have permission.' );

		$this->logger->log_execution( 'core/posts/create', [], $error, 42 );

		$this->assertCount( 1, $this->inserts );
		$this->assertSame( 'error', $this->inserts[0]['status'] );
		$this->assertSame( 'rest_forbidden', $this->inserts[0]['error_code'] );
		$this->assertSame( 'You do not have permission.', $this->inserts[0]['context']['error_message'] );
	}

	/**
	 * A WP_Error result fires the albert/logging/ability_failed action.
	 *
	 * @return void
	 */
	public function test_wp_error_fires_ability_failed_action(): void {
		$error = new WP_Error( 'some_error', 'Something went wrong.' );

		$this->logger->log_execution( 'core/posts/delete', [ 'id' => 5 ], $error, 42 );

		$failed_hooks = array_filter(
			$GLOBALS['albert_test_hooks'],
			static fn( array $h ): bool => $h['type'] === 'action' && $h['hook'] === 'albert/logging/ability_failed'
		);

		$this->assertCount( 1, $failed_hooks );
		$hook = array_values( $failed_hooks )[0];
		$this->assertSame( 'core/posts/delete', $hook['args'][0] );
		$this->assertInstanceOf( WP_Error::class, $hook['args'][1] );
	}

	/**
	 * A successful result does NOT fire albert/logging/ability_failed.
	 *
	 * @return void
	 */
	public function test_success_result_does_not_fire_ability_failed_action(): void {
		$this->logger->log_execution( 'core/posts/list', [], [ 'posts' => [] ], 42 );

		$failed_hooks = array_filter(
			$GLOBALS['albert_test_hooks'],
			static fn( array $h ): bool => $h['hook'] === 'albert/logging/ability_failed'
		);

		$this->assertCount( 0, $failed_hooks );
	}

	// ─── Storage gate ────────────────────────────────────────────────

	/**
	 * When albert/logging/enabled returns false, no insert is made.
	 *
	 * @return void
	 */
	public function test_storage_gate_suppresses_insert_when_disabled(): void {
		$GLOBALS['albert_test_filter_returns']['albert/logging/enabled'] = false;

		$this->logger->log_execution( 'core/posts/list', [], [ 'ok' => true ], 42 );

		$this->assertCount( 0, $this->inserts );
	}

	/**
	 * When albert/logging/enabled returns false, ability_failed still fires for WP_Error.
	 *
	 * The notification hook is decoupled from the storage gate so Premium always
	 * receives the signal even when Free's writes are suppressed.
	 *
	 * @return void
	 */
	public function test_ability_failed_fires_even_when_storage_gate_is_off(): void {
		$GLOBALS['albert_test_filter_returns']['albert/logging/enabled'] = false;

		$error = new WP_Error( 'some_code', 'msg' );
		$this->logger->log_execution( 'core/posts/delete', [], $error, 42 );

		// Insert must NOT have been called.
		$this->assertCount( 0, $this->inserts );

		// But the notification action MUST have fired.
		$failed_hooks = array_filter(
			$GLOBALS['albert_test_hooks'],
			static fn( array $h ): bool => $h['hook'] === 'albert/logging/ability_failed'
		);
		$this->assertCount( 1, $failed_hooks );
	}

	// ─── Exception safety ────────────────────────────────────────────

	/**
	 * A throwing repository does not propagate the exception.
	 *
	 * @return void
	 */
	public function test_repository_exception_is_swallowed(): void {
		$throwing_repo = $this->getMockBuilder( Repository::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'insert' ] )
			->getMock();

		$throwing_repo
			->method( 'insert' )
			->willThrowException( new \RuntimeException( 'DB is down' ) );

		$logger = new Logger( $throwing_repo );

		// Must not throw.
		$logger->log_execution( 'core/posts/list', [], [ 'ok' => true ], 1 );

		$this->assertTrue( true, 'Logger swallowed the repository exception.' );
	}
}
