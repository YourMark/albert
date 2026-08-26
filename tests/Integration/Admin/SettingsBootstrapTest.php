<?php
/**
 * Integration tests for SettingsBootstrap's built-in sections.
 *
 * Covers only the Uploads section added for the media-upload-link byte
 * cap — the other built-in sections (Privacy, Connections, Licenses)
 * predate this file and have no dedicated coverage of their own yet.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Admin;

use Albert\Admin\SettingsBootstrap;
use Albert\Media\UploadLinks\UploadLinkService;
use Albert\Tests\TestCase;

/**
 * SettingsBootstrap integration tests.
 *
 * @covers \Albert\Admin\SettingsBootstrap
 */
class SettingsBootstrapTest extends TestCase {

	/**
	 * Find the Uploads section from the built-in sections list.
	 *
	 * @return array<string, mixed>
	 */
	private function uploads_section(): array {
		foreach ( SettingsBootstrap::get_builtin_sections() as $section ) {
			if ( $section['id'] === 'albert/media' ) {
				return $section;
			}
		}

		$this->fail( 'albert/media section not found in built-in sections.' );
	}

	/**
	 * The Uploads section is registered with the expected single field,
	 * wired to UploadLinkService's option and sanitizer.
	 *
	 * @return void
	 */
	public function test_uploads_section_field_is_wired_correctly(): void {
		$section = $this->uploads_section();

		$this->assertCount( 1, $section['fields'] );

		$field = $section['fields'][0];
		$this->assertSame( 'custom', $field['type'] );
		$this->assertSame( UploadLinkService::MAX_BYTES_OPTION, $field['option_name'] );
		$this->assertSame( UploadLinkService::DEFAULT_MAX_MB, $field['default'] );
		$this->assertSame( [ SettingsBootstrap::class, 'render_max_mb_field' ], $field['render_callback'] );
		$this->assertSame( [ SettingsBootstrap::class, 'sanitize_max_mb' ], $field['sanitize_callback'] );
	}

	/**
	 * The field's description mentions the server's actual upload ceiling,
	 * so an owner isn't left guessing why a large value has no effect.
	 *
	 * @return void
	 */
	public function test_uploads_section_description_mentions_server_ceiling(): void {
		$field = $this->uploads_section()['fields'][0];

		$this->assertStringContainsString( size_format( wp_max_upload_size() ), $field['description'] );
	}

	/**
	 * Whether a settings error with the given code is currently registered.
	 *
	 * @param string $code The settings-error code to look for.
	 *
	 * @return bool
	 */
	private function has_settings_error( string $code ): bool {
		foreach ( get_settings_errors( 'albert_settings' ) as $error ) {
			if ( $error['code'] === $code ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reset the settings-errors global before each test.
	 *
	 * $wp_settings_errors is a plain global, not reset between tests by WP's
	 * own hook-backup mechanism — reset it explicitly so a warning added in
	 * one test can't leak into another's assertions.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_settings_errors;
		$wp_settings_errors = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test isolation, not production code overriding a WP internal.
	}

	// ─── render_max_mb_field() ────────────────────────────────────────

	/**
	 * With no filter hooked, render_max_mb_field() renders an editable
	 * input showing the stored/default value, and no override notice.
	 *
	 * @return void
	 */
	public function test_render_max_mb_field_is_editable_without_a_filter(): void {
		ob_start();
		SettingsBootstrap::render_max_mb_field( [], 9 );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'value="9"', $html );
		$this->assertStringNotContainsString( 'disabled', $html );
		$this->assertStringNotContainsString( 'albert-hint', $html );
	}

	/**
	 * With the filter active, render_max_mb_field() shows the filter's
	 * value (not the $current_value it was passed), disabled, with a
	 * notice naming the filter.
	 *
	 * @return void
	 */
	public function test_render_max_mb_field_shows_filtered_value_when_overridden(): void {
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => 12 * UploadLinkService::BYTES_PER_MB );

		ob_start();
		SettingsBootstrap::render_max_mb_field( [], 9 );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'value="12"', $html );
		$this->assertStringNotContainsString( 'value="9"', $html );
		$this->assertStringContainsString( 'disabled', $html );
		$this->assertStringContainsString( 'albert/media/upload_link_max_bytes', $html );
		$this->assertStringContainsString( 'albert-hint--info', $html );
		$this->assertStringNotContainsString( 'albert-hint--warning', $html );
	}

	/**
	 * When the filter's requested value exceeds the settable ceiling and
	 * gets clamped, the field shows a *warning* hint naming both the
	 * requested and the applied value — not the plain "overridden" info
	 * notice, which wouldn't explain why the number looks capped.
	 *
	 * @return void
	 */
	public function test_render_max_mb_field_warns_when_filter_value_is_clamped(): void {
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => '10G' ); // 10240 MB, clamped to 2048.

		ob_start();
		SettingsBootstrap::render_max_mb_field( [], 9 );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'value="' . UploadLinkService::MAX_SETTABLE_MB . '"', $html );
		$this->assertStringContainsString( 'disabled', $html );
		$this->assertStringContainsString( 'albert-hint--warning', $html );
		$this->assertStringNotContainsString( 'albert-hint--info', $html );
		$this->assertStringContainsString( '10240', $html ); // The requested value, for context.
		$this->assertStringContainsString( (string) UploadLinkService::MAX_SETTABLE_MB, $html );
	}

	/**
	 * A sub-megabyte filter value never renders as 0 against the field's own min="1".
	 *
	 * @return void
	 */
	public function test_a_sub_megabyte_filter_value_never_renders_as_zero(): void {
		add_filter(
			'albert/media/upload_link_max_bytes',
			static function () {
				return 500;
			}
		);

		ob_start();
		SettingsBootstrap::render_max_mb_field( [], 10 );
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'value="0"', $html );
		$this->assertStringContainsString( 'value="1"', $html );
		// The rounding is not allowed to mislead: the hint carries the real size.
		$this->assertStringContainsString( size_format( 500 ), $html );
	}

	// ─── sanitize_max_mb() ────────────────────────────────────────────

	/**
	 * A valid value passes through unchanged.
	 *
	 * @return void
	 */
	public function test_sanitize_max_mb_passes_through_a_valid_value(): void {
		$this->assertSame( 42, SettingsBootstrap::sanitize_max_mb( '42' ) );
	}

	/**
	 * Zero, negative, and non-numeric input fall back to the default rather
	 * than persisting a byte cap of zero.
	 *
	 * @return void
	 */
	public function test_sanitize_max_mb_rejects_invalid_input(): void {
		$this->assertSame( UploadLinkService::DEFAULT_MAX_MB, SettingsBootstrap::sanitize_max_mb( '0' ) );
		$this->assertSame( UploadLinkService::DEFAULT_MAX_MB, SettingsBootstrap::sanitize_max_mb( '-5' ) );
		$this->assertSame( UploadLinkService::DEFAULT_MAX_MB, SettingsBootstrap::sanitize_max_mb( 'not-a-number' ) );
	}

	/**
	 * Falling back to the default is reported, exactly as clamping down from
	 * above the ceiling is. Silently rewriting a 0 into 10 leaves somebody
	 * looking at a number they did not type and no reason for it.
	 *
	 * @return void
	 */
	public function test_sanitize_max_mb_warns_when_input_is_below_the_minimum(): void {
		SettingsBootstrap::sanitize_max_mb( '0' );

		$this->assertTrue( $this->has_settings_error( 'upload_link_max_mb_too_low' ) );
	}

	/**
	 * A value above the settable ceiling is clamped, not rejected outright.
	 *
	 * @return void
	 */
	public function test_sanitize_max_mb_clamps_to_the_settable_ceiling(): void {
		$this->assertSame(
			UploadLinkService::MAX_SETTABLE_MB,
			SettingsBootstrap::sanitize_max_mb( UploadLinkService::MAX_SETTABLE_MB + 1000 )
		);
	}

	/**
	 * Saving a value above the ceiling registers a settings-error warning —
	 * shown via the page's existing settings_errors('albert_settings') call,
	 * the same mechanism the "Settings saved" message already uses — so the
	 * clamp isn't silent.
	 *
	 * @return void
	 */
	public function test_sanitize_max_mb_warns_when_clamping(): void {
		SettingsBootstrap::sanitize_max_mb( UploadLinkService::MAX_SETTABLE_MB + 1000 );

		$this->assertTrue( $this->has_settings_error( 'upload_link_max_mb_clamped' ) );
	}

	/**
	 * A value within bounds never registers the clamp warning.
	 *
	 * @return void
	 */
	public function test_sanitize_max_mb_does_not_warn_within_bounds(): void {
		SettingsBootstrap::sanitize_max_mb( 42 );

		$this->assertFalse( $this->has_settings_error( 'upload_link_max_mb_clamped' ) );
	}

	/**
	 * While the filter is active, sanitize_max_mb() is a no-op: it returns
	 * whatever's already stored rather than reading $value at all. The
	 * field renders disabled in this state, so a save submits no value for
	 * it ($_POST simply lacks the key) — without this, that missing value
	 * would sanitize down to "invalid" and silently reset the stored
	 * option to the default on every unrelated settings save.
	 *
	 * @return void
	 */
	public function test_sanitize_max_mb_is_a_noop_while_the_filter_overrides(): void {
		update_option( UploadLinkService::MAX_BYTES_OPTION, 33 );
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => 12 * UploadLinkService::BYTES_PER_MB );

		// A real browser submission never happens while disabled, but even
		// if something did POST a value (or the field is simply absent),
		// the stored option must survive unchanged either way.
		$this->assertSame( 33, SettingsBootstrap::sanitize_max_mb( null ) );
		$this->assertSame( 33, SettingsBootstrap::sanitize_max_mb( '999' ) );

		delete_option( UploadLinkService::MAX_BYTES_OPTION );
	}

	/**
	 * A settings save that does not carry this field leaves the stored value
	 * alone, even once the filter that disabled the field has gone away.
	 *
	 * @return void
	 */
	public function test_an_unsubmitted_field_does_not_reset_the_stored_value(): void {
		update_option( UploadLinkService::MAX_BYTES_OPTION, 50 );

		$this->assertSame( 50, SettingsBootstrap::sanitize_max_mb( null ) );
	}
}
