<?php
/**
 * Unit tests for ToolCallObserver — LLM error-message improvement + failure logging.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\MCP;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\Logging\ExecutionLogMarker;
use Albert\MCP\ToolCallObserver;
use PHPUnit\Framework\TestCase;
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
		$out = $this->observer->handle(
			$this->invalid_input( 'title is a required property of input.' ),
			[ 'content' => 'x' ],
			'albert/create-post'
		);

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'Missing required parameter: `title`.', $out->get_error_message() );
		$this->assertSame( 'ability_invalid_input', $out->get_error_code() );
	}

	/**
	 * Multiple missing required fields are pluralised and listed.
	 *
	 * @return void
	 */
	public function test_multiple_missing_required(): void {
		$out = $this->observer->handle(
			$this->invalid_input( 'title is a required property of input. status is a required property of input.' ),
			[],
			'albert/create-post'
		);

		$this->assertSame( 'Missing required parameters: `title`, `status`.', $out->get_error_message() );
	}

	/**
	 * Non-required validation reasons are surfaced directly.
	 *
	 * @return void
	 */
	public function test_other_validation_reason(): void {
		$out = $this->observer->handle(
			$this->invalid_input( 'status is not one of draft and publish' ),
			[],
			'albert/create-post'
		);

		$this->assertSame( 'Invalid parameters: status is not one of draft and publish.', $out->get_error_message() );
	}

	/**
	 * An empty error message never reaches the LLM blank.
	 *
	 * @return void
	 */
	public function test_empty_message_fallback(): void {
		$out = $this->observer->handle(
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
		$out    = $this->observer->handle( $result, [], 'albert/create-post' );

		$this->assertSame( $result, $out );
	}

	/**
	 * Adapter meta-tools are left untouched.
	 *
	 * @return void
	 */
	public function test_meta_tool_passthrough(): void {
		$error = new WP_Error( 'whatever', 'raw' );
		$out   = $this->observer->handle( $error, [], 'mcp-adapter/execute-ability' );

		$this->assertSame( $error, $out );
	}

	/**
	 * A genuine (non-validation) pre-execute failure fires after_execute so it
	 * gets logged.
	 *
	 * @return void
	 */
	public function test_fires_after_execute_when_unmarked(): void {
		$this->observer->handle( new WP_Error( 'rest_cannot_create', 'Sorry, you are not allowed to create posts.' ), [ 'a' => 1 ], 'albert/create-post' );

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
		$out = $this->observer->handle( $this->invalid_input( 'title is a required property of input.' ), [ 'a' => 1 ], 'albert/create-post' );

		// Message is still improved for the assistant.
		$this->assertSame( 'Missing required parameter: `title`.', $out->get_error_message() );

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

		$this->observer->handle( new WP_Error( 'rest_cannot_create', 'Sorry.' ), [], 'albert/create-post' );

		$fired = array_filter(
			$GLOBALS['albert_test_hooks'],
			static function ( array $h ): bool {
				return $h['hook'] === 'albert/abilities/after_execute';
			}
		);
		$this->assertCount( 0, $fired );
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
		$GLOBALS['albert_test_abilities'][] = new class( $name, $schema ) {
			/**
			 * Ability id.
			 *
			 * @var string
			 */
			private string $name;

			/**
			 * Input schema.
			 *
			 * @var array<string, mixed>
			 */
			private array $schema;

			/**
			 * Constructor.
			 *
			 * @param string               $name   Ability id.
			 * @param array<string, mixed> $schema Input schema.
			 */
			public function __construct( string $name, array $schema ) {
				$this->name   = $name;
				$this->schema = $schema;
			}

			/**
			 * The ability id.
			 *
			 * @return string
			 */
			public function get_name(): string {
				return $this->name;
			}

			/**
			 * The input schema.
			 *
			 * @return array<string, mixed>
			 */
			public function get_input_schema(): array {
				return $this->schema;
			}
		};
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
				'type'       => 'object',
				'properties' => [
					'id'       => [ 'type' => 'integer' ],
					'taxonomy' => [ 'type' => 'string' ],
				],
				'required'   => [ 'id' ],
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

		$out = $this->observer->handle(
			$this->view_term_invalid_input(),
			[
				'term_id'  => 76,
				'taxonomy' => 'category',
				'fields'   => 'all',
			],
			'albert-view-term'
		);

		$this->assertSame(
			'Missing required parameter: `id`. Unrecognised parameters, ignored: `term_id`, `fields`. '
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

		$out = $this->observer->handle(
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
	 * An ability Albert cannot resolve simply says less, it does not guess.
	 *
	 * @return void
	 */
	public function test_unregistered_ability_keeps_the_short_message(): void {
		$out = $this->observer->handle(
			$this->view_term_invalid_input(),
			[ 'term_id' => 76 ],
			'albert-view-term'
		);

		$this->assertSame( 'Missing required parameter: `id`.', $out->get_error_message() );
	}

	/**
	 * A non-required validation reason still gains the parameter guidance.
	 *
	 * @return void
	 */
	public function test_other_reason_gains_parameter_guidance(): void {
		$this->register_view_term();

		$out = $this->observer->handle(
			new WP_Error(
				'ability_invalid_input',
				'Ability "albert/view-term" has invalid input. Reason: id is not of type integer.'
			),
			[ 'id' => 'seventy-six', 'fields' => 'all' ],
			'albert-view-term'
		);

		$this->assertStringContainsString( 'Invalid parameters: id is not of type integer.', $out->get_error_message() );
		$this->assertStringContainsString( 'Unrecognised parameters, ignored: `fields`.', $out->get_error_message() );
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

		$out = $this->observer->handle(
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
			'Missing required parameter: `id`. Unrecognised parameters, ignored: `term_id`. '
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

		$out = $this->observer->handle( $result, [ 'ability_name' => 'albert/view-term' ], 'mcp-adapter-execute-ability' );

		$this->assertSame( $result, $out );
	}

	/**
	 * A successful meta-tool result is passed through.
	 *
	 * @return void
	 */
	public function test_meta_tool_success_passthrough(): void {
		$result = [ 'success' => true, 'data' => [ 'term' => [ 'id' => 76 ] ] ];

		$out = $this->observer->handle( $result, [], 'mcp-adapter-execute-ability' );

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

		$this->observer->handle(
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

		$this->observer->handle( new WP_Error( 'rest_cannot_create', 'Sorry.' ), [], 'albert-create-post' );

		$fired = array_filter(
			$GLOBALS['albert_test_hooks'],
			static function ( array $h ): bool {
				return $h['hook'] === 'albert/abilities/after_execute';
			}
		);
		$this->assertCount( 0, $fired );
	}
}
