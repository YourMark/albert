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

namespace Albert\Tests\Unit\MCP;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__, 2 ) . '/wp-function-stubs.php';
require_once dirname( __DIR__ ) . '/stubs/function-exists-shadow.php';

use Albert\MCP\SchemaPreparer;
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
		$result = ( new SchemaPreparer() )->prepare_tools_list( [ $tool ] );

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
		$result = ( new SchemaPreparer() )->prepare_tools_list( [ $tool ] );

		$this->assertSame( $tool, $result[0] );
	}

	/**
	 * A non-array tools value (e.g. a foreign filter short-circuit) is passed through.
	 *
	 * @return void
	 */
	public function test_tools_list_ignores_non_array(): void {
		$this->set_71( true );

		$this->assertSame( 'unexpected', ( new SchemaPreparer() )->prepare_tools_list( 'unexpected' ) );
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

		$prepared = ( new SchemaPreparer() )->prepare_ability_info_result( $result, [], 'mcp-adapter/get-ability-info' );

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
		$prepared = ( new SchemaPreparer() )->prepare_ability_info_result( $result, [], 'albert/find-posts' );

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
		$prepared = ( new SchemaPreparer() )->prepare_ability_info_result( $error, [], 'mcp-adapter/get-ability-info' );

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
		$prepared = ( new SchemaPreparer() )->prepare_ability_info_result( $result, [], 'mcp-adapter/get-ability-info' );

		$this->assertSame( $result, $prepared );
	}
}
