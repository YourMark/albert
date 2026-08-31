<?php
/**
 * Integration tests for add-on state detection.
 *
 * This exists because two states were not enough. Asking only "does the class
 * exist" answers *running or not*, and everything not running was then sold to,
 * including somebody who had already bought Premium, installed it, and left it
 * switched off. They were told to go and buy it.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Admin\Dashboard;

use Albert\Admin\Dashboard\AddonState;
use Albert\Tests\TestCase;

/**
 * Add-on state tests.
 *
 * @covers \Albert\Admin\Dashboard\AddonState
 */
class AddonStateTest extends TestCase {

	/**
	 * A loaded class means running, whatever the plugin list says.
	 *
	 * Deliberately asked of the symbol rather than the list: a plugin can be
	 * listed as active without its classes loaded, and a must-use plugin never
	 * appears in the list at all.
	 *
	 * @return void
	 */
	public function test_a_loaded_class_is_active(): void {
		$this->assertSame(
			AddonState::ACTIVE,
			AddonState::of( 'WP_Query', 'does-not-matter/when-loaded.php' )
		);
	}

	/**
	 * Neither loaded nor installed is absent, which is the only state that
	 * should ever be offered a sale.
	 *
	 * @return void
	 */
	public function test_neither_loaded_nor_installed_is_absent(): void {
		$this->assertSame(
			AddonState::ABSENT,
			AddonState::of( 'Albert\\No\\Such\\Class', 'not-installed/not-installed.php' )
		);
	}

	/**
	 * Installed but not loaded is its own state, and the one the bug missed.
	 *
	 * Uses whatever plugin the test site actually has, so this does not depend
	 * on a particular add-on being present.
	 *
	 * @return void
	 */
	public function test_installed_but_not_loaded_is_inactive(): void {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = array_keys( get_plugins() );

		if ( $installed === [] ) {
			$this->markTestSkipped( 'No plugins installed in this environment to detect.' );
		}

		$this->assertSame(
			AddonState::INACTIVE,
			AddonState::of( 'Albert\\No\\Such\\Class', $installed[0] ),
			'A plugin present on disk but not loaded is installed, not missing.'
		);
	}

	/**
	 * The activation route is the Plugins screen, not a one-click activation
	 * link: switching a plugin on is a state change, and that screen is where
	 * somebody expects to confirm it.
	 *
	 * @return void
	 */
	public function test_the_activation_url_points_at_the_plugins_screen(): void {
		$url = AddonState::activation_url( 'Albert Premium Service' );

		$this->assertStringContainsString( 'plugins.php', $url );
		$this->assertStringContainsString( 'plugin_status=inactive', $url );
		$this->assertStringNotContainsString( 'action=activate', $url );
	}
}
