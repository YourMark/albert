<?php
/**
 * Integration tests for MCP adapter availability reporting.
 *
 * The failure these guard against is not a wrong value, it is silence. When
 * Albert's bundled MCP library is absent, or a second plugin loads its own
 * unscoped copy, no MCP server registers and `/albert/v1/mcp` answers
 * `401 rest_forbidden` — which is also the correct answer for a healthy install
 * handed no token. Nothing distinguishes the three from outside, so a site
 * owner debugs their token instead of their plugins.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\MCP;

use Albert\MCP\AdapterHealth;
use Albert\MCP\AdapterStatus;
use Albert\Tests\TestCase;

/**
 * Adapter health reporting tests.
 *
 * @covers \Albert\MCP\AdapterHealth
 * @covers \Albert\MCP\AdapterStatus
 */
class AdapterHealthTest extends TestCase {

	/**
	 * Clear the cached filesystem scan between tests.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		AdapterStatus::flush();
	}

	/**
	 * Clear it again, so a cached result cannot leak into another test file.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		AdapterStatus::flush();

		parent::tear_down();
	}

	/**
	 * The scoped adapter ships with the plugin, so it must be present here.
	 *
	 * If this fails, the test environment was built the way the reported bug
	 * was: dependencies installed without their development requirements, so
	 * Mozart never ran and `vendor-prefixed/` was never generated.
	 *
	 * @return void
	 */
	public function test_the_scoped_adapter_is_available_in_a_correct_install(): void {
		$this->assertTrue(
			AdapterStatus::scoped_adapter_available(),
			'The Mozart-scoped MCP adapter is missing. Run `composer install` without --no-dev.'
		);
	}

	/**
	 * A correct install reports good, and says so in plain words.
	 *
	 * @return void
	 */
	public function test_site_health_reports_good_when_nothing_is_wrong(): void {
		$result = ( new AdapterHealth() )->run_test();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'albert_mcp_adapter', $result['test'] );
		$this->assertNotEmpty( $result['label'] );
		$this->assertNotEmpty( $result['description'] );
	}

	/**
	 * The Site Health test is registered where WordPress will run it.
	 *
	 * @return void
	 */
	public function test_the_site_health_test_is_registered(): void {
		$tests = ( new AdapterHealth() )->add_test(
			[
				'direct' => [],
				'async'  => [],
			]
		);

		$this->assertArrayHasKey( 'albert_mcp_adapter', $tests['direct'] );
		$this->assertIsCallable( $tests['direct']['albert_mcp_adapter']['test'] );
	}

	/**
	 * The debug report names the library state, for a support paste.
	 *
	 * @return void
	 */
	public function test_debug_information_reports_the_library_state(): void {
		$info = ( new AdapterHealth() )->add_debug_information( [] );

		$this->assertArrayHasKey( 'albert', $info );
		$this->assertArrayHasKey( 'mcp_library', $info['albert']['fields'] );
		$this->assertArrayHasKey( 'mcp_other_copies', $info['albert']['fields'] );
	}

	/**
	 * Albert's own scoped copy is never reported as a conflict.
	 *
	 * The scan matches on the path tail `WP/MCP/Core/McpAdapter.php`, which a
	 * Mozart-scoped copy keeps — only its namespace changes. Without the
	 * namespace check, Albert would report itself.
	 *
	 * @return void
	 */
	public function test_albert_does_not_report_its_own_scoped_copy(): void {
		$this->assertArrayNotHasKey( 'albert-ai-butler', AdapterStatus::foreign_copies() );
	}

	/**
	 * A second plugin shipping an unscoped copy is found and named.
	 *
	 * Written against the filesystem rather than a mocked loaded class on
	 * purpose: reflection reports whichever copy won the autoload race, so on a
	 * site with several copies it names an arbitrary one — and if that one is
	 * known-safe, the real conflict is never reported. That is the hole this
	 * scan exists to close, so the test has to exercise the scan.
	 *
	 * @return void
	 */
	public function test_a_second_plugin_shipping_an_unscoped_copy_is_named(): void {
		$slug = 'albert-test-fake-mcp-plugin';
		$dir  = WP_PLUGIN_DIR . '/' . $slug . '/vendor/wp/mcp-adapter/src/WP/MCP/Core';

		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/McpAdapter.php', "<?php\nnamespace WP\\MCP\\Core;\nclass McpAdapter {}\n" );

		$active = get_option( 'active_plugins', [] );
		update_option( 'active_plugins', array_merge( (array) $active, [ $slug . '/' . $slug . '.php' ] ) );
		AdapterStatus::flush();

		try {
			$found = AdapterStatus::foreign_copies();

			$this->assertArrayHasKey( $slug, $found, 'A plugin bundling an unscoped copy should be named.' );

			$health = ( new AdapterHealth() )->run_test();

			$this->assertSame( 'critical', $health['status'] );
			$this->assertStringContainsString( $slug, $health['description'] );
		} finally {
			update_option( 'active_plugins', $active );
			AdapterStatus::flush();

			unlink( $dir . '/McpAdapter.php' );
			$this->remove_tree( WP_PLUGIN_DIR . '/' . $slug );
		}
	}

	/**
	 * A copy that is namespace-scoped is not a conflict and is not reported.
	 *
	 * @return void
	 */
	public function test_a_scoped_copy_in_another_plugin_is_not_a_conflict(): void {
		$slug = 'albert-test-scoped-mcp-plugin';
		$dir  = WP_PLUGIN_DIR . '/' . $slug . '/vendor-prefixed/WP/MCP/Core';

		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/McpAdapter.php', "<?php\nnamespace Other\\Vendor\\WP\\MCP\\Core;\nclass McpAdapter {}\n" );

		$active = get_option( 'active_plugins', [] );
		update_option( 'active_plugins', array_merge( (array) $active, [ $slug . '/' . $slug . '.php' ] ) );
		AdapterStatus::flush();

		try {
			$this->assertArrayNotHasKey( $slug, AdapterStatus::foreign_copies() );
		} finally {
			update_option( 'active_plugins', $active );
			AdapterStatus::flush();

			unlink( $dir . '/McpAdapter.php' );
			$this->remove_tree( WP_PLUGIN_DIR . '/' . $slug );
		}
	}

	/**
	 * Remove a directory tree created by a test.
	 *
	 * @param string $dir Absolute directory path.
	 *
	 * @return void
	 */
	private function remove_tree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}

		rmdir( $dir );
	}
}
