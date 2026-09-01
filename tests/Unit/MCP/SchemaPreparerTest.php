<?php
/**
 * Unit tests for SchemaPreparer — client-facing JSON Schema preparation.
 *
 * SchemaPreparer runs outgoing schemas through wp_prepare_json_schema_for_client()
 * on WordPress 7.1+. That function does not exist on the test runner, so it is
 * shadowed here inside the Albert\MCP namespace (a faithful stand-in that strips
 * the same server-only keys), while WpCompat's 7.1 detection is toggled through
 * the shared function_exists shadow.
 *
 * @package Albert\Tests\Unit\MCP
 */

namespace Albert\MCP;

/**
 * Namespaced shadow of the 7.1-only wp_prepare_json_schema_for_client().
 *
 * Mirrors the real behaviour the live 7.1 check confirmed: recursively remove
 * the server-only keys, leaving client-relevant ones in place.
 *
 * @param array<string, mixed> $schema Schema to prepare.
 * @return array<string, mixed>
 */
function wp_prepare_json_schema_for_client( array $schema ): array {
	$server_only = [ 'sanitize_callback', 'validate_callback', 'arg_options', 'readonly' ];

	$walk = static function ( $node ) use ( &$walk, $server_only ) {
		if ( ! is_array( $node ) ) {
			return $node;
		}
		foreach ( $server_only as $key ) {
			unset( $node[ $key ] );
		}
		foreach ( $node as $key => $value ) {
			if ( is_array( $value ) ) {
				$node[ $key ] = $walk( $value );
			}
		}
		return $node;
	};

	return $walk( $schema );
}

// phpcs:ignore Universal.Namespaces.OneDeclarationPerFile.MultipleFound -- The block above declares a stub inside the vendored MCP namespace, which has to be in the same file as the test that needs it: the stub must exist before the class under test is autoloaded.
namespace Albert\Tests\Unit\MCP;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__, 2 ) . '/wp-function-stubs.php';
require_once dirname( __DIR__ ) . '/stubs/function-exists-shadow.php';

use Albert\MCP\SchemaPreparer;
use Albert\Vendor\WP\MCP\Core\McpServer;
use Albert\Vendor\WP\McpSchema\Server\Tools\DTO\Tool;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * SchemaPreparer tests.
 *
 * @covers \Albert\MCP\SchemaPreparer
 */
class SchemaPreparerTest extends TestCase {

	/**
	 * A schema whose input parameter carries server-only keys.
	 *
	 * @return array<string, mixed>
	 */
	private function schema_with_server_keys(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'title' => [
					'type'              => 'string',
					'description'       => 'The title',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => 'rest_validate_value_from_schema',
					'arg_options'       => [ 'sanitize_callback' => 'x' ],
				],
			],
			'required'   => [ 'title' ],
		];
	}

	/**
	 * Make WpCompat report the 7.1 schema-prep capability as present/absent.
	 *
	 * @param bool $available Whether the 7.1 function should be seen.
	 * @return void
	 */
	private function set_71( bool $available ): void {
		$GLOBALS['albert_test_fn_exists']['wp_prepare_json_schema_for_client'] = $available;
	}

	/**
	 * A stand-in for Albert's own MCP server, which the filters require before
	 * acting (so they never touch another plugin's MCP server).
	 *
	 * @return McpServer
	 */
	private function server(): McpServer {
		return $this->createMock( McpServer::class );
	}

	/**
	 * Reset capability overrides before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['albert_test_fn_exists'] = [];
	}

	/**
	 * Clear overrides after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['albert_test_fn_exists'] );
		parent::tearDown();
	}

	/**
	 * On 7.1 the tools/list schemas are stripped, and each item stays a Tool DTO.
	 *
	 * @return void
	 */
	public function test_tools_list_strips_server_keys_on_71(): void {
		$this->set_71( true );

		$tool   = Tool::fromArray(
			[
				'name'        => 'albert/demo',
				'inputSchema' => $this->schema_with_server_keys(),
			]
		);
		$result = ( new SchemaPreparer() )->prepare_tools_list( [ $tool ], $this->server() );

		$this->assertInstanceOf( Tool::class, $result[0] );

		$title = json_decode( (string) wp_json_encode( $result[0]->toArray() ), true )['inputSchema']['properties']['title'];
		$this->assertArrayNotHasKey( 'sanitize_callback', $title );
		$this->assertArrayNotHasKey( 'validate_callback', $title );
		$this->assertArrayNotHasKey( 'arg_options', $title );
		$this->assertSame( 'string', $title['type'] );
		$this->assertSame( 'The title', $title['description'] );
	}

	/**
	 * Below 7.1 the tools list is returned untouched.
	 *
	 * @return void
	 */
	public function test_tools_list_passthrough_below_71(): void {
		$this->set_71( false );

		$tool   = Tool::fromArray(
			[
				'name'        => 'albert/demo',
				'inputSchema' => $this->schema_with_server_keys(),
			]
		);
		$result = ( new SchemaPreparer() )->prepare_tools_list( [ $tool ], $this->server() );

		$this->assertSame( $tool, $result[0] );
	}

	/**
	 * A non-array tools value (e.g. a foreign filter short-circuit) is passed through.
	 *
	 * @return void
	 */
	public function test_tools_list_ignores_non_array(): void {
		$this->set_71( true );

		$this->assertSame( 'unexpected', ( new SchemaPreparer() )->prepare_tools_list( 'unexpected', $this->server() ) );
	}

	/**
	 * On 7.1 the get-ability-info result has both embedded schemas stripped.
	 *
	 * @return void
	 */
	public function test_ability_info_strips_both_schemas_on_71(): void {
		$this->set_71( true );

		$result = [
			'name'          => 'albert/demo',
			'input_schema'  => $this->schema_with_server_keys(),
			'output_schema' => $this->schema_with_server_keys(),
		];

		$prepared = ( new SchemaPreparer() )->prepare_ability_info_result( $result, [], 'mcp-adapter/get-ability-info', null, $this->server() );

		$this->assertArrayNotHasKey( 'sanitize_callback', $prepared['input_schema']['properties']['title'] );
		$this->assertArrayNotHasKey( 'validate_callback', $prepared['output_schema']['properties']['title'] );
	}

	/**
	 * A tool other than get-ability-info is passed through untouched.
	 *
	 * @return void
	 */
	public function test_ability_info_ignores_other_tools(): void {
		$this->set_71( true );

		$result   = [ 'input_schema' => $this->schema_with_server_keys() ];
		$prepared = ( new SchemaPreparer() )->prepare_ability_info_result( $result, [], 'albert/find-posts', null, $this->server() );

		$this->assertSame( $result, $prepared );
	}

	/**
	 * A WP_Error result (a failed call) is passed through untouched.
	 *
	 * @return void
	 */
	public function test_ability_info_passes_through_wp_error(): void {
		$this->set_71( true );

		$error    = new WP_Error( 'nope', 'No.' );
		$prepared = ( new SchemaPreparer() )->prepare_ability_info_result( $error, [], 'mcp-adapter/get-ability-info', null, $this->server() );

		$this->assertSame( $error, $prepared );
	}

	/**
	 * Below 7.1 the get-ability-info result is returned untouched.
	 *
	 * @return void
	 */
	public function test_ability_info_passthrough_below_71(): void {
		$this->set_71( false );

		$result   = [ 'input_schema' => $this->schema_with_server_keys() ];
		$prepared = ( new SchemaPreparer() )->prepare_ability_info_result( $result, [], 'mcp-adapter/get-ability-info', null, $this->server() );

		$this->assertSame( $result, $prepared );
	}

	/**
	 * Both filters ignore a call from another plugin's MCP server (or none),
	 * so Albert never mutates a foreign server's tools or results.
	 *
	 * @return void
	 */
	public function test_ignores_foreign_or_missing_server(): void {
		$this->set_71( true );

		$tool    = Tool::fromArray(
			[
				'name'        => 'albert/demo',
				'inputSchema' => $this->schema_with_server_keys(),
			]
		);
		$foreign = new \stdClass();

		// Foreign server: tools list untouched (same Tool instance back).
		$tools = ( new SchemaPreparer() )->prepare_tools_list( [ $tool ], $foreign );
		$this->assertSame( $tool, $tools[0] );

		// Missing server: get-ability-info result untouched.
		$result   = [ 'input_schema' => $this->schema_with_server_keys() ];
		$prepared = ( new SchemaPreparer() )->prepare_ability_info_result( $result, [], 'mcp-adapter/get-ability-info', null, null );
		$this->assertSame( $result, $prepared );
	}

	/**
	 * A schema value that cannot be JSON-encoded (e.g. a callback closure) must
	 * not make preparation fail open and return the raw, unstripped schema.
	 *
	 * @return void
	 */
	public function test_does_not_fail_open_on_unencodable_value(): void {
		$this->set_71( true );

		$schema = [
			'type'       => 'object',
			'properties' => [
				'title' => [
					'type'              => 'string',
					'sanitize_callback' => static function () {},
				],
			],
		];

		$prepared = ( new SchemaPreparer() )->prepare_ability_info_result(
			[ 'input_schema' => $schema ],
			[],
			'mcp-adapter/get-ability-info',
			null,
			$this->server()
		);

		$this->assertArrayNotHasKey( 'sanitize_callback', $prepared['input_schema']['properties']['title'] );
		$this->assertSame( 'string', $prepared['input_schema']['properties']['title']['type'] );
	}

	/**
	 * A nested empty object map (`properties: {}`) must survive as an object,
	 * not collapse to an array that would serialise as invalid `[]`.
	 *
	 * @return void
	 */
	public function test_empty_nested_object_map_stays_object(): void {
		$this->set_71( true );

		$schema = [
			'type'       => 'object',
			'properties' => [
				'meta' => [
					'type'       => 'object',
					'properties' => [],
				],
			],
		];

		$prepared = ( new SchemaPreparer() )->prepare_ability_info_result(
			[ 'input_schema' => $schema ],
			[],
			'mcp-adapter/get-ability-info',
			null,
			$this->server()
		);

		$meta_properties = $prepared['input_schema']['properties']['meta']['properties'];
		$this->assertInstanceOf( \stdClass::class, $meta_properties );
		$this->assertStringContainsString(
			'"properties":{}',
			(string) wp_json_encode( $prepared['input_schema']['properties']['meta'] )
		);
	}

	/**
	 * A tool with no input parameters (empty/absent `properties`) is prepared
	 * without error and still lists — the DTO rebuild must not choke on it.
	 *
	 * @return void
	 */
	public function test_tools_list_handles_zero_argument_tool(): void {
		$this->set_71( true );

		$tool   = Tool::fromArray(
			[
				'name'        => 'albert/zero-arg',
				'inputSchema' => [ 'type' => 'object' ],
			]
		);
		$result = ( new SchemaPreparer() )->prepare_tools_list( [ $tool ], $this->server() );

		$this->assertInstanceOf( Tool::class, $result[0] );
		$this->assertStringContainsString(
			'"properties":{}',
			(string) wp_json_encode( $result[0]->toArray()['inputSchema'] )
		);
	}

	/**
	 * On the tools/list path a nested empty object map is restored to `{}` and
	 * survives the Tool DTO rebuild.
	 *
	 * @return void
	 */
	public function test_tools_list_nested_empty_object_map_stays_object(): void {
		$this->set_71( true );

		$tool   = Tool::fromArray(
			[
				'name'        => 'albert/nested',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'meta' => [
							'type'       => 'object',
							'properties' => [],
						],
					],
				],
			]
		);
		$result = ( new SchemaPreparer() )->prepare_tools_list( [ $tool ], $this->server() );

		$meta = json_decode( (string) wp_json_encode( $result[0]->toArray()['inputSchema'] ), false )->properties->meta;
		$this->assertEquals( new \stdClass(), $meta->properties );
	}
}
