<?php
/**
 * Unit tests for PrivacyMode resolution and precedence.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Privacy;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\Privacy\PrivacyMode;
use PHPUnit\Framework\TestCase;

/**
 * PrivacyMode unit tests.
 *
 * @covers \Albert\Privacy\PrivacyMode
 */
class PrivacyModeTest extends TestCase {

	/**
	 * Reset configuration globals before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['albert_test_hooks']          = [];
		$GLOBALS['albert_test_options']        = [];
		$GLOBALS['albert_test_filter_returns'] = [];
	}

	/**
	 * With nothing configured, the default is Balanced.
	 *
	 * @return void
	 */
	public function test_default_is_balanced(): void {
		$this->assertSame( PrivacyMode::Balanced, PrivacyMode::resolve() );
	}

	/**
	 * The stored option is honoured when present.
	 *
	 * @return void
	 */
	public function test_option_tier(): void {
		$GLOBALS['albert_test_options']['albert_privacy_mode'] = 'strict';
		$this->assertSame( PrivacyMode::Strict, PrivacyMode::resolve() );
	}

	/**
	 * The filter overrides the stored option.
	 *
	 * @return void
	 */
	public function test_filter_overrides_option(): void {
		$GLOBALS['albert_test_options']['albert_privacy_mode']        = 'strict';
		$GLOBALS['albert_test_filter_returns']['albert/privacy/mode'] = 'off';
		$this->assertSame( PrivacyMode::Off, PrivacyMode::resolve() );
	}

	/**
	 * The constant overrides the filter and option (isolated process).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_constant_overrides_all(): void {
		define( 'ALBERT_PRIVACY_MODE', 'strict' );
		$GLOBALS['albert_test_options']['albert_privacy_mode']        = 'balanced';
		$GLOBALS['albert_test_filter_returns']['albert/privacy/mode'] = 'off';

		$this->assertSame( PrivacyMode::Strict, PrivacyMode::resolve() );
	}

	/**
	 * An unrecognised option value falls back to the default.
	 *
	 * @return void
	 */
	public function test_unknown_value_falls_back_to_default(): void {
		$GLOBALS['albert_test_options']['albert_privacy_mode'] = 'nonsense';
		$this->assertSame( PrivacyMode::Balanced, PrivacyMode::resolve() );
	}

	/**
	 * sanitize() normalises casing and rejects unknown values.
	 *
	 * @return void
	 */
	public function test_sanitize(): void {
		$this->assertSame( 'strict', PrivacyMode::sanitize( 'STRICT' ) );
		$this->assertSame( 'off', PrivacyMode::sanitize( ' Off ' ) );
		$this->assertSame( 'balanced', PrivacyMode::sanitize( 'bogus' ) );
		$this->assertSame( 'balanced', PrivacyMode::sanitize( 123 ) );
	}
}
