<?php
/**
 * Unit tests for AbilitiesManager::heal_transport_tools().
 *
 * The self-heal actively removes any MCP transport meta-tool ID from the
 * persisted `albert_disabled_abilities` option, so a site that somehow already
 * disabled them gets the transport back automatically on update — while leaving
 * unrelated disabled abilities intact and writing nothing when there is nothing
 * to scrub.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Core;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\Admin\AbilitiesPage;
use Albert\Core\AbilitiesManager;
use PHPUnit\Framework\TestCase;

/**
 * AbilitiesManager self-heal tests.
 *
 * @covers \Albert\Core\AbilitiesManager::heal_transport_tools
 */
class AbilitiesHealTest extends TestCase {

	/**
	 * Reset the option globals and the write counter before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_options']       = [];
		$GLOBALS['albert_test_option_writes'] = [];
		$GLOBALS['albert_test_hooks']         = [];
	}

	/**
	 * Given the three transport meta-tools plus an unrelated ability in the
	 * disabled option, the meta-tools are scrubbed and the unrelated ID remains.
	 *
	 * @return void
	 */
	public function test_removes_transport_meta_tools_and_keeps_unrelated(): void {
		$GLOBALS['albert_test_options'][ AbilitiesPage::DISABLED_ABILITIES_OPTION ] = [
			'albert/create-post',
			'mcp-adapter/discover-abilities',
			'mcp-adapter/get-ability-info',
			'mcp-adapter/execute-ability',
		];

		( new AbilitiesManager() )->heal_transport_tools();

		$this->assertSame(
			[ 'albert/create-post' ],
			$GLOBALS['albert_test_options'][ AbilitiesPage::DISABLED_ABILITIES_OPTION ]
		);
	}

	/**
	 * The sanitised hyphen spelling of a meta-tool is scrubbed too, so a build
	 * that persisted the MCP tool name (rather than the raw ability ID) is healed.
	 *
	 * @return void
	 */
	public function test_removes_sanitised_hyphen_spelling(): void {
		$GLOBALS['albert_test_options'][ AbilitiesPage::DISABLED_ABILITIES_OPTION ] = [
			'mcp-adapter-execute-ability',
			'albert/delete-post',
		];

		( new AbilitiesManager() )->heal_transport_tools();

		$this->assertSame(
			[ 'albert/delete-post' ],
			$GLOBALS['albert_test_options'][ AbilitiesPage::DISABLED_ABILITIES_OPTION ]
		);
	}

	/**
	 * When the option contains no transport meta-tool, the routine writes nothing
	 * — no option churn on the steady-state path.
	 *
	 * @return void
	 */
	public function test_does_not_write_when_no_transport_present(): void {
		$GLOBALS['albert_test_options'][ AbilitiesPage::DISABLED_ABILITIES_OPTION ] = [
			'albert/create-post',
			'albert/delete-post',
		];

		( new AbilitiesManager() )->heal_transport_tools();

		$this->assertArrayNotHasKey(
			AbilitiesPage::DISABLED_ABILITIES_OPTION,
			$GLOBALS['albert_test_option_writes'],
			'heal_transport_tools() must not write the option when nothing was scrubbed.'
		);
		$this->assertSame(
			[ 'albert/create-post', 'albert/delete-post' ],
			$GLOBALS['albert_test_options'][ AbilitiesPage::DISABLED_ABILITIES_OPTION ]
		);
	}

	/**
	 * An empty or unset option is a no-op with no write.
	 *
	 * @return void
	 */
	public function test_empty_option_is_a_noop(): void {
		( new AbilitiesManager() )->heal_transport_tools();

		$this->assertArrayNotHasKey(
			AbilitiesPage::DISABLED_ABILITIES_OPTION,
			$GLOBALS['albert_test_option_writes']
		);
	}
}
