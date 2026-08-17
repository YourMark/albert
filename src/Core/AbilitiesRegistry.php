<?php
/**
 * Abilities Registry
 *
 * Supplier map, source lookup, and default-state logic for registered abilities.
 *
 * @package Albert
 * @subpackage Core
 * @since      1.0.0
 */

namespace Albert\Core;

use Albert\MCP\Server;
use Albert\Vendor\WP\MCP\Abilities\McpAbilityExposure;

/**
 * Abilities Registry class
 *
 * Supplier map, category grouping, and source lookup for registered abilities.
 *
 * @since 1.0.0
 */
class AbilitiesRegistry {

	/**
	 * Cached supplier map, populated on first call to get_suppliers().
	 *
	 * @since 1.1.0
	 * @var array<string, string>|null
	 */
	private static ?array $suppliers_cache = null;

	/**
	 * Get the curated supplier map.
	 *
	 * Maps an ability-id prefix (the namespace before the first `/`) to a
	 * human-readable supplier label. Addons can register their own suppliers
	 * via the `albert/abilities/suppliers` filter so that a custom prefix like
	 * `mycompany/` shows up with a branded label in the admin filter dropdown.
	 *
	 * Built-in entries cover the prefixes Albert knows about today. Anything
	 * not listed falls through to a prettified version of the prefix in
	 * {@see self::get_ability_source()}.
	 *
	 * @return array<string, string> Prefix => supplier label.
	 * @since 1.1.0
	 */
	public static function get_suppliers(): array {
		if ( self::$suppliers_cache !== null ) {
			return self::$suppliers_cache;
		}

		$suppliers = [
			'core'   => __( 'WordPress core', 'albert-ai-butler' ),
			'albert' => __( 'Albert', 'albert-ai-butler' ),
			'woo'    => __( 'WooCommerce', 'albert-ai-butler' ),
			'acf'    => __( 'ACF', 'albert-ai-butler' ),
		];

		/**
		 * Filters the curated supplier map.
		 *
		 * Allows addons and site code to register their own ability-id prefix
		 * under a branded supplier label. The array is keyed by prefix (the
		 * namespace before the first `/` in an ability id) and maps to the
		 * human-readable label shown in the admin filter dropdown and the
		 * expanded row details.
		 *
		 * @since 1.1.0
		 *
		 * @param array<string, string> $suppliers Prefix => supplier label.
		 */
		self::$suppliers_cache = apply_filters( 'albert/abilities/suppliers', $suppliers );

		return self::$suppliers_cache;
	}

	/**
	 * Get the supplier information for an ability.
	 *
	 * Looks the ability's prefix up in the curated supplier map
	 * ({@see self::get_suppliers()}). Unknown prefixes fall back to a
	 * prettified version of the prefix itself so every ability always
	 * has a supplier label in the UI.
	 *
	 * @param string $ability_name Ability name/slug, e.g. `albert/create-post`.
	 *
	 * @return array{slug: string, label: string} Supplier slug + human label.
	 * @since 1.0.0
	 */
	public static function get_ability_source( string $ability_name ): array {
		$parts  = explode( '/', $ability_name, 2 );
		$prefix = $parts[0] ?? '';

		if ( $prefix === '' ) {
			return [
				'slug'  => 'unknown',
				'label' => __( 'Unknown', 'albert-ai-butler' ),
			];
		}

		$suppliers = self::get_suppliers();

		if ( isset( $suppliers[ $prefix ] ) ) {
			return [
				'slug'  => $prefix,
				'label' => $suppliers[ $prefix ],
			];
		}

		// Unknown prefix — prettify so it at least reads nicely.
		return [
			'slug'  => $prefix,
			'label' => ucfirst( str_replace( [ '-', '_' ], ' ', $prefix ) ),
		];
	}

	/**
	 * Resolve an ability slug to its human-readable label.
	 *
	 * Prefers the registered ability's own label via `wp_get_ability()`.
	 * Falls back to a prettified version of the slug when the ability is no
	 * longer registered (e.g. an addon was deactivated after a run was logged)
	 * or when the Abilities API is unavailable — mirroring the fallback style
	 * of {@see AbilitiesPage::resolve_category_label()}.
	 *
	 * `wp_get_ability()` is only called once the abilities registry has been
	 * populated (`wp_abilities_api_init` has fired). On admin pages that render
	 * before that — notably the Albert dashboard — calling it would emit PHP
	 * notices from the registry, so we skip straight to the prettified-slug
	 * fallback instead. Callers that hold their own ability instances (e.g.
	 * Dashboard via AbilitiesManager) should resolve the label there first and
	 * only fall through to this resolver for slugs they do not hold.
	 *
	 * Living in core means both `Dashboard` and `AbilitiesPage` (and Premium,
	 * later) share one resolver for their Event/label columns.
	 *
	 * @param string $slug Ability slug, e.g. `albert/woo-find-products`.
	 *
	 * @return string Human-readable label.
	 * @since 1.2.0
	 */
	public static function label_for( string $slug ): string {
		if ( function_exists( 'wp_get_ability' ) && did_action( 'wp_abilities_api_init' ) ) {
			$ability = wp_get_ability( $slug );
			if ( $ability !== null ) {
				$label = (string) $ability->get_label();
				if ( $label !== '' ) {
					return $label;
				}
			}
		}

		// Prettify the slug: drop separators and title-case what remains.
		return ucwords( str_replace( [ '/', '-', '_' ], ' ', $slug ) );
	}

	/**
	 * Get every registered ability straight from the registry, unfiltered.
	 *
	 * WordPress 7.1 turned `wp_get_abilities()` into a filtering pipeline, and
	 * its two ecosystem-scoped filters — `wp_get_abilities_item_include` and
	 * `wp_get_abilities_result` — fire even on a bare, argument-less call. That
	 * is correct for anything *presenting* abilities to a consumer, but wrong for
	 * Albert's own bookkeeping: a third-party filter narrowing the list would
	 * make the admin screen hide abilities the site actually has, make
	 * `enforce_disabled()` skip abilities it must unregister, and make
	 * `reconcile_new_abilities()` treat filtered-out abilities as never-seen and
	 * auto-disable them the moment the filter stops applying.
	 *
	 * So every call site that needs to know what is *really* registered goes
	 * through here, which reads {@see \WP_Abilities_Registry::get_all_registered()}
	 * directly — the access path core itself documents for raw registry data.
	 * Call sites that legitimately want the filtered view keep calling
	 * `wp_get_abilities()`.
	 *
	 * The registry and this method both date from 6.9, so this needs no version
	 * branch; the guards below only cover a site without the Abilities API at all,
	 * and the documented case of `get_instance()` returning null before the
	 * registry is initialised.
	 *
	 * @return array<string, \WP_Ability> Registered abilities keyed by ability name.
	 * @since 1.4.0
	 */
	public static function get_all_raw(): array {
		if ( ! class_exists( '\WP_Abilities_Registry' ) ) {
			return [];
		}

		$registry = \WP_Abilities_Registry::get_instance();

		if ( $registry === null ) {
			return [];
		}

		return $registry->get_all_registered();
	}

	/**
	 * Get the default set of disabled abilities.
	 *
	 * On fresh install, non-readonly abilities (write / delete) are disabled
	 * by default. Derives the list from each registered ability's annotations
	 * so it stays in sync automatically — no hardcoded slug lists.
	 *
	 * Reads the raw registry: the default state must describe every ability the
	 * site really has, not a filtered view of it.
	 *
	 * @return array<string> Ability IDs that are disabled by default.
	 * @since 1.0.0
	 */
	public static function get_default_disabled_abilities(): array {
		$disabled = [];

		foreach ( self::get_all_raw() as $ability ) {
			$meta        = (array) $ability->get_meta();
			$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : [];

			$id = $ability->get_name();

			// No annotations — fall back to the slug heuristic.
			if ( empty( $annotations ) ) {
				$chips = AnnotationPresenter::heuristic_chips( $id );
				if ( ! empty( $chips ) && $chips[0]['key'] !== 'read' ) {
					$disabled[] = $id;
				}
				continue;
			}

			if ( empty( $annotations['readonly'] ) ) {
				$disabled[] = $id;
			}
		}

		return $disabled;
	}

	/**
	 * Get the protected ability IDs that must never be disabled.
	 *
	 * The MCP adapter's own tools (discover / get-info / execute) drive protocol
	 * discovery and execution — unregistering any of them breaks MCP entirely —
	 * so they are excluded from the effective disabled list regardless of the
	 * saved option, the fresh-install default, or add-on `disabled_list` filters.
	 * This list is intentionally fixed: these abilities are never optional.
	 *
	 * @return array<int, string> Ability IDs that must stay registered.
	 * @since 1.3.0
	 */
	public static function get_protected_abilities(): array {
		return Server::CORE_TOOL_ABILITIES;
	}

	/**
	 * Whether an ID refers to one of the MCP transport meta-tools.
	 *
	 * The transport meta-tools (discover / get-info / execute) are the same
	 * abilities as {@see self::get_protected_abilities()}, but they surface under
	 * two spellings: the raw ability ID with a slash (`mcp-adapter/execute-ability`)
	 * and the MCP-sanitised tool name where the slash becomes a hyphen
	 * (`mcp-adapter-execute-ability`, produced by {@see McpNameSanitizer}). This
	 * matches either spelling by normalising both sides — replacing `/` with `-`
	 * before comparing — against an EXACT allowlist. It is deliberately an exact
	 * allowlist and not a broad `mcp-adapter` prefix, so a future ability cannot be
	 * named to slip through the always-on gate.
	 *
	 * The single home for this check means enforce_disabled, reconcile, the
	 * self-heal, and the MCP discovery gate all agree on what "transport" means.
	 *
	 * @param string $id Ability ID or MCP tool name.
	 *
	 * @return bool True when the ID is a transport meta-tool in either spelling.
	 * @since 1.3.0
	 */
	public static function is_transport_ability( string $id ): bool {
		$normalized = str_replace( '/', '-', $id );

		foreach ( self::get_protected_abilities() as $protected ) {
			if ( str_replace( '/', '-', $protected ) === $normalized ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an ability is exposed to MCP clients through the default server.
	 *
	 * Albert-side accessor that defers to the bundled MCP adapter's single source
	 * of truth, {@see McpAbilityExposure::is_meta_public()}, so exposure is
	 * resolved by exactly the same logic the adapter applies at runtime: an
	 * explicit `meta.mcp.public` wins (including an explicit `false`, which opts
	 * an ability out), otherwise the forward-looking `meta.public` flag applies,
	 * and a malformed `meta.mcp` fails closed. Re-implementing this here would
	 * risk drifting from the adapter, so it is not re-implemented.
	 *
	 * @param array<string, mixed> $meta The ability meta array.
	 *
	 * @return bool True when the ability is discoverable over MCP.
	 * @since 1.4.0
	 */
	public static function is_mcp_public( array $meta ): bool {
		return McpAbilityExposure::is_meta_public( $meta );
	}

	/**
	 * Resolve the WordPress capability an ability is likely to require.
	 *
	 * Abilities expose only a `permission_callback` (often delegating to a core
	 * REST route), not a declared capability string, so this is a best-effort
	 * value for display. An ability can declare an exact capability in its
	 * `meta['capability']`; otherwise we infer one from its supplier and
	 * operation. Either way the `albert/abilities/required_capability` filter
	 * gets the final say, so abilities or add-ons can correct it precisely.
	 *
	 * @param \WP_Ability $ability The ability to resolve.
	 *
	 * @return string A capability slug (best-effort).
	 * @since 1.3.0
	 */
	public static function resolve_required_capability( \WP_Ability $ability ): string {
		$id   = $ability->get_name();
		$meta = (array) $ability->get_meta();

		if ( ! empty( $meta['capability'] ) && is_string( $meta['capability'] ) ) {
			$cap = $meta['capability'];
		} else {
			$cap = self::guess_capability( $id, $meta );
		}

		/**
		 * Filters the required capability surfaced for an ability.
		 *
		 * @since 1.3.0
		 *
		 * @param string      $cap     The resolved capability slug (best-effort).
		 * @param string      $id      The ability ID.
		 * @param \WP_Ability $ability The ability instance.
		 */
		return (string) apply_filters( 'albert/abilities/required_capability', $cap, $id, $ability );
	}

	/**
	 * Infer a likely capability from an ability's id, supplier and operation.
	 *
	 * Heuristic only — see {@see self::resolve_required_capability()}.
	 *
	 * @param string               $id   Ability ID.
	 * @param array<string, mixed> $meta Ability meta (for annotations).
	 *
	 * @return string
	 * @since 1.3.0
	 */
	private static function guess_capability( string $id, array $meta ): string {
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : [];
		$chips       = AnnotationPresenter::chips_for( $annotations, $id );
		$operation   = $chips[0]['key'] ?? 'read';

		if ( self::get_ability_source( $id )['slug'] === 'woo' ) {
			return 'manage_woocommerce';
		}

		$action    = strtolower( strstr( $id, '/' ) ? substr( $id, (int) strpos( $id, '/' ) + 1 ) : $id );
		$is_read   = $operation === 'read';
		$is_delete = $operation === 'delete';

		if ( str_contains( $action, 'user' ) ) {
			return $is_read ? 'list_users' : ( $is_delete ? 'delete_users' : 'edit_users' );
		}
		// Setting a featured image edits the target post, not the media library.
		if ( str_contains( $action, 'featured-image' ) ) {
			return $is_read ? 'read' : 'edit_posts';
		}
		if ( str_contains( $action, 'media' ) || str_contains( $action, 'upload' ) ) {
			return $is_read ? 'read' : 'upload_files';
		}
		if ( str_contains( $action, 'term' ) || str_contains( $action, 'taxonom' ) ) {
			return $is_read ? 'read' : 'manage_categories';
		}
		if ( str_contains( $action, 'page' ) ) {
			return $is_read ? 'read' : ( $is_delete ? 'delete_pages' : 'edit_pages' );
		}
		if ( str_contains( $action, 'setting' ) || str_contains( $action, 'option' ) ) {
			return 'manage_options';
		}

		// Posts and general content.
		if ( $is_read ) {
			return 'read';
		}
		return $is_delete ? 'delete_posts' : 'edit_posts';
	}
}
