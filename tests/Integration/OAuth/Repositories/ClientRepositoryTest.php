<?php
/**
 * Integration tests for the OAuth ClientRepository.
 *
 * Covers persistence, secret hashing, and the validateClient security
 * contract. The secret hashing path is the most critical part: a regression
 * that stores or compares plain-text would be catastrophic.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\OAuth\Repositories;

use Albert\OAuth\Database\Installer;
use Albert\OAuth\Repositories\ClientRepository;
use Albert\Tests\TestCase;

/**
 * ClientRepository integration tests.
 *
 * @covers \Albert\OAuth\Repositories\ClientRepository
 */
class ClientRepositoryTest extends TestCase {

	/**
	 * Repository under test.
	 *
	 * @var ClientRepository
	 */
	private ClientRepository $repository;

	/**
	 * Reset the clients table before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::install();
		$this->repository = new ClientRepository();

		global $wpdb;
		$tables = Installer::get_table_names();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test reset.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $tables['clients'] ) );
	}

	// ─── createClient / getClientEntity ─────────────────────────────

	/**
	 * Returns a generated client id prefixed with `albert_` plus a secret.
	 *
	 * @return void
	 */
	public function test_create_client_returns_id_and_secret(): void {
		$result = $this->repository->createClient( 'Test Client', 'https://example.test/cb' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'client_id', $result );
		$this->assertArrayHasKey( 'client_secret', $result );
		$this->assertStringStartsWith( 'albert_', $result['client_id'] );
		$this->assertNotEmpty( $result['client_secret'] );
	}

	/**
	 * The stored secret is hashed, never the plain value.
	 *
	 * @return void
	 */
	public function test_create_client_hashes_secret(): void {
		$result = $this->repository->createClient( 'Secret', 'https://example.test/cb' );

		$entity = $this->repository->getClientEntity( $result['client_id'] );
		$stored = $entity->getClientSecret();

		$this->assertNotSame( $result['client_secret'], $stored, 'Stored secret should be hashed.' );
		$this->assertTrue(
			wp_check_password( $result['client_secret'], $stored ),
			'Hashed secret should verify against the plain value.'
		);
	}

	/**
	 * Non-confidential clients do not receive a secret.
	 *
	 * @return void
	 */
	public function test_non_confidential_client_has_no_secret(): void {
		$result = $this->repository->createClient(
			'Public Client',
			'https://example.test/cb',
			false
		);

		$this->assertNull( $result['client_secret'] );

		$entity = $this->repository->getClientEntity( $result['client_id'] );
		$this->assertFalse( $entity->isConfidential() );
	}

	/**
	 * Unknown client id returns null.
	 *
	 * @return void
	 */
	public function test_get_client_entity_returns_null_for_unknown_id(): void {
		$this->assertNull( $this->repository->getClientEntity( 'albert_missing' ) );
	}

	// ─── validateClient ─────────────────────────────────────────────

	/**
	 * Passes with matching id and secret for a confidential client.
	 *
	 * @return void
	 */
	public function test_validate_client_accepts_correct_secret(): void {
		$result = $this->repository->createClient( 'Confidential', 'https://example.test/cb' );

		$this->assertTrue(
			$this->repository->validateClient(
				$result['client_id'],
				$result['client_secret'],
				'authorization_code'
			)
		);
	}

	/**
	 * Rejects a wrong secret.
	 *
	 * @return void
	 */
	public function test_validate_client_rejects_incorrect_secret(): void {
		$result = $this->repository->createClient( 'Confidential', 'https://example.test/cb' );

		$this->assertFalse(
			$this->repository->validateClient(
				$result['client_id'],
				'wrong-secret',
				'authorization_code'
			)
		);
	}

	/**
	 * Rejects an empty or null secret on a confidential client.
	 *
	 * @return void
	 */
	public function test_validate_client_rejects_empty_secret_on_confidential(): void {
		$result = $this->repository->createClient( 'Confidential', 'https://example.test/cb' );

		$this->assertFalse(
			$this->repository->validateClient( $result['client_id'], '', 'authorization_code' )
		);
		$this->assertFalse(
			$this->repository->validateClient( $result['client_id'], null, 'authorization_code' )
		);
	}

	/**
	 * Returns false for an unknown client id.
	 *
	 * @return void
	 */
	public function test_validate_client_rejects_unknown_id(): void {
		$this->assertFalse(
			$this->repository->validateClient( 'albert_missing', 'anything', 'authorization_code' )
		);
	}

	// ─── deleteClient ───────────────────────────────────────────────

	/**
	 * Removes the client row from the table on delete.
	 *
	 * @return void
	 */
	public function test_delete_client_removes_the_row(): void {
		$result = $this->repository->createClient( 'Temp', 'https://example.test/cb' );

		$this->assertTrue( $this->repository->deleteClient( $result['client_id'] ) );
		$this->assertNull( $this->repository->getClientEntity( $result['client_id'] ) );
	}

	// ─── getClientsByUser ───────────────────────────────────────────

	/**
	 * Filters clients by user_id.
	 *
	 * Ordering is asserted only to the extent it can be: within-second tie-
	 * breaking is implementation-defined by MySQL when the source query only
	 * orders on created_at without a secondary sort key. We verify the
	 * filtering (only user_a's rows come back) and the count, not the
	 * within-test ordering between freshly-inserted rows.
	 *
	 * @return void
	 */
	public function test_get_clients_by_user_filters_by_user(): void {
		$user_a = self::factory()->user->create();
		$user_b = self::factory()->user->create();

		$this->repository->createClient( 'A-one', 'https://a.test/cb', true, $user_a );
		$this->repository->createClient( 'B-one', 'https://b.test/cb', true, $user_b );
		$this->repository->createClient( 'A-two', 'https://a.test/cb2', true, $user_a );

		$a_clients = $this->repository->getClientsByUser( $user_a );
		$a_names   = array_map( static fn ( $c ): string => $c->getName(), $a_clients );

		$this->assertCount( 2, $a_clients );
		$this->assertContains( 'A-one', $a_names );
		$this->assertContains( 'A-two', $a_names );
		$this->assertNotContains( 'B-one', $a_names );
	}
}
