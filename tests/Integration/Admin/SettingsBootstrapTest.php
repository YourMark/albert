<?php
/**
 * Integration tests for SettingsBootstrap's built-in sections.
 *
 * Covers only the Uploads section added for the media-upload-link byte
 * cap — the other built-in sections (Privacy, Connections, Licenses)
 * predate this file and have no dedicated coverage of their own yet.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Admin;

use Albert\Admin\SettingsBootstrap;
use Albert\Media\UploadTickets\UploadTicketService;
use Albert\Tests\TestCase;

/**
 * SettingsBootstrap integration tests.
 *
 * @covers \Albert\Admin\SettingsBootstrap
 */
class SettingsBootstrapTest extends TestCase {

	/**
	 * Find the Uploads section from the built-in sections list.
	 *
	 * @return array<string, mixed>
	 */
	private function uploads_section(): array {
		foreach ( SettingsBootstrap::get_builtin_sections() as $section ) {
			if ( $section['id'] === 'albert/media' ) {
				return $section;
			}
		}

		$this->fail( 'albert/media section not found in built-in sections.' );
	}

	/**
	 * The Uploads section is registered with the expected single field,
	 * wired to UploadTicketService's option and sanitizer.
	 *
	 * @return void
	 */
	public function test_uploads_section_field_is_wired_correctly(): void {
		$section = $this->uploads_section();

		$this->assertCount( 1, $section['fields'] );

		$field = $section['fields'][0];
		$this->assertSame( 'custom', $field['type'] );
		$this->assertSame( UploadTicketService::MAX_BYTES_OPTION, $field['option_name'] );
		$this->assertSame( UploadTicketService::DEFAULT_MAX_MB, $field['default'] );
		$this->assertSame( [ UploadTicketService::class, 'render_max_mb_field' ], $field['render_callback'] );
		$this->assertSame( [ UploadTicketService::class, 'sanitize_max_mb' ], $field['sanitize_callback'] );
	}

	/**
	 * The field's description mentions the server's actual upload ceiling,
	 * so an owner isn't left guessing why a large value has no effect.
	 *
	 * @return void
	 */
	public function test_uploads_section_description_mentions_server_ceiling(): void {
		$field = $this->uploads_section()['fields'][0];

		$this->assertStringContainsString( size_format( wp_max_upload_size() ), $field['description'] );
	}
}
