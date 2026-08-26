<?php
/**
 * Shared MIME Allowlist
 *
 * @package Albert
 * @subpackage Media
 * @since      1.4.0
 */

namespace Albert\Media;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the MIME allowlist an upload is checked against: always the
 * issuing user's own `get_allowed_mime_types()`, never a blanket default.
 *
 * @since 1.4.0
 */
class MimeAllowlist {

	/**
	 * Resolve the effective allowlist for a user, optionally narrowed.
	 *
	 * @param int                $user_id         The user whose capabilities decide the base allowlist.
	 * @param array<int, string> $requested_mimes Optional MIME types to narrow to. Ignored when empty.
	 *
	 * @return array<string, string> Extension-regex => MIME type, WordPress's native shape.
	 * @since 1.4.0
	 */
	public static function for_user( int $user_id, array $requested_mimes = [] ): array {
		$allowed = get_allowed_mime_types( $user_id );

		if ( empty( $requested_mimes ) ) {
			return $allowed;
		}

		return self::intersect( $allowed, $requested_mimes );
	}

	/**
	 * Narrow an allowlist to only the entries matching a set of MIME types.
	 *
	 * @param array<string, string> $allowlist       Extension-regex => MIME type.
	 * @param array<int, string>    $requested_mimes MIME types to keep.
	 *
	 * @return array<string, string> The narrowed allowlist. Empty when nothing matched.
	 * @since 1.4.0
	 */
	public static function intersect( array $allowlist, array $requested_mimes ): array {
		$requested = array_map( 'strtolower', array_map( 'strval', $requested_mimes ) );

		$narrowed = [];
		foreach ( $allowlist as $ext => $mime ) {
			if ( in_array( strtolower( (string) $mime ), $requested, true ) ) {
				$narrowed[ $ext ] = $mime;
			}
		}

		return $narrowed;
	}

	/**
	 * Flatten an allowlist to a deduplicated, human/model-readable MIME type list.
	 *
	 * @param array<string, string> $allowlist Extension-regex => MIME type.
	 *
	 * @return array<int, string> Unique MIME types.
	 * @since 1.4.0
	 */
	public static function mime_list( array $allowlist ): array {
		return array_values( array_unique( array_values( $allowlist ) ) );
	}
}
