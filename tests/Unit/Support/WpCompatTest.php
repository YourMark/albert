<?php
/**
 * Unit tests for the WpCompat 7.1 capability-detection helper.
 *
 * WpCompat gates every 7.1-only branch behind a `function_exists()` check.
 * To exercise both the 7.1 path and the 6.9/7.0 fallback without a real
 * WordPress of each version, this file shadows `function_exists()` inside the
 * `Albert\Support` namespace: PHP resolves an unqualified function call to the
 * caller's namespace first, so WpCompat's `function_exists()` lands on the
 * shadow below, which the tests toggle per capability.
 *
 * @package Albert\Tests\Unit\Support
 */

namespace Albert\Tests\Unit\Support;

require_once dirname( __DIR__ ) . '/stubs/function-exists-shadow.php';

use Albert\Support\WpCompat;
use PHPUnit\Framework\TestCase;

/**
 * WpCompat capability-detection tests.
 */
class WpCompatTest extends TestCase {

	/**
	 * Reset the shadowed-function overrides before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['albert_test_fn_exists']    = [];
		$GLOBALS['albert_test_class_exists'] = [];
	}

	/**
	 * Clear overrides so later tests see the real function_exists().
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['albert_test_fn_exists'], $GLOBALS['albert_test_class_exists'] );
		parent::tearDown();
	}

	/**
	 * On WordPress 7.1 the client schema-prep function is present.
	 *
	 * @return void
	 */
	public function test_supports_client_schema_prep_true_when_function_present(): void {
		$GLOBALS['albert_test_fn_exists']['wp_prepare_json_schema_for_client'] = true;

		$this->assertTrue( WpCompat::supports_client_schema_prep() );
	}

	/**
	 * On WordPress 6.9/7.0 the function is absent, so detection is false.
	 *
	 * @return void
	 */
	public function test_supports_client_schema_prep_false_when_function_absent(): void {
		$GLOBALS['albert_test_fn_exists']['wp_prepare_json_schema_for_client'] = false;

		$this->assertFalse( WpCompat::supports_client_schema_prep() );
	}

	/**
	 * The lifecycle is reported available when its marker class is present.
	 *
	 * @return void
	 */
	public function test_execution_lifecycle_is_detected_on_71(): void {
		$GLOBALS['albert_test_class_exists']['\WP_Filter_Sentinel'] = true;

		$this->assertTrue( WpCompat::supports_execution_lifecycle() );
	}

	/**
	 * Below 7.1 the marker class is absent and the seam reports unavailable.
	 *
	 * Callers must stay correct either way — the hooks simply never fire — but
	 * a subscriber that needs to know whether the seam is live asks this.
	 *
	 * @return void
	 */
	public function test_execution_lifecycle_is_not_detected_below_71(): void {
		$GLOBALS['albert_test_class_exists']['\WP_Filter_Sentinel'] = false;

		$this->assertFalse( WpCompat::supports_execution_lifecycle() );
	}
}
