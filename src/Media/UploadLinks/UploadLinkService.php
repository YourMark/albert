<?php
/**
 * Upload Link Service
 *
 * @package Albert
 * @subpackage Media\UploadLinks
 * @since      1.4.0
 */

namespace Albert\Media\UploadLinks;

defined( 'ABSPATH' ) || exit;

use Albert\Core\Plugin;
use Albert\Core\Tokens\TokenService;
use Albert\Media\AttachmentImporter;
use Albert\Media\MimeAllowlist;
use WP_Error;

/**
 * Mints and redeems media upload links (doc 32, Path B), built on the
 * generic {@see TokenService} primitive.
 *
 * @since 1.4.0
 */
class UploadLinkService {

	/**
	 * The token purpose partitioning upload links from any other token consumer.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const PURPOSE = 'media_upload';

	/**
	 * The capability required to mint a link and to still hold one at redemption time.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const REQUIRED_CAPABILITY = 'upload_files';

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
	 * Both, deliberately: PUT suits raw-body clients, POST suits multipart ones.
	 * Comma-separated because that is the shape `register_rest_route()` wants,
	 * the same one core's own `WP_REST_Server::EDITABLE` uses. Callers are told
	 * about it through {@see self::method_list()}, never this string.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const HTTP_METHODS = 'POST, PUT';

	/**
	 * The accepted methods as a list, for the assistant reading the response.
	 *
	 * Derived from {@see self::HTTP_METHODS} rather than written out again, so
	 * the route and the advertised methods cannot drift apart. A list, because
	 * the consumer is a machine: handing it "POST, PUT" invites it to send that
	 * whole string as the method.
	 *
	 * @return array<int, string>
	 * @since 1.4.0
	 */
	public static function method_list(): array {
		return array_map( 'trim', explode( ',', self::HTTP_METHODS ) );
	}

	/**
	 * How long a minted link stays valid.
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
	const DEFAULT_MAX_BYTES = self::DEFAULT_MAX_MB * self::BYTES_PER_MB;

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
	 * Mint a new upload link.
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
		if ( ! user_can( $user_id, self::REQUIRED_CAPABILITY ) ) {
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

		// (int) cast, not absint(): absint(-12) returns 12, which would
		// silently parent against a post the caller never asked for.
		$post_id = max( 0, (int) ( $args['post_id'] ?? 0 ) );
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
			'methods'        => self::method_list(),
			'expires_at'     => $issued['expires_at'],
			'max_bytes'      => $max_bytes,
			'accepted_types' => MimeAllowlist::mime_list( $allowlist ),
			'post_id'        => $post_id,
			'curl_example'   => $this->curl_example( $upload_url, $issued['token'] ),
		];
	}

	/**
	 * Redeem a link token, re-checking the issuing user's capabilities.
	 *
	 * The MIME allowlist is re-narrowed against the user's *current*
	 * `get_allowed_mime_types()`, so a role downgrade since mint can only
	 * shrink what's accepted, never widen it.
	 *
	 * @param string $token The raw link token.
	 *
	 * @return array{user_id: int, mime_allowlist: array<string, string>, max_bytes: int, post_id: int}|WP_Error
	 * @since 1.4.0
	 */
	public function redeem_link( string $token ): array|WP_Error {
		$redeemed = $this->tokens->redeem( $token, self::PURPOSE );

		if ( is_wp_error( $redeemed ) ) {
			return $this->translate_token_error( $redeemed );
		}

		$user_id = $redeemed['user_id'];
		$payload = $redeemed['payload'];

		if ( ! user_can( $user_id, self::REQUIRED_CAPABILITY ) ) {
			return new WP_Error(
				'capability_revoked',
				__( 'The user who created this upload link no longer has permission to upload media.', 'albert-ai-butler' ),
				// user_id rides in the error data so the controller can still log the real actor.
				[
					'status'  => 403,
					'user_id' => $user_id,
				]
			);
		}

		$stored_allowlist    = is_array( $payload['mime_allowlist'] ?? null ) ? $payload['mime_allowlist'] : [];
		$current_allowlist   = get_allowed_mime_types( $user_id );
		$effective_allowlist = MimeAllowlist::intersect( $current_allowlist, MimeAllowlist::mime_list( $stored_allowlist ) );

		if ( empty( $effective_allowlist ) ) {
			return new WP_Error(
				'capability_revoked',
				__( 'The user who created this upload link no longer has permission to upload any of the accepted file types.', 'albert-ai-butler' ),
				[
					'status'  => 403,
					'user_id' => $user_id,
				]
			);
		}

		return [
			'user_id'        => $user_id,
			'mime_allowlist' => $effective_allowlist,
			// Re-ceilinged, not just read back: the server's own limit can have
			// been lowered since this link was minted, and the stored number
			// would then promise more than core will accept.
			'max_bytes'      => $this->apply_server_ceiling( (int) ( $payload['max_bytes'] ?? self::DEFAULT_MAX_BYTES ) ),
			'post_id'        => (int) ( $payload['post_id'] ?? 0 ),
		];
	}

	/**
	 * Turn a received, on-disk file into a media library attachment.
	 *
	 * Thin wrapper over {@see AttachmentImporter::import()}, which both upload
	 * paths share; this keeps the link domain's own vocabulary at the seam the
	 * controller calls. Content sniffing runs against the link's own allowlist,
	 * never `unfiltered_upload`. The temp file is always consumed.
	 *
	 * @param string                                                     $tmp_path          Path to the already-received file.
	 * @param string                                                     $original_filename Client-supplied filename (untrusted).
	 * @param array{mime_allowlist: array<string, string>, post_id: int} $context           From {@see self::redeem_link()}.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.4.0
	 */
	public function finalize_upload( string $tmp_path, string $original_filename, array $context ): array|WP_Error {
		return AttachmentImporter::import(
			$tmp_path,
			$original_filename,
			$context['mime_allowlist'],
			$context['post_id']
		);
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
		return $this->apply_server_ceiling( $requested > 0 ? $requested : $this->default_max_bytes() );
	}

	/**
	 * Clamp a byte cap to what this server will actually accept.
	 *
	 * Applied both when minting and when redeeming, so a link cannot outlive a
	 * tightening of the server's own limit. `wp_max_upload_size()` is
	 * filterable and can return 0 or less on a misconfigured host; treat that
	 * as "no usable ceiling" rather than clamping every upload to nothing.
	 *
	 * @param int $bytes The cap to clamp.
	 *
	 * @return int At least 1.
	 * @since 1.4.0
	 */
	private function apply_server_ceiling( int $bytes ): int {
		$ceiling = (int) wp_max_upload_size();

		return max( 1, $ceiling > 0 ? min( $bytes, $ceiling ) : $bytes );
	}

	/**
	 * Resolve the default byte cap: filter overrides, else the Settings
	 * value, else the built-in default. Still ceilinged by
	 * {@see self::resolve_max_bytes()} against `wp_max_upload_size()`.
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
	 * Used by both the resolver above and the Settings screen. Deliberately
	 * not memoized (unlike {@see \Albert\MCP\Server::get_external_url_state()})
	 * so a filter that starts or stops applying mid-test doesn't read stale.
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
		 * Accepts an int (bytes) or a php.ini-style shorthand string
		 * (`"10M"`, `"2G"`) via {@see wp_convert_hr_to_bytes()}; null defers
		 * to the site's own setting. Clamped to {@see self::MAX_SETTABLE_MB};
		 * `wp_max_upload_size()` still applies on top at redemption time.
		 *
		 * @since 1.4.0
		 *
		 * @param int|string|null $max_bytes The overriding default, or null to defer.
		 */
		$filtered = apply_filters( 'albert/media/upload_link_max_bytes', null );

		$bytes = self::parse_filtered_bytes( $filtered );

		if ( $bytes > 0 ) {
			return [
				'state'     => 'active',
				'value'     => min( $bytes, self::MAX_SETTABLE_MB * self::BYTES_PER_MB ),
				// Unclamped, so render_max_mb_field() can tell "clamped" from "not".
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
		// is_float too: a filter computing e.g. 1.5 * MB is otherwise dropped
		// silently, and the screen then shows no override at all.
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value > 0 ? (int) $value : 0;
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
	 * `'type' => 'custom'` (see {@see \Albert\Admin\SettingsBootstrap}), the
	 * same escape hatch the licenses table uses, since the generic renderer
	 * has no concept of "disabled because a filter overrides it".
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

		// Round a sub-megabyte filter value UP, never to 0: the field's own
		// min is 1, and rendering value="0" against it is invalid. The hint
		// below carries the exact size so the rounding can't mislead.
		$value = $active
			? max( 1, (int) ceil( $state['value'] / self::BYTES_PER_MB ) )
			: (int) $current_value;

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
					/* translators: 1: opening <code>, 2: closing </code> wrapping the filter name, 3: the effective size, human-readable (e.g. "500 B") */
					__( 'A %1$salbert/media/upload_link_max_bytes%2$s filter is currently active, overriding what\'s saved here. The limit in effect is %3$s.', 'albert-ai-butler' ),
					'<code>',
					'</code>',
					size_format( $state['value'] )
				),
				'info'
			);
		}
	}

	/**
	 * Render a `.albert-hint` block, matching the Connections screen's own hints.
	 *
	 * @param string $notice May contain `<code>` tags; built via sprintf(), not raw user input.
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
	 * While the filter is active the field renders disabled, so it's never
	 * submitted; return the stored value as a no-op rather than resetting it.
	 *
	 * @param mixed $value Raw value from the settings form.
	 *
	 * @return int MB, clamped to [1, MAX_SETTABLE_MB].
	 * @since 1.4.0
	 */
	public static function sanitize_max_mb( $value ): int {
		$stored = (int) get_option( self::MAX_BYTES_OPTION, self::DEFAULT_MAX_MB );

		// null means the field was not submitted, which is what a disabled
		// field does. Keyed on the value rather than only on the filter's
		// state at save time: the filter can go inactive between the render
		// that disabled the field and the save, and treating "absent" as
		// "invalid" would then overwrite a stored value nobody touched.
		if ( $value === null || self::get_default_max_bytes_filter_state()['state'] === 'active' ) {
			return $stored;
		}

		// (int) cast, not absint(): a negative input must fall through to the default below.
		$mb = is_scalar( $value ) ? (int) $value : 0;

		if ( $mb < 1 ) {
			// Say so, exactly as an over-range value does. Silently rewriting
			// a 0 to 10 leaves somebody staring at a number they did not type.
			add_settings_error(
				'albert_settings',
				'upload_link_max_mb_too_low',
				sprintf(
					/* translators: %d: the default that was saved instead (MB) */
					__( 'The default upload size limit must be at least 1 MB, so %d MB was saved instead.', 'albert-ai-butler' ),
					self::DEFAULT_MAX_MB
				),
				'warning'
			);

			return self::DEFAULT_MAX_MB;
		}

		if ( $mb > self::MAX_SETTABLE_MB ) {
			// Reuses the page's own "Settings saved" notice mechanism.
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
				'link_expired',
				__( 'This upload link has expired.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		return new WP_Error(
			'link_already_used',
			__( 'This upload link is invalid or has already been used.', 'albert-ai-butler' ),
			[ 'status' => 400 ]
		);
	}

	/**
	 * Build a ready-to-run curl example for the assistant to act on.
	 *
	 * @param string $upload_url The redemption endpoint.
	 * @param string $token      The raw link token.
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
}
