<?php
/**
 * Execution Log Marker
 *
 * @package Albert
 * @subpackage Logging
 * @since      1.4.0
 */

namespace Albert\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * ExecutionLogMarker class
 *
 * Request-scoped record of which abilities have already had a log row written
 * during the current request. Albert logs ability outcomes from more than one
 * place — the `albert/abilities/after_execute` hook (for executed abilities)
 * and the MCP tool-result observer (for failures that are rejected *before* the
 * ability runs, e.g. input-schema validation). This marker lets each writer
 * skip an ability already recorded so a single call never produces duplicate
 * rows.
 *
 * A single PHP request serves a single MCP tool call, but the marker is keyed
 * by ability name to stay correct under any batching.
 *
 * @since 1.4.0
 */
class ExecutionLogMarker {

	/**
	 * Ability names already logged this request.
	 *
	 * @since 1.4.0
	 * @var array<string, true>
	 */
	private static array $logged = [];

	/**
	 * Mark an ability as logged for the current request.
	 *
	 * @param string $ability_name The ability identifier.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public static function mark( string $ability_name ): void {
		if ( $ability_name !== '' ) {
			self::$logged[ $ability_name ] = true;
		}
	}

	/**
	 * Whether an ability has already been logged this request.
	 *
	 * @param string $ability_name The ability identifier.
	 *
	 * @return bool True when a row was already written for this ability.
	 * @since 1.4.0
	 */
	public static function has( string $ability_name ): bool {
		return isset( self::$logged[ $ability_name ] );
	}

	/**
	 * Reset the marker. Primarily for test isolation.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public static function reset(): void {
		self::$logged = [];
	}
}
