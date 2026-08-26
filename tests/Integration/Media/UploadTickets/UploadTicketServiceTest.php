<?php
/**
 * Integration tests for UploadTicketService.
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

namespace Albert\Tests\Integration\Media\UploadTickets;

use Albert\Database\Installer;
use Albert\Database\Tables;
use Albert\Media\UploadTickets\UploadTicketService;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * UploadTicketService integration tests.
 *
 * @covers \Albert\Media\UploadTickets\UploadTicketService
 */
class UploadTicketServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var UploadTicketService
	 */
	private UploadTicketService $service;

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

		$this->service  = new UploadTicketService();
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		// $wp_settings_errors is a plain global, not reset between tests by
		// WP's own hook-backup mechanism — reset it explicitly so a warning
		// added in one test can't leak into another's assertions.
		global $wp_settings_errors;
		$wp_settings_errors = [];
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
	 * mint() returns a complete, usable ticket.
	 *
	 * @return void
	 */
	public function test_mint_returns_ticket_fields(): void {
		$ticket = $this->service->mint( $this->admin_id, [] );

		$this->assertIsArray( $ticket );
		$this->assertNotEmpty( $ticket['upload_token'] );
		$this->assertSame( UploadTicketService::TOKEN_HEADER, $ticket['token_header'] );
		$this->assertGreaterThan( 0, $ticket['max_bytes'] );
		$this->assertNotEmpty( $ticket['accepted_types'] );
		$this->assertStringContainsString( $ticket['upload_token'], $ticket['curl_example'] );
		$this->assertStringContainsString( UploadTicketService::TOKEN_HEADER, $ticket['curl_example'] );
	}

	/**
	 * accepted_types narrows to a caller's request.
	 *
	 * @return void
	 */
	public function test_mint_narrows_accepted_types_to_request(): void {
		$ticket = $this->service->mint( $this->admin_id, [ 'accepted_types' => [ 'image/jpeg' ] ] );

		$this->assertSame( [ 'image/jpeg' ], $ticket['accepted_types'] );
	}

	/**
	 * Requesting only disallowed types fails outright rather than silently
	 * minting a ticket nothing can ever be uploaded through.
	 *
	 * @return void
	 */
	public function test_mint_rejects_a_request_matching_nothing_allowed(): void {
		$ticket = $this->service->mint( $this->admin_id, [ 'accepted_types' => [ 'application/x-totally-not-real' ] ] );

		$this->assertInstanceOf( WP_Error::class, $ticket );
		$this->assertSame( 'no_accepted_types', $ticket->get_error_code() );
	}

	/**
	 * A user without upload_files cannot mint a ticket.
	 *
	 * @return void
	 */
	public function test_mint_requires_upload_files_capability(): void {
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$ticket = $this->service->mint( $subscriber, [] );

		$this->assertInstanceOf( WP_Error::class, $ticket );
	}

	/**
	 * A caller's requested max_bytes is honoured when under the server ceiling.
	 *
	 * @return void
	 */
	public function test_mint_honours_requested_max_bytes(): void {
		$ticket = $this->service->mint( $this->admin_id, [ 'max_bytes' => 500 ] );

		$this->assertSame( 500, $ticket['max_bytes'] );
	}

	/**
	 * A caller cannot request a cap above the server's own upload ceiling.
	 *
	 * @return void
	 */
	public function test_mint_caps_max_bytes_to_server_ceiling(): void {
		$ticket = $this->service->mint( $this->admin_id, [ 'max_bytes' => PHP_INT_MAX ] );

		$this->assertLessThanOrEqual( (int) wp_max_upload_size(), $ticket['max_bytes'] );
	}

	// ─── Default max_bytes: option + filter precedence ───────────────

	/**
	 * With no filter and no stored option, mint() falls back to the
	 * built-in conservative default.
	 *
	 * @return void
	 */
	public function test_default_max_bytes_falls_back_to_constant(): void {
		delete_option( UploadTicketService::MAX_BYTES_OPTION );

		$ticket = $this->service->mint( $this->admin_id, [] );

		$this->assertSame( UploadTicketService::DEFAULT_MAX_BYTES, $ticket['max_bytes'] );
	}

	/**
	 * The site owner's own Settings-screen value (stored in MB) is honoured
	 * when no filter overrides it.
	 *
	 * @return void
	 */
	public function test_default_max_bytes_honours_the_stored_option(): void {
		update_option( UploadTicketService::MAX_BYTES_OPTION, 5 );

		$ticket = $this->service->mint( $this->admin_id, [] );

		$this->assertSame( 5 * UploadTicketService::BYTES_PER_MB, $ticket['max_bytes'] );

		delete_option( UploadTicketService::MAX_BYTES_OPTION );
	}

	/**
	 * The filter overrides the stored option outright.
	 *
	 * @return void
	 */
	public function test_default_max_bytes_filter_overrides_the_option(): void {
		update_option( UploadTicketService::MAX_BYTES_OPTION, 5 );
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => 3 * UploadTicketService::BYTES_PER_MB );

		$ticket = $this->service->mint( $this->admin_id, [] );

		$this->assertSame( 3 * UploadTicketService::BYTES_PER_MB, $ticket['max_bytes'] );

		delete_option( UploadTicketService::MAX_BYTES_OPTION );
	}

	/**
	 * The filter returning null defers to the option, not the constant
	 * default — a filter that isn't trying to override shouldn't silently
	 * mask the site owner's own setting.
	 *
	 * @return void
	 */
	public function test_default_max_bytes_filter_returning_null_defers_to_the_option(): void {
		update_option( UploadTicketService::MAX_BYTES_OPTION, 5 );
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => null );

		$ticket = $this->service->mint( $this->admin_id, [] );

		$this->assertSame( 5 * UploadTicketService::BYTES_PER_MB, $ticket['max_bytes'] );

		delete_option( UploadTicketService::MAX_BYTES_OPTION );
	}

	/**
	 * A caller's own explicit max_bytes still wins over the site default,
	 * filtered or not — the filter/option only apply when nothing was requested.
	 *
	 * @return void
	 */
	public function test_explicit_request_overrides_the_default_entirely(): void {
		update_option( UploadTicketService::MAX_BYTES_OPTION, 5 );

		$ticket = $this->service->mint( $this->admin_id, [ 'max_bytes' => 777 ] );

		$this->assertSame( 777, $ticket['max_bytes'] );

		delete_option( UploadTicketService::MAX_BYTES_OPTION );
	}

	/**
	 * The site default is still ceilinged by the server's real upload limit,
	 * exactly like an explicit request would be.
	 *
	 * @return void
	 */
	public function test_default_max_bytes_is_still_capped_by_server_ceiling(): void {
		update_option( UploadTicketService::MAX_BYTES_OPTION, UploadTicketService::MAX_SETTABLE_MB );

		$ticket = $this->service->mint( $this->admin_id, [] );

		$this->assertLessThanOrEqual( (int) wp_max_upload_size(), $ticket['max_bytes'] );

		delete_option( UploadTicketService::MAX_BYTES_OPTION );
	}

	// ─── Settings-screen filter-override surface ───────────────────────

	/**
	 * With no filter hooked, the state is inactive.
	 *
	 * @return void
	 */
	public function test_filter_state_is_inactive_by_default(): void {
		$state = UploadTicketService::get_default_max_bytes_filter_state();

		$this->assertSame( 'inactive', $state['state'] );
	}

	/**
	 * A filter returning a positive int reports active, with that value.
	 *
	 * @return void
	 */
	public function test_filter_state_is_active_when_the_filter_overrides(): void {
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => 4 * UploadTicketService::BYTES_PER_MB );

		$state = UploadTicketService::get_default_max_bytes_filter_state();

		$this->assertSame( 'active', $state['state'] );
		$this->assertSame( 4 * UploadTicketService::BYTES_PER_MB, $state['value'] );
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
		$this->assertSame( 10 * UploadTicketService::BYTES_PER_MB, UploadTicketService::get_default_max_bytes_filter_state()['value'] );
	}

	/**
	 * "10MB" behaves identically to "10M" — wp_convert_hr_to_bytes() only
	 * checks for the presence of the unit letter, not an exact suffix.
	 *
	 * @return void
	 */
	public function test_filter_accepts_mb_suffix_identically_to_m(): void {
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => '10MB' );
		$this->assertSame( 10 * UploadTicketService::BYTES_PER_MB, UploadTicketService::get_default_max_bytes_filter_state()['value'] );
	}

	/**
	 * A gigabyte shorthand resolves correctly too.
	 *
	 * @return void
	 */
	public function test_filter_accepts_gigabyte_shorthand(): void {
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => '1G' );
		$this->assertSame( 1024 * UploadTicketService::BYTES_PER_MB, UploadTicketService::get_default_max_bytes_filter_state()['value'] );
	}

	/**
	 * A string wp_convert_hr_to_bytes() can't make sense of is treated as
	 * "not overriding", not as a crash or a silent zero-byte cap.
	 *
	 * @return void
	 */
	public function test_filter_ignores_an_unparseable_string(): void {
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => 'not-a-size' );
		$this->assertSame( 'inactive', UploadTicketService::get_default_max_bytes_filter_state()['state'] );
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

		$state = UploadTicketService::get_default_max_bytes_filter_state();

		$this->assertSame( 'active', $state['state'] );
		$this->assertSame( UploadTicketService::MAX_SETTABLE_MB * UploadTicketService::BYTES_PER_MB, $state['value'] );
	}

	/**
	 * With no filter hooked, render_max_mb_field() renders an editable
	 * input showing the stored/default value, and no override notice.
	 *
	 * @return void
	 */
	public function test_render_max_mb_field_is_editable_without_a_filter(): void {
		ob_start();
		UploadTicketService::render_max_mb_field( [], 9 );
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
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => 12 * UploadTicketService::BYTES_PER_MB );

		ob_start();
		UploadTicketService::render_max_mb_field( [], 9 );
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
		UploadTicketService::render_max_mb_field( [], 9 );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'value="' . UploadTicketService::MAX_SETTABLE_MB . '"', $html );
		$this->assertStringContainsString( 'disabled', $html );
		$this->assertStringContainsString( 'albert-hint--warning', $html );
		$this->assertStringNotContainsString( 'albert-hint--info', $html );
		$this->assertStringContainsString( '10240', $html ); // The requested value, for context.
		$this->assertStringContainsString( (string) UploadTicketService::MAX_SETTABLE_MB, $html );
	}

	// ─── sanitize_max_mb() ────────────────────────────────────────────

	/**
	 * A valid value passes through unchanged.
	 *
	 * @return void
	 */
	public function test_sanitize_max_mb_passes_through_a_valid_value(): void {
		$this->assertSame( 42, UploadTicketService::sanitize_max_mb( '42' ) );
	}

	/**
	 * Zero, negative, and non-numeric input fall back to the default rather
	 * than persisting a byte cap of zero.
	 *
	 * @return void
	 */
	public function test_sanitize_max_mb_rejects_invalid_input(): void {
		$this->assertSame( UploadTicketService::DEFAULT_MAX_MB, UploadTicketService::sanitize_max_mb( '0' ) );
		$this->assertSame( UploadTicketService::DEFAULT_MAX_MB, UploadTicketService::sanitize_max_mb( '-5' ) );
		$this->assertSame( UploadTicketService::DEFAULT_MAX_MB, UploadTicketService::sanitize_max_mb( 'not-a-number' ) );
	}

	/**
	 * A value above the settable ceiling is clamped, not rejected outright.
	 *
	 * @return void
	 */
	public function test_sanitize_max_mb_clamps_to_the_settable_ceiling(): void {
		$this->assertSame(
			UploadTicketService::MAX_SETTABLE_MB,
			UploadTicketService::sanitize_max_mb( UploadTicketService::MAX_SETTABLE_MB + 1000 )
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
		UploadTicketService::sanitize_max_mb( UploadTicketService::MAX_SETTABLE_MB + 1000 );

		$this->assertTrue( $this->has_settings_error( 'upload_link_max_mb_clamped' ) );
	}

	/**
	 * A value within bounds never registers the clamp warning.
	 *
	 * @return void
	 */
	public function test_sanitize_max_mb_does_not_warn_within_bounds(): void {
		UploadTicketService::sanitize_max_mb( 42 );

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
		update_option( UploadTicketService::MAX_BYTES_OPTION, 33 );
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => 12 * UploadTicketService::BYTES_PER_MB );

		// A real browser submission never happens while disabled, but even
		// if something did POST a value (or the field is simply absent),
		// the stored option must survive unchanged either way.
		$this->assertSame( 33, UploadTicketService::sanitize_max_mb( null ) );
		$this->assertSame( 33, UploadTicketService::sanitize_max_mb( '999' ) );

		delete_option( UploadTicketService::MAX_BYTES_OPTION );
	}

	/**
	 * An invalid post_id is rejected at mint time.
	 *
	 * @return void
	 */
	public function test_mint_rejects_nonexistent_post(): void {
		$ticket = $this->service->mint( $this->admin_id, [ 'post_id' => 999999 ] );

		$this->assertInstanceOf( WP_Error::class, $ticket );
		$this->assertSame( 'invalid_post', $ticket->get_error_code() );
	}

	// ─── redeem_ticket() ────────────────────────────────────────────

	/**
	 * A fresh ticket redeems to the issuing user's context.
	 *
	 * @return void
	 */
	public function test_redeem_ticket_returns_context(): void {
		$ticket = $this->service->mint( $this->admin_id, [] );

		$context = $this->service->redeem_ticket( $ticket['upload_token'] );

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
		$ticket = $this->service->mint( $this->admin_id, [] );

		$first  = $this->service->redeem_ticket( $ticket['upload_token'] );
		$second = $this->service->redeem_ticket( $ticket['upload_token'] );

		$this->assertIsArray( $first );
		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( 'ticket_already_used', $second->get_error_code() );
	}

	/**
	 * An expired ticket fails cleanly with a distinct error code.
	 *
	 * @return void
	 */
	public function test_expired_ticket_fails_cleanly(): void {
		global $wpdb;

		$ticket = $this->service->mint( $this->admin_id, [] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup: force expiry.
		$wpdb->update(
			Tables::single_use_tokens(),
			[ 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ],
			[ 'token_hash' => hash( 'sha256', $ticket['upload_token'] ) ]
		);

		$result = $this->service->redeem_ticket( $ticket['upload_token'] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ticket_expired', $result->get_error_code() );
	}

	/**
	 * A role downgrade between mint and redemption revokes the ticket, even
	 * though it hasn't expired and was never used.
	 *
	 * @return void
	 */
	public function test_role_downgrade_between_mint_and_redemption_revokes_ticket(): void {
		$editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		$ticket    = $this->service->mint( $editor_id, [] );

		$editor = get_userdata( $editor_id );
		$editor->set_role( 'subscriber' );

		$result = $this->service->redeem_ticket( $ticket['upload_token'] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'capability_revoked', $result->get_error_code() );
	}

	/**
	 * A capability change that narrows (but doesn't remove) upload rights
	 * between mint and redemption narrows the effective allowlist rather
	 * than honouring the wider one captured at mint time.
	 *
	 * @return void
	 */
	public function test_narrowed_capability_shrinks_effective_allowlist(): void {
		$admin  = get_userdata( $this->admin_id );
		$ticket = $this->service->mint( $this->admin_id, [] );

		$this->assertContains( 'text/plain', $ticket['accepted_types'] );

		// Narrow what this user is allowed to upload via the same filter
		// get_allowed_mime_types() itself consults.
		add_filter(
			'upload_mimes',
			static function () {
				return [ 'jpg|jpeg|jpe' => 'image/jpeg' ];
			}
		);

		$context = $this->service->redeem_ticket( $ticket['upload_token'] );

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
		$ticket  = $this->service->mint( $this->admin_id, [] );
		$context = $this->service->redeem_ticket( $ticket['upload_token'] );

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

		$ticket  = $this->service->mint( $this->admin_id, [] );
		$context = $this->service->redeem_ticket( $ticket['upload_token'] );

		$tmp = wp_tempnam();
		file_put_contents( $tmp, "<?php echo 'pwned'; " ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- Test fixture, not user input.

		$result = $this->service->finalize_upload( $tmp, 'evil.jpg', $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'type_not_allowed', $result->get_error_code() );
		$this->assertFileDoesNotExist( $tmp );
	}

	/**
	 * A real file whose type simply isn't in the ticket's (narrowed)
	 * allowlist is rejected, and the response carries the accepted list.
	 *
	 * @return void
	 */
	public function test_finalize_upload_rejects_type_outside_narrowed_allowlist(): void {
		$ticket  = $this->service->mint( $this->admin_id, [ 'accepted_types' => [ 'application/pdf' ] ] );
		$context = $this->service->redeem_ticket( $ticket['upload_token'] );

		$tmp = wp_tempnam();
		copy( $this->jpg_fixture(), $tmp );

		$result = $this->service->finalize_upload( $tmp, 'photo.jpg', $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'type_not_allowed', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( [ 'application/pdf' ], $data['accepted_types'] );
		$this->assertFileDoesNotExist( $tmp );
	}
}
