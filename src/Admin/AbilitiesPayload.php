<?php
/**
 * Abilities Payload
 *
 * Builds the normalized abilities dataset consumed by the DataViews admin app.
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.3.0
 */

namespace Albert\Admin;

use Albert\Core\AbilitiesRegistry;
use Albert\Core\AbilitiesState;
use Albert\Core\AnnotationPresenter;

defined( 'ABSPATH' ) || exit;

/**
 * AbilitiesPayload class
 *
 * Pure data assembler that turns the live Abilities API registry into the shape
 * the React screen expects: a flat list of ability rows plus the category and
 * supplier filter options and headline counts. Reused by the REST controller so
 * the screen has a single source for its data.
 *
 * @since 1.3.0
 */
class AbilitiesPayload {

	/**
	 * Build the full payload for the abilities screen.
	 *
	 * @return array{
	 *     abilities: array<int, array<string, mixed>>,
	 *     categories: array<int, array{value: string, label: string}>,
	 *     suppliers: array<int, array{value: string, label: string}>,
	 *     counts: array{total: int, enabled: int}
	 * }
	 * @since 1.3.0
	 */
	public static function build(): array {
		$categories = function_exists( 'wp_get_ability_categories' ) ? wp_get_ability_categories() : [];
		$disabled   = AbilitiesState::disabled();

		$abilities     = [];
		$category_opts = [];
		$supplier_opts = [];
		$enabled_count = 0;

		foreach ( wp_get_abilities() as $ability ) {
			$row         = self::row_from_ability( $ability, $categories, $disabled );
			$abilities[] = $row;

			if ( $row['enabled'] ) {
				++$enabled_count;
			}
			if ( $row['category'] !== '' && ! isset( $category_opts[ $row['category'] ] ) ) {
				$category_opts[ $row['category'] ] = $row['categoryLabel'];
			}
			if ( $row['supplier'] !== '' && ! isset( $supplier_opts[ $row['supplier'] ] ) ) {
				$supplier_opts[ $row['supplier'] ] = $row['supplierLabel'];
			}
		}

		usort(
			$abilities,
			static function ( array $a, array $b ): int {
				$by_category = strcasecmp( $a['categoryLabel'], $b['categoryLabel'] );
				return $by_category !== 0 ? $by_category : strcasecmp( $a['label'], $b['label'] );
			}
		);
		asort( $category_opts, SORT_NATURAL | SORT_FLAG_CASE );
		asort( $supplier_opts, SORT_NATURAL | SORT_FLAG_CASE );

		return [
			'abilities'  => $abilities,
			'categories' => self::to_options( $category_opts ),
			'suppliers'  => self::to_options( $supplier_opts ),
			'counts'     => [
				'total'   => count( $abilities ),
				'enabled' => $enabled_count,
			],
		];
	}

	/**
	 * Build a single ability row by ID, or null if it is not registered.
	 *
	 * Used by the REST controller to return just the row it changed.
	 *
	 * @param string $ability_id Ability ID.
	 *
	 * @return array<string, mixed>|null
	 * @since 1.3.0
	 */
	public static function row( string $ability_id ): ?array {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return null;
		}

		$ability = wp_get_ability( $ability_id );
		if ( $ability === null ) {
			return null;
		}

		$categories = function_exists( 'wp_get_ability_categories' ) ? wp_get_ability_categories() : [];

		return self::row_from_ability( $ability, $categories, AbilitiesState::disabled() );
	}

	/**
	 * Normalize one ability into a row for the screen.
	 *
	 * @param \WP_Ability          $ability    The ability.
	 * @param array<string, mixed> $categories Map from wp_get_ability_categories().
	 * @param array<int, string>   $disabled   Disabled ability IDs.
	 *
	 * @return array<string, mixed>
	 * @since 1.3.0
	 */
	private static function row_from_ability( \WP_Ability $ability, array $categories, array $disabled ): array {
		$id          = $ability->get_name();
		$meta        = (array) $ability->get_meta();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : [];
		$chips       = AnnotationPresenter::chips_for( $annotations, $id );
		$source      = AbilitiesRegistry::get_ability_source( $id );

		return [
			'id'            => $id,
			'label'         => $ability->get_label(),
			'description'   => $ability->get_description(),
			'category'      => $ability->get_category(),
			'categoryLabel' => self::category_label( $ability->get_category(), $categories ),
			'supplier'      => $source['slug'],
			'supplierLabel' => $source['label'],
			'operation'     => $chips[0]['key'] ?? 'read',
			'enabled'       => ! in_array( $id, $disabled, true ),
			'capability'    => AbilitiesRegistry::resolve_required_capability( $ability ),
			'inputs'        => self::inputs( $ability->get_input_schema() ),
			'output'        => self::output( $ability->get_output_schema() ),
			'annotations'   => array_map(
				static fn( array $chip ): array => [
					'key'         => $chip['key'],
					'label'       => $chip['label'],
					'description' => $chip['description'],
					'tone'        => $chip['tone'],
				],
				$chips
			),
		];
	}

	/**
	 * Flatten an input JSON schema into a list of field descriptors.
	 *
	 * @param array<string, mixed> $schema Input schema.
	 *
	 * @return array<int, array{name: string, type: string, required: bool, description: string}>
	 * @since 1.3.0
	 */
	private static function inputs( array $schema ): array {
		$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : [];
		$required   = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : [];

		$fields = [];
		foreach ( $properties as $name => $definition ) {
			$type = $definition['type'] ?? '';
			if ( is_array( $type ) ) {
				$type = implode( '|', $type );
			}

			$fields[] = [
				'name'        => (string) $name,
				'type'        => (string) $type,
				'required'    => in_array( $name, $required, true ),
				'description' => (string) ( $definition['description'] ?? '' ),
			];
		}

		return $fields;
	}

	/**
	 * Extract a human-readable description from an output JSON schema.
	 *
	 * @param array<string, mixed> $schema Output schema.
	 *
	 * @return string
	 * @since 1.3.0
	 */
	private static function output( array $schema ): string {
		return (string) ( $schema['description'] ?? '' );
	}

	/**
	 * Resolve a category slug to its human label.
	 *
	 * @param string               $slug       Category slug.
	 * @param array<string, mixed> $categories Map from wp_get_ability_categories().
	 *
	 * @return string
	 * @since 1.3.0
	 */
	private static function category_label( string $slug, array $categories ): string {
		if ( $slug === '' ) {
			return '';
		}

		$category = $categories[ $slug ] ?? null;
		if ( is_object( $category ) && method_exists( $category, 'get_label' ) ) {
			return (string) $category->get_label();
		}
		if ( is_array( $category ) && isset( $category['label'] ) ) {
			return (string) $category['label'];
		}

		return ucfirst( str_replace( [ '-', '_' ], ' ', $slug ) );
	}

	/**
	 * Convert a slug => label map into a value/label option list.
	 *
	 * @param array<string, string> $map Slug => label.
	 *
	 * @return array<int, array{value: string, label: string}>
	 * @since 1.3.0
	 */
	private static function to_options( array $map ): array {
		$options = [];
		foreach ( $map as $value => $label ) {
			$options[] = [
				'value' => (string) $value,
				'label' => (string) $label,
			];
		}

		return $options;
	}
}
