<?php
/**
 * Integration tests for the Logging Repository.
 *
 * Exercises the real SQL — retention, bulk fetch, pruning, ordering —
 * against the WordPress test suite's MySQL. These are the queries most
 * likely to regress silently when someone refactors.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Logging;

use Albert\Logging\Installer;
use Albert\Logging\Repository;
use Albert\Tests\TestCase;

/**
 * Repository integration tests.
 *
 * @covers \Albert\Logging\Repository
 */
class RepositoryTest extends TestCase {

	/**
	 * Repository under test.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Reset the log table before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// Defensive: make sure the table exists even if activation didn't run.
		Installer::install();

		$this->repository = new Repository();
		$this->repository->truncate();
	}

	// ─── insert() + auto-prune ───────────────────────────────────────

	/**
	 * Insert writes a row with the expected columns.
	 *
	 * @return void
	 */
	public function test_insert_writes_row(): void {
		$this->repository->insert( 'albert/create-post', 42 );

		$row = $this->repository->latest_for_ability( 'albert/create-post' );

		$this->assertNotNull( $row );
		$this->assertSame( 'albert/create-post', $row->ability_name );
		$this->assertSame( 42, (int) $row->user_id );
		$this->assertNotEmpty( $row->created_at );
	}

	/**
	 * Retention keeps exactly RETENTION_COUNT (2) rows per ability.
	 *
	 * @return void
	 */
	public function test_insert_prunes_to_retention_count(): void {
		$this->repository->insert( 'albert/same', 1 );
		$this->repository->insert( 'albert/same', 2 );
		$this->repository->insert( 'albert/same', 3 );
		$this->repository->insert( 'albert/same', 4 );

		$rows = $this->all_rows_for( 'albert/same' );

		$this->assertCount( Repository::RETENTION_COUNT, $rows );
	}

	/**
	 * Retention keeps the newest rows, not arbitrary ones.
	 *
	 * @return void
	 */
	public function test_insert_keeps_most_recent_rows(): void {
		$this->repository->insert( 'albert/newest', 1 );
		$this->repository->insert( 'albert/newest', 2 );
		$this->repository->insert( 'albert/newest', 3 );

		$rows     = $this->all_rows_for( 'albert/newest' );
		$user_ids = array_map( static fn( $row ): int => (int) $row->user_id, $rows );

		$this->assertNotContains( 1, $user_ids, 'Oldest row (user 1) should be pruned.' );
	}

	/**
	 * Pruning a log entry for one ability does not touch other abilities.
	 *
	 * @return void
	 */
	public function test_insert_does_not_prune_other_abilities(): void {
		$this->repository->insert( 'albert/alpha', 10 );
		$this->repository->insert( 'albert/beta', 20 );
		$this->repository->insert( 'albert/alpha', 11 );
		$this->repository->insert( 'albert/alpha', 12 );
		$this->repository->insert( 'albert/alpha', 13 );

		$this->assertCount( 1, $this->all_rows_for( 'albert/beta' ) );
	}

	// ─── latest_for_ability() ───────────────────────────────────────

	/**
	 * Returns null when no rows exist for the ability.
	 *
	 * @return void
	 */
	public function test_latest_for_ability_returns_null_when_empty(): void {
		$this->assertNull( $this->repository->latest_for_ability( 'albert/nobody' ) );
	}

	/**
	 * Ordering breaks ties on id when created_at matches.
	 *
	 * Two rows with identical created_at (likely in real use within the same
	 * second) must order deterministically by id DESC — the higher id wins.
	 *
	 * @return void
	 */
	public function test_latest_for_ability_breaks_ties_on_id(): void {
		global $wpdb;

		$table = Installer::get_table_name();
		$now   = current_time( 'mysql' );

		$wpdb->insert(
			$table,
			[
				'ability_name' => 'albert/tied',
				'user_id'      => 1,
				'created_at'   => $now,
			],
			[ '%s', '%d', '%s' ]
		);
		$wpdb->insert(
			$table,
			[
				'ability_name' => 'albert/tied',
				'user_id'      => 2,
				'created_at'   => $now,
			],
			[ '%s', '%d', '%s' ]
		);

		$latest = $this->repository->latest_for_ability( 'albert/tied' );

		$this->assertSame( 2, (int) $latest->user_id, 'Tie-breaker should pick the higher id.' );
	}

	// ─── latest_bulk() ──────────────────────────────────────────────

	/**
	 * An empty input returns an empty array without querying the DB.
	 *
	 * @return void
	 */
	public function test_latest_bulk_returns_empty_for_empty_input(): void {
		$this->assertSame( [], $this->repository->latest_bulk( [] ) );
	}

	/**
	 * Maps each requested ability to its latest row.
	 *
	 * @return void
	 */
	public function test_latest_bulk_maps_by_ability_name(): void {
		$this->repository->insert( 'albert/one', 1 );
		$this->repository->insert( 'albert/two', 2 );
		$this->repository->insert( 'albert/one', 10 );

		$map = $this->repository->latest_bulk( [ 'albert/one', 'albert/two' ] );

		$this->assertArrayHasKey( 'albert/one', $map );
		$this->assertArrayHasKey( 'albert/two', $map );
		$this->assertSame( 10, (int) $map['albert/one']->user_id );
		$this->assertSame( 2, (int) $map['albert/two']->user_id );
	}

	/**
	 * Abilities without any logged rows are omitted from the result map.
	 *
	 * @return void
	 */
	public function test_latest_bulk_omits_abilities_without_rows(): void {
		$this->repository->insert( 'albert/exists', 1 );

		$map = $this->repository->latest_bulk( [ 'albert/exists', 'albert/ghost' ] );

		$this->assertArrayHasKey( 'albert/exists', $map );
		$this->assertArrayNotHasKey( 'albert/ghost', $map );
	}

	/**
	 * An ability name containing a single-quote is escaped via esc_sql.
	 *
	 * Guards against the IN-clause construction in latest_bulk() that
	 * interpolates after esc_sql(). A bypass would both crash the query
	 * and expose an injection surface.
	 *
	 * @return void
	 */
	public function test_latest_bulk_escapes_sql_injection_attempt(): void {
		$this->repository->insert( "albert/evil'; DROP TABLE x;--", 1 );

		$map = $this->repository->latest_bulk( [ "albert/evil'; DROP TABLE x;--" ] );

		$this->assertArrayHasKey( "albert/evil'; DROP TABLE x;--", $map );
	}

	// ─── recent() and latest_overall() ──────────────────────────────

	/**
	 * Returns the latest rows globally, newest first, capped by limit.
	 *
	 * @return void
	 */
	public function test_recent_returns_latest_rows_capped_by_limit(): void {
		$this->repository->insert( 'albert/a', 1 );
		$this->repository->insert( 'albert/b', 2 );
		$this->repository->insert( 'albert/c', 3 );
		$this->repository->insert( 'albert/d', 4 );

		$rows = $this->repository->recent( 2 );

		$this->assertCount( 2, $rows );
		$this->assertSame( 4, (int) $rows[0]->user_id );
	}

	/**
	 * Returns null when the table is empty.
	 *
	 * @return void
	 */
	public function test_latest_overall_returns_null_when_empty(): void {
		$this->assertNull( $this->repository->latest_overall() );
	}

	/**
	 * Picks the most recent across every ability.
	 *
	 * @return void
	 */
	public function test_latest_overall_picks_most_recent(): void {
		$this->repository->insert( 'albert/older', 1 );
		$this->repository->insert( 'albert/newer', 99 );

		$latest = $this->repository->latest_overall();

		$this->assertSame( 'albert/newer', $latest->ability_name );
		$this->assertSame( 99, (int) $latest->user_id );
	}

	// ─── prune_for_ability() with custom keep ───────────────────────

	/**
	 * Accepts a custom $keep value, overriding the default retention count.
	 *
	 * @return void
	 */
	public function test_prune_for_ability_respects_custom_keep(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->repository->insert( 'albert/custom', $i );
		}

		// insert() has already pruned to RETENTION_COUNT=2. Now keep only 1.
		$this->repository->prune_for_ability( 'albert/custom', 1 );

		$this->assertCount( 1, $this->all_rows_for( 'albert/custom' ) );
	}

	// ─── truncate() ─────────────────────────────────────────────────

	/**
	 * Clears every row in the log table.
	 *
	 * @return void
	 */
	public function test_truncate_clears_all_rows(): void {
		$this->repository->insert( 'albert/one', 1 );
		$this->repository->insert( 'albert/two', 2 );

		$this->repository->truncate();

		$this->assertNull( $this->repository->latest_overall() );
	}

	// ─── helpers ────────────────────────────────────────────────────

	/**
	 * Fetch every row for an ability — test helper, bypasses Repository.
	 *
	 * @param string $ability_name Ability id.
	 *
	 * @return array<int, object>
	 */
	private function all_rows_for( string $ability_name ): array {
		global $wpdb;

		$table = Installer::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test helper.
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE ability_name = %s ORDER BY id DESC',
				$table,
				$ability_name
			)
		);
	}
}
