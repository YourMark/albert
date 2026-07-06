<?php
/**
 * Unit tests for Server::hide_unauthorized_tools — the MCP discovery gate that
 * hides tools the connected user can't execute from tools/list.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\MCP;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\MCP\Server;
use Albert\Vendor\WP\MCP\Core\McpServer;
use Albert\Vendor\WP\MCP\Domain\Tools\McpTool;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * @covers \Albert\MCP\Server::hide_unauthorized_tools
 */
class ServerDiscoveryGateTest extends TestCase {

	/**
	 * Reset recorded hooks and any injected filter return.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['albert_test_hooks'] = [];
		unset( $GLOBALS['albert_test_filter_returns'] );
	}

	/**
	 * A minimal tool DTO exposing getName().
	 *
	 * @param string $name Tool name.
	 * @return object
	 */
	private function tool( string $name ): object {
		return new class( $name ) {
			/**
			 * @param string $name Tool name.
			 */
			public function __construct( private string $name ) {}

			/**
			 * @return string
			 */
			public function getName(): string {
				return $this->name;
			}
		};
	}

	/**
	 * A real callable-backed McpTool whose permission callback returns a fixed
	 * result (McpTool is final and get_mcp_tool() is typed `?McpTool`, so a plain
	 * double won't satisfy the return type — build a genuine instance instead).
	 *
	 * @param string $name    Tool name.
	 * @param bool   $allowed Whether the tool is allowed.
	 * @return McpTool
	 */
	private function mcp_tool( string $name, bool $allowed ): McpTool {
		$tool = McpTool::fromArray(
			[
				'name'        => $name,
				'description' => 'Test tool.',
				'inputSchema' => [ 'type' => 'object' ],
				'handler'     => static fn() => null,
				'permission'  => static fn( $args ) => $allowed ? true : new WP_Error( 'denied', 'No.' ),
			]
		);

		$this->assertInstanceOf( McpTool::class, $tool, 'Failed to build test McpTool.' );

		return $tool;
	}

	/**
	 * Tools the connected user can't execute are dropped; allowed tools and the
	 * adapter's own meta-tools are kept.
	 *
	 * @return void
	 */
	public function test_hides_only_unauthorized_non_meta_tools(): void {
		$mcp_tools = [
			'albert/create-post' => $this->mcp_tool( 'albert/create-post', false ),
			'albert/find-posts'  => $this->mcp_tool( 'albert/find-posts', true ),
		];

		$server = $this->createMock( McpServer::class );
		$server->method( 'get_mcp_tool' )->willReturnCallback(
			static fn( string $name ) => $mcp_tools[ $name ] ?? null
		);

		$tools = [
			$this->tool( 'albert/create-post' ),          // denied → hidden
			$this->tool( 'albert/find-posts' ),           // allowed → kept
			$this->tool( 'mcp-adapter/execute-ability' ), // meta-tool → always kept
		];

		$result = ( new Server() )->hide_unauthorized_tools( $tools, $server );
		$names  = array_map( static fn( $tool ) => $tool->getName(), $result );

		$this->assertContains( 'albert/find-posts', $names );
		$this->assertContains( 'mcp-adapter/execute-ability', $names );
		$this->assertNotContains( 'albert/create-post', $names );
	}

	/**
	 * A foreign (non-Albert) MCP server firing the same global hook is left
	 * untouched — the gate only acts on Albert's own server.
	 *
	 * @return void
	 */
	public function test_ignores_non_albert_server(): void {
		$tools  = [ $this->tool( 'albert/create-post' ) ];
		$result = ( new Server() )->hide_unauthorized_tools( $tools, new \stdClass() );

		$this->assertSame( $tools, $result );
	}

	/**
	 * The gate can be disabled via the albert/mcp/hide_unauthorized_tools filter,
	 * in which case no per-tool permission check runs.
	 *
	 * @return void
	 */
	public function test_can_be_disabled_by_filter(): void {
		$GLOBALS['albert_test_filter_returns']['albert/mcp/hide_unauthorized_tools'] = false;

		$server = $this->createMock( McpServer::class );
		$server->expects( $this->never() )->method( 'get_mcp_tool' );

		$tools  = [ $this->tool( 'albert/create-post' ) ];
		$result = ( new Server() )->hide_unauthorized_tools( $tools, $server );

		$this->assertSame( $tools, $result );
	}
}
