<?php
/**
 * Unit tests for ToolCallObserver — LLM error-message improvement + failure logging.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\MCP;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__ ) . '/stubs/WP_Ability.php';

use Albert\Logging\ExecutionLogMarker;
use Albert\MCP\Server;
use Albert\MCP\ToolCallObserver;
use PHPUnit\Framework\TestCase;
use WP\MCP\Core\McpServer;
use WP_Error;

/**
 * ToolCallObserver unit tests.
 *
 * @covers \Albert\MCP\ToolCallObserver
 */
class ToolCallObserverTest extends TestCase {

	/**
	 * Observer under test.
	 *
	 * @var ToolCallObserver
	 */
	private ToolCallObserver $observer;

	/**
	 * Reset state before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['albert_test_hooks']     = [];
		$GLOBALS['albert_test_abilities'] = [];
		ExecutionLogMarker::reset();
		$this->observer = new ToolCallObserver();
	}

	/**
	 * Build a mocked MCP server with a given id.
	 *
	 * Every plugin's server is an `instanceof McpServer` — they share one class —
	 * so a foreign server must be a real mock with a different id, not a stand-in
	 * object of another type, or the test passes against a guard that only checks
	 * the class.
	 *
	 * @param string $id The server id.
	 *
	 * @return McpServer
	 */
	private function server( string $id = Server::SERVER_ID ): McpServer {
		$server = $this->createMock( McpServer::class );
		$server->method( 'get_server_id' )->willReturn( $id );

		return $server;
	}

	/**
	 * Call the observer as the adapter does, on Albert's own server.
	 *
	 * @param mixed  $result    The tool result.
	 * @param mixed  $args      The tool arguments.
	 * @param string $tool_name The tool name.
	 * @param mixed  $mcp_tool  The adapter tool instance, if any.
	 *
	 * @return mixed
	 */
	private function handle( $result, $args, string $tool_name, $mcp_tool = null ): mixed {
		return $this->observer->handle( $result, $args, $tool_name, $mcp_tool, $this->server() );
	}

	/**
	 * Build the validation WP_Error the Abilities API produces.
	 *
	 * @param string $reason The "Reason: …" tail.
	 *
	 * @return WP_Error
	 */
	private function invalid_input( string $reason ): WP_Error {
		return new WP_Error(
			'ability_invalid_input',
			sprintf( 'Ability "albert/create-post" has invalid input. Reason: %s', $reason )
		);
	}

	/**
	 * A single missing required field becomes a concise message.
	 *
	 * @return void
	 */
	public function test_single_missing_required(): void {
		$this->register_create_post();

		$out = $this->handle(
			$this->invalid_input( 'title is a required property of input.' ),
			[ 'content' => 'x' ],
			'albert/create-post'
		);

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertStringStartsWith( 'Missing required parameter: `title`.', $out->get_error_message() );
		$this->assertSame( 'ability_invalid_input', $out->get_error_code() );
	}

	/**
	 * Multiple missing required fields are pluralised and listed.
	 *
	 * @return void
	 */
	public function test_multiple_missing_required(): void {
		$this->register_create_post( [ 'title', 'status' ] );

		$out = $this->handle(
			$this->invalid_input( 'title is a required property of input. status is a required property of input.' ),
			[],
			'albert/create-post'
		);

		$this->assertStringStartsWith( 'Missing required parameters: `title`, `status`.', $out->get_error_message() );
	}

	/**
	 * Non-required validation reasons are surfaced directly.
	 *
	 * @return void
	 */
	public function test_other_validation_reason(): void {
		$this->register_create_post();

		$out = $this->handle(
			$this->invalid_input( 'input[title] is not of type string.' ),
			[ 'title' => 123 ],
			'albert/create-post'
		);

		$this->assertStringStartsWith( 'Invalid parameters: input[title] is not of type string.', $out->get_error_message() );
	}

	/**
	 * An empty error message never reaches the LLM blank.
	 *
	 * @return void
	 */
	public function test_empty_message_fallback(): void {
		$out = $this->handle(
			new WP_Error( 'ability_invalid_input', '' ),
			[],
			'albert/create-post'
		);

		$this->assertSame( 'The "albert/create-post" operation could not be completed.', $out->get_error_message() );
	}

	/**
	 * Non-error results pass through untouched.
	 *
	 * @return void
	 */
	public function test_success_passthrough(): void {
		$result = [ 'id' => 5 ];
		$out    = $this->handle( $result, [], 'albert/create-post' );

		$this->assertSame( $result, $out );
	}

	/**
	 * Adapter meta-tools are left untouched.
	 *
	 * @return void
	 */
	public function test_meta_tool_passthrough(): void {
		$error = new WP_Error( 'whatever', 'raw' );
		$out   = $this->handle( $error, [], 'mcp-adapter/execute-ability' );

		$this->assertSame( $error, $out );
	}

	/**
	 * A genuine (non-validation) pre-execute failure fires after_execute so it
	 * gets logged.
	 *
	 * @return void
	 */
	public function test_fires_after_execute_when_unmarked(): void {
		$this->handle( new WP_Error( 'rest_cannot_create', 'Sorry, you are not allowed to create posts.' ), [ 'a' => 1 ], 'albert/create-post' );

		$fired = array_filter(
			$GLOBALS['albert_test_hooks'],
			static function ( array $h ): bool {
				return $h['hook'] === 'albert/abilities/after_execute';
			}
		);
		$this->assertCount( 1, $fired );
	}

	/**
	 * A self-correcting validation rejection still improves the message, but is
	 * NOT logged — it would only add noise to an owner-facing log.
	 *
	 * @return void
	 */
	public function test_validation_rejection_improves_message_but_is_not_logged(): void {
		$this->register_create_post();

		$out = $this->handle( $this->invalid_input( 'title is a required property of input.' ), [ 'a' => 1 ], 'albert/create-post' );

		// Message is still improved for the assistant.
		$this->assertStringStartsWith( 'Missing required parameter: `title`.', $out->get_error_message() );

		// But nothing is logged.
		$fired = array_filter(
			$GLOBALS['albert_test_hooks'],
			static function ( array $h ): bool {
				return $h['hook'] === 'albert/abilities/after_execute';
			}
		);
		$this->assertCount( 0, $fired );
	}

	/**
	 * An already-logged ability is not re-fired (no double-log).
	 *
	 * @return void
	 */
	public function test_skips_after_execute_when_marked(): void {
		ExecutionLogMarker::mark( 'albert/create-post' );

		$this->handle( new WP_Error( 'rest_cannot_create', 'Sorry.' ), [], 'albert/create-post' );

		$fired = array_filter(
			$GLOBALS['albert_test_hooks'],
			static function ( array $h ): bool {
				return $h['hook'] === 'albert/abilities/after_execute';
			}
		);
		$this->assertCount( 0, $fired );
	}

	/**
	 * Register a double shaped like albert/create-post.
	 *
	 * @param array<int, string> $required Required property names.
	 *
	 * @return void
	 */
	private function register_create_post( array $required = [ 'title' ] ): void {
		$this->register_ability(
			'albert/create-post',
			[
				'type'                 => 'object',
				'properties'           => [
					'title'   => [ 'type' => 'string' ],
					'status'  => [ 'type' => 'string' ],
					'content' => [ 'type' => 'string' ],
				],
				'required'             => $required,
				'additionalProperties' => false,
			]
		);
	}

	/**
	 * Register a test double for an ability with the given input schema.
	 *
	 * @param string               $name   Ability id.
	 * @param array<string, mixed> $schema Input schema the double reports.
	 *
	 * @return void
	 */
	private function register_ability( string $name, array $schema ): void {
		$GLOBALS['albert_test_abilities'][] = new \WP_Ability( $name, [], $schema );
	}

	/**
	 * Register a double shaped like albert/view-term.
	 *
	 * @return void
	 */
	private function register_view_term(): void {
		$this->register_ability(
			'albert/view-term',
			[
				'type'                 => 'object',
				'properties'           => [
					'id'       => [ 'type' => 'integer' ],
					'taxonomy' => [ 'type' => 'string' ],
				],
				'required'             => [ 'id' ],
				// As registered: BaseAbility::prepare_input_schema() closes
				// every object-typed input schema, so a double that leaves it
				// open is not the schema the observer meets in production.
				'additionalProperties' => false,
			]
		);
	}

	/**
	 * The validation WP_Error the Abilities API produces for view-term.
	 *
	 * @return WP_Error
	 */
	private function view_term_invalid_input(): WP_Error {
		return new WP_Error(
			'ability_invalid_input',
			'Ability "albert/view-term" has invalid input. Reason: id is a required property of input.'
		);
	}

	/**
	 * Input the schema does not recognise is named, not silently dropped.
	 *
	 * The reported case: `term_id` is what a caller reaches for after using an
	 * ability that spells it that way, and the old message named only `id`.
	 *
	 * @return void
	 */
	public function test_unrecognised_parameters_are_named(): void {
		$this->register_view_term();

		$out = $this->handle(
			$this->view_term_invalid_input(),
			[
				'term_id'  => 76,
				'taxonomy' => 'category',
				'fields'   => 'all',
			],
			'albert-view-term'
		);

		$this->assertSame(
			'Missing required parameter: `id`. Unrecognised parameters: `term_id`, `fields`. '
			. 'Accepted parameters: `id` (integer, required), `taxonomy` (string).',
			$out->get_error_message()
		);
	}

	/**
	 * With nothing unrecognised, only the accepted list is added.
	 *
	 * @return void
	 */
	public function test_accepted_parameters_listed_without_unrecognised_line(): void {
		$this->register_view_term();

		$out = $this->handle(
			$this->view_term_invalid_input(),
			[ 'taxonomy' => 'category' ],
			'albert-view-term'
		);

		$this->assertSame(
			'Missing required parameter: `id`. Accepted parameters: `id` (integer, required), `taxonomy` (string).',
			$out->get_error_message()
		);
	}

	/**
	 * An ability Albert cannot resolve keeps core's own message.
	 *
	 * The guidance is read off the registered schema, so without one there is
	 * nothing to say that is not a guess. Core's message already names the
	 * ability and the reason, so it is passed through rather than reworded from
	 * — which is also the only answer that does not depend on core's wording.
	 *
	 * @return void
	 */
	public function test_unregistered_ability_keeps_cores_message(): void {
		$raw = $this->view_term_invalid_input();

		$out = $this->handle( $raw, [ 'term_id' => 76 ], 'albert-view-term' );

		$this->assertSame( $raw->get_error_message(), $out->get_error_message() );
	}

	/**
	 * A non-required validation reason still gains the parameter guidance.
	 *
	 * @return void
	 */
	public function test_other_reason_gains_parameter_guidance(): void {
		$this->register_view_term();

		$out = $this->handle(
			new WP_Error(
				'ability_invalid_input',
				'Ability "albert/view-term" has invalid input. Reason: id is not of type integer.'
			),
			[
				'id'     => 'seventy-six',
				'fields' => 'all',
			],
			'albert-view-term'
		);

		$this->assertStringContainsString( 'Invalid parameters: input[id] is not of type integer.', $out->get_error_message() );
		$this->assertStringContainsString( 'Unrecognised parameters: `fields`.', $out->get_error_message() );
	}

	/**
	 * The failure reported inside an execute-ability result is improved too.
	 *
	 * The meta-tool calls the ability itself and reports the failure in the
	 * result body rather than as a WP_Error, so this is the path an assistant
	 * that found an ability through the meta-tools actually takes.
	 *
	 * @return void
	 */
	public function test_meta_tool_result_message_is_improved(): void {
		$this->register_view_term();

		$out = $this->handle(
			[
				'success' => false,
				'error'   => 'Ability "albert/view-term" has invalid input. Reason: id is a required property of input.',
			],
			[
				'ability_name' => 'albert/view-term',
				'parameters'   => [ 'term_id' => 76 ],
			],
			'mcp-adapter-execute-ability'
		);

		$this->assertSame(
			'Missing required parameter: `id`. Unrecognised parameters: `term_id`. '
			. 'Accepted parameters: `id` (integer, required), `taxonomy` (string).',
			$out['error']
		);
	}

	/**
	 * A meta-tool failure that is not a validation rejection is left alone.
	 *
	 * @return void
	 */
	public function test_meta_tool_result_non_validation_error_untouched(): void {
		$result = [
			'success' => false,
			'error'   => "Ability 'albert/view-term' not found",
		];

		$out = $this->handle( $result, [ 'ability_name' => 'albert/view-term' ], 'mcp-adapter-execute-ability' );

		$this->assertSame( $result, $out );
	}

	/**
	 * A successful meta-tool result is passed through.
	 *
	 * @return void
	 */
	public function test_meta_tool_success_passthrough(): void {
		$result = [
			'success' => true,
			'data'    => [ 'term' => [ 'id' => 76 ] ],
		];

		$out = $this->handle( $result, [], 'mcp-adapter-execute-ability' );

		$this->assertSame( $result, $out );
	}

	/**
	 * A failure is logged against the ability id, not the sanitised tool name.
	 *
	 * The name arriving on the filter is what the client sent — the MCP-legal
	 * spelling with a hyphen — so logging it verbatim wrote a row against an
	 * ability id that does not exist, and never matched the marker that stops a
	 * call being logged twice.
	 *
	 * @return void
	 */
	public function test_logs_against_the_ability_id(): void {
		$this->register_ability( 'albert/create-post', [ 'type' => 'object' ] );

		$this->handle(
			new WP_Error( 'rest_cannot_create', 'Sorry, you are not allowed to create posts.' ),
			[ 'a' => 1 ],
			'albert-create-post'
		);

		$fired = array_values(
			array_filter(
				$GLOBALS['albert_test_hooks'],
				static function ( array $h ): bool {
					return $h['hook'] === 'albert/abilities/after_execute';
				}
			)
		);

		$this->assertCount( 1, $fired );
		$this->assertSame( 'albert/create-post', $fired[0]['args'][0] );
	}

	/**
	 * An ability already logged this request is not logged again, even when the
	 * call came in under the sanitised tool name.
	 *
	 * @return void
	 */
	public function test_marker_matches_the_sanitised_tool_name(): void {
		$this->register_ability( 'albert/create-post', [ 'type' => 'object' ] );
		ExecutionLogMarker::mark( 'albert/create-post' );

		$this->handle( new WP_Error( 'rest_cannot_create', 'Sorry.' ), [], 'albert-create-post' );

		$fired = array_filter(
			$GLOBALS['albert_test_hooks'],
			static function ( array $h ): bool {
				return $h['hook'] === 'albert/abilities/after_execute';
			}
		);
		$this->assertCount( 0, $fired );
	}

	/**
	 * A call whose only fault is an unrecognised parameter says just that.
	 *
	 * Core stops at the first property it does not recognise and phrases it as
	 * "fields is not a valid property of Object", which names one of them and
	 * offers no alternative. The message names them all, and what is accepted.
	 *
	 * @return void
	 */
	public function test_only_unrecognised_parameters(): void {
		$this->register_view_term();

		$out = $this->handle(
			new WP_Error(
				'ability_invalid_input',
				'Ability "albert/view-term" has invalid input. Reason: fields is not a valid property of Object.'
			),
			[
				'id'     => 76,
				'fields' => 'all',
				'depth'  => 2,
			],
			'albert-view-term'
		);

		$this->assertSame(
			'Unrecognised parameters: `fields`, `depth`. '
			. 'Accepted parameters: `id` (integer, required), `taxonomy` (string).',
			$out->get_error_message()
		);
	}

	/**
	 * With no schema to diff against, core's own reason is kept rather than lost.
	 *
	 * @return void
	 */
	public function test_unrecognised_reason_kept_without_a_schema(): void {
		$raw = new WP_Error(
			'ability_invalid_input',
			'Ability "albert/view-term" has invalid input. Reason: fields is not a valid property of Object.'
		);

		$out = $this->handle( $raw, [ 'fields' => 'all' ], 'albert-view-term' );

		$this->assertSame( $raw->get_error_message(), $out->get_error_message() );
	}

	/**
	 * The guidance does not depend on the error message at all.
	 *
	 * Everything it says is read off the schema and the input, so two calls that
	 * differ only in the text WordPress happened to produce must be identical.
	 * This is the regression test for building the message by matching core's
	 * prose: core translates every string that was once matched, so on a Dutch
	 * site the guidance disappeared entirely.
	 *
	 * @return void
	 */
	public function test_guidance_ignores_the_error_message_text(): void {
		$this->register_view_term();

		$messages = [
			'Ability "albert/view-term" has invalid input. Reason: id is a required property of input.',
			'Ability "albert/view-term" heeft ongeldige invoer. Reden: id is een vereiste eigenschap van invoer.',
			'',
		];

		$guidance = array_map(
			function ( string $message ): string {
				return $this->handle(
					new WP_Error( 'ability_invalid_input', $message ),
					[ 'term_id' => 76 ],
					'albert-view-term'
				)->get_error_message();
			},
			$messages
		);

		$this->assertCount( 1, array_unique( $guidance ) );
		$this->assertStringContainsString( 'Unrecognised parameters: `term_id`.', $guidance[0] );
	}

	/**
	 * The execute-ability path ignores the message text too.
	 *
	 * This is the path the issue was reported from, and the one with no error
	 * code to fall back on — whether it was an input rejection used to be decided
	 * by matching English text, so it improved nothing once that text changed.
	 *
	 * @return void
	 */
	public function test_meta_tool_result_ignores_the_error_message_text(): void {
		$this->register_view_term();

		$out = $this->handle(
			[
				'success' => false,
				'error'   => 'Ability "albert/view-term" heeft ongeldige invoer. Reden: id is een vereiste eigenschap van invoer.',
			],
			[
				'ability_name' => 'albert/view-term',
				'parameters'   => [ 'term_id' => 76 ],
			],
			'mcp-adapter-execute-ability'
		);

		$this->assertSame(
			'Missing required parameter: `id`. Unrecognised parameters: `term_id`. '
			. 'Accepted parameters: `id` (integer, required), `taxonomy` (string).',
			$out['error']
		);
	}

	/**
	 * An undeclared name on an open schema is not called unrecognised.
	 *
	 * Naming a parameter unrecognised is a claim about the contract. A schema
	 * that opts back into extra input accepts it, so the claim would be false.
	 *
	 * @return void
	 */
	public function test_open_schema_does_not_name_extra_input_unrecognised(): void {
		$this->register_ability(
			'albert/open-term',
			[
				'type'                 => 'object',
				'properties'           => [ 'id' => [ 'type' => 'integer' ] ],
				'required'             => [ 'id' ],
				'additionalProperties' => true,
			]
		);

		$out = $this->handle(
			new WP_Error( 'ability_invalid_input', 'Ability "albert/open-term" has invalid input. Reason: id is a required property of input.' ),
			[ 'term_id' => 76 ],
			'albert-open-term'
		);

		$this->assertStringNotContainsString( 'Unrecognised', $out->get_error_message() );
		$this->assertStringContainsString( 'Missing required parameter: `id`.', $out->get_error_message() );
	}

	/**
	 * An enum is named by its values, not by the type it narrows.
	 *
	 * The value a caller gets wrong is `published` for `publish`, and being told
	 * the parameter is a `string` does nothing to correct that.
	 *
	 * @return void
	 */
	public function test_accepted_parameters_name_enum_values(): void {
		$this->register_ability(
			'albert/create-thing',
			[
				'type'                 => 'object',
				'properties'           => [
					'title'  => [ 'type' => 'string' ],
					'status' => [
						'type' => 'string',
						'enum' => [ 'draft', 'publish' ],
					],
				],
				'required'             => [ 'title' ],
				'additionalProperties' => false,
			]
		);

		$out = $this->handle(
			new WP_Error( 'ability_invalid_input', 'Ability "albert/create-thing" has invalid input. Reason: whatever.' ),
			[ 'status' => 'published' ],
			'albert-create-thing'
		);

		$this->assertStringContainsString( '`status` (draft|publish)', $out->get_error_message() );
		$this->assertStringNotContainsString( '`status` (string', $out->get_error_message() );
	}

	/**
	 * An ability that takes nothing says so, rather than saying nothing.
	 *
	 * A no-parameter ability declares `properties` as an empty map so it encodes
	 * as `{}` — which may reach us as a stdClass, and used to be read as "no
	 * schema to describe". That silenced the guidance in the one case where it
	 * is the entire answer: everything the caller sent is unrecognised.
	 *
	 * @return void
	 */
	public function test_an_ability_with_no_parameters_says_so(): void {
		$GLOBALS['albert_test_abilities'][] = new \WP_Ability(
			'albert/view-sessions',
			[],
			[
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			]
		);

		$out = $this->handle(
			new WP_Error( 'ability_invalid_input', 'Ability "albert/view-sessions" has invalid input. Reason: whatever.' ),
			[ 'limit' => 10 ],
			'albert-view-sessions'
		);

		$this->assertSame(
			'Unrecognised parameters: `limit`. This ability accepts no parameters.',
			$out->get_error_message()
		);
	}

	/**
	 * A key refused by a nested object is named with the path to it.
	 *
	 * Core's message drops the path — `company is not a valid property of
	 * Object` — so a caller cannot tell which object refused it, nor that the
	 * top level would refuse it too.
	 *
	 * @return void
	 */
	public function test_nested_unrecognised_keys_are_named_with_their_path(): void {
		$this->register_ability(
			'albert/update-order',
			[
				'type'                 => 'object',
				'properties'           => [
					'order_id' => [ 'type' => 'integer' ],
					'billing'  => [
						'type'                 => 'object',
						'properties'           => [
							'city'  => [ 'type' => 'string' ],
							'phone' => [ 'type' => 'string' ],
						],
						'additionalProperties' => false,
					],
				],
				'required'             => [ 'order_id' ],
				'additionalProperties' => false,
			]
		);

		$out = $this->handle(
			new WP_Error( 'ability_invalid_input', 'Ability "albert/update-order" has invalid input. Reason: company is not a valid property of Object.' ),
			[
				'order_id' => 1,
				'billing'  => [
					'company' => 'Acme BV',
					'city'    => 'Utrecht',
				],
			],
			'albert-update-order'
		);

		$this->assertSame(
			'Unrecognised parameters: `billing.company`. '
			. 'Accepted parameters for `billing`: `city` (string), `phone` (string).',
			$out->get_error_message()
		);
	}

	/**
	 * A root-level offender gets the root's list, not a nested one.
	 *
	 * Naming only the nested object's parameters would leave the root-level
	 * mistake named but unexplained.
	 *
	 * @return void
	 */
	public function test_offenders_at_two_levels_fall_back_to_the_root_list(): void {
		$this->register_ability(
			'albert/update-order',
			[
				'type'                 => 'object',
				'properties'           => [
					'order_id' => [ 'type' => 'integer' ],
					'billing'  => [
						'type'                 => 'object',
						'properties'           => [ 'city' => [ 'type' => 'string' ] ],
						'additionalProperties' => false,
					],
				],
				'additionalProperties' => false,
			]
		);

		$out = $this->handle(
			new WP_Error( 'ability_invalid_input', 'Ability "albert/update-order" has invalid input. Reason: whatever.' ),
			[
				'orderid' => 1,
				'billing' => [ 'company' => 'Acme BV' ],
			],
			'albert-update-order'
		);

		$message = $out->get_error_message();
		$this->assertStringContainsString( '`orderid`', $message );
		$this->assertStringContainsString( '`billing.company`', $message );
		$this->assertStringContainsString( 'Accepted parameters: `order_id` (integer), `billing` (object).', $message );
		$this->assertStringNotContainsString( 'Accepted parameters for', $message );
	}

	/**
	 * A key refused inside an array of objects carries its index.
	 *
	 * @return void
	 */
	public function test_unrecognised_keys_in_a_list_carry_their_index(): void {
		$this->register_ability(
			'albert/create-thing',
			[
				'type'                 => 'object',
				'properties'           => [
					'blocks' => [
						'type'  => 'array',
						'items' => [
							'type'                 => 'object',
							'properties'           => [ 'name' => [ 'type' => 'string' ] ],
							'additionalProperties' => false,
						],
					],
				],
				'additionalProperties' => false,
			]
		);

		$out = $this->handle(
			new WP_Error( 'ability_invalid_input', 'Ability "albert/create-thing" has invalid input. Reason: whatever.' ),
			[ 'blocks' => [ [ 'name' => 'core/paragraph' ], [ 'name' => 'core/heading', 'content' => 'Hi' ] ] ],
			'albert-create-thing'
		);

		$this->assertStringContainsString( 'Unrecognised parameters: `blocks[1].content`.', $out->get_error_message() );
	}

	/**
	 * Offenders under different indices of one array share one accepted list.
	 *
	 * `blocks[0]` and `blocks[1]` are different inputs but the same schema node,
	 * so one list explains both. Grouping on the literal path treated them as two
	 * parents and fell back to the root's list, which names neither offending key.
	 *
	 * @return void
	 */
	public function test_offenders_under_different_indices_share_one_list(): void {
		$this->register_ability(
			'albert/create-thing',
			[
				'type'                 => 'object',
				'properties'           => [
					'title'  => [ 'type' => 'string' ],
					'blocks' => [
						'type'  => 'array',
						'items' => [
							'type'                 => 'object',
							'properties'           => [ 'name' => [ 'type' => 'string' ] ],
							'additionalProperties' => false,
						],
					],
				],
				'additionalProperties' => false,
			]
		);

		$out = $this->handle(
			new WP_Error( 'ability_invalid_input', 'Ability "albert/create-thing" has invalid input. Reason: whatever.' ),
			[
				'title'  => 'T',
				'blocks' => [
					[ 'name' => 'core/paragraph', 'x' => 1 ],
					[ 'name' => 'core/heading', 'y' => 2 ],
				],
			],
			'albert-create-thing'
		);

		$message = $out->get_error_message();

		$this->assertStringContainsString( 'Unrecognised parameters: `blocks[0].x`, `blocks[1].y`.', $message );
		$this->assertStringContainsString( 'Accepted parameters for `blocks[]`: `name` (string).', $message );
		$this->assertStringNotContainsString( '`title`', $message );
	}

	/**
	 * The ability is taken from the adapter's tool, not guessed from its name.
	 *
	 * A namespace may contain hyphens of its own, so unpicking the first hyphen
	 * of a sanitised tool name is a guess. The adapter built the mapping at
	 * registration time and hands its tool to this filter, so it is asked.
	 *
	 * @return void
	 */
	public function test_ability_comes_from_the_adapter_tool(): void {
		$this->register_ability(
			'albert-extra/view-term',
			[
				'type'                 => 'object',
				'properties'           => [ 'id' => [ 'type' => 'integer' ] ],
				'required'             => [ 'id' ],
				'additionalProperties' => false,
			]
		);

		$mcp_tool = new class() {
			/**
			 * The adapter's registration-time tags.
			 *
			 * @return array<string, mixed>
			 */
			public function get_observability_context(): array {
				return [ 'ability_name' => 'albert-extra/view-term' ];
			}
		};

		$out = $this->handle(
			new WP_Error( 'ability_invalid_input', 'Ability "albert-extra/view-term" has invalid input. Reason: id is a required property of input.' ),
			[ 'term_id' => 76 ],
			'albert-extra-view-term',
			$mcp_tool
		);

		$this->assertStringContainsString( 'Unrecognised parameters: `term_id`.', $out->get_error_message() );
	}

	/**
	 * Another plugin's MCP server firing the same global filter is left alone.
	 *
	 * Its error text is not rewritten into Albert's format and, just as
	 * importantly, its failure is not fired at `albert/abilities/after_execute`,
	 * which is what puts a row in the owner-facing activity log. A call Albert
	 * never served has no business appearing there.
	 *
	 * @return void
	 */
	public function test_a_foreign_server_is_left_alone(): void {
		$this->register_create_post();

		$error = new WP_Error( 'rest_cannot_create', 'Sorry, you are not allowed to create posts.' );

		$out = $this->observer->handle(
			$error,
			[ 'a' => 1 ],
			'albert/create-post',
			null,
			$this->server( 'woocommerce' )
		);

		$this->assertSame( $error, $out );
		$this->assertSame( [], $GLOBALS['albert_test_hooks'] );
	}

	/**
	 * A foreign server's proxied meta-tool failure keeps its own error text.
	 *
	 * The meta-tool branch runs before the WP_Error check, so without the server
	 * guard this is the one path that rewrote a plain array result belonging to
	 * someone else's server.
	 *
	 * @return void
	 */
	public function test_a_foreign_servers_proxied_result_is_not_rewritten(): void {
		$result = [
			'success' => false,
			'error'   => 'Ability "someplugin/do-thing" has invalid input. Reason: whatever.',
		];

		$this->assertSame(
			$result,
			$this->observer->handle(
				$result,
				[ 'ability_name' => 'someplugin/do-thing' ],
				'mcp-adapter-execute-ability',
				null,
				$this->server( 'woocommerce' )
			)
		);
	}

	/**
	 * A server the adapter did not pass at all is treated as foreign.
	 *
	 * Albert cannot tell whose call it is, so it does not touch it.
	 *
	 * @return void
	 */
	public function test_an_unidentified_server_is_left_alone(): void {
		$this->register_create_post();

		$error = new WP_Error( 'rest_cannot_create', 'Sorry.' );

		$this->assertSame( $error, $this->observer->handle( $error, [], 'albert/create-post' ) );
		$this->assertSame( [], $GLOBALS['albert_test_hooks'] );
	}
}
