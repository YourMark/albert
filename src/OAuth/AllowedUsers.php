<?php
/**
 * Allowed Users
 *
 * @package Albert
 * @subpackage OAuth
 * @since      1.4.0
 */

namespace Albert\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * AllowedUsers class
 *
 * The single reader/writer of `albert_allowed_users`: the list of people who
 * may complete an OAuth authorisation, checked at
 * {@see \Albert\OAuth\Endpoints\AuthorizationPage::handle_authorization()}.
 *
 * Stored keyed by user id, each entry carrying `added_at` (when they were
 * granted the standing invitation) and `authorised_at` (when they first
 * actually used it, null until then):
 *
 *     [ 42 => [ 'added_at' => 1755765600, 'authorised_at' => null ], ... ]
 *
 * Before 1.4.0 this was a flat array of ints with no timestamp.
 * {@see \Albert\Database\Installer} migrates existing sites once, on
 * upgrade, before any of these methods can run in the same request (its
 * `maybe_upgrade()` fires first in {@see \Albert\Core\Plugin::init()}).
 * `added_at`/`authorised_at` are still read defensively here in case an
 * entry somehow predates that migration.
 *
 * `authorised_at` is what {@see \Albert\Cron\AllowedUserExpiry} checks: an
 * invitation that was exercised at least once is never swept for going
 * unused, no matter what happens to that connection later.
 *
 * @since 1.4.0
 */
class AllowedUsers {

	/**
	 * The option name.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const OPTION = 'albert_allowed_users';

	/**
	 * Every allowed user, normalised.
	 *
	 * @return array<int, array{added_at: int|null, authorised_at: int|null}>
	 * @since 1.4.0
	 */
	public static function all(): array {
		$raw = get_option( self::OPTION, [] );

		if ( ! is_array( $raw ) ) {
			return [];
		}

		$normalised = [];

		foreach ( $raw as $key => $value ) {
			$id = is_array( $value ) ? (int) $key : (int) $value;

			if ( $id <= 0 || isset( $normalised[ $id ] ) ) {
				continue;
			}

			$entry = is_array( $value ) ? $value : [];

			$normalised[ $id ] = [
				'added_at'      => self::to_timestamp( $entry['added_at'] ?? null ),
				'authorised_at' => self::to_timestamp( $entry['authorised_at'] ?? null ),
			];
		}

		return $normalised;
	}

	/**
	 * Every allowed user id.
	 *
	 * @return array<int, int>
	 * @since 1.4.0
	 */
	public static function ids(): array {
		return array_keys( self::all() );
	}

	/**
	 * Whether anybody is allowed at all.
	 *
	 * @return bool
	 * @since 1.4.0
	 */
	public static function has_any(): bool {
		return ! empty( self::all() );
	}

	/**
	 * Whether a user may complete an OAuth authorisation.
	 *
	 * @param int $user_id The user id.
	 *
	 * @return bool
	 * @since 1.4.0
	 */
	public static function is_allowed( int $user_id ): bool {
		return array_key_exists( $user_id, self::all() );
	}

	/**
	 * When a user was added to the list, or null if they are not on it or
	 * predate the 1.4.0 migration.
	 *
	 * @param int $user_id The user id.
	 *
	 * @return int|null Unix timestamp.
	 * @since 1.4.0
	 */
	public static function added_at( int $user_id ): ?int {
		return self::all()[ $user_id ]['added_at'] ?? null;
	}

	/**
	 * When a user first completed an authorisation, or null if they never have.
	 *
	 * @param int $user_id The user id.
	 *
	 * @return int|null Unix timestamp.
	 * @since 1.4.0
	 */
	public static function authorised_at( int $user_id ): ?int {
		return self::all()[ $user_id ]['authorised_at'] ?? null;
	}

	/**
	 * Whether a user has ever completed an authorisation.
	 *
	 * @param int $user_id The user id.
	 *
	 * @return bool
	 * @since 1.4.0
	 */
	public static function has_authorised( int $user_id ): bool {
		return self::authorised_at( $user_id ) !== null;
	}

	/**
	 * Add a user to the allowed list, if they are not already on it.
	 *
	 * @param int $user_id The user id.
	 *
	 * @return bool True if the user was newly added, false if already allowed.
	 * @since 1.4.0
	 */
	public static function add( int $user_id ): bool {
		$all = self::all();

		if ( isset( $all[ $user_id ] ) ) {
			return false;
		}

		$all[ $user_id ] = [
			'added_at'      => time(),
			'authorised_at' => null,
		];

		self::save( $all );

		return true;
	}

	/**
	 * Remove a user from the allowed list.
	 *
	 * Does not touch their tokens; callers that mean "and disconnect them"
	 * (e.g. {@see \Albert\Admin\Connections::handle_remove_allowed_user()})
	 * revoke separately via {@see \Albert\Admin\Settings::revoke_user_tokens()}.
	 *
	 * @param int $user_id The user id.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public static function remove( int $user_id ): void {
		$all = self::all();

		unset( $all[ $user_id ] );

		self::save( $all );
	}

	/**
	 * Record that a user has completed an authorisation, the first time only.
	 *
	 * A no-op for a user not on the list (removed between granting and
	 * completing the OAuth flow) or who has already authorised before: the
	 * invitation only needs to be exercised once to be exempt from expiry
	 * forever, so the first timestamp is the one that matters.
	 *
	 * @param int $user_id The user id.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public static function mark_authorised( int $user_id ): void {
		$all = self::all();

		if ( ! isset( $all[ $user_id ] ) || $all[ $user_id ]['authorised_at'] !== null ) {
			return;
		}

		$all[ $user_id ]['authorised_at'] = time();

		self::save( $all );
	}

	/**
	 * Persist the normalised structure as MySQL-friendly datetime strings.
	 *
	 * @param array<int, array{added_at: int|null, authorised_at: int|null}> $all Normalised entries.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private static function save( array $all ): void {
		$stored = [];

		foreach ( $all as $id => $entry ) {
			$stored[ $id ] = [
				'added_at'      => $entry['added_at'] !== null ? gmdate( 'Y-m-d H:i:s', $entry['added_at'] ) : null,
				'authorised_at' => $entry['authorised_at'] !== null ? gmdate( 'Y-m-d H:i:s', $entry['authorised_at'] ) : null,
			];
		}

		update_option( self::OPTION, $stored );
	}

	/**
	 * A stored datetime string (or already-int timestamp) to a Unix timestamp.
	 *
	 * @param mixed $value A `Y-m-d H:i:s` string, an int, or null.
	 *
	 * @return int|null
	 * @since 1.4.0
	 */
	private static function to_timestamp( $value ): ?int {
		if ( $value === null || $value === '' ) {
			return null;
		}

		if ( is_int( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return (int) $value;
		}

		$timestamp = strtotime( (string) $value . ' UTC' );

		return $timestamp !== false ? $timestamp : null;
	}
}
