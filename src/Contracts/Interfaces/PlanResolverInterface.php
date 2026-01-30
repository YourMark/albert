<?php
/**
 * Plan Resolver Interface
 *
 * Allows premium addon plugins to override free-tier limits
 * by registering a resolver with the Limits class.
 *
 * @package Albert
 * @subpackage Contracts\Interfaces
 * @since      1.0.0
 */

namespace Albert\Contracts\Interfaces;

/**
 * The PlanResolverInterface
 *
 * Implementations provide plan identification and limit overrides.
 * Premium addon plugins implement this interface and register their
 * resolver via Limits::register_plan_resolver().
 *
 * @since 1.0.0
 */
interface PlanResolverInterface {

	/**
	 * Get the plan identifier.
	 *
	 * @since 1.0.0
	 * @return string Plan identifier, e.g. 'free', 'pro', 'agency'.
	 */
	public function get_plan(): string;

	/**
	 * Get limit overrides for the plan.
	 *
	 * Return an associative array of limit keys and their values,
	 * or null to use the core defaults for the current plan.
	 *
	 * Supported keys:
	 * - 'max_users' (int)
	 * - 'max_connections_per_user' (int)
	 *
	 * @since 1.0.0
	 * @return array<string, int>|null Limit overrides or null for defaults.
	 */
	public function get_limits(): ?array;
}
