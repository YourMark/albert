<?php
/**
 * Integration tests for the guidance ToolCallObserver adds to a rejected call.
 *
 * These run against real WordPress: a real registered ability, real
 * `WP_Ability::execute()`, and core's real `rest_validate_value_from_schema()`.
 * The unit tests cover the observer's own branching against a stubbed
 * validator; these cover the part that must agree with core.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\MCP;

use Albert\MCP\ToolCallObserver;
use Albert\Tests\TestCase;

/**
 * Tool-call guidance, against the validator that produced the rejection.
 *
 * @since 1.4.0
 */
class ToolCallObserverTest extends TestCase {

	/**
	 * The observer under test.
	 *
	 * @var ToolCallObserver
	 */
	private ToolCallObserver $observer;

	/**
	 * Every test runs as an authenticated administrator.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		delete_option( 'albert_disabled_abilities' );
		update_option( 'albert_abilities_saved', true );

		$this->observer = new ToolCallObserver();
	}

	/**
	 * An ability that is registered and takes a required integer id.
	 *
	 * @return string The ability id.
	 */
	private function ability(): string {
		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( 'albert/view-term' ) ) {
			$this->markTestSkipped( 'albert/view-term is not registered.' );
		}

		return 'albert/view-term';
	}

	/**
	 * Run a wrongly-named parameter through both paths a client can take.
	 *
	 * @return array{direct: string, proxied: string, core: string}
	 */
	private function guidance_for_a_misspelled_parameter(): array {
		$supplied = [
			'term_id' => 76,
			'fields'  => 'all',
		];

		$raw = wp_get_ability( $this->ability() )->execute( $supplied );
		$this->assertWPError( $raw, 'Expected the Abilities API to reject an undeclared parameter.' );

		$direct = $this->observer->handle( $raw, $supplied, 'albert-view-term' );

		$proxied = $this->observer->handle(
			[
				'success' => false,
				'error'   => $raw->get_error_message(),
			],
			[
				'ability_name' => $this->ability(),
				'parameters'   => $supplied,
			],
			'mcp-adapter-execute-ability'
		);

		return [
			'core'    => $raw->get_error_message(),
			'direct'  => $direct->get_error_message(),
			'proxied' => $proxied['error'],
		];
	}

	/**
	 * The wrongly-named parameter is named, along with what is accepted.
	 *
	 * @return void
	 */
	public function test_the_wrong_parameter_name_is_named(): void {
		$guidance = $this->guidance_for_a_misspelled_parameter();

		foreach ( [ 'direct', 'proxied' ] as $path ) {
			$this->assertStringContainsString( 'Unrecognised parameters: `term_id`, `fields`.', $guidance[ $path ], $path );
			$this->assertStringContainsString( 'Accepted parameters:', $guidance[ $path ], $path );
			$this->assertStringContainsString( '`id` (integer', $guidance[ $path ], $path );
		}
	}

	/**
	 * A translated core message still produces the same guidance.
	 *
	 * Core translates every string this used to be built by matching — "has
	 * invalid input. Reason:", "is a required property of", "is not a valid
	 * property of Object" — and nl_NL ships all three, which is how the
	 * execute-ability path came to improve nothing at all on a translated site.
	 *
	 * What is asserted is the parameter names, not the whole sentence: the
	 * guidance itself is translatable like everything else Albert emits, so it
	 * may legitimately differ between locales. The names cannot — they are
	 * identifiers read off the schema and the caller's own input, and their
	 * presence is what proves the message was derived rather than parsed.
	 *
	 * @return void
	 */
	public function test_a_translated_core_message_still_names_the_parameters(): void {
		$english = $this->guidance_for_a_misspelled_parameter();

		switch_to_locale( 'nl_NL' );
		try {
			$dutch = $this->guidance_for_a_misspelled_parameter();
		} finally {
			restore_previous_locale();
		}

		if ( $dutch['core'] === $english['core'] ) {
			$this->markTestSkipped( 'nl_NL translations for core are not installed, so there is nothing to prove.' );
		}

		foreach ( [ 'direct', 'proxied' ] as $path ) {
			$this->assertStringContainsString( '`term_id`', $dutch[ $path ], $path );
			$this->assertStringContainsString( '`fields`', $dutch[ $path ], $path );
			$this->assertStringContainsString( '`id`', $dutch[ $path ], $path );

			// And it is guidance, not core's sentence handed back unchanged.
			$this->assertNotSame( $dutch['core'], $dutch[ $path ], $path );
		}
	}

	/**
	 * A meta-tool failure that is not an input rejection is left alone.
	 *
	 * Permission refusals and errors from the ability itself are reported by
	 * `execute-ability` in the same shape as a rejection, so the only thing that
	 * separates them is whether the input actually fails the schema. The input
	 * here has to be genuinely valid for the ability, or the message is improved
	 * and rightly so.
	 *
	 * @return void
	 */
	public function test_a_non_validation_meta_tool_failure_is_untouched(): void {
		$schema   = wp_get_ability( $this->ability() )->get_input_schema();
		$valid    = [
			'id'       => 1,
			'taxonomy' => 'category',
		];
		$this->assertTrue(
			rest_validate_value_from_schema( $valid, $schema, 'input' ),
			'This test only means anything while its input satisfies the real schema.'
		);

		$result = [
			'success' => false,
			'error'   => 'Sorry, you are not allowed to do that.',
		];

		$out = $this->observer->handle(
			$result,
			[
				'ability_name' => $this->ability(),
				'parameters'   => $valid,
			],
			'mcp-adapter-execute-ability'
		);

		$this->assertSame( $result, $out );
	}
}
