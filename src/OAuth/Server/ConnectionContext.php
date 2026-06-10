<?php
/**
 * OAuth Connection Context
 *
 * @package Albert
 * @subpackage OAuth\Server
 * @since      1.4.0
 */

namespace Albert\OAuth\Server;

defined( 'ABSPATH' ) || exit;

/**
 * ConnectionContext class
 *
 * Request-scoped holder for the OAuth client ("connection") that authenticated
 * the current request. Populated by {@see TokenValidator::validate_request()}
 * when a Bearer token is validated, and read later — e.g. at ability-execution
 * logging time — to record *which* AI assistant/integration made the call.
 *
 * A single PHP request serves a single OAuth connection, so request-scoped
 * static state is safe (the same approach the activity-log execution timer
 * uses). Requests that never authenticate via OAuth — WordPress admin, direct
 * REST, WP-CLI — leave this empty and the accessors return null.
 *
 * This lives in Core (Free) so add-ons can read the connection identity through
 * the public accessors without reaching into the OAuth internals.
 *
 * @since 1.4.0
 */
class ConnectionContext {

	/**
	 * The current connection's OAuth client identifier, or null.
	 *
	 * @since 1.4.0
	 * @var string|null
	 */
	private static ?string $client_id = null;

	/**
	 * The resolved client name snapshot, or null.
	 *
	 * @since 1.4.0
	 * @var string|null
	 */
	private static ?string $client_name = null;

	/**
	 * Whether the client name has been resolved for the current client id.
	 *
	 * @since 1.4.0
	 * @var bool
	 */
	private static bool $name_resolved = false;

	/**
	 * Record the OAuth client for the current request.
	 *
	 * @param string|null $client_id The OAuth client identifier, or null/empty to clear.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public static function set( ?string $client_id ): void {
		$client_id = ( is_string( $client_id ) && $client_id !== '' ) ? $client_id : null;

		if ( $client_id !== self::$client_id ) {
			self::$client_name   = null;
			self::$name_resolved = false;
		}

		self::$client_id = $client_id;
	}

	/**
	 * Get the current connection's OAuth client identifier.
	 *
	 * @return string|null The client id, or null when the request is not OAuth-authenticated.
	 * @since 1.4.0
	 */
	public static function client_id(): ?string {
		return self::$client_id;
	}

	/**
	 * Get the current connection's human-readable client name.
	 *
	 * Resolved lazily (and snapshotted) from the OAuth clients table the first
	 * time it is requested, so requests that never log pay no query cost.
	 *
	 * @return string|null The client name, or null when unknown / not OAuth-authenticated.
	 * @since 1.4.0
	 */
	public static function client_name(): ?string {
		if ( self::$client_id === null ) {
			return null;
		}

		if ( ! self::$name_resolved ) {
			self::$client_name   = self::resolve_name( self::$client_id );
			self::$name_resolved = true;
		}

		return self::$client_name;
	}

	/**
	 * Reset the holder. Primarily for test isolation.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public static function reset(): void {
		self::$client_id     = null;
		self::$client_name   = null;
		self::$name_resolved = false;
	}

	/**
	 * Look up a client's display name from the OAuth clients table.
	 *
	 * @param string $client_id The OAuth client identifier.
	 *
	 * @return string|null The client name, or null when not found.
	 * @since 1.4.0
	 */
	private static function resolve_name( string $client_id ): ?string {
		global $wpdb;

		$table = $wpdb->prefix . 'albert_oauth_clients';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required to snapshot the client name at log time.
		$name = $wpdb->get_var(
			$wpdb->prepare( 'SELECT name FROM %i WHERE client_id = %s LIMIT 1', $table, $client_id )
		);

		return ( is_string( $name ) && $name !== '' ) ? $name : null;
	}
}
