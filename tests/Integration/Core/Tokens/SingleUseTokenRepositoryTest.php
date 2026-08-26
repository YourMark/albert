<?php
/**
 * Integration tests for the SingleUseTokenRepository.
 *
 * Covers the security-critical redemption race guard — two concurrent
 * redemption attempts on the same row must never both succeed.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Core\Tokens;

use Albert\Core\Tokens\SingleUseTokenRepository;
use Albert\Database\Installer;
use Albert\Database\Tables;
use Albert\Tests\TestCase;

/**
 * SingleUseTokenRepository integration tests.
 *
 * @covers \Albert\Core\Tokens\SingleUseTokenRepository
 */
class SingleUseTokenRepositoryTest extends TestCase {

	/**
	 * Repository under test.
	 *
	 * @var SingleUseTokenRepository
	 */
	private SingleUseTokenRepository $repository;

	/**
	 * Reset the single_use_tokens table before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::install();
		$this->repository = new SingleUseTokenRepository();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test reset.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Tables::single_use_tokens() ) );
	}

	/**
	 * Insert stores every field, including the JSON-encoded payload.
	 *
	 * @return void
	 */
	public function test_insert_stores_fields(): void {
		$stored = $this->repository->insert( 'hash_a', 'media_upload', 7, [ 'foo' => 'bar' ], gmdate( 'Y-m-d H:i:s', time() + 600 ) );

		$this->assertTrue( $stored );

		$row = $this->repository->find( 'hash_a', 'media_upload' );

		$this->assertNotNull( $row );
		$this->assertSame( 'hash_a', $row->token_hash );
		$this->assertSame( 7, (int) $row->user_id );
		$this->assertSame( [ 'foo' => 'bar' ], json_decode( (string) $row->payload, true ) );
		$this->assertNull( $row->redeemed_at );
	}

	/**
	 * Find() is scoped by purpose — a hash minted under one purpose is invisible to another.
	 *
	 * @return void
	 */
	public function test_find_is_scoped_by_purpose(): void {
		$this->repository->insert( 'hash_b', 'media_upload', 1, [], gmdate( 'Y-m-d H:i:s', time() + 600 ) );

		$this->assertNotNull( $this->repository->find( 'hash_b', 'media_upload' ) );
		$this->assertNull( $this->repository->find( 'hash_b', 'some_other_purpose' ) );
	}

	/**
	 * The first mark_redeemed() call on a row succeeds.
	 *
	 * @return void
	 */
	public function test_mark_redeemed_succeeds_once(): void {
		$this->repository->insert( 'hash_c', 'media_upload', 1, [], gmdate( 'Y-m-d H:i:s', time() + 600 ) );
		$row = $this->repository->find( 'hash_c', 'media_upload' );

		$this->assertTrue( $this->repository->mark_redeemed( (int) $row->id ) );

		$after = $this->repository->find( 'hash_c', 'media_upload' );
		$this->assertNotNull( $after->redeemed_at );
	}

	/**
	 * A second mark_redeemed() call on the same row fails — this is the
	 * compare-and-set that makes single-use hold under a race.
	 *
	 * @return void
	 */
	public function test_mark_redeemed_is_a_compare_and_set(): void {
		$this->repository->insert( 'hash_d', 'media_upload', 1, [], gmdate( 'Y-m-d H:i:s', time() + 600 ) );
		$row = $this->repository->find( 'hash_d', 'media_upload' );

		$this->assertTrue( $this->repository->mark_redeemed( (int) $row->id ) );
		$this->assertFalse( $this->repository->mark_redeemed( (int) $row->id ) );
	}

	/**
	 * Cleanup_expired() removes rows expired more than a day ago, and keeps
	 * both unexpired rows and rows only recently expired.
	 *
	 * @return void
	 */
	public function test_cleanup_expired_removes_only_long_expired_rows(): void {
		$this->repository->insert( 'hash_long_expired', 'media_upload', 1, [], gmdate( 'Y-m-d H:i:s', time() - ( 2 * DAY_IN_SECONDS ) ) );
		$this->repository->insert( 'hash_recently_expired', 'media_upload', 1, [], gmdate( 'Y-m-d H:i:s', time() - 60 ) );
		$this->repository->insert( 'hash_future', 'media_upload', 1, [], gmdate( 'Y-m-d H:i:s', time() + 600 ) );

		$deleted = $this->repository->cleanup_expired();

		$this->assertSame( 1, $deleted );
		$this->assertNull( $this->repository->find( 'hash_long_expired', 'media_upload' ) );
		$this->assertNotNull( $this->repository->find( 'hash_recently_expired', 'media_upload' ) );
		$this->assertNotNull( $this->repository->find( 'hash_future', 'media_upload' ) );
	}
}
