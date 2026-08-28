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

use Albert\Admin\Settings\Lock;
use Albert\Admin\Settings\Overrides;
use Albert\Admin\Settings\Schema;
use Albert\Admin\Settings\Value;
use Albert\Admin\SettingsRegistry;
use Albert\Admin\SettingsRenderer;
use Albert\Admin\SettingsSanitizer;
use Albert\Media\UploadLinks\UploadLinkService;
use Albert\OAuth\AllowedUsers;
use Albert\OAuth\ConnectionRetention;
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

		$this->assertTrue( Lock::is_locked( $field, Value::override( 'albert_privacy_mode' ) ) );
	}

	/**
	 * An ordinary field is not skipped, or nothing could ever be saved.
	 *
	 * @return void
	 */
	public function test_the_save_loop_does_not_skip_an_ordinary_field(): void {
		$field = $this->field_for( 'albert_privacy_mode' );

		$this->assertFalse( Lock::is_locked( $field, Value::override( 'albert_privacy_mode' ) ) );
	}

	/**
	 * An override the option's own validator rejects locks nothing.
	 *
	 * The screen and the code reading a setting have to agree about whether an
	 * override is in force. They did not: `PrivacyMode::resolve()` applied a
	 * vocabulary check and the screen did not, so a typo'd constant rendered the
	 * field read-only, showing the typo, naming the constant, and skipped by
	 * the save loop, while the site went on running the stored mode. The owner
	 * was locked out of a setting the constant did not control.
	 *
	 * @return void
	 */
	public function test_an_invalid_override_neither_locks_the_field_nor_changes_the_mode(): void {
		update_option( 'albert_privacy_mode', 'off' );

		add_filter( 'albert/privacy/mode', static fn () => 'bananas' );

		$field = $this->field_for( 'albert_privacy_mode' );
		$this->assertNotNull( $field );

		$this->assertNull(
			Value::override( 'albert_privacy_mode' ),
			'A value outside the vocabulary is not an override.'
		);
		$this->assertFalse( Lock::is_locked( $field, Value::override( 'albert_privacy_mode' ) ) );
		$this->assertSame( PrivacyMode::Off, PrivacyMode::resolve() );
	}

	/**
	 * The same, one layer up: a constant is skipped too, so resolution falls
	 * through to the stored value rather than pinning the site to nonsense.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_an_invalid_constant_falls_through_to_the_stored_mode(): void {
		define( 'ALBERT_PRIVACY_MODE', 'bananas' );
		update_option( 'albert_privacy_mode', 'off' );

		$this->assertNull( Value::override( 'albert_privacy_mode' ) );
		$this->assertSame( PrivacyMode::Off, PrivacyMode::resolve() );
	}

	/**
	 * A validator answers to the option, so it applies without anything having
	 * been booted.
	 *
	 * Free's own live in a static map rather than on a hook for exactly this
	 * reason: a privacy question asked before `plugins_loaded` must still get
	 * the fall-through.
	 *
	 * @return void
	 */
	public function test_the_validator_does_not_depend_on_registration(): void {
		remove_all_filters( Value::validator_name( 'albert_privacy_mode' ) );

		$validator = Value::validator( 'albert_privacy_mode' );

		$this->assertIsCallable( $validator );
		$this->assertTrue( $validator( 'strict' ) );
		$this->assertFalse( $validator( 'bananas' ) );
	}

	/**
	 * Every setting the screen can report as overridden is read through the
	 * chain that decides that, so the two cannot describe the site differently.
	 *
	 * A constant used to lock the connection-retention fields on screen while
	 * the sweeps went on reading the stored option: a countdown to a date
	 * nothing was ever going to act on, and a field nobody could edit.
	 *
	 * @return void
	 */
	public function test_the_retention_settings_resolve_through_the_chain(): void {
		$cases = [
			ConnectionRetention::NEVER_USED_OPTION => 'sweep_never_used',
			ConnectionRetention::IDLE_OPTION       => 'sweep_idle',
			AllowedUsers::EXPIRY_OPTION            => null,
		];

		foreach ( $cases as $option_name => $unused ) {
			update_option( $option_name, 5 );

			add_filter( Value::filter_name( $option_name ), static fn () => 30 );

			$this->assertSame(
				30,
				Value::get( $option_name, 0 ),
				$option_name . ' must resolve through the settings chain.'
			);

			remove_all_filters( Value::filter_name( $option_name ) );
			delete_option( $option_name );
		}
	}

	/**
	 * The retention sweep uses the overridden window, not the stored one.
	 *
	 * @return void
	 */
	public function test_the_idle_sweep_reads_the_override(): void {
		update_option( ConnectionRetention::IDLE_OPTION, 0 );

		// 0 disables the sweep; the override switches it back on, which is only
		// observable if the sweep is reading the same chain the screen does.
		add_filter( Value::filter_name( ConnectionRetention::IDLE_OPTION ), static fn () => 30 );

		$this->assertIsArray( ConnectionRetention::sweep_idle() );
		$this->assertSame( 30, (int) Value::get( ConnectionRetention::IDLE_OPTION, 0 ) );

		remove_all_filters( Value::filter_name( ConnectionRetention::IDLE_OPTION ) );
		delete_option( ConnectionRetention::IDLE_OPTION );
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
