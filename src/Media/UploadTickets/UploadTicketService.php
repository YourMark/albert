<?php
/**
 * Upload Ticket Service
 *
 * @package Albert
 * @subpackage Media\UploadTickets
 * @since      1.4.0
 */

namespace Albert\Media\UploadTickets;

defined( 'ABSPATH' ) || exit;

use Albert\Core\Plugin;
use Albert\Core\Tokens\TokenService;
use Albert\Media\MimeAllowlist;
use WP_Error;

/**
 * UploadTicketService class
 *
 * Media upload tickets (doc 32, Path B): mints a short-lived, single-use
 * redemption for an assistant that has bytes to upload directly, rather than
 * a URL to sideload. Builds on the generic {@see TokenService} primitive —
 * this class owns everything specific to "a ticket is a media upload
 * authorisation": the MIME allowlist binding, the byte cap, the error
 * vocabulary (`ticket_expired`, `ticket_already_used`, ...), and turning a
 * redeemed ticket plus a received file into a media library attachment.
 *
 * @since 1.4.0
 */
class UploadTicketService {

	/**
	 * The token purpose partitioning upload tickets from any other token consumer.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const PURPOSE = 'media_upload';

	/**
	 * The header the redemption endpoint expects the token in — never the URL.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const TOKEN_HEADER = 'X-Albert-Upload-Token';

	/**
	 * HTTP methods the redemption endpoint accepts.
	 *
	 * Both, deliberately: {@see UploadTicketController::handle_upload()} reads
	 * the same headers and body regardless of which verb got it there. PUT is
	 * arguably the more correct verb for "put these exact bytes at this URL"
	 * and is what some raw-body HTTP tooling defaults to; POST is what the
	 * multipart curl example uses. Accepting only one would reject a client
	 * that reasonably assumed the other worked, for no benefit.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const HTTP_METHODS = 'POST, PUT';

	/**
	 * How long a minted ticket stays valid.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	const TTL_SECONDS = 600;

	/**
	 * Conservative default byte cap, used when a caller doesn't request one
	 * AND neither the filter nor the site owner's own setting apply.
	 * Always additionally capped to {@see wp_max_upload_size()}.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	const DEFAULT_MAX_BYTES = 10485760; // 10 MB.

	/**
	 * The above, expressed in MB — what the Settings screen field and its
	 * stored option actually deal in (bytes are not a friendly unit to type
	 * into a form).
	 *
	 * @since 1.4.0
	 * @var int
	 */
	const DEFAULT_MAX_MB = 10;

	/**
	 * Option storing the site owner's own default byte cap, in MB. Set via
	 * Albert → Settings → Uploads.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const MAX_BYTES_OPTION = 'albert_upload_link_max_mb';

	/**
	 * Upper bound accepted for the Settings field, guarding against garbage
	 * input rather than expressing a real ceiling — {@see wp_max_upload_size()}
	 * is what actually limits an upload regardless of this setting.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	const MAX_SETTABLE_MB = 2048; // 2 GB.

	/**
	 * Bytes per MB, for converting the MB-denominated option/setting to bytes.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	const BYTES_PER_MB = 1048576;

	/**
	 * The token service.
	 *
	 * @since 1.4.0
	 * @var TokenService
	 */
	private TokenService $tokens;

	/**
	 * Constructor.
	 *
	 * @param TokenService|null $tokens Optional token service override (tests).
	 *
	 * @since 1.4.0
	 */
	public function __construct( ?TokenService $tokens = null ) {
		$this->tokens = $tokens ?? new TokenService();
	}

	/**
	 * Mint a new upload ticket.
	 *
	 * @param int                  $user_id The issuing user — re-checked at redemption.
	 * @param array<string, mixed> $args    {
	 *     Input parameters.
	 *
	 *     @type array<int, string> $accepted_types Optional MIME types to narrow the allowlist to.
	 *     @type int                $max_bytes      Optional byte cap request (capped by wp_max_upload_size()).
	 *     @type int                $post_id        Optional post to parent the resulting attachment to.
	 * }
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.4.0
	 */
	public function mint( int $user_id, array $args ): array|WP_Error {
		if ( ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'ability_permission_denied',
				__( 'This user does not have permission to upload media.', 'albert-ai-butler' ),
				[ 'status' => 403 ]
			);
		}

		$requested_types = array_values(
			array_filter( array_map( 'strval', (array) ( $args['accepted_types'] ?? [] ) ) )
		);
		$allowlist       = MimeAllowlist::for_user( $user_id, $requested_types );

		if ( empty( $allowlist ) ) {
			return new WP_Error(
				'no_accepted_types',
				__( 'None of the requested file types are allowed for this user.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		$post_id = absint( $args['post_id'] ?? 0 );
		if ( $post_id > 0 && ! get_post( $post_id ) ) {
			return new WP_Error(
				'invalid_post',
				__( 'The specified post does not exist.', 'albert-ai-butler' ),
				[ 'status' => 404 ]
			);
		}

		$max_bytes = $this->resolve_max_bytes( (int) ( $args['max_bytes'] ?? 0 ) );

		$issued = $this->tokens->issue(
			self::PURPOSE,
			$user_id,
			[
				'mime_allowlist' => $allowlist,
				'max_bytes'      => $max_bytes,
				'post_id'        => $post_id,
			],
			self::TTL_SECONDS
		);

		if ( is_wp_error( $issued ) ) {
			return $issued;
		}

		$upload_url = rest_url( Plugin::rest_namespace() . '/media/uploads' );

		return [
			'upload_url'     => $upload_url,
			'upload_token'   => $issued['token'],
			'token_header'   => self::TOKEN_HEADER,
			'method'         => self::HTTP_METHODS,
			'expires_at'     => $issued['expires_at'],
			'max_bytes'      => $max_bytes,
			'accepted_types' => MimeAllowlist::mime_list( $allowlist ),
			'post_id'        => $post_id,
			'curl_example'   => $this->curl_example( $upload_url, $issued['token'] ),
		];
	}

	/**
	 * Redeem a ticket token, re-checking the issuing user's capabilities.
	 *
	 * Marks the token spent (via {@see TokenService::redeem()}) before this
	 * method returns — single-use holds even if the caller never manages to
	 * finish the upload. The MIME allowlist is re-narrowed against the
	 * user's *current* `get_allowed_mime_types()`, so a role downgrade
	 * between mint and redemption can only shrink what is accepted.
	 *
	 * @param string $token The raw ticket token.
	 *
	 * @return array{user_id: int, mime_allowlist: array<string, string>, max_bytes: int, post_id: int}|WP_Error
	 * @since 1.4.0
	 */
	public function redeem_ticket( string $token ): array|WP_Error {
		$redeemed = $this->tokens->redeem( $token, self::PURPOSE );

		if ( is_wp_error( $redeemed ) ) {
			return $this->translate_token_error( $redeemed );
		}

		$user_id = $redeemed['user_id'];
		$payload = $redeemed['payload'];

		if ( ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'capability_revoked',
				__( 'The user who created this upload link no longer has permission to upload media.', 'albert-ai-butler' ),
				[ 'status' => 403 ]
			);
		}

		$stored_allowlist    = is_array( $payload['mime_allowlist'] ?? null ) ? $payload['mime_allowlist'] : [];
		$current_allowlist   = get_allowed_mime_types( $user_id );
		$effective_allowlist = MimeAllowlist::intersect( $current_allowlist, MimeAllowlist::mime_list( $stored_allowlist ) );

		if ( empty( $effective_allowlist ) ) {
			return new WP_Error(
				'capability_revoked',
				__( 'The user who created this upload link no longer has permission to upload any of the accepted file types.', 'albert-ai-butler' ),
				[ 'status' => 403 ]
			);
		}

		return [
			'user_id'        => $user_id,
			'mime_allowlist' => $effective_allowlist,
			'max_bytes'      => (int) ( $payload['max_bytes'] ?? self::DEFAULT_MAX_BYTES ),
			'post_id'        => (int) ( $payload['post_id'] ?? 0 ),
		];
	}

	/**
	 * Turn a received, on-disk file into a media library attachment.
	 *
	 * Real content sniffing against the redeemed ticket's effective allowlist
	 * — never the site's blanket default — decides acceptance; a capability
	 * such as `unfiltered_upload` is never consulted here, so it cannot widen
	 * what this endpoint accepts regardless of who is uploading. Core
	 * (`media_handle_sideload()`) owns filename sanitisation, uniqueness, the
	 * uploads directory, and attachment creation from that point on.
	 *
	 * The temp file is always deleted before this method returns, on every path.
	 *
	 * @param string                                                     $tmp_path          Path to the already-received file.
	 * @param string                                                     $original_filename Client-supplied filename (untrusted).
	 * @param array{mime_allowlist: array<string, string>, post_id: int} $context           From {@see self::redeem_ticket()}.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.4.0
	 */
	public function finalize_upload( string $tmp_path, string $original_filename, array $context ): array|WP_Error {
		$mime_allowlist = $context['mime_allowlist'];
		$filename       = sanitize_file_name( $original_filename );

		if ( $filename === '' ) {
			$this->delete_temp_file( $tmp_path );

			return new WP_Error(
				'type_not_allowed',
				__( 'A filename with a valid extension is required.', 'albert-ai-butler' ),
				[
					'status'         => 415,
					'accepted_types' => MimeAllowlist::mime_list( $mime_allowlist ),
				]
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		// Real content sniffing against ONLY this ticket's effective allowlist.
		// Deliberately independent of the uploading user's capabilities —
		// unfiltered_upload is never consulted here, by any code path, for
		// any user. A mismatch (including a renamed-extension spoof) fails
		// here, before core's own, capability-aware check ever runs.
		$file_type = wp_check_filetype_and_ext( $tmp_path, $filename, $mime_allowlist );

		if ( empty( $file_type['ext'] ) || empty( $file_type['type'] ) ) {
			$this->delete_temp_file( $tmp_path );

			return new WP_Error(
				'type_not_allowed',
				__( 'This file type is not accepted.', 'albert-ai-butler' ),
				[
					'status'         => 415,
					'accepted_types' => MimeAllowlist::mime_list( $mime_allowlist ),
				]
			);
		}

		if ( ! empty( $file_type['proper_filename'] ) ) {
			$filename = $file_type['proper_filename'];
		}

		$file_array = [
			'name'     => $filename,
			'tmp_name' => $tmp_path,
		];

		$attachment_id = media_handle_sideload( $file_array, $context['post_id'] );

		$this->delete_temp_file( $tmp_path );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		return $this->format_attachment_response( $attachment_id );
	}

	/**
	 * Resolve the max_bytes cap: a caller's request, or the site's own
	 * default, always ceilinged at the server's own upload limit.
	 *
	 * @param int $requested Caller-requested byte cap, 0 for "use the default".
	 *
	 * @return int
	 * @since 1.4.0
	 */
	private function resolve_max_bytes( int $requested ): int {
		$ceiling = (int) wp_max_upload_size();
		$base    = $requested > 0 ? $requested : $this->default_max_bytes();

		return max( 1, $ceiling > 0 ? min( $base, $ceiling ) : $base );
	}

	/**
	 * Resolve the default byte cap applied when a caller doesn't request one.
	 *
	 * Precedence, same shape as {@see \Albert\Privacy\PrivacyMode::resolve()}:
	 * the filter can override outright (return an int; `null` defers), then
	 * the site owner's own Settings-screen value, then the built-in
	 * conservative default. Whatever this returns is still ceilinged by
	 * {@see self::resolve_max_bytes()} against `wp_max_upload_size()` — this
	 * only ever adjusts the *default*, it can't make the endpoint accept more
	 * than the server itself will.
	 *
	 * @return int
	 * @since 1.4.0
	 */
	private function default_max_bytes(): int {
		$state = self::get_default_max_bytes_filter_state();
		if ( $state['state'] === 'active' ) {
			return $state['value'];
		}

		$option_mb = (int) get_option( self::MAX_BYTES_OPTION, self::DEFAULT_MAX_MB );

		return $option_mb > 0 ? $option_mb * self::BYTES_PER_MB : self::DEFAULT_MAX_BYTES;
	}

	/**
	 * Get the current state of the `albert/media/upload_link_max_bytes` filter.
	 *
	 * Same shape and purpose as {@see \Albert\MCP\Server::get_external_url_state()},
	 * used by both the resolver above and the Settings screen so the screen can
	 * show what's actually in effect (see {@see self::render_max_mb_field()})
	 * instead of a stored value the filter is silently overriding. Deliberately
	 * NOT memoized, unlike that precedent: this is called at most a couple of
	 * times per request (a mint() call, a Settings-screen render), never in a
	 * hot loop, so there's no real cost to calling apply_filters() fresh each
	 * time — and a request-lifetime static cache would mean a hook that starts
	 * or stops overriding mid-request (e.g. a test flipping it, or a callback
	 * whose own condition changes) reads stale.
	 *
	 * @since 1.4.0
	 *
	 * @return array{state: 'inactive'|'active', value: int, requested: int}
	 */
	public static function get_default_max_bytes_filter_state(): array {
		/**
		 * Filters the default byte cap for a media upload link.
		 *
		 * Applies only when a caller doesn't request `max_bytes` explicitly.
		 * Return an int (bytes), or a php.ini-style shorthand string such as
		 * `"10M"`, `"512K"`, or `"2G"` — anything {@see wp_convert_hr_to_bytes()}
		 * understands, the same parser WordPress itself uses for
		 * `memory_limit`/`upload_max_filesize` — to override the site's own
		 * setting outright. Return null (the default) to defer to it.
		 *
		 * Clamped to {@see self::MAX_SETTABLE_MB} regardless of what's
		 * returned — the same ceiling the Settings screen's own field is
		 * bound to, so a filter can't set something the UI itself would
		 * refuse (e.g. a stray extra zero producing "10G" instead of "10M").
		 * `wp_max_upload_size()` still applies on top of that at redemption
		 * time regardless, as it does for every other source of this value.
		 *
		 * @since 1.4.0
		 *
		 * @param int|string|null $max_bytes The overriding default (bytes, or a shorthand
		 *                                   size string), or null to defer.
		 */
		$filtered = apply_filters( 'albert/media/upload_link_max_bytes', null );

		$bytes = self::parse_filtered_bytes( $filtered );

		if ( $bytes > 0 ) {
			return [
				'state'     => 'active',
				'value'     => min( $bytes, self::MAX_SETTABLE_MB * self::BYTES_PER_MB ),
				// The unclamped value the filter actually asked for — lets a
				// caller (render_max_mb_field()) tell "filter set 15 MB,
				// using 15 MB" apart from "filter asked for 10240 MB, using
				// 2048 MB instead" and warn accordingly, rather than showing
				// the same plain notice either way.
				'requested' => $bytes,
			];
		}

		return [
			'state'     => 'inactive',
			'value'     => 0,
			'requested' => 0,
		];
	}

	/**
	 * Parse the `albert/media/upload_link_max_bytes` filter's return value
	 * into a positive byte count, or 0 when it isn't a usable value (null,
	 * empty, zero, negative, or a string wp_convert_hr_to_bytes() can't
	 * make sense of).
	 *
	 * @param mixed $value Raw filter return value.
	 *
	 * @return int Bytes, or 0 when not usable.
	 * @since 1.4.0
	 */
	private static function parse_filtered_bytes( $value ): int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : 0;
		}

		if ( is_string( $value ) && trim( $value ) !== '' ) {
			$bytes = (int) wp_convert_hr_to_bytes( $value );

			return $bytes > 0 ? $bytes : 0;
		}

		return 0;
	}

	/**
	 * Render the Uploads section's default_max_mb field.
	 *
	 * The Settings screen's field types (see {@see \Albert\Admin\SettingsRenderer})
	 * are plain inputs with no concept of "disabled because a filter is
	 * overriding it" — that's specific to this one field, not something
	 * every settings field needs, so rather than teach the generic renderer
	 * a cross-cutting mechanism for a single consumer, this field is
	 * `'type' => 'custom'` (see {@see \Albert\Admin\SettingsBootstrap}) and
	 * owns its own markup, the same escape hatch the licenses table already
	 * uses. When the filter is active the field shows *that* value, disabled,
	 * with an explanatory notice — never the stored option's value, which
	 * would invite editing something that can't currently take effect.
	 *
	 * @since 1.4.0
	 *
	 * @param array<string, mixed> $field         Field definition (unused — the input is hand-rolled).
	 * @param mixed                $current_value The stored option's value (or default), from get_option().
	 *
	 * @return void
	 */
	public static function render_max_mb_field( array $field, $current_value ): void {
		unset( $field );

		$state   = self::get_default_max_bytes_filter_state();
		$active  = $state['state'] === 'active';
		$clamped = $active && $state['requested'] > $state['value'];
		$value   = $active ? (int) round( $state['value'] / self::BYTES_PER_MB ) : (int) $current_value;

		printf(
			'<input type="number" name="%1$s" id="albert-field-%1$s" value="%2$d" class="albert-text-input" min="1" max="%3$d" step="1"%4$s />',
			esc_attr( self::MAX_BYTES_OPTION ),
			absint( $value ),
			absint( self::MAX_SETTABLE_MB ),
			$active ? ' disabled' : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static string, not user input.
		);

		if ( $clamped ) {
			self::render_hint(
				sprintf(
					/* translators: 1: opening <code>, 2: closing </code> wrapping the filter name, 3: the value the filter requested (MB), 4: the maximum allowed (MB) */
					__( 'A %1$salbert/media/upload_link_max_bytes%2$s filter is requesting %3$d MB, above the %4$d MB maximum — %4$d MB is being used instead.', 'albert-ai-butler' ),
					'<code>',
					'</code>',
					(int) round( $state['requested'] / self::BYTES_PER_MB ),
					self::MAX_SETTABLE_MB
				),
				'warning'
			);
		} elseif ( $active ) {
			self::render_hint(
				sprintf(
					/* translators: 1: opening <code>, 2: closing </code> wrapping the filter name. */
					__( 'A %1$salbert/media/upload_link_max_bytes%2$s filter is currently active, overriding what\'s saved here.', 'albert-ai-butler' ),
					'<code>',
					'</code>'
				),
				'info'
			);
		}
	}

	/**
	 * Render a `.albert-hint` block — the same component the Connections
	 * screen uses for its own filter-override and invalid-value cases
	 * (`albert/mcp/external_url`), reused here rather than inventing new
	 * markup for the same shape of message.
	 *
	 * @param string $notice May contain `<code>` tags — the codebase's
	 *                       convention for wrapping a filter/hook name —
	 *                       and nothing else; built via sprintf(), not raw
	 *                       user input.
	 * @param string $tone   'info' or 'warning'.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private static function render_hint( string $notice, string $tone ): void {
		$icon = $tone === 'warning' ? 'warning' : 'info';

		echo '<div class="albert-hint albert-hint--' . esc_attr( $tone ) . '">';
		echo '<span class="dashicons dashicons-' . esc_attr( $icon ) . '" aria-hidden="true"></span>';
		echo '<p>' . wp_kses( $notice, [ 'code' => [] ] ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses() with an explicit allowlist above.
		echo '</div>';
	}

	/**
	 * Sanitize the Settings-screen field for {@see self::MAX_BYTES_OPTION}.
	 *
	 * Clamped to [1, MAX_SETTABLE_MB] rather than validated against the
	 * server's real upload ceiling — {@see wp_max_upload_size()} already
	 * enforces that at redemption time regardless of what's stored here, so
	 * this is only guarding against garbage input (0, negative, non-numeric).
	 *
	 * While the filter is active the field renders disabled ({@see
	 * self::render_max_mb_field()}), so a browser never submits it — $_POST
	 * simply won't carry this key, and a plain "missing means invalid, fall
	 * back to the default" rule would silently reset the stored value on
	 * every unrelated settings save. Returning the value already stored
	 * makes that submission a harmless no-op instead.
	 *
	 * @param mixed $value Raw value from the settings form.
	 *
	 * @return int MB, clamped to [1, MAX_SETTABLE_MB].
	 * @since 1.4.0
	 */
	public static function sanitize_max_mb( $value ): int {
		if ( self::get_default_max_bytes_filter_state()['state'] === 'active' ) {
			return (int) get_option( self::MAX_BYTES_OPTION, self::DEFAULT_MAX_MB );
		}

		// (int) casts preserve sign, unlike absint() — a negative input must
		// fall through to the default below, not have its sign silently
		// flipped into a different, "valid-looking" positive value.
		$mb = is_scalar( $value ) ? (int) $value : 0;

		if ( $mb < 1 ) {
			return self::DEFAULT_MAX_MB;
		}

		if ( $mb > self::MAX_SETTABLE_MB ) {
			// A one-time, save-triggered notice — reuses the same
			// add_settings_error()/settings_errors() mechanism the page
			// already displays its "Settings saved" message through, rather
			// than inventing a separate way to surface this. Unlike the
			// filter-override hint in render_max_mb_field() (which has to
			// hold on every page load while the condition persists), this
			// only needs to fire once, at the moment someone actually tries
			// to save an over-the-cap value.
			add_settings_error(
				'albert_settings',
				'upload_link_max_mb_clamped',
				sprintf(
					/* translators: 1: the value that was requested (MB), 2: the maximum allowed (MB) */
					__( 'The default upload size limit can\'t be set above %2$d MB. %1$d MB was requested, so %2$d MB was saved instead.', 'albert-ai-butler' ),
					$mb,
					self::MAX_SETTABLE_MB
				),
				'warning'
			);
		}

		return min( $mb, self::MAX_SETTABLE_MB );
	}

	/**
	 * Translate a generic {@see TokenService} error into this domain's vocabulary.
	 *
	 * @param WP_Error $error The generic token error.
	 *
	 * @return WP_Error
	 * @since 1.4.0
	 */
	private function translate_token_error( WP_Error $error ): WP_Error {
		if ( $error->get_error_code() === 'token_expired' ) {
			return new WP_Error(
				'ticket_expired',
				__( 'This upload link has expired.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		return new WP_Error(
			'ticket_already_used',
			__( 'This upload link is invalid or has already been used.', 'albert-ai-butler' ),
			[ 'status' => 400 ]
		);
	}

	/**
	 * Build a ready-to-run curl example for the assistant to act on.
	 *
	 * @param string $upload_url The redemption endpoint.
	 * @param string $token      The raw ticket token.
	 *
	 * @return string
	 * @since 1.4.0
	 */
	private function curl_example( string $upload_url, string $token ): string {
		return sprintf(
			"curl -X POST '%s' -H '%s: %s' -F 'file=@/path/to/file.jpg'",
			$upload_url,
			self::TOKEN_HEADER,
			$token
		);
	}

	/**
	 * Delete the temp file if it still exists. Never errors on a missing file.
	 *
	 * @param string $tmp_path Path to the temp file.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function delete_temp_file( string $tmp_path ): void {
		if ( $tmp_path !== '' && file_exists( $tmp_path ) ) {
			wp_delete_file( $tmp_path );
		}
	}

	/**
	 * Format attachment data for response. Mirrors UploadMedia's shape.
	 *
	 * @param int $attachment_id The attachment ID.
	 *
	 * @return array<string, mixed>
	 * @since 1.4.0
	 */
	private function format_attachment_response( int $attachment_id ): array {
		$metadata  = wp_get_attachment_metadata( $attachment_id );
		$file_size = $metadata['filesize'] ?? 0;

		if ( empty( $file_size ) ) {
			$attached_file = get_attached_file( $attachment_id );
			$file_size     = $attached_file ? filesize( $attached_file ) : 0;
		}

		return [
			'attachment_id' => $attachment_id,
			'url'           => wp_get_attachment_url( $attachment_id ),
			'width'         => $metadata['width'] ?? 0,
			'height'        => $metadata['height'] ?? 0,
			'mime_type'     => get_post_mime_type( $attachment_id ),
			'file_size'     => $file_size ? $file_size : 0,
		];
	}
}
