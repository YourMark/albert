<?php
/**
 * Ability invocation relay.
 *
 * @package Albert
 * @subpackage Core
 * @since      1.4.0
 */

namespace Albert\Core;

defined( 'ABSPATH' ) || exit;

use Albert\Support\WpCompat;
use WP_Ability;

/**
 * Relays WordPress 7.1's `wp_ability_invoked` action onto Albert's own hook.
 *
 * WordPress 7.1 fires `wp_ability_invoked` at the very top of
 * {@see \WP_Ability::execute()} — before input normalisation, before validation,
 * before the permission check — for *every* call, whatever its outcome. That is
 * a strictly wider net than Albert's existing `albert/abilities/after_execute`,
 * which only fires for abilities that reach
 * {@see \Albert\Abstracts\BaseAbility::guarded_execute()}: it also sees denied
 * calls, calls rejected by schema validation, calls short-circuited by
 * `wp_pre_execute_ability`, and calls to abilities Albert does not own.
 *
 * This class is deliberately a relay and nothing more. It records nothing,
 * decides nothing and blocks nothing — it re-emits the core action under
 * Albert's `albert/{module}/{action}` convention so subscribers bind to a stable
 * Albert hook rather than to a core action whose availability depends on the
 * WordPress version. The consumer is Premium Service's activity log in 1.5.0;
 * there are no consumers in Free today, and that is intentional. Doc 20 §5.
 *
 * Below WordPress 7.1 the core action never fires, so the listener is inert
 * rather than broken. Subscribers that must know whether the seam is live can
 * ask {@see WpCompat::supports_execution_lifecycle()}.
 *
 * @since 1.4.0
 */
class InvocationRelay {

	/**
	 * Bind the relay to the core action.
	 *
	 * Registered unconditionally: `add_action()` on an action that never fires
	 * costs one array entry, and gating registration on a version check would
	 * mean a host that back-ports the lifecycle silently loses the seam.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function register_hooks(): void {
		add_action( 'wp_ability_invoked', [ $this, 'relay' ], 10, 3 );
	}

	/**
	 * Re-emit a core invocation as `albert/abilities/invoked`.
	 *
	 * Passes core's arguments through untouched, in core's order, so the Albert
	 * hook stays a faithful mirror: renaming or reshaping them here would mean
	 * subscribers have to learn two vocabularies for one event.
	 *
	 * @param string     $ability_name The ability being invoked.
	 * @param mixed      $input        Raw input, before normalisation. May be null.
	 * @param WP_Ability $ability      The ability instance.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function relay( string $ability_name, $input, WP_Ability $ability ): void {
		/**
		 * Fires when any registered ability is invoked, before any processing.
		 *
		 * Mirrors WordPress 7.1's `wp_ability_invoked` and fires for every call
		 * regardless of outcome — including calls that go on to fail validation,
		 * be denied by a permission check, or be short-circuited. Covers every
		 * registered ability, not only Albert's own.
		 *
		 * Observers must not throw and must not block: this runs on the hot path
		 * of every ability call. Use `albert/abilities/after_execute` when the
		 * result matters.
		 *
		 * Never fires below WordPress 7.1 — see
		 * {@see WpCompat::supports_execution_lifecycle()}.
		 *
		 * @since 1.4.0
		 *
		 * @param string     $ability_name The ability being invoked.
		 * @param mixed      $input        Raw input, before normalisation.
		 * @param WP_Ability $ability      The ability instance.
		 */
		do_action( 'albert/abilities/invoked', $ability_name, $input, $ability );
	}
}
