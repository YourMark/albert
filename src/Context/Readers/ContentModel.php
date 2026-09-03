<?php
/**
 * Content model reader: what this site is made of, and who models it.
 *
 * @package Albert
 * @subpackage Context\Readers
 * @since      1.4.0
 */

namespace Albert\Context\Readers;

defined( 'ABSPATH' ) || exit;

use Albert\Context\Symbols;

/**
 * Reads the site's post types, taxonomies and content-model owner.
 *
 * Two questions the assistant otherwise answers by guessing. *What can I put
 * content in*, a site with a `docs` type and a `product_cat` taxonomy is a
 * different site from a stock install. And *who owns the model*, on a site
 * where ACF or Pods defines the structure, an assistant proposing
 * `register_post_type()` in a theme file is proposing to fork the model, which
 * is exactly the kind of confident wrong turn this section exists to prevent.
 *
 * The owner list is a resolved decision, not an inventory: it names only
 * plugins that model content, and nothing else that happens to be installed.
 *
 * @since 1.4.0
 */
class ContentModel {

	/**
	 * Content-modelling plugins we can name.
	 *
	 * The probe is a symbol the plugin defines: a class or a function, whichever
	 * it happens to be, never a plugin path or an `is_plugin_active()` call. A
	 * renamed plugin folder is common; the symbol is what actually tells us the
	 * plugin is running.
	 *
	 * @since 1.4.0
	 * @var array<string, string> Label keyed by the symbol that proves it is running.
	 */
	private const OWNERS = [
		'acf_get_field_groups'     => 'ACF',
		'pods'                     => 'Pods',
		'RWMB_Loader'              => 'Meta Box',
		'cptui_get_post_type_data' => 'CPT UI',
		'Jet_Engine'               => 'JetEngine',
	];

	/**
	 * Most post types or taxonomies to name before summarising the rest.
	 *
	 * A site with sixty post types would otherwise spend most of the context
	 * budget listing them. What was left out is counted rather than dropped
	 * silently: an assistant handed a short list assumes it is complete and
	 * stops looking, where one told there are 48 more asks.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	private const MAX_NAMED = 12;

	/**
	 * Build the content-model section.
	 *
	 * @return array{post_types: list<string>, post_types_more?: int, taxonomies: list<string>, taxonomies_more?: int, owners?: list<string>}
	 * @since 1.4.0
	 */
	public function read(): array {
		$post_types = $this->post_types();
		$taxonomies = $this->taxonomies();

		$model = [
			'post_types' => array_slice( $post_types, 0, self::MAX_NAMED ),
			'taxonomies' => array_slice( $taxonomies, 0, self::MAX_NAMED ),
		];

		$post_types_more = count( $post_types ) - self::MAX_NAMED;
		if ( $post_types_more > 0 ) {
			$model['post_types_more'] = $post_types_more;
		}

		$taxonomies_more = count( $taxonomies ) - self::MAX_NAMED;
		if ( $taxonomies_more > 0 ) {
			$model['taxonomies_more'] = $taxonomies_more;
		}

		$owners = $this->owners();
		if ( $owners !== [] ) {
			$model['owners'] = $owners;
		}

		return $model;
	}

	/**
	 * The post types worth naming.
	 *
	 * Public types only, plus `attachment` dropped: the assistant reaches media
	 * through the media abilities, not by treating it as a content type, and
	 * naming it here invites the wrong tool. Everything non-public is
	 * infrastructure the model has no business writing to.
	 *
	 * @return list<string> Public post type slugs, without `attachment`.
	 * @since 1.4.0
	 */
	private function post_types(): array {
		$types = get_post_types( [ 'public' => true ], 'names' );

		return array_values( array_diff( array_map( 'strval', $types ), [ 'attachment' ] ) );
	}

	/**
	 * The taxonomies worth naming.
	 *
	 * @return list<string> Public taxonomy slugs.
	 * @since 1.4.0
	 */
	private function taxonomies(): array {
		$taxonomies = get_taxonomies( [ 'public' => true ], 'names' );

		return array_values( array_map( 'strval', $taxonomies ) );
	}

	/**
	 * Which plugins model content on this site.
	 *
	 * @return list<string> Labels of the content-modelling plugins running here.
	 * @since 1.4.0
	 */
	private function owners(): array {
		$owners = [];

		foreach ( self::OWNERS as $symbol => $label ) {
			if ( Symbols::defined( (string) $symbol ) ) {
				$owners[] = $label;
			}
		}

		return $owners;
	}
}
