<?php
/**
 * Unit tests for ConnectionContext — the request-scoped OAuth connection holder.
 *
 * Covers the request-scoped client-id state and the lazy, snapshotted
 * client-name resolution (backed by a minimal in-memory $wpdb fake).
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\OAuth\Server;

require_once dirname( __DIR__, 2 ) . '/stubs/wordpress.php';

use Albert\OAuth\Server\ConnectionContext;
use PHPUnit\Framework\TestCase;

/**
 * ConnectionContext unit tests.
 *
 * @covers \Albert\OAuth\Server\ConnectionContext
 */
class ConnectionContextTest extends TestCase {

	/**
	 * Reset the static holder and install a fresh $wpdb fake before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ConnectionContext::reset();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture for $wpdb.
		$GLOBALS['wpdb'] = $this->make_wpdb( [ 'albert_abc' => 'Claude Desktop' ] );
	}

	/**
	 * Clean up the static holder after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		ConnectionContext::reset();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * A minimal $wpdb fake resolving client ids to names.
	 *
	 * @param array<string, string> $clients Map of client_id => name.
	 *
	 * @return object The fake.
	 */
	private function make_wpdb( array $clients ): object {
		return new class( $clients ) {
			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public string $prefix = 'wp_';

			/**
			 * Map of client_id => name.
			 *
			 * @var array<string, string>
			 */
			private array $clients;

			/**
			 * Number of get_var() lookups performed.
			 *
			 * @var int
			 */
			public int $queries = 0;

			/**
			 * Construct with a client_id => name map.
			 *
			 * @param array<string, string> $clients Map of client_id => name.
			 */
			public function __construct( array $clients ) {
				$this->clients = $clients;
			}

			/**
			 * Stand-in for $wpdb->prepare(): returns the client_id argument.
			 *
			 * @param string $query Query template.
			 * @param mixed  ...$args Bound arguments ($table, $client_id).
			 *
			 * @return string
			 */
			public function prepare( string $query, ...$args ): string {
				return (string) ( $args[1] ?? '' );
			}

			/**
			 * Stand-in for $wpdb->get_var(): resolves the prepared client_id.
			 *
			 * @param string $client_id The client id (returned by prepare()).
			 *
			 * @return string|null
			 */
			public function get_var( string $client_id ): ?string {
				++$this->queries;
				return $this->clients[ $client_id ] ?? null;
			}
		};
	}

	/**
	 * Unset state returns null for both accessors.
	 *
	 * @return void
	 */
	public function test_empty_by_default(): void {
		$this->assertNull( ConnectionContext::client_id() );
		$this->assertNull( ConnectionContext::client_name() );
	}

	/**
	 * Setting a client id exposes it and resolves a snapshot name.
	 *
	 * @return void
	 */
	public function test_set_exposes_id_and_resolves_name(): void {
		ConnectionContext::set( 'albert_abc' );

		$this->assertSame( 'albert_abc', ConnectionContext::client_id() );
		$this->assertSame( 'Claude Desktop', ConnectionContext::client_name() );
	}

	/**
	 * Name resolution is lazy and cached (one query for repeated reads).
	 *
	 * @return void
	 */
	public function test_name_resolution_is_lazy_and_cached(): void {
		ConnectionContext::set( 'albert_abc' );

		$this->assertSame( 0, $GLOBALS['wpdb']->queries, 'Setting must not query.' );

		ConnectionContext::client_name();
		ConnectionContext::client_name();

		$this->assertSame( 1, $GLOBALS['wpdb']->queries, 'Name must resolve once and cache.' );
	}

	/**
	 * Null/empty ids clear the holder; unknown ids resolve to a null name.
	 *
	 * @return void
	 */
	public function test_null_and_unknown_handling(): void {
		ConnectionContext::set( '' );
		$this->assertNull( ConnectionContext::client_id() );

		ConnectionContext::set( 'albert_unknown' );
		$this->assertSame( 'albert_unknown', ConnectionContext::client_id() );
		$this->assertNull( ConnectionContext::client_name(), 'Unknown client resolves to null name.' );
	}

	/**
	 * Changing the client id invalidates the previously snapshotted name.
	 *
	 * @return void
	 */
	public function test_changing_id_reresolves_name(): void {
		ConnectionContext::set( 'albert_abc' );
		$this->assertSame( 'Claude Desktop', ConnectionContext::client_name() );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture for $wpdb.
		$GLOBALS['wpdb'] = $this->make_wpdb( [ 'albert_xyz' => 'Claude Code' ] );
		ConnectionContext::set( 'albert_xyz' );

		$this->assertSame( 'Claude Code', ConnectionContext::client_name() );
	}

	/**
	 * Resetting clears all state.
	 *
	 * @return void
	 */
	public function test_reset_clears_state(): void {
		ConnectionContext::set( 'albert_abc' );
		ConnectionContext::reset();

		$this->assertNull( ConnectionContext::client_id() );
		$this->assertNull( ConnectionContext::client_name() );
	}
}
