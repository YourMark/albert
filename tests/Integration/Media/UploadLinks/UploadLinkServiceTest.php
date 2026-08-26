<?php
/**
 * Integration tests for UploadLinkService.
 *
 * Covers every acceptance criterion in docs/features/32-media-uploads.md:
 * mint -> redeem once -> second redemption fails; expiry; content-sniffed
 * rejection of a renamed file, independent of unfiltered_upload; the MIME
 * allowlist reflecting the issuing user's own capabilities and shrinking on
 * a role downgrade; and a successful upload landing as a normal media
 * library item with thumbnails.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Media\UploadLinks;

use Albert\Database\Installer;
use Albert\Database\Tables;
use Albert\Media\UploadLinks\UploadLinkService;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * UploadLinkService integration tests.
 *
 * @covers \Albert\Media\UploadLinks\UploadLinkService
 */
class UploadLinkServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var UploadLinkService
	 */
	private UploadLinkService $service;

	/**
	 * An administrator, current user for most tests.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Reset state before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::install();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test reset.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Tables::single_use_tokens() ) );

		$this->service  = new UploadLinkService();
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		// $wp_settings_errors is a plain global, not reset between tests by
		// WP's own hook-backup mechanism — reset it explicitly so a warning
		// added in one test can't leak into another's assertions.
		global $wp_settings_errors;
		$wp_settings_errors = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test isolation, not production code overriding a WP internal.
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
	 * Lift `wp_max_upload_size()` out of the way for a test that is about
	 * default/option/filter precedence rather than the server ceiling.
	 *
	 * A stock PHP ships `upload_max_filesize = 2M`, which is below every
	 * value the precedence tests deal in, so without this they assert the
	 * ceiling instead of the precedence they name — passing on a dev machine
	 * with a generous php.ini and failing on CI. `wp_max_upload_size()` runs
	 * its result through `upload_size_limit`, so raising it needs no ini
	 * change. The ceiling itself is covered separately by
	 * {@see self::test_default_max_bytes_is_still_capped_by_server_ceiling()}.
	 *
	 * @return void
	 */
	private function lift_server_upload_ceiling(): void {
		// Above MAX_SETTABLE_MB, so nothing this class can express hits it.
		add_filter(
			'upload_size_limit',
			static fn (): int => ( UploadLinkService::MAX_SETTABLE_MB + 1 ) * UploadLinkService::BYTES_PER_MB
		);
	}

	/**
	 * Path to a real fixture JPEG from the WP test suite, or skip.
	 *
	 * @return string
	 */
	private function jpg_fixture(): string {
		if ( ! defined( 'DIR_TESTDATA' ) ) {
			$this->markTestSkipped( 'DIR_TESTDATA not defined — WP test suite fixtures unavailable.' );
		}

		return DIR_TESTDATA . '/images/sugarloaf-mountain.jpg';
	}

	// ─── mint() ─────────────────────────────────────────────────────

	/**
	 * Mint() returns a complete, usable link.
	 *
	 * @return void
	 */
	public function test_mint_returns_ticket_fields(): void {
		$link = $this->service->mint( $this->admin_id, [] );

		$this->assertIsArray( $link );
		$this->assertNotEmpty( $link['upload_token'] );
		$this->assertSame( UploadLinkService::TOKEN_HEADER, $link['token_header'] );
		$this->assertGreaterThan( 0, $link['max_bytes'] );
		$this->assertNotEmpty( $link['accepted_types'] );
		$this->assertStringContainsString( $link['upload_token'], $link['curl_example'] );
		$this->assertStringContainsString( UploadLinkService::TOKEN_HEADER, $link['curl_example'] );
	}

	/**
	 * Narrows accepted_types to a caller's request.
	 *
	 * @return void
	 */
	public function test_mint_narrows_accepted_types_to_request(): void {
		$link = $this->service->mint( $this->admin_id, [ 'accepted_types' => [ 'image/jpeg' ] ] );

		$this->assertSame( [ 'image/jpeg' ], $link['accepted_types'] );
	}

	/**
	 * Requesting only disallowed types fails outright rather than silently
	 * minting a link nothing can ever be uploaded through.
	 *
	 * @return void
	 */
	public function test_mint_rejects_a_request_matching_nothing_allowed(): void {
		$link = $this->service->mint( $this->admin_id, [ 'accepted_types' => [ 'application/x-totally-not-real' ] ] );

		$this->assertInstanceOf( WP_Error::class, $link );
		$this->assertSame( 'no_accepted_types', $link->get_error_code() );
	}

	/**
	 * A user without upload_files cannot mint a link.
	 *
	 * @return void
	 */
	public function test_mint_requires_upload_files_capability(): void {
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$link = $this->service->mint( $subscriber, [] );

		$this->assertInstanceOf( WP_Error::class, $link );
	}

	/**
	 * A caller's requested max_bytes is honoured when under the server ceiling.
	 *
	 * @return void
	 */
	public function test_mint_honours_requested_max_bytes(): void {
		$link = $this->service->mint( $this->admin_id, [ 'max_bytes' => 500 ] );

		$this->assertSame( 500, $link['max_bytes'] );
	}

	/**
	 * A caller cannot request a cap above the server's own upload ceiling.
	 *
	 * @return void
	 */
	public function test_mint_caps_max_bytes_to_server_ceiling(): void {
		$link = $this->service->mint( $this->admin_id, [ 'max_bytes' => PHP_INT_MAX ] );

		$this->assertLessThanOrEqual( (int) wp_max_upload_size(), $link['max_bytes'] );
	}

	// ─── Default max_bytes: option + filter precedence ───────────────

	/**
	 * With no filter and no stored option, mint() falls back to the
	 * built-in conservative default.
	 *
	 * @return void
	 */
	public function test_default_max_bytes_falls_back_to_constant(): void {
		$this->lift_server_upload_ceiling();
		delete_option( UploadLinkService::MAX_BYTES_OPTION );

		$link = $this->service->mint( $this->admin_id, [] );

		$this->assertSame( UploadLinkService::DEFAULT_MAX_BYTES, $link['max_bytes'] );
	}

	/**
	 * The site owner's own Settings-screen value (stored in MB) is honoured
	 * when no filter overrides it.
	 *
	 * @return void
	 */
	public function test_default_max_bytes_honours_the_stored_option(): void {
		$this->lift_server_upload_ceiling();
		update_option( UploadLinkService::MAX_BYTES_OPTION, 5 );

		$link = $this->service->mint( $this->admin_id, [] );

		$this->assertSame( 5 * UploadLinkService::BYTES_PER_MB, $link['max_bytes'] );

		delete_option( UploadLinkService::MAX_BYTES_OPTION );
	}

	/**
	 * The filter overrides the stored option outright.
	 *
	 * @return void
	 */
	public function test_default_max_bytes_filter_overrides_the_option(): void {
		$this->lift_server_upload_ceiling();
		update_option( UploadLinkService::MAX_BYTES_OPTION, 5 );
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => 3 * UploadLinkService::BYTES_PER_MB );

		$link = $this->service->mint( $this->admin_id, [] );

		$this->assertSame( 3 * UploadLinkService::BYTES_PER_MB, $link['max_bytes'] );

		delete_option( UploadLinkService::MAX_BYTES_OPTION );
	}

	/**
	 * The filter returning null defers to the option, not the constant
	 * default — a filter that isn't trying to override shouldn't silently
	 * mask the site owner's own setting.
	 *
	 * @return void
	 */
	public function test_default_max_bytes_filter_returning_null_defers_to_the_option(): void {
		$this->lift_server_upload_ceiling();
		update_option( UploadLinkService::MAX_BYTES_OPTION, 5 );
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => null );

		$link = $this->service->mint( $this->admin_id, [] );

		$this->assertSame( 5 * UploadLinkService::BYTES_PER_MB, $link['max_bytes'] );

		delete_option( UploadLinkService::MAX_BYTES_OPTION );
	}

	/**
	 * A caller's own explicit max_bytes still wins over the site default,
	 * filtered or not — the filter/option only apply when nothing was requested.
	 *
	 * @return void
	 */
	public function test_explicit_request_overrides_the_default_entirely(): void {
		update_option( UploadLinkService::MAX_BYTES_OPTION, 5 );

		$link = $this->service->mint( $this->admin_id, [ 'max_bytes' => 777 ] );

		$this->assertSame( 777, $link['max_bytes'] );

		delete_option( UploadLinkService::MAX_BYTES_OPTION );
	}

	/**
	 * The site default is still ceilinged by the server's real upload limit,
	 * exactly like an explicit request would be.
	 *
	 * @return void
	 */
	public function test_default_max_bytes_is_still_capped_by_server_ceiling(): void {
		update_option( UploadLinkService::MAX_BYTES_OPTION, UploadLinkService::MAX_SETTABLE_MB );

		$link = $this->service->mint( $this->admin_id, [] );

		$this->assertLessThanOrEqual( (int) wp_max_upload_size(), $link['max_bytes'] );

		delete_option( UploadLinkService::MAX_BYTES_OPTION );
	}

	// ─── Settings-screen filter-override surface ───────────────────────

	/**
	 * With no filter hooked, the state is inactive.
	 *
	 * @return void
	 */
	public function test_filter_state_is_inactive_by_default(): void {
		$state = UploadLinkService::get_default_max_bytes_filter_state();

		$this->assertSame( 'inactive', $state['state'] );
	}

	/**
	 * A filter returning a positive int reports active, with that value.
	 *
	 * @return void
	 */
	public function test_filter_state_is_active_when_the_filter_overrides(): void {
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => 4 * UploadLinkService::BYTES_PER_MB );

		$state = UploadLinkService::get_default_max_bytes_filter_state();

		$this->assertSame( 'active', $state['state'] );
		$this->assertSame( 4 * UploadLinkService::BYTES_PER_MB, $state['value'] );
	}

	/**
	 * The filter accepts php.ini-style shorthand strings ("10M", "10MB",
	 * "2G") via wp_convert_hr_to_bytes() — the same parser WordPress itself
	 * uses for memory_limit/upload_max_filesize — not just a raw int.
	 *
	 * @return void
	 */
	public function test_filter_accepts_shorthand_size_strings(): void {
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => '10M' );
		$this->assertSame( 10 * UploadLinkService::BYTES_PER_MB, UploadLinkService::get_default_max_bytes_filter_state()['value'] );
	}

	/**
	 * "10MB" behaves identically to "10M" — wp_convert_hr_to_bytes() only
	 * checks for the presence of the unit letter, not an exact suffix.
	 *
	 * @return void
	 */
	public function test_filter_accepts_mb_suffix_identically_to_m(): void {
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => '10MB' );
		$this->assertSame( 10 * UploadLinkService::BYTES_PER_MB, UploadLinkService::get_default_max_bytes_filter_state()['value'] );
	}

	/**
	 * A gigabyte shorthand resolves correctly too.
	 *
	 * @return void
	 */
	public function test_filter_accepts_gigabyte_shorthand(): void {
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => '1G' );
		$this->assertSame( 1024 * UploadLinkService::BYTES_PER_MB, UploadLinkService::get_default_max_bytes_filter_state()['value'] );
	}

	/**
	 * A string wp_convert_hr_to_bytes() can't make sense of is treated as
	 * "not overriding", not as a crash or a silent zero-byte cap.
	 *
	 * @return void
	 */
	public function test_filter_ignores_an_unparseable_string(): void {
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => 'not-a-size' );
		$this->assertSame( 'inactive', UploadLinkService::get_default_max_bytes_filter_state()['state'] );
	}

	/**
	 * A filter accidentally set to something absurd (e.g. a typo producing
	 * "10G" instead of "10M") is clamped to the same absolute ceiling the
	 * Settings screen's own field is bound to — a filter can't set
	 * something the UI itself would refuse.
	 *
	 * @return void
	 */
	public function test_filter_value_is_clamped_to_the_settable_ceiling(): void {
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => '10G' ); // 10240 MB, well past the 2048 MB ceiling.

		$state = UploadLinkService::get_default_max_bytes_filter_state();

		$this->assertSame( 'active', $state['state'] );
		$this->assertSame( UploadLinkService::MAX_SETTABLE_MB * UploadLinkService::BYTES_PER_MB, $state['value'] );
	}

	/**
	 * With no filter hooked, render_max_mb_field() renders an editable
	 * input showing the stored/default value, and no override notice.
	 *
	 * @return void
	 */
	public function test_render_max_mb_field_is_editable_without_a_filter(): void {
		ob_start();
		UploadLinkService::render_max_mb_field( [], 9 );
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
		UploadLinkService::render_max_mb_field( [], 9 );
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
		UploadLinkService::render_max_mb_field( [], 9 );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'value="' . UploadLinkService::MAX_SETTABLE_MB . '"', $html );
		$this->assertStringContainsString( 'disabled', $html );
		$this->assertStringContainsString( 'albert-hint--warning', $html );
		$this->assertStringNotContainsString( 'albert-hint--info', $html );
		$this->assertStringContainsString( '10240', $html ); // The requested value, for context.
		$this->assertStringContainsString( (string) UploadLinkService::MAX_SETTABLE_MB, $html );
	}

	// ─── sanitize_max_mb() ────────────────────────────────────────────

	/**
	 * A valid value passes through unchanged.
	 *
	 * @return void
	 */
	public function test_sanitize_max_mb_passes_through_a_valid_value(): void {
		$this->assertSame( 42, UploadLinkService::sanitize_max_mb( '42' ) );
	}

	/**
	 * Zero, negative, and non-numeric input fall back to the default rather
	 * than persisting a byte cap of zero.
	 *
	 * @return void
	 */
	public function test_sanitize_max_mb_rejects_invalid_input(): void {
		$this->assertSame( UploadLinkService::DEFAULT_MAX_MB, UploadLinkService::sanitize_max_mb( '0' ) );
		$this->assertSame( UploadLinkService::DEFAULT_MAX_MB, UploadLinkService::sanitize_max_mb( '-5' ) );
		$this->assertSame( UploadLinkService::DEFAULT_MAX_MB, UploadLinkService::sanitize_max_mb( 'not-a-number' ) );
	}

	/**
	 * A value above the settable ceiling is clamped, not rejected outright.
	 *
	 * @return void
	 */
	public function test_sanitize_max_mb_clamps_to_the_settable_ceiling(): void {
		$this->assertSame(
			UploadLinkService::MAX_SETTABLE_MB,
			UploadLinkService::sanitize_max_mb( UploadLinkService::MAX_SETTABLE_MB + 1000 )
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
		UploadLinkService::sanitize_max_mb( UploadLinkService::MAX_SETTABLE_MB + 1000 );

		$this->assertTrue( $this->has_settings_error( 'upload_link_max_mb_clamped' ) );
	}

	/**
	 * A value within bounds never registers the clamp warning.
	 *
	 * @return void
	 */
	public function test_sanitize_max_mb_does_not_warn_within_bounds(): void {
		UploadLinkService::sanitize_max_mb( 42 );

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
		$this->assertSame( 33, UploadLinkService::sanitize_max_mb( null ) );
		$this->assertSame( 33, UploadLinkService::sanitize_max_mb( '999' ) );

		delete_option( UploadLinkService::MAX_BYTES_OPTION );
	}

	/**
	 * An invalid post_id is rejected at mint time.
	 *
	 * @return void
	 */
	public function test_mint_rejects_nonexistent_post(): void {
		$link = $this->service->mint( $this->admin_id, [ 'post_id' => 999999 ] );

		$this->assertInstanceOf( WP_Error::class, $link );
		$this->assertSame( 'invalid_post', $link->get_error_code() );
	}

	/**
	 * A negative post_id is treated as "not requested" (post_id: 0), not
	 * flipped to its positive magnitude — absint(-12) would return 12 and
	 * silently validate/attach against a real post the caller never asked
	 * for, the exact bug already fixed once for max_bytes in this method.
	 *
	 * @return void
	 */
	public function test_mint_treats_negative_post_id_as_not_requested(): void {
		// Guarantee a post with a small positive ID exists, so a sign-flip
		// bug (-3 -> 3) would have something real to wrongly validate against.
		self::factory()->post->create_many( 5 );

		$link = $this->service->mint( $this->admin_id, [ 'post_id' => -3 ] );

		$this->assertIsArray( $link );
		$this->assertSame( 0, $link['post_id'] );
	}

	// ─── redeem_link() ────────────────────────────────────────────

	/**
	 * A fresh link redeems to the issuing user's context.
	 *
	 * @return void
	 */
	public function test_redeem_link_returns_context(): void {
		$link = $this->service->mint( $this->admin_id, [] );

		$context = $this->service->redeem_link( $link['upload_token'] );

		$this->assertIsArray( $context );
		$this->assertSame( $this->admin_id, $context['user_id'] );
	}

	/**
	 * Mint -> redeem once -> second redemption fails (the doc's primary
	 * acceptance criterion).
	 *
	 * @return void
	 */
	public function test_second_redemption_fails(): void {
		$link = $this->service->mint( $this->admin_id, [] );

		$first  = $this->service->redeem_link( $link['upload_token'] );
		$second = $this->service->redeem_link( $link['upload_token'] );

		$this->assertIsArray( $first );
		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( 'link_already_used', $second->get_error_code() );
	}

	/**
	 * An expired link fails cleanly with a distinct error code.
	 *
	 * @return void
	 */
	public function test_expired_ticket_fails_cleanly(): void {
		global $wpdb;

		$link = $this->service->mint( $this->admin_id, [] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup: force expiry.
		$wpdb->update(
			Tables::single_use_tokens(),
			[ 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ],
			[ 'token_hash' => hash( 'sha256', $link['upload_token'] ) ]
		);

		$result = $this->service->redeem_link( $link['upload_token'] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'link_expired', $result->get_error_code() );
	}

	/**
	 * A role downgrade between mint and redemption revokes the link, even
	 * though it hasn't expired and was never used.
	 *
	 * @return void
	 */
	public function test_role_downgrade_between_mint_and_redemption_revokes_ticket(): void {
		$editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		$link      = $this->service->mint( $editor_id, [] );

		$editor = get_userdata( $editor_id );
		$editor->set_role( 'subscriber' );

		$result = $this->service->redeem_link( $link['upload_token'] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'capability_revoked', $result->get_error_code() );

		// The resolved user_id rides in the error data so a caller (the REST
		// controller's execution-log call) can still attribute this failure
		// to the real, identifiable user rather than logging it as user 0.
		$this->assertSame( $editor_id, $result->get_error_data()['user_id'] );
	}

	/**
	 * A capability change that narrows (but doesn't remove) upload rights
	 * between mint and redemption narrows the effective allowlist rather
	 * than honouring the wider one captured at mint time.
	 *
	 * @return void
	 */
	public function test_narrowed_capability_shrinks_effective_allowlist(): void {
		$admin = get_userdata( $this->admin_id );
		$link  = $this->service->mint( $this->admin_id, [] );

		$this->assertContains( 'text/plain', $link['accepted_types'] );

		// Narrow what this user is allowed to upload via the same filter
		// get_allowed_mime_types() itself consults.
		add_filter(
			'upload_mimes',
			static function () {
				return [ 'jpg|jpeg|jpe' => 'image/jpeg' ];
			}
		);

		$context = $this->service->redeem_link( $link['upload_token'] );

		$this->assertIsArray( $context );
		$this->assertSame( [ 'image/jpeg' ], array_values( $context['mime_allowlist'] ) );
	}

	// ─── finalize_upload() ──────────────────────────────────────────

	/**
	 * A legitimate upload becomes a normal media library attachment with
	 * generated thumbnails.
	 *
	 * @return void
	 */
	public function test_finalize_upload_creates_attachment_with_thumbnails(): void {
		$link    = $this->service->mint( $this->admin_id, [] );
		$context = $this->service->redeem_link( $link['upload_token'] );

		$tmp = wp_tempnam();
		copy( $this->jpg_fixture(), $tmp );

		$result = $this->service->finalize_upload( $tmp, 'photo.jpg', $context );

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['attachment_id'] );
		$this->assertSame( 'image/jpeg', $result['mime_type'] );

		$metadata = wp_get_attachment_metadata( $result['attachment_id'] );
		$this->assertArrayHasKey( 'sizes', $metadata );
		$this->assertFileDoesNotExist( $tmp );
	}

	/**
	 * A .php file renamed to .jpg is rejected by real content sniffing, even
	 * when the uploading user holds `unfiltered_upload` — that capability is
	 * never consulted by this path, for any user.
	 *
	 * @return void
	 */
	public function test_php_renamed_to_jpg_is_rejected_even_with_unfiltered_upload(): void {
		$admin = get_userdata( $this->admin_id );
		$admin->add_cap( 'unfiltered_upload' );

		$link    = $this->service->mint( $this->admin_id, [] );
		$context = $this->service->redeem_link( $link['upload_token'] );

		$tmp = wp_tempnam();
		file_put_contents( $tmp, "<?php echo 'pwned'; " ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- Test fixture, not user input.

		$result = $this->service->finalize_upload( $tmp, 'evil.jpg', $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'type_not_allowed', $result->get_error_code() );
		$this->assertFileDoesNotExist( $tmp );
	}

	/**
	 * A real file whose type simply isn't in the link's (narrowed)
	 * allowlist is rejected, and the response carries the accepted list.
	 *
	 * @return void
	 */
	public function test_finalize_upload_rejects_type_outside_narrowed_allowlist(): void {
		$link    = $this->service->mint( $this->admin_id, [ 'accepted_types' => [ 'application/pdf' ] ] );
		$context = $this->service->redeem_link( $link['upload_token'] );

		$tmp = wp_tempnam();
		copy( $this->jpg_fixture(), $tmp );

		$result = $this->service->finalize_upload( $tmp, 'photo.jpg', $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'type_not_allowed', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( [ 'application/pdf' ], $data['accepted_types'] );
		$this->assertFileDoesNotExist( $tmp );
	}

	/**
	 * The two default constants express the same size, so neither can drift.
	 *
	 * @return void
	 */
	public function test_the_byte_and_megabyte_defaults_agree(): void {
		$this->assertSame(
			UploadLinkService::DEFAULT_MAX_MB * UploadLinkService::BYTES_PER_MB,
			UploadLinkService::DEFAULT_MAX_BYTES
		);
	}

	/**
	 * A filter returning a float overrides, rather than being silently dropped.
	 *
	 * @return void
	 */
	public function test_a_float_filter_value_is_honoured(): void {
		add_filter(
			'albert/media/upload_link_max_bytes',
			static function () {
				return 1.5 * UploadLinkService::BYTES_PER_MB;
			}
		);

		$state = UploadLinkService::get_default_max_bytes_filter_state();

		$this->assertSame( 'active', $state['state'] );
		$this->assertSame( (int) ( 1.5 * UploadLinkService::BYTES_PER_MB ), $state['value'] );
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
		UploadLinkService::render_max_mb_field( [], 10 );
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'value="0"', $html );
		$this->assertStringContainsString( 'value="1"', $html );
		// The rounding is not allowed to mislead: the hint carries the real size.
		$this->assertStringContainsString( size_format( 500 ), $html );
	}

	/**
	 * A settings save that does not carry this field leaves the stored value
	 * alone, even once the filter that disabled the field has gone away.
	 *
	 * @return void
	 */
	public function test_an_unsubmitted_field_does_not_reset_the_stored_value(): void {
		update_option( UploadLinkService::MAX_BYTES_OPTION, 50 );

		$this->assertSame( 50, UploadLinkService::sanitize_max_mb( null ) );
	}

	/**
	 * A core-side upload refusal is a 4xx with its own code, not a bare 500
	 * colliding with the controller's own 'upload_error'.
	 *
	 * @return void
	 */
	public function test_a_core_upload_refusal_is_recoded_as_a_client_error(): void {
		$link    = $this->service->mint( $this->admin_id, [] );
		$context = $this->service->redeem_link( $link['upload_token'] );

		// Make core's own sideload fail after our checks have passed.
		add_filter(
			'wp_handle_sideload_prefilter',
			static function ( $file ) {
				$file['error'] = 'forced failure';

				return $file;
			}
		);

		$tmp = wp_tempnam();
		copy( $this->jpg_fixture(), $tmp );

		$result = $this->service->finalize_upload( $tmp, 'photo.jpg', $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'upload_failed', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}
}
