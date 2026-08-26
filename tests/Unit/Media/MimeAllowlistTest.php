<?php
/**
 * Unit tests for MimeAllowlist's pure set operations.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Media;

use Albert\Media\MimeAllowlist;
use PHPUnit\Framework\TestCase;

/**
 * MimeAllowlist unit tests (intersect() and mime_list() only — for_user()
 * calls WordPress's get_allowed_mime_types() and is covered in Integration).
 *
 * @covers \Albert\Media\MimeAllowlist
 */
class MimeAllowlistTest extends TestCase {

	/**
	 * A sample WordPress-shaped allowlist: extension-regex => MIME type.
	 *
	 * @return array<string, string>
	 */
	private function sample_allowlist(): array {
		return [
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'gif'          => 'image/gif',
			'pdf'          => 'application/pdf',
		];
	}

	/**
	 * Intersect() keeps only entries whose MIME type was requested.
	 *
	 * @return void
	 */
	public function test_intersect_keeps_only_requested_mimes(): void {
		$narrowed = MimeAllowlist::intersect( $this->sample_allowlist(), [ 'image/jpeg', 'image/png' ] );

		$this->assertSame(
			[
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
			],
			$narrowed
		);
	}

	/**
	 * Intersect() is case-insensitive on the MIME type string.
	 *
	 * @return void
	 */
	public function test_intersect_is_case_insensitive(): void {
		$narrowed = MimeAllowlist::intersect( $this->sample_allowlist(), [ 'IMAGE/JPEG' ] );

		$this->assertSame( [ 'jpg|jpeg|jpe' => 'image/jpeg' ], $narrowed );
	}

	/**
	 * A request for a MIME type not in the base allowlist narrows to nothing
	 * for that type — it can never widen the base allowlist.
	 *
	 * @return void
	 */
	public function test_intersect_cannot_widen_the_allowlist(): void {
		$narrowed = MimeAllowlist::intersect( $this->sample_allowlist(), [ 'application/x-php' ] );

		$this->assertSame( [], $narrowed );
	}

	/**
	 * Intersecting with an empty request yields nothing — narrowing to
	 * "everything" is expressed by not calling intersect() at all
	 * ({@see MimeAllowlist::for_user()}'s empty-request short-circuit).
	 *
	 * @return void
	 */
	public function test_intersect_with_empty_request_yields_nothing(): void {
		$this->assertSame( [], MimeAllowlist::intersect( $this->sample_allowlist(), [] ) );
	}

	/**
	 * Mime_list() flattens to unique MIME type strings.
	 *
	 * @return void
	 */
	public function test_mime_list_deduplicates(): void {
		$list = MimeAllowlist::mime_list(
			[
				'jpg|jpeg|jpe' => 'image/jpeg',
				'jpg2'         => 'image/jpeg',
				'png'          => 'image/png',
			]
		);

		$this->assertSame( [ 'image/jpeg', 'image/png' ], $list );
	}

	/**
	 * Mime_list() on an empty allowlist returns an empty list.
	 *
	 * @return void
	 */
	public function test_mime_list_of_empty_allowlist_is_empty(): void {
		$this->assertSame( [], MimeAllowlist::mime_list( [] ) );
	}
}
