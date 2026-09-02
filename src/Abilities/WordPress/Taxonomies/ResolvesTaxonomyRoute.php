<?php
/**
 * Shared taxonomy route resolution for the term abilities.
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Taxonomies
 * @since      1.4.0
 */

namespace Albert\Abilities\WordPress\Taxonomies;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Resolves a taxonomy slug to the `/wp/v2/` route segment WordPress serves it on.
 *
 * FindTerms, CreateTerm, UpdateTerm and DeleteTerm all reach their taxonomy
 * through `rest_do_request()`, and each carried its own byte-identical copy of
 * this method. One trait instead of four copies, so the next correction happens
 * once.
 *
 * @since 1.4.0
 */
trait ResolvesTaxonomyRoute {

	/**
	 * Slugs an assistant is likely to guess, mapped to the real taxonomy.
	 *
	 * `post_tags` is not a taxonomy on any site; it is the plural an assistant
	 * reaches for after seeing `tags` in a post payload. Accepting it costs a
	 * lookup and saves a round trip.
	 *
	 * @since 1.4.0
	 * @var array<string, string>
	 */
	private static array $taxonomy_aliases = [
		'post_tags' => 'post_tag',
	];

	/**
	 * Get the REST route segment for a taxonomy.
	 *
	 * Two things decide the answer, and until 1.4.0 this asked neither of them.
	 *
	 * Whether the taxonomy is reachable over REST at all is `show_in_rest`.
	 * `rest_base` is only the route *name*, and `register_taxonomy()` leaves it
	 * `false` unless the caller sets it — WordPress then serves the taxonomy
	 * under its own slug. Reading `rest_base` as the gate rejected every
	 * REST-enabled taxonomy that had not been given an explicit base, which is
	 * the common case for custom taxonomies: WooCommerce's `product_cat` is
	 * registered with `show_in_rest => true` and no `rest_base`, and came back
	 * as "not available via REST API" from an ability whose whole job was to
	 * read it.
	 *
	 * The base therefore falls back to the taxonomy name, matching what
	 * `WP_REST_Terms_Controller` does when it registers the route.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 *
	 * @return string|WP_Error Route segment, or an error naming the taxonomy.
	 * @since 1.4.0
	 */
	private function get_taxonomy_rest_base( string $taxonomy ): string|WP_Error {
		$taxonomy = self::$taxonomy_aliases[ $taxonomy ] ?? $taxonomy;

		$taxonomy_obj = get_taxonomy( $taxonomy );
		if ( ! $taxonomy_obj ) {
			return new WP_Error(
				'invalid_taxonomy',
				sprintf(
					/* translators: %s: taxonomy slug that was requested */
					__( 'No taxonomy named "%s" is registered on this site. Use albert/find-taxonomies to list the ones that are.', 'albert-ai-butler' ),
					$taxonomy
				),
				[ 'status' => 404 ]
			);
		}

		if ( empty( $taxonomy_obj->show_in_rest ) ) {
			return new WP_Error(
				'taxonomy_not_rest_enabled',
				sprintf(
					/* translators: %s: taxonomy slug that was requested */
					__( 'The "%s" taxonomy is registered on this site but is not exposed over the REST API, so Albert cannot read or change its terms.', 'albert-ai-butler' ),
					$taxonomy
				),
				[ 'status' => 400 ]
			);
		}

		// `rest_base` is the route name, not a flag: false or '' means WordPress
		// serves the taxonomy under its own slug.
		return is_string( $taxonomy_obj->rest_base ) && $taxonomy_obj->rest_base !== ''
			? $taxonomy_obj->rest_base
			: $taxonomy_obj->name;
	}
}
