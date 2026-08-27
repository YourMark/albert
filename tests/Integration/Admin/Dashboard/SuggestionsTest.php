<?php
/**
 * Integration tests for the Dashboard's prompt suggestions.
 *
 * The promise this card makes is narrow and worth keeping: every prompt on it
 * works on this site today. That only holds while each one is checked against
 * the abilities it needs, so these tests guard the check rather than the copy.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Admin\Dashboard;

use Albert\Admin\Dashboard\Suggestions;
use Albert\Tests\TestCase;

/**
 * Prompt suggestion tests.
 *
 * @covers \Albert\Admin\Dashboard\Suggestions
 */
class SuggestionsTest extends TestCase {

	/**
	 * Drop anything a test registered.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'albert/dashboard/suggestions' );

		parent::tear_down();
	}

	/**
	 * Register one prompt and nothing else, so a test sees only its own.
	 *
	 * @param array<int, string> $requires Ability ids the prompt needs.
	 *
	 * @return void
	 */
	private function only_prompt( array $requires ): void {
		add_filter(
			'albert/dashboard/suggestions',
			static fn (): array => [
				[
					'text'     => 'Ask the thing.',
					'requires' => $requires,
				],
			]
		);
	}

	/**
	 * A prompt whose abilities are all enabled is offered.
	 *
	 * @return void
	 */
	public function test_a_prompt_is_offered_when_its_abilities_are_enabled(): void {
		update_option( 'albert_disabled_abilities', [] );
		$this->only_prompt( [ 'albert/find-pages' ] );

		$this->assertCount( 1, ( new Suggestions() )->all() );
	}

	/**
	 * A prompt is withheld when any ability it needs is switched off.
	 *
	 * Withheld rather than shown-and-broken: sending somebody to an assistant
	 * that will refuse is worse than showing them one prompt fewer.
	 *
	 * @return void
	 */
	public function test_a_prompt_is_withheld_when_an_ability_is_off(): void {
		update_option( 'albert_disabled_abilities', [ 'albert/find-pages' ] );
		update_option( 'albert_abilities_saved', true );

		$this->only_prompt( [ 'albert/find-pages' ] );
		$this->assertSame( [], ( new Suggestions() )->all() );

		// And when it needs two, one being off is enough to withhold it.
		$this->only_prompt( [ 'albert/find-posts', 'albert/find-pages' ] );
		$this->assertSame( [], ( new Suggestions() )->all() );

		delete_option( 'albert_disabled_abilities' );
	}

	/**
	 * The list is capped, so the card cannot become a wall of text.
	 *
	 * @return void
	 */
	public function test_the_list_is_capped(): void {
		update_option( 'albert_disabled_abilities', [] );

		add_filter(
			'albert/dashboard/suggestions',
			static function (): array {
				$prompts = [];

				for ( $i = 0; $i < 12; $i++ ) {
					$prompts[] = [
						'text'     => 'Prompt ' . $i,
						'requires' => [],
					];
				}

				return $prompts;
			}
		);

		$this->assertLessThanOrEqual( 3, count( ( new Suggestions() )->all() ) );
	}

	/**
	 * Malformed entries are dropped rather than rendered as blanks.
	 *
	 * @return void
	 */
	public function test_malformed_entries_are_dropped(): void {
		add_filter(
			'albert/dashboard/suggestions',
			static fn (): array => [
				[ 'requires' => [] ],
				[ 'text' => '' ],
				'not an array',
				[
					'text'     => 'The only good one.',
					'requires' => [],
				],
			]
		);

		$prompts = ( new Suggestions() )->all();

		$this->assertCount( 1, $prompts );
		$this->assertSame( 'The only good one.', $prompts[0]['text'] );
	}
}
