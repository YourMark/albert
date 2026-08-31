<?php
/**
 * Integration tests for option-name resolution on the Settings screen.
 *
 * These exist because the name was derived twice, by two functions that
 * disagreed. The renderer produced the input's `name` as `option_name ?? id`;
 * the save loop produced the `$_POST` key as `option_name ?? {section}_{field}`.
 * Every field that set an explicit `option_name` hid the difference, so the one
 * field that did not — privacy mode — rendered `name="mode"` while the save loop
 * looked for `albert_privacy_mode`. The key was never present, the sanitiser saw
 * null, and its default was written back on every save: the setting silently
 * reverted to Balanced and could not be changed from the screen at all.
 *
 * The bug was invisible to every existing test because they all asserted on the
 * render side or the save side alone. What was missing was an assertion that the
 * two agree, which is what this file is.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Admin;

use Albert\Admin\Settings;
use Albert\Admin\SettingsBootstrap;
use Albert\Admin\SettingsRegistry;
use Albert\Tests\TestCase;

/**
 * Option-name resolution tests.
 *
 * @covers \Albert\Admin\Settings
 * @covers \Albert\Admin\SettingsRenderer
 * @covers \Albert\Admin\SettingsRegistry::get_option_name
 */
class SettingsOptionNameTest extends TestCase {

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
	 * Every `name` attribute the page renders, minus WordPress's own form
	 * plumbing (the nonce, the referer, and admin-post's routing field).
	 *
	 * @param string $html Full page HTML.
	 *
	 * @return array<int, string> Unique names, in document order.
	 */
	private function rendered_names( string $html ): array {
		preg_match_all( '/<(?:input|select|textarea)[^>]*\sname="([^"]+)"/', $html, $matches );

		$plumbing = [ 'action', '_wp_http_referer', 'albert_save_settings_nonce' ];

		return array_values( array_unique( array_diff( $matches[1], $plumbing ) ) );
	}

	/**
	 * The option name the save loop will look for, derived the one way the
	 * system derives it.
	 *
	 * @param string $section_id Section id.
	 * @param string $field_id   Field id.
	 * @param mixed  $override   The field's own `option_name`, if any.
	 *
	 * @return string
	 */
	private function expected_name( string $section_id, string $field_id, $override ): string {
		return SettingsRegistry::get_option_name(
			$section_id,
			$field_id,
			is_string( $override ) ? $override : null
		);
	}

	/**
	 * The regression itself: privacy mode renders the name the save loop reads.
	 *
	 * It is the only built-in field with no `option_name` override, which is
	 * exactly why it was the only one broken.
	 *
	 * @return void
	 */
	public function test_privacy_mode_renders_the_name_the_save_loop_reads(): void {
		$html = $this->render();

		$this->assertStringContainsString(
			'name="albert_privacy_mode"',
			$html,
			'Privacy mode must post under the option name the save loop reads.'
		);

		$this->assertStringNotContainsString(
			'name="mode"',
			$html,
			'Privacy mode must not post under its bare field id — the save loop never reads that key.'
		);
	}

	/**
	 * The general property, asserted across every built-in field rather than
	 * the one that happened to break: whatever the screen renders as a control
	 * name is a key the save loop would read.
	 *
	 * A field added later with no `option_name` override fails here
	 * immediately, which is the point.
	 *
	 * @return void
	 */
	public function test_every_rendered_control_posts_under_a_resolvable_option_name(): void {
		$expected = [];

		foreach ( SettingsBootstrap::get_builtin_sections() as $section ) {
			foreach ( $section['fields'] as $field ) {
				// Custom fields render whatever their callback chooses and are
				// not part of the generic name contract.
				if ( ( $field['type'] ?? '' ) === 'custom' ) {
					continue;
				}

				$expected[] = $this->expected_name(
					$section['id'],
					$field['id'],
					$field['option_name'] ?? null
				);
			}
		}

		$this->assertNotEmpty( $expected, 'Expected at least one built-in field to check.' );

		$rendered = $this->rendered_names( $this->render() );

		foreach ( $expected as $option_name ) {
			$this->assertContains(
				$option_name,
				$rendered,
				sprintf( 'Field "%s" is registered but no control posts under that name.', $option_name )
			);
		}
	}

	/**
	 * A field whose value is currently stored is rendered with that value, so
	 * a save that does not touch it round-trips the same value back.
	 *
	 * This is the consequence the bug actually had: because the control posted
	 * under an unread key, the stored value never reached the form and the
	 * sanitiser's default was written back instead.
	 *
	 * @return void
	 */
	public function test_a_stored_value_reaches_the_form(): void {
		update_option( 'albert_privacy_mode', 'strict' );

		$html = $this->render();

		$this->assertMatchesRegularExpression(
			'/<input[^>]*name="albert_privacy_mode"[^>]*value="strict"[^>]*checked/',
			$html,
			'The stored privacy mode must render as the checked option.'
		);
	}
}
