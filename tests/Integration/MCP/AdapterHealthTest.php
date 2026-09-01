<?php
/**
 * Integration tests for MCP adapter availability reporting.
 *
 * The failure these guard against is not a wrong value, it is silence. With no
 * usable MCP library nothing registers a server and `/albert/v1/mcp` answers
 * `401 rest_forbidden` — which is also the correct answer for a healthy install
 * handed no token. Nothing outside the site tells those apart, so whoever is
 * debugging it is looking at their token.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\MCP;

use Albert\MCP\AdapterHealth;
use Albert\MCP\AdapterStatus;
use Albert\Tests\TestCase;

/**
 * Adapter health reporting tests.
 *
 * @covers \Albert\MCP\AdapterHealth
 * @covers \Albert\MCP\AdapterStatus
 */
class AdapterHealthTest extends TestCase {

	/**
	 * A correct install has every class Albert calls into.
	 *
	 * If this fails, the MCP library is absent or older than Albert needs —
	 * which is the exact state that produces the misleading 401.
	 *
	 * @return void
	 */
	public function test_the_adapter_is_usable_in_a_correct_install(): void {
		$this->assertSame(
			[],
			AdapterStatus::missing_classes(),
			'The MCP library is missing classes Albert calls. Run `composer install`.'
		);
		$this->assertTrue( AdapterStatus::adapter_available() );
		$this->assertTrue( AdapterStatus::adapter_present() );
	}

	/**
	 * The library is loaded unscoped, which is what makes sharing possible.
	 *
	 * Albert used to Mozart-scope this into `Albert\Vendor\WP\MCP\`. That is
	 * the one arrangement upstream does not support, because the adapter
	 * coordinates through global hook names and a fixed server id that scoping
	 * cannot rewrite. If the scoped class ever comes back, the collision comes
	 * back with it.
	 *
	 * @return void
	 */
	public function test_the_adapter_is_not_namespace_scoped(): void {
		$this->assertTrue( class_exists( \WP\MCP\Core\McpAdapter::class ) );
		$this->assertFalse(
			class_exists( 'Albert\\Vendor\\WP\\MCP\\Core\\McpAdapter' ),
			'A Mozart-scoped copy has returned; that is what caused duplicate_server_id.'
		);
	}

	/**
	 * Exactly one default server is registered, on one adapter.
	 *
	 * The collision this replaced showed up as a second
	 * `mcp-adapter-default-server` registration on the same instance. With a
	 * single shared copy there is one adapter and one firing, so it cannot
	 * recur — this asserts that rather than trusting it.
	 *
	 * @return void
	 */
	public function test_only_one_adapter_class_is_loaded(): void {
		$loaded = array_values(
			array_filter(
				get_declared_classes(),
				static fn( string $name ): bool => str_ends_with( $name, '\\MCP\\Core\\McpAdapter' )
			)
		);

		$this->assertCount( 1, $loaded, 'More than one McpAdapter class is loaded: ' . implode( ', ', $loaded ) );
	}

	/**
	 * A correct install reports good.
	 *
	 * @return void
	 */
	public function test_site_health_reports_good_when_nothing_is_wrong(): void {
		$result = ( new AdapterHealth() )->run_test();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'albert_mcp_adapter', $result['test'] );
		$this->assertNotEmpty( $result['label'] );
	}

	/**
	 * The Site Health test is registered where WordPress will run it.
	 *
	 * @return void
	 */
	public function test_the_site_health_test_is_registered(): void {
		$tests = ( new AdapterHealth() )->add_test(
			[
				'direct' => [],
				'async'  => [],
			]
		);

		$this->assertArrayHasKey( 'albert_mcp_adapter', $tests['direct'] );
		$this->assertIsCallable( $tests['direct']['albert_mcp_adapter']['test'] );
	}

	/**
	 * The debug report names where the library came from.
	 *
	 * Under a shared copy that may legitimately be another plugin's directory,
	 * which is precisely the fact worth having in a support paste.
	 *
	 * @return void
	 */
	public function test_debug_information_reports_where_the_library_loaded_from(): void {
		$info = ( new AdapterHealth() )->add_debug_information( [] );

		$this->assertArrayHasKey( 'albert', $info );

		$fields = $info['albert']['fields'];

		$this->assertArrayHasKey( 'mcp_library', $fields );
		$this->assertArrayHasKey( 'mcp_loaded_at', $fields );
		$this->assertArrayHasKey( 'mcp_missing', $fields );
		$this->assertNotEmpty( $fields['mcp_loaded_at']['value'] );
	}
}
