<?php
/**
 * Logging Repository
 *
 * @package Albert
 * @subpackage Logging
 * @since      1.1.0
 */

namespace Albert\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * Repository class
 *
 * Handles database operations for the ability log table.
 * Free tier retains only the last 2 records per ability_name.
 *
 * @since 1.1.0
 */
class Repository {

	/**
	 * Number of log entries to keep per ability.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	const RETENTION_COUNT = 2;

	/**
	 * Insert a log entry and prune old entries for the ability/status partition.
	 *
	 * Accepts an optional $context array whose keys map to the richer columns
	 * added in 1.2.0. Unknown keys are silently ignored. Free always passes an
	 * empty array; Premium populates the full set.
	 *
	 * Context keys → columns:
	 *   duration_ms  (int|null)    → duration_ms
	 *   ip_address   (string|null) → ip_address
	 *   user_agent   (string|null) → user_agent
	 *   referrer     (string|null) → referrer
	 *   request_id    (string|null) → request_id
	 *   input         (string|null) → input
	 *   output        (string|null) → output
	 *   client_id     (string|null) → client_id
	 *   client_name   (string|null) → client_name
	 *   error_message (string|null) → error_message
	 *
	 * The prune step is gated behind `albert/logging/enabled` so that Premium's
	 * full-history rows are never trimmed by Free's 2-row cap. When Premium is
	 * active it returns false from that filter and handles its own retention.
	 *
	 * @param string               $ability_name The ability identifier.
	 * @param int                  $user_id      The user ID who executed the ability.
	 * @param string               $status       Execution status: 'success' or 'error'. Default 'success'.
	 * @param string|null          $error_code   WP_Error code when status is 'error'. Default null.
	 * @param array<string, mixed> $context      Optional extended context. Default [].
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function insert( string $ability_name, int $user_id, string $status = 'success', ?string $error_code = null, array $context = [] ): void {
		global $wpdb;

		$table_name = Installer::get_table_name();

		$data    = [
			'ability_name' => $ability_name,
			'user_id'      => $user_id,
			'status'       => $status,
			'error_code'   => $error_code,
		];
		$formats = [ '%s', '%d', '%s', '%s' ];

		// Map allowed context keys to columns. Unknown keys are ignored.
		$allowed = [ 'duration_ms', 'ip_address', 'user_agent', 'referrer', 'request_id', 'input', 'output', 'client_id', 'client_name', 'error_message' ];
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $context ) ) {
				$data[ $key ] = $context[ $key ];
				$formats[]    = ( $key === 'duration_ms' && $context[ $key ] !== null ) ? '%d' : '%s';
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct insert required for logging.
		$wpdb->insert( $table_name, $data, $formats );

		// Prune only when Free is the writer (i.e. albert/logging/enabled is true).
		// When Premium is active it returns false from this filter and manages
		// its own time-based retention — Free must not apply its 2-row cap.
		if ( apply_filters( 'albert/logging/enabled', true ) ) {
			$this->prune_for_ability( $ability_name, self::RETENTION_COUNT, $status );
		}
	}

	/**
	 * Get the latest log entry for a specific ability.
	 *
	 * Returns the most recent row regardless of status.
	 *
	 * @param string $ability_name The ability identifier.
	 *
	 * @return object{id: string, ability_name: string, user_id: string, created_at: string, status: string, error_code: string|null}|null The log entry or null if none found.
	 * @since 1.1.0
	 */
	public function latest_for_ability( string $ability_name ): ?object {
		global $wpdb;

		$table_name = Installer::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for fetching latest log entry.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, ability_name, user_id, created_at, status, error_code FROM %i WHERE ability_name = %s ORDER BY created_at DESC, id DESC LIMIT 1',
				$table_name,
				$ability_name
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Get the latest log entry overall.
	 *
	 * @return object{id: string, ability_name: string, user_id: string, created_at: string, status: string, error_code: string|null}|null The log entry or null if none found.
	 * @since 1.1.0
	 */
	public function latest_overall(): ?object {
		global $wpdb;

		$table_name = Installer::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for fetching latest log entry.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, ability_name, user_id, created_at, status, error_code FROM %i ORDER BY created_at DESC, id DESC LIMIT 1',
				$table_name
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Get the most recent log entries across all abilities.
	 *
	 * @param int $limit Maximum number of rows to return.
	 *
	 * `created_ts` is the row's created_at converted to a Unix timestamp by the
	 * database, which sidesteps any mismatch between the MySQL server time zone
	 * (used by CURRENT_TIMESTAMP) and PHP's time zone.
	 *
	 * @return array<int, object{id: string, ability_name: string, user_id: string, created_at: string, created_ts: string, status: string, error_code: string|null}> List of log rows, newest first.
	 * @since 1.1.0
	 */
	public function recent( int $limit = 5 ): array {
		global $wpdb;

		$table_name = Installer::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for dashboard recent activity.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, ability_name, user_id, created_at, UNIX_TIMESTAMP( created_at ) AS created_ts, status, error_code FROM %i ORDER BY created_at DESC, id DESC LIMIT %d',
				$table_name,
				$limit
			)
		);

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Get the latest log entry for each ability in a list.
	 *
	 * Returns an associative array keyed by ability_name, each value being
	 * the most recent log row (regardless of status) for that ability.
	 * Abilities with no log entries are omitted from the result.
	 *
	 * @param array<int, string> $ability_names List of ability identifiers.
	 *
	 * @return array<string, object{id: string, ability_name: string, user_id: string, created_at: string, status: string, error_code: string|null}> Map of ability_name => log row object.
	 * @since 1.1.0
	 */
	public function latest_bulk( array $ability_names ): array {
		global $wpdb;

		if ( empty( $ability_names ) ) {
			return [];
		}

		$table_name = Installer::get_table_name();

		// Sanitize ability names for direct use in the query.
		// Each name is escaped via esc_sql() and wrapped in quotes.
		$escaped_names = array_map(
			static function ( string $name ): string {
				return "'" . esc_sql( $name ) . "'";
			},
			$ability_names
		);
		$in_clause     = implode( ',', $escaped_names );

		// Use a subquery to get the max id per ability, then join to get full rows.
		// This is more efficient than a correlated subquery for each ability.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IN clause built from esc_sql() escaped values.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.id, l.ability_name, l.user_id, l.created_at, l.status, l.error_code
				FROM %i l
				INNER JOIN (
					SELECT ability_name, MAX(id) as max_id
					FROM %i
					WHERE ability_name IN ({$in_clause})
					GROUP BY ability_name
				) latest ON l.id = latest.max_id",
				$table_name,
				$table_name
			)
		);
		// phpcs:enable

		$map = [];
		if ( $results ) {
			foreach ( $results as $row ) {
				$map[ $row->ability_name ] = $row;
			}
		}

		return $map;
	}

	/**
	 * Prune old log entries for an ability/status partition, keeping only the most recent.
	 *
	 * Uses a derived table pattern to work around MySQL's restriction on
	 * specifying the target table in a subquery within DELETE. Pruning is
	 * status-aware so a burst of failures cannot evict the last success row.
	 *
	 * @param string $ability_name The ability identifier.
	 * @param int    $keep         Number of entries to keep per status partition (default: RETENTION_COUNT).
	 * @param string $status       Status partition to prune ('success' or 'error'). Default 'success'.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function prune_for_ability( string $ability_name, int $keep = self::RETENTION_COUNT, string $status = 'success' ): void {
		global $wpdb;

		$table_name = Installer::get_table_name();

		// Delete all rows for this ability+status except the most recent $keep rows.
		// The subselect-in-derived-table pattern avoids MySQL's "can't specify
		// target table for update in FROM clause" error.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for pruning.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i
				WHERE ability_name = %s
				AND status = %s
				AND id NOT IN (
					SELECT id FROM (
						SELECT id FROM %i
						WHERE ability_name = %s
						AND status = %s
						ORDER BY created_at DESC, id DESC
						LIMIT %d
					) AS keep
				)',
				$table_name,
				$ability_name,
				$status,
				$table_name,
				$ability_name,
				$status,
				$keep
			)
		);
	}

	/**
	 * Truncate the entire log table.
	 *
	 * Use with caution. Primarily for testing or complete reset.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function truncate(): void {
		global $wpdb;

		$table_name = Installer::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for truncate.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table_name ) );
	}
}
