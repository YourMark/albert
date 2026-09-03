<?php
/**
 * Unit tests for AbilitiesRegistry::get_all_raw().
 *
 * The method exists so Albert's bookkeeping never reads the WordPress 7.1
 * filter pipeline: `wp_get_abilities()` runs `wp_get_abilities_item_include`
 * and `wp_get_abilities_result` even on a bare call, so a third-party filter
 * could otherwise shrink what Albert believes is registered. See doc 20 §3.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Core;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\Core\AbilitiesRegistry;
use PHPUnit\Framework\TestCase;
use WP_Abilities_Registry;

/**
 * Raw-registry accessor tests.
 *
 * @covers \Albert\Core\AbilitiesRegistry::get_all_raw
 */
class AbilitiesRawRegistryTest extends TestCase {

	/**
	 * Reset the ability set and the registry singleton before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_abilities'] = [];
		WP_Abilities_Registry::reset();
	}

	/**
	 * Restore the singleton so a forced-null test cannot leak into others.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		WP_Abilities_Registry::reset();

		parent::tearDown();
	}

	/**
	 * Every registered ability is returned, keyed by ability name.
	 *
	 * @return void
	 */
	public function test_returns_all_registered_abilities_keyed_by_name(): void {
		$GLOBALS['albert_test_abilities'] = [
			$this->ability( 'albert/create-post' ),
			$this->ability( 'albert/find-posts' ),
		];

		$all = AbilitiesRegistry::get_all_raw();

		$this->assertCount( 2, $all );
		$this->assertArrayHasKey( 'albert/create-post', $all );
		$this->assertArrayHasKey( 'albert/find-posts', $all );
	}

	/**
	 * An empty registry yields an empty array rather than a notice.
	 *
	 * @return void
	 */
	public function test_returns_empty_array_when_nothing_is_registered(): void {
		$this->assertSame( [], AbilitiesRegistry::get_all_raw() );
	}

	/**
	 * A null singleton — core's documented pre-initialisation state — is handled.
	 *
	 * The registry is null until the Abilities API boots, and Albert's admin
	 * screens can be constructed before that point. Returning an empty array
	 * keeps those callers on the "nothing registered yet" path instead of
	 * fatalling on a method call against null.
	 *
	 * @return void
	 */
	public function test_returns_empty_array_when_registry_is_unavailable(): void {
		// Abilities exist, but the registry reports itself as not yet booted —
		// so the guard, not the ability set, must decide the result.
		$GLOBALS['albert_test_abilities'] = [ $this->ability( 'albert/create-post' ) ];

		WP_Abilities_Registry::set_unavailable( true );

		$this->assertSame( [], AbilitiesRegistry::get_all_raw() );
	}

	/**
	 * Build an ability double exposing get_name(), matching the registry shape.
	 *
	 * @param string $name Ability id.
	 *
	 * @return object
	 */
	private function ability( string $name ): object {
		return new class( $name ) {
			/**
			 * Test double used by the case below.
			 *
			 * @param string $name Ability id.
			 */
			public function __construct( private string $name ) {}

			/**
			 * Test double used by the case below.
			 *
			 * @return string
			 */
			public function get_name(): string {
				return $this->name;
			}
		};
	}
}
