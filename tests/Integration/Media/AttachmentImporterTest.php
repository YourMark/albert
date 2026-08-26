<?php
/**
 * Integration tests for AttachmentImporter.
 *
 * The tail both upload paths now share. Three of these cover behaviour that
 * used to exist on the upload-link path only, while `albert/upload-media`
 * quietly did something worse with the same inputs: core's corrected filename
 * was ignored, and core's refusal surfaced as a 500.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Media;

use Albert\Media\AttachmentImporter;
use Albert\Media\MimeAllowlist;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * AttachmentImporter integration tests.
 *
 * @covers \Albert\Media\AttachmentImporter
 */
class AttachmentImporterTest extends TestCase {

	/**
	 * An administrator, current user for these tests.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Reset state before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * A disposable copy of a fixture, since the importer consumes what it is given.
	 *
	 * @param string $name Fixture filename under DIR_TESTDATA/images.
	 *
	 * @return string Path to the copy.
	 */
	private function fixture_copy( string $name = 'sugarloaf-mountain.jpg' ): string {
		if ( ! defined( 'DIR_TESTDATA' ) ) {
			$this->markTestSkipped( 'DIR_TESTDATA not defined — WP test suite fixtures unavailable.' );
		}

		$copy = wp_tempnam();
		copy( DIR_TESTDATA . '/images/' . $name, $copy );

		return $copy;
	}

	/**
	 * The allowlist an administrator would upload under.
	 *
	 * @return array<string, string>
	 */
	private function allowlist(): array {
		return MimeAllowlist::for_user( $this->admin_id );
	}

	/**
	 * A valid file becomes an attachment, and the temp file is consumed.
	 *
	 * @return void
	 */
	public function test_imports_a_valid_file_and_consumes_the_temp_file(): void {
		$tmp = $this->fixture_copy();

		$result = AttachmentImporter::import( $tmp, 'mountain.jpg', $this->allowlist() );

		$this->assertIsArray( $result );
		$this->assertNotNull( get_post( $result['attachment_id'] ) );
		$this->assertSame( 'image/jpeg', $result['mime_type'] );
		$this->assertFileDoesNotExist( $tmp, 'The temp file should never outlive the import.' );

		wp_delete_attachment( $result['attachment_id'], true );
	}

	/**
	 * A type outside the allowlist is refused, and the caller is told what
	 * would have been accepted instead of being left to guess.
	 *
	 * @return void
	 */
	public function test_refuses_a_type_outside_the_allowlist(): void {
		$tmp = $this->fixture_copy();

		$result = AttachmentImporter::import(
			$tmp,
			'mountain.jpg',
			MimeAllowlist::intersect( $this->allowlist(), [ 'application/pdf' ] )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'type_not_allowed', $result->get_error_code() );
		$this->assertSame( 415, $result->get_error_data()['status'] );
		$this->assertContains( 'application/pdf', $result->get_error_data()['accepted_types'] );
		$this->assertFileDoesNotExist( $tmp );
	}

	/**
	 * A filename that sanitises to nothing is refused rather than handed on.
	 *
	 * @return void
	 */
	public function test_refuses_an_empty_filename(): void {
		$tmp = $this->fixture_copy();

		$result = AttachmentImporter::import( $tmp, '', $this->allowlist() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'type_not_allowed', $result->get_error_code() );
		$this->assertFileDoesNotExist( $tmp );
	}

	/**
	 * Core's corrected filename is taken, so a JPEG sent as ".png" is not
	 * stored under the extension it was mislabelled with.
	 *
	 * `albert/upload-media` ignored this before both paths shared this method.
	 *
	 * @return void
	 */
	public function test_takes_the_corrected_filename_from_core(): void {
		$tmp = $this->fixture_copy();

		$result = AttachmentImporter::import( $tmp, 'actually-a-jpeg.png', $this->allowlist() );

		$this->assertIsArray( $result );
		$this->assertStringEndsWith( '.jpg', (string) $result['url'] );
		$this->assertSame( 'image/jpeg', $result['mime_type'] );

		wp_delete_attachment( $result['attachment_id'], true );
	}

	/**
	 * A refusal from core is reported as a 400 under our own code, not as
	 * core's status-less `upload_error`, which REST maps to a 500 and which
	 * collides with the upload endpoint's own error of that name.
	 *
	 * @return void
	 */
	public function test_reports_a_core_refusal_as_a_client_error(): void {
		$tmp = $this->fixture_copy();

		add_filter(
			'wp_handle_sideload_prefilter',
			static function ( array $file ): array {
				$file['error'] = 'Refused by a filter, standing in for core refusing the file.';

				return $file;
			}
		);

		$result = AttachmentImporter::import( $tmp, 'mountain.jpg', $this->allowlist() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'upload_failed', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertFileDoesNotExist( $tmp );
	}
}
