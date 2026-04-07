<?php
/**
 * Annotation Presenter
 *
 * Maps ability annotation arrays into chip DTOs for the admin UI.
 *
 * @package Albert
 * @subpackage Core
 * @since      1.1.0
 */

namespace Albert\Core;

defined( 'ABSPATH' ) || exit;

/**
 * AnnotationPresenter class
 *
 * Pure helper that converts an ability's WP 6.9 annotations array
 * (readonly / destructive / idempotent) into a list of chip DTOs the
 * AbilitiesPage renders. Keeping the mapping here makes the view thin
 * and the logic unit-testable without a WordPress bootstrap.
 *
 * @since 1.1.0
 */
class AnnotationPresenter {

	/**
	 * Build chip DTOs for an ability.
	 *
	 * Each chip is an array with:
	 *  - 'key'   string — stable identifier ('read-only', 'destructive', …).
	 *  - 'label' string — translated human label.
	 *  - 'icon'  string — Dashicon class name.
	 *  - 'tone'  string — one of: neutral, info, warning, danger.
	 *
	 * If the ability has no annotations the presenter falls back to the
	 * slug heuristic ({@see self::heuristic_chips()}) so older custom
	 * abilities still display something sensible.
	 *
	 * @param array<string, mixed> $annotations Raw annotations from get_meta()['annotations'].
	 * @param string               $ability_id  Ability id, used only for the fallback heuristic.
	 *
	 * @return array<int, array{key: string, label: string, icon: string, tone: string}>
	 * @since 1.1.0
	 */
	public static function chips_for( array $annotations, string $ability_id = '' ): array {
		if ( empty( $annotations ) ) {
			return self::heuristic_chips( $ability_id );
		}

		$chips    = [];
		$readonly = ! empty( $annotations['readonly'] );

		if ( $readonly ) {
			$chips[] = [
				'key'   => 'read-only',
				'label' => __( 'Read-only', 'albert-ai-butler' ),
				'icon'  => 'dashicons-visibility',
				'tone'  => 'neutral',
			];
		} elseif ( empty( $annotations['destructive'] ) ) {
			// Non-readonly and non-destructive: writes data.
			$chips[] = [
				'key'   => 'writes-data',
				'label' => __( 'Writes data', 'albert-ai-butler' ),
				'icon'  => 'dashicons-edit',
				'tone'  => 'warning',
			];
		}

		if ( ! empty( $annotations['destructive'] ) ) {
			$chips[] = [
				'key'   => 'destructive',
				'label' => __( 'Destructive', 'albert-ai-butler' ),
				'icon'  => 'dashicons-trash',
				'tone'  => 'danger',
			];
		}

		if ( ! empty( $annotations['idempotent'] ) && ! $readonly ) {
			$chips[] = [
				'key'   => 'idempotent',
				'label' => __( 'Idempotent', 'albert-ai-butler' ),
				'icon'  => 'dashicons-update',
				'tone'  => 'info',
			];
		}

		return $chips;
	}

	/**
	 * Derive chips from the ability id when no annotations are declared.
	 *
	 * Uses the same write-prefix heuristic as the previous AbstractAbilitiesPage,
	 * so existing third-party abilities without annotations still look sensible.
	 *
	 * @param string $ability_id Ability id, e.g. 'albert/create-post'.
	 *
	 * @return array<int, array{key: string, label: string, icon: string, tone: string}>
	 * @since 1.1.0
	 */
	public static function heuristic_chips( string $ability_id ): array {
		if ( '' === $ability_id ) {
			return [];
		}

		$parts  = explode( '/', $ability_id, 2 );
		$action = $parts[1] ?? $parts[0];

		if ( str_starts_with( $action, 'delete-' ) ) {
			return [
				[
					'key'   => 'destructive',
					'label' => __( 'Destructive', 'albert-ai-butler' ),
					'icon'  => 'dashicons-trash',
					'tone'  => 'danger',
				],
			];
		}

		foreach ( [ 'create-', 'update-', 'upload-', 'set-' ] as $prefix ) {
			if ( str_starts_with( $action, $prefix ) ) {
				return [
					[
						'key'   => 'writes-data',
						'label' => __( 'Writes data', 'albert-ai-butler' ),
						'icon'  => 'dashicons-edit',
						'tone'  => 'warning',
					],
				];
			}
		}

		return [
			[
				'key'   => 'read-only',
				'label' => __( 'Read-only', 'albert-ai-butler' ),
				'icon'  => 'dashicons-visibility',
				'tone'  => 'neutral',
			],
		];
	}

	/**
	 * Whether the ability should trigger a confirmation prompt when enabled.
	 *
	 * @param array<string, mixed> $annotations Raw annotations.
	 * @param string               $ability_id  Ability id for the fallback heuristic.
	 *
	 * @return bool
	 * @since 1.1.0
	 */
	public static function is_destructive( array $annotations, string $ability_id = '' ): bool {
		if ( isset( $annotations['destructive'] ) ) {
			return (bool) $annotations['destructive'];
		}

		$parts  = explode( '/', $ability_id, 2 );
		$action = $parts[1] ?? $parts[0];

		return str_starts_with( $action, 'delete-' );
	}
}
