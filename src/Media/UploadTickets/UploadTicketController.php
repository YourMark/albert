<?php
/**
 * Upload Ticket Redemption Controller
 *
 * @package Albert
 * @subpackage Media\UploadTickets
 * @since      1.4.0
 */

namespace Albert\Media\UploadTickets;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\Core\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * UploadTicketController class
 *
 * The redemption endpoint an assistant PUTs/POSTs bytes to after minting a
 * ticket via `albert/create-upload-link`. Deliberately public
 * (`permission_callback` is `__return_true`) — the ticket token, sent only
 * in a header, is the credential; there is no WordPress-authenticated user
 * on this request otherwise. Everything past reading the token is delegated
 * to {@see UploadTicketService}, which re-checks the issuing user's
 * capabilities before touching the filesystem.
 *
 * This is a raw HTTP endpoint the assistant curls directly, not an MCP
 * ability call — bytes never pass through the MCP transport.
 *
 * @since 1.4.0
 */
class UploadTicketController implements Hookable {

	/**
	 * Synthetic ability id this endpoint logs under.
	 *
	 * Not a registered WP_Ability — the execution log's `ability_name` column
	 * only needs a stable string, and {@see \Albert\Logging\Logger} listens
	 * to `albert/abilities/after_execute` generically. Named to sort next to
	 * `albert/create-upload-link` in the log.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const LOG_ABILITY_ID = 'albert/redeem-upload-ticket';

	/**
	 * Bytes read per chunk while streaming a raw request body to disk.
	 *
	 * Deliberately a small, fixed, server-owned constant — never derived
	 * from `max_bytes`, which is caller-influenced (up to
	 * `wp_max_upload_size()`, easily hundreds of MB on a real host). Sizing
	 * the chunk to the cap would mean a single fread() has to pull the
	 * entire allowed size into memory before the cap can even be checked,
	 * which is exactly the "buffer the whole body to measure it"
	 * anti-pattern doc 32 rules out, and it ties peak per-request memory to
	 * whatever a caller happened to request.
	 *
	 * 128 KiB: at the old 8 KiB, a 100 MB body cost ~12,800 fread()/fwrite()
	 * iterations; here it's ~800 — most of the win for a fraction of 1 MiB's
	 * footprint. 1 MiB was matched to Novamira's number without re-deriving
	 * it for our scale: their 512 MB cap and multipart-network sizing (à la
	 * S3's 5 MiB minimum part size) don't apply to local disk streaming
	 * bounded at a realistic tens-of-MB media upload. 1 MiB also costs more
	 * under concurrency — 100 simultaneous uploads hold ~100 MB of buffers
	 * across the PHP-FPM pool at once at 1 MiB, vs. ~13 MB at 128 KiB.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	const STREAM_CHUNK_BYTES = 131072;

	/**
	 * The upload ticket service.
	 *
	 * @since 1.4.0
	 * @var UploadTicketService
	 */
	private UploadTicketService $tickets;

	/**
	 * Constructor.
	 *
	 * @param UploadTicketService|null $tickets Optional service override (tests).
	 *
	 * @since 1.4.0
	 */
	public function __construct( ?UploadTicketService $tickets = null ) {
		$this->tickets = $tickets ?? new UploadTicketService();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the redemption REST route.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function register_routes(): void {
		register_rest_route(
			Plugin::rest_namespace(),
			'/media/uploads',
			[
				'methods'             => UploadTicketService::HTTP_METHODS,
				'callback'            => [ $this, 'handle_upload' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Handle a ticket redemption.
	 *
	 * Order matters and mirrors docs/features/32-media-uploads.md exactly:
	 * look up + mark redeemed BEFORE any processing (inside
	 * {@see UploadTicketService::redeem_ticket()}), re-check the issuing
	 * user, receive the file under the byte cap, sniff real content against
	 * the effective allowlist, then hand off to core.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 * @since 1.4.0
	 */
	public function handle_upload( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$token = trim( (string) $request->get_header( UploadTicketService::TOKEN_HEADER ) );

		if ( $token === '' ) {
			return new WP_Error(
				'ticket_already_used',
				__( 'No upload token was provided.', 'albert-ai-butler' ),
				[ 'status' => 401 ]
			);
		}

		// Burns the token — single-use holds from this point on, whatever
		// happens next.
		$context = $this->tickets->redeem_ticket( $token );

		if ( is_wp_error( $context ) ) {
			$this->log( 0, [], $context );

			return $context;
		}

		$received = $this->receive_file( $request, $context['max_bytes'] );

		if ( is_wp_error( $received ) ) {
			$this->log( $context['user_id'], [ 'post_id' => $context['post_id'] ], $received );

			return $received;
		}

		$result = $this->tickets->finalize_upload( $received['tmp_path'], $received['filename'], $context );

		$this->log(
			$context['user_id'],
			[
				'post_id'  => $context['post_id'],
				'filename' => $received['filename'],
			],
			$result
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * Receive the uploaded bytes onto disk, enforcing the ticket's byte cap.
	 *
	 * Supports a multipart `file` field (PHP/the webserver already streamed
	 * it to a temp file; we only need to check its size before doing
	 * anything else with it) or a raw request body, streamed to disk in
	 * chunks so an oversized body is never buffered in memory and never
	 * fully written to disk before being rejected.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request   The REST request.
	 * @param int                                   $max_bytes The ticket's byte cap.
	 *
	 * @return array{tmp_path: string, filename: string}|WP_Error
	 * @since 1.4.0
	 */
	private function receive_file( WP_REST_Request $request, int $max_bytes ): array|WP_Error {
		$files = $request->get_file_params();

		if ( ! empty( $files['file']['tmp_name'] ) && is_string( $files['file']['tmp_name'] ) ) {
			$file = $files['file'];

			if ( ! empty( $file['error'] ) ) {
				return new WP_Error(
					'upload_error',
					__( 'The uploaded file could not be received.', 'albert-ai-butler' ),
					[ 'status' => 400 ]
				);
			}

			$size = isset( $file['size'] ) ? (int) $file['size'] : (int) @filesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort fallback when the client omitted size.

			if ( $size > $max_bytes ) {
				if ( file_exists( $file['tmp_name'] ) ) {
					wp_delete_file( $file['tmp_name'] );
				}

				return new WP_Error(
					'too_large',
					__( 'The uploaded file exceeds the size allowed for this upload link.', 'albert-ai-butler' ),
					[
						'status'    => 413,
						'max_bytes' => $max_bytes,
					]
				);
			}

			return [
				'tmp_path' => $file['tmp_name'],
				'filename' => (string) ( $file['name'] ?? '' ),
			];
		}

		$filename = sanitize_file_name( (string) $request->get_param( 'filename' ) );

		if ( $filename === '' ) {
			return new WP_Error(
				'type_not_allowed',
				__( 'A "filename" parameter with a valid extension is required for a raw-body upload.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		// Never call $request->get_body() — that would buffer the whole body
		// in memory first. Read the input stream directly instead.
		$input = fopen( 'php://input', 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming an HTTP request body, not a filesystem file.

		if ( ! $input ) {
			return new WP_Error(
				'upload_error',
				__( 'Could not read the request body.', 'albert-ai-butler' ),
				[ 'status' => 500 ]
			);
		}

		$tmp_path = $this->stream_to_temp_file( $input, $max_bytes );
		fclose( $input ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired with the fopen() above.

		if ( is_wp_error( $tmp_path ) ) {
			return $tmp_path;
		}

		return [
			'tmp_path' => $tmp_path,
			'filename' => $filename,
		];
	}

	/**
	 * Stream a readable resource to a new temp file, enforcing a byte cap
	 * while writing. The chunk that would push the total over the cap is
	 * never written, so the file on disk never exceeds `$max_bytes`, and the
	 * full body is never held in memory at once.
	 *
	 * Public so the streaming/cap logic is directly testable against an
	 * in-memory stream, independent of a real HTTP request.
	 *
	 * @param resource $stream    A readable stream (e.g. `php://input`).
	 * @param int      $max_bytes The byte cap to enforce.
	 *
	 * @return string|WP_Error Path to the temp file, or a `too_large`/`upload_error` WP_Error.
	 * @since 1.4.0
	 */
	public function stream_to_temp_file( $stream, int $max_bytes ): string|WP_Error {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$tmp_path = wp_tempnam();
		$out      = fopen( $tmp_path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Writing a controlled temp file, not arbitrary user input as a path.

		if ( ! $out ) {
			return new WP_Error(
				'upload_error',
				__( 'Could not create a temporary file.', 'albert-ai-butler' ),
				[ 'status' => 500 ]
			);
		}

		$total     = 0;
		$too_large = false;

		while ( ! feof( $stream ) ) {
			$chunk = fread( $stream, self::STREAM_CHUNK_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reading a stream resource in chunks, not a filesystem file by path.

			if ( $chunk === false || $chunk === '' ) {
				break;
			}

			$total += strlen( $chunk );

			if ( $total > $max_bytes ) {
				$too_large = true;
				break;
			}

			fwrite( $out, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming to a controlled temp file.
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired with the fopen() above.

		if ( $too_large ) {
			wp_delete_file( $tmp_path );

			return new WP_Error(
				'too_large',
				__( 'The uploaded file exceeds the size allowed for this upload link.', 'albert-ai-butler' ),
				[
					'status'    => 413,
					'max_bytes' => $max_bytes,
				]
			);
		}

		if ( $total === 0 ) {
			wp_delete_file( $tmp_path );

			return new WP_Error(
				'upload_error',
				__( 'No file data was received.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		return $tmp_path;
	}

	/**
	 * Log a redemption attempt through Albert's normal execution-log path.
	 *
	 * This endpoint is not a WP_Ability, so `guarded_execute()` never fires
	 * `albert/abilities/after_execute` for it — the same gap
	 * `MCP\ToolCallObserver` fills for pre-execute ability failures. Firing
	 * it here directly is what makes a ticket redemption show up in the
	 * execution log at all.
	 *
	 * @param int                           $user_id The issuing user (0 when the token itself was rejected).
	 * @param array<string, mixed>          $args    Non-sensitive context: post_id, filename. Never the token.
	 * @param array<string, mixed>|WP_Error $result  The outcome.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function log( int $user_id, array $args, array|WP_Error $result ): void {
		try {
			do_action( 'albert/abilities/after_execute', self::LOG_ABILITY_ID, $args, $result, $user_id );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Never let a logging failure surface as an upload failure.
		}
	}
}
