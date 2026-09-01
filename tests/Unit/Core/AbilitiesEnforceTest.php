<?php
/**
 * Unit tests for AbilitiesManager::enforce_disabled().
 *
 * Focuses on the guarantee that the MCP transport meta-tools are never
 * unregistered — even when their IDs are present in the disabled option — so the
 * transport is always available.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Core;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\Admin\AbilitiesPage;
use Albert\Core\AbilitiesManager;
use PHPUnit\Framework\TestCase;

/**
 * AbilitiesManager enforcement tests.
 *
 * @covers \Albert\Core\AbilitiesManager::enforce_disabled
 */
class AbilitiesEnforceTest extends TestCase {

	/**
	 * Reset the option/ability/hook globals and the management-context request
	 * state before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_options']                = [];
		$GLOBALS['albert_test_abilities']              = [];
		$GLOBALS['albert_test_hooks']                  = [];
		$GLOBALS['albert_test_unregistered_abilities'] = [];
		$GLOBALS['albert_test_is_admin']               = false;
		unset( $_GET['page'] );
	}

	/**
	 * A minimal ability double exposing get_name(), which is all
	 * enforce_disabled() reads for non-Albert-managed abilities.
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

	/**
	 * All three transport meta-tools stay registered even when every one of them
	 * is present in the disabled option, while a genuinely disabled non-meta
	 * ability is still unregistered.
	 *
	 * @return void
	 */
	public function test_never_unregisters_transport_meta_tools_even_when_disabled(): void {
		$GLOBALS['albert_test_options']['albert_abilities_saved']                   = true;
		$GLOBALS['albert_test_options'][ AbilitiesPage::DISABLED_ABILITIES_OPTION ] = [
			'mcp-adapter/discover-abilities',
			'mcp-adapter/get-ability-info',
			'mcp-adapter/execute-ability',
			'albert/create-post',
		];

		$GLOBALS['albert_test_abilities'] = [
			$this->ability( 'mcp-adapter/discover-abilities' ),
			$this->ability( 'mcp-adapter/get-ability-info' ),
			$this->ability( 'mcp-adapter/execute-ability' ),
			$this->ability( 'albert/create-post' ),
			$this->ability( 'albert/find-posts' ),
		];

		( new AbilitiesManager() )->enforce_disabled();

		$unregistered = $GLOBALS['albert_test_unregistered_abilities'];

		// The genuinely disabled non-meta ability is removed…
		$this->assertContains( 'albert/create-post', $unregistered );
		// …but none of the transport meta-tools ever are.
		$this->assertNotContains( 'mcp-adapter/discover-abilities', $unregistered );
		$this->assertNotContains( 'mcp-adapter/get-ability-info', $unregistered );
		$this->assertNotContains( 'mcp-adapter/execute-ability', $unregistered );
		// An enabled ability is left alone.
		$this->assertNotContains( 'albert/find-posts', $unregistered );
	}
}
