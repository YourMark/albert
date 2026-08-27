<?php
/**
 * Integration tests for Settings\Storage.
 *
 * The point of registering Albert's settings with WordPress is that
 * sanitisation stops being a property of the settings form and becomes a
 * property of the option: `register_setting()` hooks `sanitize_option_{$name}`,
 * and `update_option()` applies that filter itself. These tests assert that
 * through `update_option()` rather than by inspecting the registration, because
 * the registration is the mechanism and the behaviour is the point.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Admin;

use Albert\Admin\SettingsRegistry;
use Albert\Admin\Settings\Schema;
use Albert\Admin\SettingsSanitizer;
use Albert\Admin\Settings\Storage;
use Albert\Media\UploadLinks\UploadLinkService;
use Albert\OAuth\ConnectionRetention;
use Albert\Privacy\PrivacyMode;
use Albert\Tests\TestCase;

/**
 * Settings storage integration tests.
 *
 * @covers \Albert\Admin\Settings\Storage
 * @covers \Albert\Admin\Settings\Schema
 */
class SettingsStorageTest extends TestCase {

	/**
	 * Register the settings, as an admin request would.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		SettingsRegistry::reset();
		Schema::reset_cache();
		( new Storage() )->register_settings();
	}

	/**
	 * Forget the collected schema so a test that filters sections cannot leak
	 * into the next one.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		SettingsRegistry::reset();
		Schema::reset_cache();

		parent::tear_down();
	}

	/**
	 * The registration argument array for one option.
	 *
	 * @param string $option_name Option name.
	 *
	 * @return array<string, mixed>|null
	 */
	private function registration( string $option_name ): ?array {
		global $wp_registered_settings;

		return $wp_registered_settings[ $option_name ] ?? null;
	}

	/**
	 * Every field the screen renders is registered, with a usable type.
	 *
	 * @return void
	 */
	public function test_built_in_fields_are_registered(): void {
		$expected = [
			'albert_privacy_mode'                  => 'string',
			ConnectionRetention::NEVER_USED_OPTION => 'integer',
			ConnectionRetention::IDLE_OPTION       => 'integer',
			UploadLinkService::MAX_BYTES_OPTION    => 'integer',
		];

		foreach ( $expected as $option_name => $type ) {
			$registration = $this->registration( $option_name );

			$this->assertNotNull( $registration, sprintf( '"%s" should be registered.', $option_name ) );
			$this->assertSame( $type, $registration['type'] );
		}
	}

	/**
	 * A read-only custom field stores nothing, so there is no option to
	 * register. The licences table marks itself with `__return_null`.
	 *
	 * @return void
	 */
	public function test_read_only_custom_fields_are_not_registered(): void {
		$this->assertNull( $this->registration( 'albert_licenses_licenses_table' ) );
	}

	/**
	 * The declared default is served by get_option() without the caller
	 * passing one — which is what stops a default drifting between the field
	 * definition and the class that reads it.
	 *
	 * @return void
	 */
	public function test_the_declared_default_is_served_without_asking_for_it(): void {
		delete_option( ConnectionRetention::NEVER_USED_OPTION );

		$this->assertSame(
			ConnectionRetention::DEFAULT_NEVER_USED_DAYS,
			get_option( ConnectionRetention::NEVER_USED_OPTION )
		);
	}

	/**
	 * The guarantee, on the setting where it matters most: a write that never
	 * touches the settings form still cannot store a privacy mode outside the
	 * closed vocabulary.
	 *
	 * @return void
	 */
	public function test_a_direct_write_cannot_store_an_invalid_privacy_mode(): void {
		update_option( 'albert_privacy_mode', 'strict' );
		$this->assertSame( 'strict', get_option( 'albert_privacy_mode' ) );

		update_option( 'albert_privacy_mode', 'garbage' );
		$this->assertSame(
			PrivacyMode::Balanced->value,
			get_option( 'albert_privacy_mode' ),
			'An unrecognised mode must fall back to the default, not be stored.'
		);

		update_option( 'albert_privacy_mode', '<script>alert(1)</script>' );
		$this->assertSame( PrivacyMode::Balanced->value, get_option( 'albert_privacy_mode' ) );
	}

	/**
	 * The same for a plain number field: a direct write is coerced, not stored
	 * as whatever string arrived.
	 *
	 * @return void
	 */
	public function test_a_direct_write_to_a_number_field_is_coerced(): void {
		update_option( ConnectionRetention::IDLE_OPTION, 'nonsense' );
		$this->assertSame( 0, get_option( ConnectionRetention::IDLE_OPTION ) );

		update_option( ConnectionRetention::IDLE_OPTION, '42' );
		$this->assertSame( 42, get_option( ConnectionRetention::IDLE_OPTION ) );
	}

	/**
	 * Sanitisers have to be idempotent, because the save loop sanitises and
	 * then `update_option()` sanitises the result again through the registered
	 * callback. A second pass over already-clean input must return it unchanged
	 * and must not repeat a side effect — Free's clamping sanitiser raises an
	 * `add_settings_error()` when it clamps, and that must happen once.
	 *
	 * @return void
	 */
	public function test_registered_sanitisers_are_idempotent(): void {
		$registration = $this->registration( ConnectionRetention::NEVER_USED_OPTION );
		$this->assertNotNull( $registration );

		$once  = call_user_func( $registration['sanitize_callback'], '30' );
		$twice = call_user_func( $registration['sanitize_callback'], $once );

		$this->assertSame( $once, $twice, 'A second pass over clean input must change nothing.' );
		$this->assertSame( 30, $once );
	}

	/**
	 * The regression the plan predicted, tested through the real save path.
	 *
	 * Registering the option means a save sanitises twice: once in the save loop
	 * and again inside `update_option()`. The clamping sanitiser is the only one
	 * in the system that talks back to the user, so it is the only one where a
	 * second pass could be noticed — as a duplicated warning. It is not, because
	 * the second pass is handed the already-clamped value, which is in range.
	 *
	 * @return void
	 */
	public function test_a_clamped_value_warns_exactly_once(): void {
		// A filter owning the value makes the field read-only and the sanitiser
		// a no-op, so clear any before testing the clamp.
		remove_all_filters( 'albert/media/upload_link_max_bytes' );

		$option_name = UploadLinkService::MAX_BYTES_OPTION;
		$field       = $this->field_for( $option_name );
		$this->assertNotNull( $field );

		$cases = [
			'999999' => 2048,
			'0'      => UploadLinkService::DEFAULT_MAX_MB,
		];

		foreach ( $cases as $raw => $expected ) {
			update_option( $option_name, UploadLinkService::DEFAULT_MAX_MB );

			// Counted as a delta rather than by clearing $wp_settings_errors:
			// the global belongs to WordPress, and what matters is how many
			// warnings this one save adds, not how many exist in total.
			$before = count( get_settings_errors( 'albert_settings' ) );

			$clean = ( new SettingsSanitizer() )->sanitize_field( $field, (string) $raw );
			update_option( $option_name, $clean );

			$this->assertSame( $expected, (int) get_option( $option_name ) );
			$this->assertSame(
				1,
				count( get_settings_errors( 'albert_settings' ) ) - $before,
				sprintf( 'Saving "%s" should warn once, not once per sanitise pass.', $raw )
			);
		}
	}

	/**
	 * The registered field that writes one option.
	 *
	 * @param string $option_name Option name.
	 *
	 * @return array<string, mixed>|null
	 */
	private function field_for( string $option_name ): ?array {
		foreach ( Schema::collect() as $section ) {
			foreach ( $section['fields'] ?? [] as $field ) {
				if ( is_array( $field ) && ( $field['option_name'] ?? '' ) === $option_name ) {
					return $field;
				}
			}
		}

		return null;
	}

	/**
	 * The add-on path, end to end.
	 *
	 * `albert_register_setting()` is how an add-on adds a field, and Premium
	 * calls it unguarded, so it is the one entry point here that cannot be
	 * allowed to regress. Nothing covered it before: the global helpers were
	 * absent from this suite entirely, because Composer's `files` autoload runs
	 * before WordPress and src/functions.php returns at its own ABSPATH guard.
	 * The bootstrap now loads it, which is what makes this assertable.
	 *
	 * @return void
	 */
	public function test_an_addon_registered_setting_reaches_wordpress(): void {
		SettingsRegistry::reset();
		Schema::reset_cache();

		add_action(
			'albert/settings/register',
			static function () {
				albert_register_setting(
					[
						'title'       => 'Retention',
						'option_name' => 'albert_test_addon_retention_days',
						'type'        => 'number',
						'default'     => 90,
					]
				);
			}
		);

		( new Storage() )->register_settings();

		$registration = $this->registration( 'albert_test_addon_retention_days' );

		$this->assertNotNull( $registration, 'An add-on field must reach register_setting().' );
		$this->assertSame( 'integer', $registration['type'] );

		// The declared default is served without the reader restating it, and a
		// junk write is coerced rather than stored.
		$this->assertSame( 90, get_option( 'albert_test_addon_retention_days' ) );

		update_option( 'albert_test_addon_retention_days', 'nonsense' );
		$this->assertSame( 0, get_option( 'albert_test_addon_retention_days' ) );

		delete_option( 'albert_test_addon_retention_days' );
		remove_all_actions( 'albert/settings/register' );
	}

	/**
	 * A simple-API field can name the card it belongs in, and an unknown name
	 * misplaces it rather than losing it.
	 *
	 * @return void
	 */
	public function test_an_addon_field_can_choose_its_card(): void {
		SettingsRegistry::reset();
		Schema::reset_cache();

		add_action(
			'albert/settings/register',
			static function () {
				albert_register_settings_section(
					[
						'id'     => 'test/addon-card',
						'title'  => 'Add-on card',
						'fields' => [],
					]
				);
				albert_register_setting(
					[
						'title'       => 'Placed',
						'option_name' => 'albert_test_placed',
						'type'        => 'text',
						'section'     => 'test/addon-card',
					]
				);
				albert_register_setting(
					[
						'title'       => 'Misplaced',
						'option_name' => 'albert_test_misplaced',
						'type'        => 'text',
						'section'     => 'test/no-such-card',
					]
				);
			}
		);

		$cards = [];
		foreach ( Schema::collect() as $section ) {
			foreach ( $section['fields'] ?? [] as $field ) {
				if ( is_array( $field ) && isset( $field['option_name'] ) ) {
					$cards[ $field['option_name'] ] = $section['id'] ?? '';
				}
			}
		}

		$this->assertSame( 'test/addon-card', $cards['albert_test_placed'] ?? null );
		$this->assertSame(
			'albert/settings',
			$cards['albert_test_misplaced'] ?? null,
			'A mistyped section id should misplace the field, never drop it.'
		);

		remove_all_actions( 'albert/settings/register' );
	}

	/**
	 * One call can create the card as well as the field, which is what lets an
	 * add-on get a heading that names its subject instead of landing in the
	 * shared catch-all.
	 *
	 * @return void
	 */
	public function test_a_field_can_create_its_own_titled_card(): void {
		SettingsRegistry::reset();
		Schema::reset_cache();

		add_action(
			'albert/settings/register',
			static function () {
				albert_register_setting(
					[
						'title'            => 'Log retention',
						'option_name'      => 'albert_test_retention',
						'type'             => 'number',
						'section'          => 'testaddon/logging',
						'section_title'    => 'Logging',
						'section_priority' => 40,
					]
				);
				// A second field naming the same section joins the card that
				// now exists, rather than trying to create it twice.
				albert_register_setting(
					[
						'title'       => 'Log level',
						'option_name' => 'albert_test_level',
						'type'        => 'text',
						'section'     => 'testaddon/logging',
					]
				);
			}
		);

		$section = null;
		foreach ( Schema::collect() as $candidate ) {
			if ( ( $candidate['id'] ?? '' ) === 'testaddon/logging' ) {
				$section = $candidate;
			}
		}

		$this->assertNotNull( $section, 'section_title should create the card.' );
		$this->assertSame( 'Logging', $section['title'] );
		$this->assertSame( 40, $section['priority'] );
		$this->assertCount( 2, $section['fields'] );

		// Nothing was left in the catch-all.
		foreach ( Schema::collect() as $candidate ) {
			if ( ( $candidate['id'] ?? '' ) === 'albert/settings' ) {
				$this->fail( 'The shared card should not exist when every field named a section.' );
			}
		}

		remove_all_actions( 'albert/settings/register' );
	}

	/**
	 * A section id has to be namespaced, the same rule
	 * albert_register_settings_section() enforces. Told loudly, and the field
	 * still lands somewhere.
	 *
	 * @return void
	 */
	public function test_an_unnamespaced_section_id_is_rejected_but_the_field_survives(): void {
		SettingsRegistry::reset();
		Schema::reset_cache();

		$this->setExpectedIncorrectUsage( 'albert_register_setting' );

		add_action(
			'albert/settings/register',
			static function () {
				albert_register_setting(
					[
						'title'         => 'Stray',
						'option_name'   => 'albert_test_stray',
						'type'          => 'text',
						'section'       => 'logging',
						'section_title' => 'Logging',
					]
				);
			}
		);

		$found = false;
		foreach ( Schema::collect() as $section ) {
			foreach ( $section['fields'] ?? [] as $field ) {
				if ( is_array( $field ) && ( $field['option_name'] ?? '' ) === 'albert_test_stray' ) {
					$found = ( $section['id'] ?? '' ) === 'albert/settings';
				}
			}
		}

		$this->assertTrue( $found, 'A rejected section id should misplace the field, not lose it.' );

		remove_all_actions( 'albert/settings/register' );
	}
}
