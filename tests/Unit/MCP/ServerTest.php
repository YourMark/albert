<?php
/**
 * Unit tests for the MCP Server.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\MCP;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\MCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Server unit tests.
 *
 * @covers \Albert\MCP\Server
 */
class ServerTest extends TestCase {

	/**
	 * create_server() is bound to the global `mcp_adapter_init` action, which is
	 * fired by every loaded MCP-adapter copy — including the unscoped `WP\MCP\…`
	 * one WooCommerce bundles. Handed a foreign adapter, it must bail without a
	 * TypeError (regression: a foreign adapter previously fatal'd the strict type
	 * hint) and without trying to build a server against it.
	 *
	 * @return void
	 */
	public function test_create_server_ignores_a_foreign_adapter(): void {
		// stdClass stands in for WooCommerce's unscoped WP\MCP\Core\McpAdapter:
		// not our Mozart-scoped instance, and has no create_server() method — so if
		// the guard failed, we'd get a TypeError or a call-to-undefined-method.
		$foreign = new \stdClass();

		( new Server() )->create_server( $foreign );

		// Reaching here means it returned early instead of fataling.
		$this->expectNotToPerformAssertions();
	}
}
