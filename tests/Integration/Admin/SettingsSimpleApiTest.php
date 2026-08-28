<?php
/**
 * Integration tests for the simplified add-on settings API.
 *
 * `albert_register_setting()` is the entry point add-ons actually use, and it
 * rebuilds a field from a whitelist rather than merging what it was handed,
 * so a key it forgets is dropped in silence rather than erroring. These tests
 * hold that whitelist to what the documentation promises.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Admin;

use Albert\Admin\Settings\Schema;
use Albert\Admin\SettingsRegistry;
use Albert\Admin\SettingsRenderer;
use Albert\Admin\SettingsSanitizer;
use Albert\Tests\TestCase;

/**
 * Simplified settings API tests.
 *
 * @covers ::albert_register_setting
 */
class SettingsSimpleApiTest extends TestCase {

	/**
	 * Start from an empty registry so a test sees only its own field.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		SettingsRegistry::reset();
		Schema::reset_cache();
	}

	/**
	 * Drop anything a test registered.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_actions( 'albert/settings/register' );

		SettingsRegistry::reset();
		Schema::reset_cache();

		parent::tear_down();
	}

	/**
	 * A declared range reaches both the control and the sanitiser.
	 *
	 * The regression: `min` and `max` were forwarded from the advanced API but
	 * not from this one, so the range in the documented example was dropped
	 * without a word: the control rendered unbounded and a crafted POST of
	 * 99999 was stored against a declared maximum of 3650.
	 *
	 * @return void
	 */
	public function test_a_declared_range_is_rendered_and_enforced(): void {
		$field = $this->register(
			[
				'title'       => 'Log retention',
				'option_name' => 'albert_test_retention_days',
				'type'        => 'number',
				'min'         => 0,
				'max'         => 3650,
			]
		);

		$this->assertSame( 0, $field['min'] );
		$this->assertSame( 3650, $field['max'] );

		// Enforced.
		$this->assertSame( 3650, ( new SettingsSanitizer() )->sanitize_field( $field, '99999' ) );
		$this->assertSame( 0, ( new SettingsSanitizer() )->sanitize_field( $field, '-5' ) );

		// And rendered, so the browser and the stored value agree.
		ob_start();
		( new SettingsRenderer() )->render_field( $field, 30 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'min="0"', $html );
		$this->assertStringContainsString( 'max="3650"', $html );
	}

	/**
	 * A range declared under `attributes` keeps working, which is where it
	 * lived before 1.4.0.
	 *
	 * @return void
	 */
	public function test_a_range_under_attributes_still_clamps(): void {
		$field = $this->register(
			[
				'title'       => 'Log retention',
				'option_name' => 'albert_test_retention_days',
				'type'        => 'number',
				'attributes'  => [
					'min' => 0,
					'max' => 365,
				],
			]
		);

		$this->assertSame( 365, ( new SettingsSanitizer() )->sanitize_field( $field, '99999' ) );
	}

	/**
	 * A field naming its own card, with a title, gets that card.
	 *
	 * @return void
	 */
	public function test_a_field_can_create_and_name_its_own_card(): void {
		$this->register(
			[
				'title'         => 'Log retention',
				'option_name'   => 'albert_test_retention_days',
				'type'          => 'number',
				'section'       => 'my-addon/logging',
				'section_title' => 'Logging',
			]
		);

		$titles = [];
		foreach ( Schema::collect() as $section ) {
			$titles[ $section['id'] ] = $section['title'];
		}

		$this->assertArrayHasKey( 'my-addon/logging', $titles );
		$this->assertSame( 'Logging', $titles['my-addon/logging'] );
	}

	/**
	 * Register one field and hand back its normalised shape.
	 *
	 * @param array<string, mixed> $setting Simplified field definition.
	 *
	 * @return array<string, mixed>
	 */
	private function register( array $setting ): array {
		add_action(
			'albert/settings/register',
			static function () use ( $setting ): void {
				albert_register_setting( $setting );
			}
		);

		foreach ( Schema::collect() as $section ) {
			foreach ( $section['fields'] as $field ) {
				if ( ( $field['option_name'] ?? '' ) === $setting['option_name'] ) {
					return $field;
				}
			}
		}

		$this->fail( 'The field was not registered.' );
	}
}
