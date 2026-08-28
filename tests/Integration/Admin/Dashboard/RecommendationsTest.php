<?php
/**
 * Integration tests for the Dashboard's add-on recommendations.
 *
 * The line between a useful pointer and an advert is entirely whether the site
 * runs the thing being pointed at, so that is what these tests hold. There is
 * no dismissal to test: the card is silenced by installing the add-on or by
 * removing the plugin it is about, not by a control for hiding it.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Admin\Dashboard;

use Albert\Admin\Dashboard\Recommendations;
use Albert\Tests\TestCase;

/**
 * Add-on recommendation tests.
 *
 * @covers \Albert\Admin\Dashboard\Recommendations
 */
class RecommendationsTest extends TestCase {

	/**
	 * Clear anything a test registered or dismissed.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'albert/dashboard/recommendations' );

		parent::tear_down();
	}

	/**
	 * Register a single recommendation with chosen detection symbols.
	 *
	 * @param string $host  Symbol standing in for the host plugin.
	 * @param string $addon Symbol standing in for the add-on.
	 *
	 * @return void
	 */
	private function only_addon( string $host, string $addon = '' ): void {
		add_filter(
			'albert/dashboard/recommendations',
			static fn (): array => [
				[
					'slug'         => 'test-addon',
					'name'         => 'Test add-on',
					'because'      => 'Because you run the thing',
					'detail'       => 'It does something useful.',
					'url'          => 'https://example.com/',
					'host_symbol'  => $host,
					'addon_symbol' => $addon,
				],
			]
		);
	}

	/**
	 * Recommended when the host plugin is present and the add-on is not.
	 *
	 * @return void
	 */
	public function test_recommended_when_the_host_is_active(): void {
		$this->only_addon( 'WP_Query', 'Albert\Nothing\ThatExists' );

		$addons = ( new Recommendations() )->current( 1 );

		$this->assertCount( 1, $addons );
		$this->assertSame( 'test-addon', $addons[0]['slug'] );
	}

	/**
	 * Silent when the host plugin is absent. This is the whole difference
	 * between a pointer and an advert.
	 *
	 * @return void
	 */
	public function test_silent_when_the_host_is_absent(): void {
		$this->only_addon( 'Some\Plugin\That\Is\Not\Installed' );

		$this->assertSame( [], ( new Recommendations() )->current( 1 ) );
	}

	/**
	 * Silent when the add-on is already installed. Selling somebody what they
	 * already own is the fastest way to make the screen untrustworthy.
	 *
	 * @return void
	 */
	public function test_silent_when_the_addon_is_already_installed(): void {
		$this->only_addon( 'WP_Query', 'WP_Post' );

		$this->assertSame( [], ( new Recommendations() )->current( 1 ) );
	}

	/**
	 * The list is capped, however many qualify.
	 *
	 * Two is the honest maximum: the add-on that applies to every site, and the
	 * one that applies to this one. A column of them is a shop, not a dashboard.
	 *
	 * @return void
	 */
	public function test_the_list_is_capped(): void {
		add_filter(
			'albert/dashboard/recommendations',
			static fn (): array => [
				[
					'slug'        => 'first',
					'name'        => 'First',
					'because'     => 'x',
					'detail'      => 'y',
					'url'         => 'https://example.com/',
					'host_symbol' => 'WP_Query',
				],
				[
					'slug'        => 'second',
					'name'        => 'Second',
					'because'     => 'x',
					'detail'      => 'y',
					'url'         => 'https://example.com/',
					'host_symbol' => 'WP_Query',
				],
				[
					'slug'        => 'third',
					'name'        => 'Third',
					'because'     => 'x',
					'detail'      => 'y',
					'url'         => 'https://example.com/',
					'host_symbol' => 'WP_Query',
				],
			]
		);

		$shown = ( new Recommendations() )->current( 1 );

		$this->assertCount( 1, $shown, 'Three qualifying add-ons must not produce three cards.' );
		$this->assertSame( [ 'first' ], array_column( $shown, 'slug' ) );
	}

	/**
	 * The shipped entry names the add-on's real class.
	 *
	 * Worth pinning because a wrong symbol fails silently and in the worst
	 * direction: the add-on is installed, the class check misses, and the card
	 * goes on selling somebody what they already own. The first version of this
	 * guessed `Albert\WooCommerce\...` when the plugin actually declares
	 * `namespace AlbertWooCommerce;`.
	 *
	 * Asserted as a string rather than by loading the add-on, because Free must
	 * not depend on an add-on being present. If the add-on ever renames its
	 * main class, this test is the reminder to update the pair.
	 *
	 * @return void
	 */
	public function test_the_shipped_entry_names_the_real_addon_class(): void {
		$defaults = ( new Recommendations() )->all();

		$woo = null;
		foreach ( $defaults as $addon ) {
			if ( ( $addon['slug'] ?? '' ) === 'albert-woocommerce' ) {
				$woo = $addon;
			}
		}

		$this->assertNotNull( $woo, 'Free ships the WooCommerce recommendation.' );
		$this->assertSame( 'WooCommerce', $woo['host_symbol'], 'The host check is WooCommerce\'s own main class.' );
		$this->assertSame( 'AlbertWooCommerce\\AlbertWooCommerceService', $woo['addon_symbol'] );
		$this->assertNotEmpty( $woo['icon'], 'Each recommendation carries its own icon.' );
	}

	/**
	 * An entry with no host applies to every site.
	 *
	 * Free ships nothing like this any more: the WooCommerce entry is worth
	 * mentioning to a shop and nobody else, and Premium, which used to be the
	 * hostless one, moved to the activity card so the Dashboard stopped
	 * pitching it twice. The capability stays supported for add-ons, so it
	 * stays tested against a fixture rather than a shipped entry.
	 *
	 * @return void
	 */
	public function test_an_entry_without_a_host_applies_everywhere(): void {
		add_filter(
			'albert/dashboard/recommendations',
			static fn (): array => [
				[
					'slug'         => 'universal',
					'name'         => 'Universal',
					'because'      => 'x',
					'detail'       => 'y',
					'url'          => 'https://example.com/',
					'host_symbol'  => '',
					'addon_symbol' => 'Albert\\No\\Such\\Class',
				],
			]
		);

		$this->assertCount( 1, ( new Recommendations() )->current( 1 ) );
	}
}
