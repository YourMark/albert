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
		$GLOBALS['albert_test_hooks'] = [];
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
}
