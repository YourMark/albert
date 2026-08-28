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
	 * A prompt needing an ability this site does not have is withheld, even
	 * though nothing has switched that ability off.
	 *
	 * The regression this guards is the whole card's promise failing open.
	 * `AbilitiesState` answers from a blocklist, so an ability that was never
	 * registered has never been disabled and reads as enabled, which put
	 * "Which products sold best last month?" at the top of the card on every
	 * site without WooCommerce.
	 *
	 * @return void
	 */
	public function test_a_prompt_is_withheld_when_its_ability_is_not_registered(): void {
		update_option( 'albert_disabled_abilities', [] );

		$this->assertTrue(
			\Albert\Core\AbilitiesState::is_enabled( 'albert/nothing-registers-this' ),
			'An unregistered ability reads as enabled; that is what makes the registry check necessary.'
		);

		$this->only_prompt( [ 'albert/nothing-registers-this' ] );

		$this->assertSame( [], ( new Suggestions() )->all() );
	}

	/**
	 * The shipped WooCommerce prompt does not appear without WooCommerce.
	 *
	 * The concrete case behind the test above, asserted against the real
	 * defaults rather than a fixture, because it is the shipped list that was
	 * wrong.
	 *
	 * Skipped rather than asserted when WooCommerce is present. The suite runs
	 * on a WooCommerce matrix as well as a plain one, and an environment
	 * precondition written as an assertion fails on the legitimate half: that
	 * reports a broken test as a broken product. The presence half of the
	 * contract is covered by
	 * {@see self::test_a_prompt_is_offered_when_its_abilities_are_enabled()},
	 * which registers the abilities it needs rather than depending on which
	 * plugins the runner happens to have.
	 *
	 * @return void
	 */
	public function test_the_shipped_woocommerce_prompt_is_absent_without_woocommerce(): void {
		if ( class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is active, so the prompt is legitimately offered.' );
		}

		update_option( 'albert_disabled_abilities', [] );

		$texts = array_column( ( new Suggestions() )->all(), 'text' );

		foreach ( $texts as $text ) {
			$this->assertStringNotContainsString( 'products sold', $text );
		}
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
