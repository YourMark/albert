<?php
/**
 * Unit tests for the PII reveal policy.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Privacy;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\Privacy\PiiPolicy;
use Albert\Privacy\PrivacyMode;
use PHPUnit\Framework\TestCase;

/**
 * PiiPolicy unit tests.
 *
 * @covers \Albert\Privacy\PiiPolicy
 */
class PiiPolicyTest extends TestCase {

	/**
	 * Reset configuration globals before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['albert_test_hooks'] = [];
		$GLOBALS['albert_test_caps']  = [ 'manage_woocommerce' ];
	}

	/**
	 * Strict mode never reveals, even with a valid request and capability.
	 *
	 * @return void
	 */
	public function test_strict_never_reveals(): void {
		$this->assertFalse(
			PiiPolicy::allows_reveal( [ 'reveal_personal_data' => true ], PrivacyMode::Strict )
		);
	}

	/**
	 * Balanced mode reveals with an explicit opt-in and the capability.
	 *
	 * @return void
	 */
	public function test_balanced_reveals_with_opt_in_and_capability(): void {
		$this->assertTrue(
			PiiPolicy::allows_reveal( [ 'reveal_personal_data' => true ], PrivacyMode::Balanced )
		);
	}

	/**
	 * Balanced mode without the capability does not reveal.
	 *
	 * @return void
	 */
	public function test_balanced_without_capability_redacts(): void {
		$GLOBALS['albert_test_caps'] = [];
		$this->assertFalse(
			PiiPolicy::allows_reveal( [ 'reveal_personal_data' => true ], PrivacyMode::Balanced )
		);
	}

	/**
	 * Balanced mode without the explicit opt-in does not reveal.
	 *
	 * @return void
	 */
	public function test_balanced_without_opt_in_redacts(): void {
		$this->assertFalse(
			PiiPolicy::allows_reveal( [], PrivacyMode::Balanced )
		);
	}

	/**
	 * Off mode never reveals via the policy (handled separately by the caller).
	 *
	 * @return void
	 */
	public function test_off_does_not_reveal(): void {
		$this->assertFalse(
			PiiPolicy::allows_reveal( [ 'reveal_personal_data' => true ], PrivacyMode::Off )
		);
	}
}
