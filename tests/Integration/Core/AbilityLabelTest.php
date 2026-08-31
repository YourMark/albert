<?php
/**
 * Integration tests for resolving an ability slug to a label.
 *
 * The slugs this is asked about routinely are not registered abilities, and
 * that is by design rather than by accident: it resolves names out of the
 * ability log, and the cron sweeps deliberately write event rows there under
 * names that were never abilities (`albert/allowed-user-expired`,
 * `albert/connection-dropped-unused`), alongside genuine rows left behind by
 * abilities that have since been renamed or removed.
 *
 * WordPress 6.9's `WP_Abilities_Registry::get_registered()` raises
 * `_doing_it_wrong()` on a miss *before* returning null, so `wp_get_ability()`
 * cannot be used to ask whether an ability exists. It was, and the Dashboard
 * printed "Ability ... not found" every time the recent-activity table showed a
 * row for a slug the registry no longer holds. `wp_has_ability()` is core's own
 * silent check.
 *
 * These tests need no explicit assertion about the notice: the WordPress test
 * suite fails any test that triggers an unexpected `_doing_it_wrong()`, so
 * calling the resolver is the assertion.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Core;

use Albert\Core\AbilitiesRegistry;
use Albert\Tests\TestCase;

/**
 * Label resolution for registered and unregistered slugs.
 *
 * @covers \Albert\Core\AbilitiesRegistry::label_for
 */
class AbilityLabelTest extends TestCase {

	/**
	 * Put the resolver on the branch that actually asks WordPress.
	 *
	 * `label_for()` only consults the registry once `wp_abilities_api_init` has
	 * fired, and skips to the prettified fallback otherwise. That guard is
	 * right in production and awkward here: the WordPress test suite restores
	 * `$wp_actions` between tests, while `WP_Abilities_Registry`'s singleton
	 * survives, so from the second test onwards the registry is built and
	 * `did_action()` says it is not. Left alone, every test below would take
	 * the fallback, pass, and prove nothing about the guard it is here for.
	 *
	 * So the registry is built and the count restated. Restating it is the
	 * honest move rather than a workaround: in a real request the two are the
	 * same fact, and this only makes them agree again.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		AbilitiesRegistry::get_all_raw();

		global $wp_actions;
		$wp_actions['wp_abilities_api_init'] = 1;

		$this->assertTrue(
			(bool) did_action( 'wp_abilities_api_init' ),
			'These tests are meaningless unless the resolver reaches the registry.'
		);
	}

	/**
	 * A slug the registry does not hold resolves quietly.
	 *
	 * @return void
	 */
	public function test_an_unregistered_slug_does_not_complain(): void {
		$this->assertSame(
			'Albert Redeem Upload Ticket',
			AbilitiesRegistry::label_for( 'albert/redeem-upload-ticket' )
		);
	}

	/**
	 * ...including the pseudo-ability names the cron sweeps log, which are the
	 * common case rather than an exotic one: any site whose retention sweep has
	 * ever dropped a connection has one of these in its log.
	 *
	 * @return void
	 */
	public function test_a_logged_event_name_does_not_complain(): void {
		foreach ( [ 'albert/allowed-user-expired', 'albert/connection-dropped-unused' ] as $slug ) {
			$this->assertNotSame( '', AbilitiesRegistry::label_for( $slug ) );
		}
	}

	/**
	 * A registered ability still gets its own label rather than the fallback,
	 * so the guard did not simply switch the useful branch off.
	 *
	 * @return void
	 */
	public function test_a_registered_ability_still_uses_its_own_label(): void {
		$registered = AbilitiesRegistry::get_all_raw();

		if ( $registered === [] ) {
			$this->markTestSkipped( 'No abilities registered in this environment.' );
		}

		$ability = reset( $registered );
		$slug    = $ability->get_name();
		$label   = (string) $ability->get_label();

		if ( $label === '' ) {
			$this->markTestSkipped( 'The first registered ability has no label to compare against.' );
		}

		$this->assertSame( $label, AbilitiesRegistry::label_for( $slug ) );
		$this->assertNotSame(
			ucwords( str_replace( [ '/', '-', '_' ], ' ', $slug ) ),
			AbilitiesRegistry::label_for( $slug ),
			'A registered ability must not fall through to the prettified slug.'
		);
	}
}
