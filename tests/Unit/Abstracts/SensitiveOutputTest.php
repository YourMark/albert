<?php
/**
 * Unit tests for BaseAbility's sensitive-output redaction.
 *
 * The generic contract, independent of the one ability that currently uses
 * it: observers are loggers, and a credential an ability has to hand its
 * caller should not also land in a log column in the clear.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Abstracts;

use Albert\Abstracts\BaseAbility;
use Albert\Tests\Unit\StubAbility;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once __DIR__ . '/../stubs/StubAbility.php';

/**
 * An ability that declares one of its result keys secret.
 */
class SecretBearingAbility extends StubAbility {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>|WP_Error $return_value Value returned by execute().
	 */
	public function __construct( array|WP_Error $return_value = [] ) {
		parent::__construct( 'test/mints-a-secret', $return_value );

		$this->sensitive_output_keys = [ 'token' ];
	}
}

/**
 * BaseAbility sensitive-output tests.
 *
 * @covers \Albert\Abstracts\BaseAbility
 */
class SensitiveOutputTest extends TestCase {

	/**
	 * Reset the recorded hook calls before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_hooks']   = [];
		$GLOBALS['albert_test_user_id'] = 42;
		$GLOBALS['albert_test_options'] = [
			'albert_abilities_saved'    => true,
			'albert_disabled_abilities' => [],
		];
	}

	/**
	 * The result handed to observers for a given ability.
	 *
	 * @param string $hook The hook to read.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private function observed_result( string $hook = 'albert/abilities/after_execute' ) {
		foreach ( $GLOBALS['albert_test_hooks'] as $call ) {
			if ( $call['hook'] === $hook ) {
				// The after_execute hook passes id, args, result and user id, in
				// that order, so the result is the third argument.
				return $call['args'][2];
			}
		}

		$this->fail( "No {$hook} call was recorded." );
	}

	/**
	 * The caller gets the secret; the observer gets a mask.
	 *
	 * @return void
	 */
	public function test_declared_keys_are_masked_for_observers_only(): void {
		$ability = new SecretBearingAbility(
			[
				'token'      => 'super-secret-value',
				'expires_at' => '2026-01-01 00:00:00',
			]
		);

		$returned = $ability->guarded_execute( [] );

		$this->assertSame( 'super-secret-value', $returned['token'], 'The caller must still receive the real value.' );

		$observed = $this->observed_result();

		$this->assertSame( '[redacted]', $observed['token'] );
		$this->assertSame( '2026-01-01 00:00:00', $observed['expires_at'], 'Only declared keys are touched.' );
	}

	/**
	 * The per-ability hook is redacted too, not just the general one.
	 *
	 * @return void
	 */
	public function test_the_per_ability_hook_is_redacted_as_well(): void {
		$ability = new SecretBearingAbility( [ 'token' => 'super-secret-value' ] );
		$ability->guarded_execute( [] );

		foreach ( $GLOBALS['albert_test_hooks'] as $call ) {
			if ( $call['hook'] === 'albert/abilities/after_execute/test/mints-a-secret' ) {
				// This hook passes ( $args, $result, $user_id ).
				$this->assertSame( '[redacted]', $call['args'][1]['token'] );

				return;
			}
		}

		$this->fail( 'The per-ability after_execute hook was never fired.' );
	}

	/**
	 * Masking, not removal: a log should still record that the field came back.
	 *
	 * @return void
	 */
	public function test_a_declared_key_is_masked_rather_than_dropped(): void {
		$ability = new SecretBearingAbility( [ 'token' => 'super-secret-value' ] );
		$ability->guarded_execute( [] );

		$this->assertArrayHasKey( 'token', $this->observed_result() );
	}

	/**
	 * An ability that declares nothing sensitive is passed through untouched,
	 * which is every ability but one.
	 *
	 * @return void
	 */
	public function test_an_ability_without_declared_keys_is_untouched(): void {
		$ability = new StubAbility( 'test/ordinary', [ 'token' => 'not-actually-secret' ] );
		$ability->guarded_execute( [] );

		$this->assertSame( 'not-actually-secret', $this->observed_result()['token'] );
	}

	/**
	 * A declared key the result never carried is not invented.
	 *
	 * @return void
	 */
	public function test_a_missing_declared_key_is_not_added(): void {
		$ability = new SecretBearingAbility( [ 'something_else' => 'value' ] );
		$ability->guarded_execute( [] );

		$this->assertArrayNotHasKey( 'token', $this->observed_result() );
	}

	/**
	 * Errors pass through: a WP_Error carries a code and a message, never a
	 * minted credential, and loggers need it intact to record the failure.
	 *
	 * @return void
	 */
	public function test_an_error_result_passes_through_unchanged(): void {
		$error   = new WP_Error( 'nope', 'Could not mint anything.' );
		$ability = new SecretBearingAbility( $error );

		$ability->guarded_execute( [] );

		$observed = $this->observed_result();

		$this->assertInstanceOf( WP_Error::class, $observed );
		$this->assertSame( 'nope', $observed->get_error_code() );
	}

	/**
	 * The property exists on the base class, so any ability can declare it.
	 *
	 * @return void
	 */
	public function test_the_seam_lives_on_the_base_class(): void {
		$this->assertTrue(
			property_exists( BaseAbility::class, 'sensitive_output_keys' ),
			'Add-ons declare secrets through this property; it is part of the ability contract.'
		);
	}
}
