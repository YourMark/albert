<?php
/**
 * Unit tests for AnnotationPresenter.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Core;

use Albert\Core\Annotations;
use Albert\Core\AnnotationPresenter;
use PHPUnit\Framework\TestCase;

/**
 * AnnotationPresenter tests.
 *
 * Covers each annotation preset from {@see Annotations}, the slug-heuristic
 * fallback used when an ability declares no annotations, and the
 * is_idempotent helper that surfaces idempotency in the row details panel.
 */
class AnnotationPresenterTest extends TestCase {

	/**
	 * Read-only annotations produce a single neutral "Read" chip with a description.
	 *
	 * @return void
	 */
	public function test_read_annotation_yields_read_chip(): void {
		$chips = AnnotationPresenter::chips_for( Annotations::read() );

		$this->assertCount( 1, $chips );
		$this->assertSame( 'read', $chips[0]['key'] );
		$this->assertSame( 'neutral', $chips[0]['tone'] );
		$this->assertNotEmpty( $chips[0]['description'] );
	}

	/**
	 * Create annotations produce a single "Write" chip — never an idempotent chip.
	 *
	 * @return void
	 */
	public function test_create_annotation_yields_write_chip(): void {
		$chips = AnnotationPresenter::chips_for( Annotations::create() );
		$keys  = array_column( $chips, 'key' );

		$this->assertSame( [ 'write' ], $keys );
		$this->assertNotContains( 'idempotent', $keys );
	}

	/**
	 * Update annotations produce a "Write" chip but no idempotent chip.
	 *
	 * Idempotency is surfaced via {@see AnnotationPresenter::is_idempotent()}
	 * for the row details panel, not as a top-level chip.
	 *
	 * @return void
	 */
	public function test_update_annotation_yields_write_only(): void {
		$chips = AnnotationPresenter::chips_for( Annotations::update() );
		$keys  = array_column( $chips, 'key' );

		$this->assertSame( [ 'write' ], $keys );
		$this->assertNotContains( 'delete', $keys );
	}

	/**
	 * Delete annotations produce a "Delete" chip with the danger tone.
	 *
	 * @return void
	 */
	public function test_delete_annotation_yields_delete_chip(): void {
		$chips = AnnotationPresenter::chips_for( Annotations::delete() );
		$keys  = array_column( $chips, 'key' );
		$tones = array_column( $chips, 'tone' );

		$this->assertSame( [ 'delete' ], $keys );
		$this->assertContains( 'danger', $tones );
	}

	/**
	 * Action annotations produce only a "Write" chip.
	 *
	 * @return void
	 */
	public function test_action_annotation_yields_write_only(): void {
		$chips = AnnotationPresenter::chips_for( Annotations::action() );
		$keys  = array_column( $chips, 'key' );

		$this->assertSame( [ 'write' ], $keys );
	}

	/**
	 * Every chip carries a non-empty description used for tooltips.
	 *
	 * @return void
	 */
	public function test_every_chip_has_a_description(): void {
		foreach ( [ Annotations::read(), Annotations::create(), Annotations::update(), Annotations::delete(), Annotations::action() ] as $preset ) {
			$chips = AnnotationPresenter::chips_for( $preset );
			foreach ( $chips as $chip ) {
				$this->assertArrayHasKey( 'description', $chip );
				$this->assertNotEmpty( $chip['description'], "Chip {$chip['key']} should have a description." );
			}
		}
	}

	/**
	 * The is_idempotent helper returns the annotation flag when present.
	 *
	 * @return void
	 */
	public function test_is_idempotent_returns_annotation_value(): void {
		$this->assertTrue( AnnotationPresenter::is_idempotent( Annotations::read() ) );
		$this->assertTrue( AnnotationPresenter::is_idempotent( Annotations::update() ) );
		$this->assertTrue( AnnotationPresenter::is_idempotent( Annotations::delete() ) );
		$this->assertFalse( AnnotationPresenter::is_idempotent( Annotations::create() ) );
		$this->assertFalse( AnnotationPresenter::is_idempotent( Annotations::action() ) );
	}

	/**
	 * The is_idempotent helper returns null when the annotation is absent.
	 *
	 * @return void
	 */
	public function test_is_idempotent_returns_null_when_unset(): void {
		$this->assertNull( AnnotationPresenter::is_idempotent( [] ) );
		$this->assertNull( AnnotationPresenter::is_idempotent( [ 'readonly' => true ] ) );
	}

	/**
	 * Missing annotations + delete- prefix falls back to "Delete".
	 *
	 * @return void
	 */
	public function test_missing_annotations_delete_prefix_falls_back_to_delete(): void {
		$chips = AnnotationPresenter::chips_for( [], 'albert/delete-post' );
		$keys  = array_column( $chips, 'key' );

		$this->assertSame( [ 'delete' ], $keys );
	}

	/**
	 * Missing annotations + write prefix falls back to "Write".
	 *
	 * @return void
	 */
	public function test_missing_annotations_create_prefix_falls_back_to_write(): void {
		$chips = AnnotationPresenter::chips_for( [], 'albert/create-post' );
		$keys  = array_column( $chips, 'key' );

		$this->assertSame( [ 'write' ], $keys );
	}

	/**
	 * Missing annotations + unknown action defaults to "Read".
	 *
	 * @return void
	 */
	public function test_missing_annotations_unknown_action_falls_back_to_read(): void {
		$chips = AnnotationPresenter::chips_for( [], 'albert/find-posts' );
		$keys  = array_column( $chips, 'key' );

		$this->assertSame( [ 'read' ], $keys );
	}

	/**
	 * Destructive flag in annotations always wins regardless of id.
	 *
	 * @return void
	 */
	public function test_is_destructive_respects_annotation_flag(): void {
		$this->assertTrue(
			AnnotationPresenter::is_destructive( Annotations::delete(), 'albert/find-posts' )
		);
		$this->assertFalse(
			AnnotationPresenter::is_destructive( Annotations::read(), 'albert/delete-post' )
		);
	}

	/**
	 * The is_destructive helper falls back to the delete-prefix heuristic when annotations are empty.
	 *
	 * @return void
	 */
	public function test_is_destructive_heuristic_when_annotations_missing(): void {
		$this->assertTrue( AnnotationPresenter::is_destructive( [], 'albert/delete-post' ) );
		$this->assertFalse( AnnotationPresenter::is_destructive( [], 'albert/create-post' ) );
	}
}
