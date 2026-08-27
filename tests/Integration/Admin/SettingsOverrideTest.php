<?php
/**
 * Integration tests for settings values owned by code.
 *
 * A setting can be decided by a `wp-config.php` constant or by a filter instead
 * of by the stored option. Before 1.4.0 each setting that allowed this
 * hand-rolled its own precedence, and only the upload size limit told the
 * Settings screen about it — so a site filtering the privacy mode saw the
 * stored value on screen, fully editable, and could save over it without
 * changing what the site actually did.
 *
 * These tests are integration rather than unit because the behaviour now runs
 * through real `add_filter()` plumbing: the unit suite's `apply_filters` stub
 * returns configured values without invoking callbacks, so it cannot see the
 * bridge that carries the domain-specific filters into the shared chain.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Admin;

use Albert\Admin\SettingsRegistry;
use Albert\Admin\Settings\Overrides;
use Albert\Admin\Settings\Schema;
use Albert\Admin\Settings\Value;
use Albert\Admin\Settings;
use Albert\Admin\SettingsRenderer;
use Albert\Admin\SettingsSanitizer;
use Albert\Media\UploadLinks\UploadLinkService;
use Albert\Privacy\PrivacyMode;
use Albert\Tests\TestCase;

/**
 * Override resolution and the read-only UI it produces.
 *
 * @covers \Albert\Admin\Settings\Value
 * @covers \Albert\Admin\Settings\Overrides
 */
class SettingsOverrideTest extends TestCase {

	/**
	 * Register the bridge, as Plugin::init() does on a real request.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		SettingsRegistry::reset();
		Schema::reset_cache();
		( new Overrides() )->register_hooks();
	}

	/**
	 * Drop anything a test hooked so it cannot leak into the next.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'albert/privacy/mode' );
		remove_all_filters( 'albert/media/upload_link_max_bytes' );
		remove_all_filters( Value::filter_name( 'albert_privacy_mode' ) );
		remove_all_filters( Value::filter_name( UploadLinkService::MAX_BYTES_OPTION ) );

		SettingsRegistry::reset();
		Schema::reset_cache();

		parent::tear_down();
	}

	/**
	 * The 1.3.0 filter is not deprecated and still decides the mode.
	 *
	 * @return void
	 */
	public function test_the_domain_filter_still_overrides_the_stored_mode(): void {
		update_option( 'albert_privacy_mode', 'strict' );

		add_filter( 'albert/privacy/mode', static fn () => 'off' );

		$this->assertSame( PrivacyMode::Off, PrivacyMode::resolve() );
	}

	/**
	 * ...and the screen now says so, instead of offering an editable control
	 * whose value the filter would discard.
	 *
	 * @return void
	 */
	public function test_a_filtered_mode_renders_read_only_and_names_the_filter(): void {
		update_option( 'albert_privacy_mode', 'strict' );

		add_filter( 'albert/privacy/mode', static fn () => 'off' );

		$html = $this->render_field( 'albert_privacy_mode' );

		$this->assertStringContainsString( 'albert-radio-cards--disabled', $html );
		$this->assertSame(
			3,
			substr_count( $html, 'disabled=' ),
			'Every choice should be disabled, not only the one in force.'
		);
		// Names the hook the site actually wrote. Reporting the generic hook this
		// is bridged onto would send somebody grepping for a string that appears
		// nowhere in their code.
		$this->assertStringContainsString( 'albert/privacy/mode', $html );
		$this->assertStringNotContainsString( 'albert/settings/value/albert_privacy_mode', $html );

		// The value shown is the one in force, not the one stored.
		$this->assertMatchesRegularExpression( '/value="off"[^>]*checked/', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="strict"[^>]*checked/', $html );
	}

	/**
	 * With nothing overriding it the control is ordinary: editable, no note.
	 *
	 * @return void
	 */
	public function test_an_unoverridden_field_stays_editable(): void {
		update_option( 'albert_privacy_mode', 'strict' );

		$html = $this->render_field( 'albert_privacy_mode' );

		$this->assertStringNotContainsString( 'albert-radio-cards--disabled', $html );
		$this->assertStringNotContainsString( 'disabled=', $html );
		$this->assertStringNotContainsString( 'albert-field-hint', $html );
		$this->assertMatchesRegularExpression( '/value="strict"[^>]*checked/', $html );
	}

	/**
	 * A constant beats a filter, and is named on screen.
	 *
	 * Uses a setting invented for this test: a constant cannot be undefined, so
	 * defining one for a real option would follow the suite into every later
	 * test in the process.
	 *
	 * @return void
	 */
	public function test_a_constant_wins_and_is_reported(): void {
		define( 'ALBERT_TEST_OVERRIDE_OPTION', 'from-constant' );

		add_filter( Value::filter_name( 'albert_test_override_option' ), static fn () => 'from-filter' );
		update_option( 'albert_test_override_option', 'from-option' );

		$override = Value::override( 'albert_test_override_option' );

		$this->assertNotNull( $override );
		$this->assertSame( 'constant', $override['source'] );
		$this->assertSame( 'ALBERT_TEST_OVERRIDE_OPTION', $override['name'] );
		$this->assertSame( 'from-constant', Value::get( 'albert_test_override_option' ) );
		$this->assertSame( 'from-constant', albert_get_setting( 'albert_test_override_option' ) );

		delete_option( 'albert_test_override_option' );
	}

	/**
	 * The upload limit keeps its own hint rather than taking the generated one,
	 * because only it can state the exact size in force — the control rounds up
	 * to whole megabytes, and 500 KB would otherwise display as "1 MB" with
	 * nothing to correct the impression.
	 *
	 * @return void
	 */
	public function test_the_upload_field_keeps_its_more_precise_hint(): void {
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => 500 * 1024 );

		$html = $this->render_field( UploadLinkService::MAX_BYTES_OPTION );

		$this->assertStringContainsString( 'disabled', $html );
		$this->assertStringContainsString( size_format( 500 * 1024 ), $html );
		$this->assertStringContainsString( 'albert/media/upload_link_max_bytes', $html );

		// Rounded up for display, never to zero: the control's own minimum is 1.
		$this->assertStringContainsString( 'value="1"', $html );
	}

	/**
	 * The byte filter still sets the limit that is actually enforced, exactly,
	 * rather than the megabyte-rounded figure the screen displays.
	 *
	 * @return void
	 */
	public function test_the_enforced_upload_limit_is_not_rounded(): void {
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => 500 * 1024 );

		$service    = new UploadLinkService();
		$reflection = new \ReflectionMethod( $service, 'default_max_bytes' );
		$reflection->setAccessible( true );

		$this->assertSame( 500 * 1024, $reflection->invoke( $service ) );
	}

	/**
	 * Render one registered field and return its markup.
	 *
	 * @param string $option_name The option the field writes.
	 *
	 * @return string
	 */
	private function render_field( string $option_name ): string {
		$field = null;

		foreach ( Schema::collect() as $section ) {
			foreach ( $section['fields'] ?? [] as $candidate ) {
				if ( is_array( $candidate ) && ( $candidate['option_name'] ?? '' ) === $option_name ) {
					$field = $candidate;
				}
			}
		}

		$this->assertNotNull( $field, sprintf( 'No registered field writes "%s".', $option_name ) );

		$default = array_key_exists( 'default', $field ) ? $field['default'] : '';

		ob_start();
		( new SettingsRenderer() )->render_field( $field, get_option( $option_name, $default ) );

		return (string) ob_get_clean();
	}

	/**
	 * Saving the form while an override is active must not touch what is
	 * stored.
	 *
	 * The control renders read-only, so the browser submits nothing for it, and
	 * an absent value sanitises to a bounded number's minimum — or, here, to the
	 * privacy default. Writing that would destroy the owner's own choice the
	 * first time they saved any other setting on the page, and they would have
	 * no way to notice, because the screen shows the override rather than the
	 * stored value.
	 *
	 * Asserted against the save loop's own guard rather than by running
	 * handle_save_settings(), which redirects and exits.
	 *
	 * @return void
	 */
	public function test_the_save_loop_skips_an_overridden_field(): void {
		update_option( 'albert_privacy_mode', 'strict' );

		add_filter( 'albert/privacy/mode', static fn () => 'off' );

		$field = $this->field_for( 'albert_privacy_mode' );
		$this->assertNotNull( $field );

		// The value the loop would otherwise write is not the stored one, so
		// the guard is load-bearing rather than belt-and-braces.
		$this->assertNotSame(
			'strict',
			( new SettingsSanitizer() )->sanitize_field( $field, null ),
			'An absent value must sanitise to something destructive for this test to mean anything.'
		);

		$guard = new \ReflectionMethod( Settings::class, 'is_field_locked' );
		$guard->setAccessible( true );

		$this->assertTrue( $guard->invoke( new Settings(), $field, 'albert_privacy_mode' ) );
	}

	/**
	 * An ordinary field is not skipped, or nothing could ever be saved.
	 *
	 * @return void
	 */
	public function test_the_save_loop_does_not_skip_an_ordinary_field(): void {
		$field = $this->field_for( 'albert_privacy_mode' );

		$guard = new \ReflectionMethod( Settings::class, 'is_field_locked' );
		$guard->setAccessible( true );

		$this->assertFalse( $guard->invoke( new Settings(), $field, 'albert_privacy_mode' ) );
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
}
