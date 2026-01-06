<?php
/**
 * OAuth Scope Repository
 *
 * @package    ExtendedAbilities
 * @subpackage OAuth\Repositories
 * @since      1.1.0
 */

namespace ExtendedAbilities\OAuth\Repositories;

use ExtendedAbilities\OAuth\Entities\ScopeEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

/**
 * ScopeRepository class
 *
 * Minimal scope implementation - this plugin uses user capabilities
 * instead of OAuth scopes for authorization.
 *
 * @since 1.1.0
 */
class ScopeRepository implements ScopeRepositoryInterface {

	/**
	 * Return information about a scope.
	 *
	 * @param string $identifier The scope identifier to search for.
	 *
	 * @return ScopeEntityInterface|null The scope entity or null.
	 * @since 1.1.0
	 */
	public function getScopeEntityByIdentifier( $identifier ): ?ScopeEntityInterface {
		// We only support a default scope.
		if ( 'default' === $identifier ) {
			return new ScopeEntity( 'default' );
		}

		return null;
	}

	/**
	 * Given a client, grant type and optional user identifier validate the set of scopes requested.
	 *
	 * @param ScopeEntityInterface[] $scopes        The scopes requested.
	 * @param string                 $grant_type    The grant type used.
	 * @param ClientEntityInterface  $client_entity The client entity.
	 * @param string|null            $user_id       The user identifier (optional).
	 *
	 * @return ScopeEntityInterface[] The validated scopes.
	 * @since 1.1.0
	 */
	public function finalizeScopes(
		array $scopes,
		$grant_type,
		ClientEntityInterface $client_entity,
		$user_id = null
	): array {
		// Always return the default scope.
		// Actual authorization is based on WordPress user capabilities.
		return [ new ScopeEntity( 'default' ) ];
	}
}
