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
	 * The shipped top-sellers prompt needs the WooCommerce add-on, and says so
	 * by naming the add-on's own ability.
	 *
	 * The concrete case behind the test above, asserted against the real
	 * defaults rather than a fixture, because it is the shipped list that was
	 * wrong. It used to name Free's `woo-find-orders` and `woo-find-products`,
	 * which between them list orders and list products and cannot join the two,
	 * so the prompt appeared on any shop and answered properly on none.
	 *
	 * Gated on the ability being registered rather than on
	 * `class_exists( 'WooCommerce' )`. That is both the real rule and the safe
	 * assertion: the suite runs a WooCommerce matrix as well as a plain one, so
	 * an environment precondition written as an assertion fails on the
	 * legitimate half and reports a broken test as a broken product. This one
	 * asks the same question the code asks, so it cannot be wrong on either leg.
	 *
	 * @return void
	 */
	public function test_the_top_sellers_prompt_needs_the_woocommerce_addon(): void {
		update_option( 'albert_disabled_abilities', [] );

		$texts           = array_column( ( new Suggestions() )->all(), 'text' );
		$offered         = false;
		$has_top_sellers = array_key_exists(
			'albert-woocommerce/view-top-sellers',
			\Albert\Core\AbilitiesRegistry::get_all_raw()
		);

		foreach ( $texts as $text ) {
			if ( str_contains( $text, 'products sold' ) ) {
				$offered = true;
			}
		}

		$this->assertSame(
			$has_top_sellers,
			$offered,
			'The prompt must track the add-on ability it names, in both directions.'
		);
	}

	/**
	 * A shop running Free alone still gets a commerce prompt, and one its own
	 * abilities can answer.
	 *
	 * Gating the top-sellers prompt on the add-on would otherwise leave a shop
	 * without it with nothing commerce-shaped on the card at all, which reads
	 * as Albert not knowing it is a shop. `woo-find-orders` filters on a date
	 * range and returns each order's total, so this one is answerable on its
	 * own.
	 *
	 * @return void
	 */
	public function test_a_shop_on_free_alone_still_gets_a_commerce_prompt(): void {
		update_option( 'albert_disabled_abilities', [] );

		$commerce = array_values(
			array_filter(
				( new Suggestions() )->all(),
				static fn ( array $prompt ): bool => in_array(
					'albert/woo-find-orders',
					$prompt['requires'] ?? [],
					true
				)
			)
		);

		if ( ! array_key_exists( 'albert/woo-find-orders', \Albert\Core\AbilitiesRegistry::get_all_raw() ) ) {
			$this->assertSame( [], $commerce, 'No WooCommerce, no commerce prompt.' );

			return;
		}

		$this->assertNotEmpty( $commerce );
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
