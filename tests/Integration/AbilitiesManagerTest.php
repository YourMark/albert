<?php
/**
 * AbilitiesManager integration tests.
 *
 * @package ExtendedAbilities
 */

namespace ExtendedAbilities\Tests\Integration;

use ExtendedAbilities\Tests\TestCase;
use ExtendedAbilities\Core\AbilitiesManager;

/**
 * Test the AbilitiesManager functionality.
 */
class AbilitiesManagerTest extends TestCase {

	/**
	 * The abilities manager instance.
	 *
	 * @var AbilitiesManager
	 */
	private AbilitiesManager $manager;

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->manager = new AbilitiesManager();
	}

	/**
	 * Test that the manager can be instantiated.
	 *
	 * @return void
	 */
	public function test_manager_can_be_instantiated(): void {
		$this->assertInstanceOf( AbilitiesManager::class, $this->manager );
	}

	/**
	 * Test that get_abilities returns an array.
	 *
	 * @return void
	 */
	public function test_get_abilities_returns_array(): void {
		$abilities = $this->manager->get_abilities();

		$this->assertIsArray( $abilities );
	}

	/**
	 * Test that core abilities are registered after init.
	 *
	 * @return void
	 */
	public function test_core_abilities_are_registered(): void {
		// Trigger hook registration.
		$this->manager->register_hooks();
		do_action( 'init' );

		$abilities = $this->manager->get_abilities();

		// Should have some registered abilities.
		$this->assertNotEmpty( $abilities, 'Core abilities should be registered' );
	}
}
