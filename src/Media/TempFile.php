<?php
/**
 * Temporary Upload File
 *
 * @package Albert
 * @subpackage Media
 * @since      1.4.0
 */

namespace Albert\Media;

defined( 'ABSPATH' ) || exit;

/**
 * The one place a half-finished upload's temp file is cleaned up.
 *
 * Both upload paths abandon a temp file on several branches each, and every
 * one of them wants the same "delete it if it is still there" behaviour:
 * `media_handle_sideload()` moves the file on success, so by the time the
 * caller cleans up there may be nothing left to delete.
 *
 * @since 1.4.0
 */
class TempFile {

	/**
	 * Delete a temp file if it still exists. Never errors on a missing file.
	 *
	 * @param string $path Path to the temp file. An empty string is a no-op.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public static function delete( string $path ): void {
		if ( $path !== '' && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}
}
