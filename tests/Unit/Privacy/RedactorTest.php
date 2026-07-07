<?php
/**
 * Unit tests for the ability-facing PII redaction entrypoint.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Privacy;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\Privacy\PiiPolicy;
use PHPUnit\Framework\TestCase;

/**
 * PiiPolicy::redact() unit tests.
 *
 * @covers \Albert\Privacy\PiiPolicy::redact
 */
class RedactorTest extends TestCase {

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
		$GLOBALS['albert_test_caps']           = [ 'manage_woocommerce' ];

		// Simulate an add-on (e.g. the WooCommerce add-on) having registered its
		// payment/card matchers, so the always-strip-payment behaviour is
		// exercised across every privacy mode below.
		$GLOBALS['albert_test_filter_returns']['albert/privacy/payment_keys'] = [
			'keys'     => [ 'card_number' ],
			'prefixes' => [ '_stripe' ],
		];
	}

	/**
	 * A customer-shaped payload carrying PII and payment data.
	 *
	 * @return array<string, mixed>
	 */
	private function customer_payload(): array {
		return [
			'customer' => [
				'id'          => 42,
				'email'       => 'john@example.com',
				'first_name'  => 'John',
				'card_number' => '4242424242424242',
			],
		];
	}

	/**
	 * Off mode returns raw data but always strips payment/card data.
	 *
	 * @return void
	 */
	public function test_off_mode_strips_payment_only(): void {
		$GLOBALS['albert_test_options']['albert_privacy_mode'] = 'off';

		$out = PiiPolicy::redact( $this->customer_payload() );

		$this->assertSame( 'John', $out['customer']['first_name'] );
		$this->assertSame( 'john@example.com', $out['customer']['email'] );
		$this->assertArrayNotHasKey( 'card_number', $out['customer'] );
	}

	/**
	 * An authorised reveal (Balanced + opt-in + capability) returns raw data
	 * but still strips payment/card data.
	 *
	 * @return void
	 */
	public function test_reveal_keeps_pii_but_strips_payment(): void {
		$out = PiiPolicy::redact(
			$this->customer_payload(),
			[ 'reveal_personal_data' => true ]
		);

		$this->assertSame( 'John', $out['customer']['first_name'] );
		$this->assertSame( 'john@example.com', $out['customer']['email'] );
		$this->assertArrayNotHasKey( 'card_number', $out['customer'] );
	}

	/**
	 * A reveal request without the gating capability still anonymises.
	 *
	 * @return void
	 */
	public function test_reveal_without_capability_anonymises(): void {
		$GLOBALS['albert_test_caps'] = [];

		$out = PiiPolicy::redact(
			$this->customer_payload(),
			[ 'reveal_personal_data' => true ]
		);

		$this->assertSame( 'Customer #42', $out['customer']['first_name'] );
		$this->assertSame( 'j***@e***.com', $out['customer']['email'] );
		$this->assertArrayNotHasKey( 'card_number', $out['customer'] );
	}

	/**
	 * The default (Balanced, no reveal) anonymises personal data.
	 *
	 * @return void
	 */
	public function test_default_anonymises(): void {
		$out = PiiPolicy::redact( $this->customer_payload() );

		$this->assertSame( 'Customer #42', $out['customer']['first_name'] );
		$this->assertSame( 'j***@e***.com', $out['customer']['email'] );
		$this->assertArrayNotHasKey( 'card_number', $out['customer'] );
	}

	/**
	 * The `mask_context_names` opt masks a top-level `name` sitting outside any
	 * billing/shipping/customer context (a pure person record).
	 *
	 * @return void
	 */
	public function test_mask_context_names_opt_masks_top_level_name(): void {
		$payload = [
			'id'    => 7,
			'name'  => 'John Doe',
			'email' => 'john@example.com',
		];

		$out = PiiPolicy::redact( $payload, [], [ 'mask_context_names' => true ] );

		$this->assertSame( 'Customer #7', $out['name'] );
		$this->assertSame( 'j***@e***.com', $out['email'] );
	}

	/**
	 * Without the opt, a top-level `name` outside a personal-data context is
	 * left intact (e.g. a product name).
	 *
	 * @return void
	 */
	public function test_without_opt_top_level_name_survives(): void {
		$payload = [
			'name'  => 'Blue T-Shirt',
			'price' => '19.99',
		];

		$out = PiiPolicy::redact( $payload );

		$this->assertSame( 'Blue T-Shirt', $out['name'] );
	}
}
