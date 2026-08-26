<?php
/**
 * Attachment Importer
 *
 * @package Albert
 * @subpackage Media
 * @since      1.4.0
 */

namespace Albert\Media;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Turns a file already on disk into a media library attachment.
 *
 * The single tail shared by both upload paths: `albert/upload-media`, which
 * downloads from a URL, and `albert/create-upload-link`, which receives bytes
 * over HTTP. Once either has a temp file and a filename, everything from here
 * is identical, and the two had drifted: only one honoured the corrected
 * filename core hands back, and only one turned core's status-less refusal
 * into something other than a 500.
 *
 * Content is always sniffed against a caller-supplied allowlist. That is what
 * keeps `unfiltered_upload` from widening either path: core's own check in
 * `_wp_handle_upload()` waves a bad type through for a user holding that
 * capability (wp-admin/includes/file.php), so this rejection has to happen
 * first. Do not reorder it below the sideload.
 *
 * @since 1.4.0
 */
class AttachmentImporter {

	/**
	 * Import a file on disk into the media library.
	 *
	 * The temp file is always consumed: moved into place on success, deleted
	 * on every failure. Callers never clean up after this method.
	 *
	 * @param string                $tmp_path  Path to the file already on disk.
	 * @param string                $filename  Client-supplied filename (untrusted).
	 * @param array<string, string> $allowlist Extension-regex => MIME type, from {@see MimeAllowlist}.
	 * @param int                   $post_id   Post to attach to, 0 for none.
	 *
	 * @return array<string, mixed>|WP_Error The formatted attachment, or why it was refused.
	 * @since 1.4.0
	 */
	public static function import( string $tmp_path, string $filename, array $allowlist, int $post_id = 0 ): array|WP_Error {
		$filename = sanitize_file_name( $filename );

		if ( $filename === '' ) {
			TempFile::delete( $tmp_path );

			return self::type_error(
				__( 'A filename with a valid extension is required.', 'albert-ai-butler' ),
				$allowlist
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$file_type = wp_check_filetype_and_ext( $tmp_path, $filename, $allowlist );

		if ( empty( $file_type['ext'] ) || empty( $file_type['type'] ) ) {
			TempFile::delete( $tmp_path );

			return self::type_error(
				__( 'This file type is not accepted.', 'albert-ai-butler' ),
				$allowlist
			);
		}

		// Core sets this when the extension disagrees with the actual content.
		// Taking it is what stops a PNG arriving as "invoice.pdf" and being
		// stored under the misleading name it was sent with.
		if ( ! empty( $file_type['proper_filename'] ) ) {
			$filename = $file_type['proper_filename'];
		}

		$file_array = [
			'name'     => $filename,
			'tmp_name' => $tmp_path,
		];

		$attachment_id = media_handle_sideload( $file_array, $post_id );

		TempFile::delete( $tmp_path );

		if ( is_wp_error( $attachment_id ) ) {
			// Core returns 'upload_error' with no status, which REST maps to a
			// 500, and which collides with the upload endpoint's own
			// 'upload_error' code. Re-code it so a caller can tell "your file
			// was refused" from "the site broke", and get a 4xx for the former.
			return new WP_Error(
				'upload_failed',
				$attachment_id->get_error_message(),
				[ 'status' => 400 ]
			);
		}

		return AttachmentResponse::format( $attachment_id );
	}

	/**
	 * The one rejection for "we will not accept this file", carrying the list
	 * of what would have been accepted so a caller can retry usefully.
	 *
	 * @param string                $message   Why it was refused.
	 * @param array<string, string> $allowlist The allowlist it was checked against.
	 *
	 * @return WP_Error
	 * @since 1.4.0
	 */
	private static function type_error( string $message, array $allowlist ): WP_Error {
		return new WP_Error(
			'type_not_allowed',
			$message,
			[
				'status'         => 415,
				'accepted_types' => MimeAllowlist::mime_list( $allowlist ),
			]
		);
	}
}
