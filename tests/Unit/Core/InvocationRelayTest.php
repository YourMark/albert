<?php
/**
 * Unit tests for InvocationRelay.
 *
 * The relay re-emits WordPress 7.1's `wp_ability_invoked` as Albert's
 * `albert/abilities/invoked`, so subscribers bind to a stable Albert hook
 * instead of a core action whose availability is version-dependent. See
 * doc 20 §5.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Core;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__ ) . '/stubs/WP_Ability.php';

use Albert\Core\InvocationRelay;
use PHPUnit\Framework\TestCase;

/**
 * Invocation relay tests.
 *
 * @covers \Albert\Core\InvocationRelay
 */
class InvocationRelayTest extends TestCase {

	/**
	 * Reset the hook recorder before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_hooks'] = [];
	}

	/**
	 * The relay binds to the core action with all three arguments.
	 *
	 * Argument count matters: registering with the default of 1 would silently
	 * drop the input and the ability instance, leaving subscribers with a name
	 * and nothing else.
	 *
	 * @return void
	 */
	public function test_registers_on_the_core_invocation_action(): void {
		( new InvocationRelay() )->register_hooks();

		$registered = array_filter(
			$GLOBALS['albert_test_hooks'],
			static fn( array $hook ): bool => ( $hook['hook'] ?? '' ) === 'wp_ability_invoked'
		);

		$this->assertCount( 1, $registered, 'The relay did not register on wp_ability_invoked.' );

		$hook = array_values( $registered )[0];
		$this->assertSame( 3, $hook['accepted_args'] ?? null, 'The relay must accept all three core arguments.' );
	}

	/**
	 * Relaying re-emits the Albert action with core's arguments untouched.
	 *
	 * @return void
	 */
	public function test_relays_core_arguments_unchanged(): void {
		$ability = $this->ability( 'albert/create-post' );
		$input   = [ 'title' => 'Hello' ];

		( new InvocationRelay() )->relay( 'albert/create-post', $input, $ability );

		$fired = array_values(
			array_filter(
				$GLOBALS['albert_test_hooks'],
				static fn( array $hook ): bool => ( $hook['hook'] ?? '' ) === 'albert/abilities/invoked'
			)
		);

		$this->assertCount( 1, $fired, 'albert/abilities/invoked did not fire.' );
		$this->assertSame( 'albert/create-post', $fired[0]['args'][0] );
		$this->assertSame( $input, $fired[0]['args'][1] );
		$this->assertSame( $ability, $fired[0]['args'][2] );
	}

	/**
	 * A null input — core's default for an ability taking no arguments — relays.
	 *
	 * @return void
	 */
	public function test_relays_a_null_input(): void {
		( new InvocationRelay() )->relay( 'albert/find-posts', null, $this->ability( 'albert/find-posts' ) );

		$fired = array_values(
			array_filter(
				$GLOBALS['albert_test_hooks'],
				static fn( array $hook ): bool => ( $hook['hook'] ?? '' ) === 'albert/abilities/invoked'
			)
		);

		$this->assertCount( 1, $fired );
		$this->assertNull( $fired[0]['args'][1] );
	}

	/**
	 * A throwing observer cannot break the ability it observes.
	 *
	 * The relay fires from the top of WP_Ability::execute(), outside any Albert
	 * try/catch, for every ability on the site. Without the guard a subscriber
	 * that throws would take the whole tool call down with it.
	 *
	 * @return void
	 */
	public function test_a_throwing_observer_does_not_break_the_call(): void {
		$GLOBALS['albert_test_throw_on_action'] = 'albert/abilities/invoked';

		try {
			( new InvocationRelay() )->relay( 'albert/create-post', [], $this->ability( 'albert/create-post' ) );
			$this->addToAssertionCount( 1 );
		} catch ( \Throwable $e ) {
			$this->fail( 'The relay let an observer Throwable escape: ' . $e->getMessage() );
		} finally {
			unset( $GLOBALS['albert_test_throw_on_action'] );
		}
	}

	/**
	 * Build a WP_Ability double.
	 *
	 * @param string $name Ability id.
	 *
	 * @return \WP_Ability
	 */
	private function ability( string $name ): \WP_Ability {
		return new \WP_Ability( $name );
	}
}
