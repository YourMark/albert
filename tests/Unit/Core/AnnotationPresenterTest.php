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
 * Covers each annotation preset from {@see Annotations} plus the id-heuristic
 * fallback used when an ability declares no annotations.
 */
class AnnotationPresenterTest extends TestCase {

	/**
	 * Read-only annotations produce a single neutral "Read-only" chip.
	 *
	 * @return void
	 */
	public function test_read_annotation_yields_read_only_chip(): void {
		$chips = AnnotationPresenter::chips_for( Annotations::read() );

		$this->assertCount( 1, $chips );
		$this->assertSame( 'read-only', $chips[0]['key'] );
		$this->assertSame( 'neutral', $chips[0]['tone'] );
	}

	/**
	 * Create annotations produce a warning "Writes data" chip (no idempotent).
	 *
	 * @return void
	 */
	public function test_create_annotation_yields_writes_data_chip(): void {
		$chips = AnnotationPresenter::chips_for( Annotations::create() );
		$keys  = array_column( $chips, 'key' );

		$this->assertContains( 'writes-data', $keys );
		$this->assertNotContains( 'idempotent', $keys );
		$this->assertNotContains( 'destructive', $keys );
	}

	/**
	 * Update annotations produce both a "Writes data" and "Idempotent" chip.
	 *
	 * @return void
	 */
	public function test_update_annotation_yields_writes_and_idempotent_chips(): void {
		$chips = AnnotationPresenter::chips_for( Annotations::update() );
		$keys  = array_column( $chips, 'key' );

		$this->assertContains( 'writes-data', $keys );
		$this->assertContains( 'idempotent', $keys );
		$this->assertNotContains( 'destructive', $keys );
	}

	/**
	 * Delete annotations produce "Destructive" and "Idempotent" chips.
	 *
	 * @return void
	 */
	public function test_delete_annotation_yields_destructive_and_idempotent_chips(): void {
		$chips = AnnotationPresenter::chips_for( Annotations::delete() );
		$keys  = array_column( $chips, 'key' );
		$tones = array_column( $chips, 'tone' );

		$this->assertContains( 'destructive', $keys );
		$this->assertContains( 'idempotent', $keys );
		$this->assertContains( 'danger', $tones );
	}

	/**
	 * Action annotations produce only a "Writes data" chip.
	 *
	 * @return void
	 */
	public function test_action_annotation_yields_writes_data_only(): void {
		$chips = AnnotationPresenter::chips_for( Annotations::action() );
		$keys  = array_column( $chips, 'key' );

		$this->assertSame( [ 'writes-data' ], $keys );
	}

	/**
	 * When annotations are missing, the delete-prefix heuristic kicks in.
	 *
	 * @return void
	 */
	public function test_missing_annotations_delete_prefix_falls_back_to_destructive(): void {
		$chips = AnnotationPresenter::chips_for( [], 'albert/delete-post' );
		$keys  = array_column( $chips, 'key' );

		$this->assertSame( [ 'destructive' ], $keys );
	}

	/**
	 * When annotations are missing, write prefixes produce a "Writes data" chip.
	 *
	 * @return void
	 */
	public function test_missing_annotations_create_prefix_falls_back_to_writes_data(): void {
		$chips = AnnotationPresenter::chips_for( [], 'albert/create-post' );
		$keys  = array_column( $chips, 'key' );

		$this->assertSame( [ 'writes-data' ], $keys );
	}

	/**
	 * When annotations are missing and no write prefix matches, default to read-only.
	 *
	 * @return void
	 */
	public function test_missing_annotations_unknown_action_falls_back_to_read_only(): void {
		$chips = AnnotationPresenter::chips_for( [], 'albert/find-posts' );
		$keys  = array_column( $chips, 'key' );

		$this->assertSame( [ 'read-only' ], $keys );
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
	 * is_destructive falls back to the delete-prefix heuristic when annotations are empty.
	 *
	 * @return void
	 */
	public function test_is_destructive_heuristic_when_annotations_missing(): void {
		$this->assertTrue( AnnotationPresenter::is_destructive( [], 'albert/delete-post' ) );
		$this->assertFalse( AnnotationPresenter::is_destructive( [], 'albert/create-post' ) );
	}
}
