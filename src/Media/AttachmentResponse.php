<?php
/**
 * Attachment Response Formatter
 *
 * @package Albert
 * @subpackage Media
 * @since      1.4.0
 */

namespace Albert\Media;

defined( 'ABSPATH' ) || exit;

/**
 * The response shape both media-upload paths hand back to the calling assistant.
 *
 * @since 1.4.0
 */
class AttachmentResponse {

	/**
	 * Format attachment data for response.
	 *
	 * @param int $attachment_id The attachment ID.
	 *
	 * @return array<string, mixed>
	 * @since 1.4.0
	 */
	public static function format( int $attachment_id ): array {
		$metadata  = wp_get_attachment_metadata( $attachment_id );
		$file_size = $metadata['filesize'] ?? 0;

		if ( empty( $file_size ) ) {
			// wp_filesize(), not filesize(): returns 0 rather than false-with-a-warning
			// for an unreadable path, and offload plugins filter it so a remote file
			// still reports a size. get_attached_file() returns a path either way.
			$attached_file = get_attached_file( $attachment_id );
			$file_size     = $attached_file ? wp_filesize( $attached_file ) : 0;
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
