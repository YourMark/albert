<?php
/**
 * Integration tests for the OAuth ScopeRepository.
 *
 * ScopeRepository is a pure-logic class with no WP or DB coupling, but it
 * lives under Integration because Albert treats all OAuth tests as one
 * suite — placing it here keeps discoverability consistent.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\OAuth\Repositories;

use Albert\OAuth\Entities\ClientEntity;
use Albert\OAuth\Entities\ScopeEntity;
use Albert\OAuth\Repositories\ScopeRepository;
use Albert\Tests\TestCase;

/**
 * ScopeRepository integration tests.
 *
 * @covers \Albert\OAuth\Repositories\ScopeRepository
 */
class ScopeRepositoryTest extends TestCase {

	/**
	 * Returns the default scope when the identifier matches.
	 *
	 * @return void
	 */
	public function test_resolves_default_scope(): void {
		$repository = new ScopeRepository();

		$scope = $repository->getScopeEntityByIdentifier( 'default' );

		$this->assertInstanceOf( ScopeEntity::class, $scope );
		$this->assertSame( 'default', $scope->getIdentifier() );
	}

	/**
	 * Returns null for any unknown scope identifier.
	 *
	 * The repository does not silently upgrade unknown scopes to default —
	 * that would be an authorisation bypass if a future grant type started
	 * trusting scopes.
	 *
	 * @return void
	 */
	public function test_returns_null_for_unknown_scope(): void {
		$repository = new ScopeRepository();

		$this->assertNull( $repository->getScopeEntityByIdentifier( 'admin' ) );
		$this->assertNull( $repository->getScopeEntityByIdentifier( '' ) );
	}

	/**
	 * Always collapses to the default scope regardless of request.
	 *
	 * Albert uses WP capabilities for authorisation, not OAuth scopes —
	 * regardless of what the client requests, the server returns only
	 * the `default` scope.
	 *
	 * @return void
	 */
	public function test_finalize_scopes_always_returns_default(): void {
		$repository = new ScopeRepository();
		$client     = new ClientEntity();
		$client->setIdentifier( 'cli' );

		$requested = [ new ScopeEntity( 'admin' ), new ScopeEntity( 'write' ) ];

		$finalized = $repository->finalizeScopes( $requested, 'authorization_code', $client );

		$this->assertCount( 1, $finalized );
		$this->assertSame( 'default', $finalized[0]->getIdentifier() );
	}
}
