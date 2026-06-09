<?php
/**
 * Ability Execution Logger
 *
 * @package Albert
 * @subpackage Logging
 * @since      1.1.0
 */

namespace Albert\Logging;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use WP_Error;

/**
 * Logger class
 *
 * Hooks into Albert's own after_execute action to log ability executions,
 * including failures. Only writes to the DB when the `albert/logging/enabled`
 * filter returns true (default). Premium can disable Free's writes by returning
 * false from that filter. The `albert/logging/ability_failed` notification hook
 * fires regardless of the storage gate so Premium always receives failure signals.
 *
 * @since 1.1.0
 */
class Logger implements Hookable {

	/**
	 * The repository instance.
	 *
	 * @since 1.1.0
	 * @var Repository
	 */
	protected Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param Repository $repository The logging repository.
	 *
	 * @since 1.1.0
	 */
	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * Hooks into Albert's own `albert/abilities/after_execute` action instead of
	 * the WP core `wp_after_execute_ability` (success-only) so failures are
	 * also captured.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function register_hooks(): void {
		add_action( 'albert/abilities/after_execute', [ $this, 'log_execution' ], 10, 4 );
	}

	/**
	 * Log an ability execution (success or failure).
	 *
	 * Called after every ability execution via Albert's own hook — covers
	 * both WP_Error results and successful ones.  Wrapped in try/catch to
	 * ensure logging never breaks ability execution.
	 *
	 * Must be public so WordPress can invoke it via call_user_func_array
	 * from the albert/abilities/after_execute action — a protected method
	 * throws a TypeError when dispatched externally.
	 *
	 * @param string                         $ability_name The ability identifier.
	 * @param mixed                          $input        The input arguments.
	 * @param array<string, mixed>|\WP_Error $result       The execution result.
	 * @param int                            $user_id      The user ID who executed the ability.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function log_execution( string $ability_name, mixed $input, array|WP_Error $result, int $user_id ): void {
		try {
			$is_error   = is_wp_error( $result );
			$status     = $is_error ? 'error' : 'success';
			$error_code = $is_error ? (string) $result->get_error_code() : null;

			// Fire the Premium-facing failure notification BEFORE the storage gate
			// so Premium always receives the signal even when Free's writes are off.
			if ( $is_error ) {
				/**
				 * Fires when an ability execution results in a WP_Error.
				 *
				 * Decoupled from Free's storage gate so Premium (or any add-on)
				 * can react to failures even when `albert/logging/enabled` returns
				 * false. Core never depends on who hooks here.
				 *
				 * @since 1.2.0
				 *
				 * @param string   $ability_name The ability identifier.
				 * @param \WP_Error $result       The error returned by the ability.
				 * @param int      $user_id      The ID of the user who triggered the execution.
				 * @param mixed    $input        The input arguments passed to the ability.
				 */
				do_action( 'albert/logging/ability_failed', $ability_name, $result, $user_id, $input );
			}

			/**
			 * Filters whether Free's ability logging is enabled.
			 *
			 * Premium returns false from this filter to suppress Free's writes
			 * and use its own extended logging instead.
			 *
			 * @since 1.1.0
			 *
			 * @param bool $enabled Whether logging is enabled. Default true.
			 */
			$enabled = apply_filters( 'albert/logging/enabled', true );

			if ( ! $enabled ) {
				return;
			}

			$this->repository->insert( $ability_name, $user_id, $status, $error_code );
		} catch ( \Throwable $e ) {
			// Never rethrow — logging must not break ability execution.
			// Silently fail. In debug mode, WordPress will log the error.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging.
				error_log( 'Albert Logger Error: ' . $e->getMessage() );
			}
		}
	}
}
