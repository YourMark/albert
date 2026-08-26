<?php
/**
 * Integration tests for the Settings screen's filter-override UX, rendered
 * through the real page (Settings::render_settings_page() -> the generic
 * 'custom' field dispatch in SettingsRenderer -> SettingsBootstrap's own
 * render_callback) rather than calling the field's render method directly —
 * that part is covered in isolation by SettingsBootstrapTest. This file
 * proves the field is actually wired into the real Settings page and
 * dispatches correctly, not just that the callback works standalone.
 *
 * The save-path no-op (SettingsBootstrap::sanitize_max_mb() returning the
 * stored value unchanged while the filter overrides) is unit-tested
 * directly in SettingsBootstrapTest, not here: exercising it through
 * Settings::handle_save_settings() would require invoking a method that
 * ends in wp_safe_redirect() + exit, which would terminate the test runner.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Admin;

use Albert\Admin\Settings;
use Albert\Media\UploadLinks\UploadLinkService;
use Albert\Tests\TestCase;

/**
 * Settings filter-override integration tests.
 *
 * @covers \Albert\Admin\Settings
 * @covers \Albert\Admin\SettingsRenderer
 * @covers \Albert\Admin\SettingsBootstrap::render_max_mb_field
 */
class SettingsFilterOverrideTest extends TestCase {

	/**
	 * Run as an administrator so render_settings_page() doesn't wp_die().
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Render the settings page and return the captured HTML.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		( new Settings() )->render_settings_page();

		return (string) ob_get_clean();
	}

	/**
	 * Extract just the Uploads field's <input> tag, to avoid false
	 * positives from other fields elsewhere on the page.
	 *
	 * @param string $html Full page HTML.
	 *
	 * @return string
	 */
	private function extract_field( string $html ): string {
		preg_match(
			'/<input[^>]*name="' . preg_quote( UploadLinkService::MAX_BYTES_OPTION, '/' ) . '"[^>]*>/',
			$html,
			$matches
		);

		$this->assertNotEmpty( $matches, 'Uploads field <input> not found in rendered page.' );

		return $matches[0];
	}

	/**
	 * With no filter hooked, the field is a normal, editable input.
	 *
	 * @return void
	 */
	public function test_field_is_editable_without_a_filter(): void {
		$html  = $this->render();
		$field = $this->extract_field( $html );

		$this->assertStringNotContainsString( 'disabled', $field );
		$this->assertStringNotContainsString( 'albert/media/upload_link_max_bytes', $html );
	}

	/**
	 * With the filter active, the field shows the filter's value, renders
	 * disabled, and the page carries a notice naming the filter.
	 *
	 * @return void
	 */
	public function test_field_is_disabled_and_shows_the_filtered_value_when_overridden(): void {
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => 7 * UploadLinkService::BYTES_PER_MB );

		$html  = $this->render();
		$field = $this->extract_field( $html );

		$this->assertStringContainsString( 'disabled', $field );
		$this->assertStringContainsString( 'value="7"', $field );
		$this->assertStringContainsString( 'albert/media/upload_link_max_bytes', $html );
	}

	/**
	 * The stored option's own value is never shown while the filter
	 * overrides it — showing a value nobody can currently act on invites
	 * confusion about which number is "real".
	 *
	 * @return void
	 */
	public function test_stored_option_value_is_not_shown_while_overridden(): void {
		update_option( UploadLinkService::MAX_BYTES_OPTION, 42 );
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => 7 * UploadLinkService::BYTES_PER_MB );

		$field = $this->extract_field( $this->render() );

		$this->assertStringContainsString( 'value="7"', $field );
		$this->assertStringNotContainsString( 'value="42"', $field );

		delete_option( UploadLinkService::MAX_BYTES_OPTION );
	}
}
