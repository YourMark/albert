<?php
/**
 * OAuth REST API Controller
 *
 * @package Albert
 * @subpackage OAuth\Endpoints
 * @since      1.0.0
 */

namespace Albert\OAuth\Endpoints;

defined( 'ABSPATH' ) || exit;

use Exception;
use Albert\Contracts\Interfaces\Hookable;
use Albert\Core\Plugin;
use Albert\OAuth\Server\AuthorizationServerFactory;
use League\OAuth2\Server\Exception\OAuthServerException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * OAuthController class
 *
 * Handles OAuth 2.0 REST API endpoints.
 *
 * @since 1.0.0
 */
class OAuthController implements Hookable {

	/**
	 * REST API namespace.
	 *
	 * @deprecated 1.0.1 Use {@see Plugin::rest_namespace()} instead.
	 * @since      1.0.0
	 * @var string
	 */
	const NAMESPACE = 'albert/v1';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_routes(): void {
		// OAuth Authorization Server Metadata (alternative to .well-known).
		register_rest_route(
			Plugin::rest_namespace(),
			'/oauth/metadata',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_authorization_server_metadata' ],
				'permission_callback' => '__return_true',
			]
		);

		// OAuth Protected Resource Metadata (alternative to .well-known).
		register_rest_route(
			Plugin::rest_namespace(),
			'/oauth/resource',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_protected_resource_metadata' ],
				'permission_callback' => '__return_true',
			]
		);

		// Token endpoint - exchanges code for tokens.
		register_rest_route(
			Plugin::rest_namespace(),
			'/oauth/token',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_token' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Handle token request (POST).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The REST request.
	 *
	 * @return WP_REST_Response The response.
	 * @since 1.0.0
	 */
	public function handle_token( WP_REST_Request $request ): WP_REST_Response {
		try {
			$server       = AuthorizationServerFactory::create();
			$psr_request  = Psr7Bridge::to_psr7_request( $request );
			$psr_response = Psr7Bridge::create_response();

			$psr_response = $server->respondToAccessTokenRequest( $psr_request, $psr_response );

			return Psr7Bridge::to_wp_response( $psr_response );
		} catch ( OAuthServerException $e ) {
			$response = Psr7Bridge::create_response();
			$response = $e->generateHttpResponse( $response );

			return Psr7Bridge::to_wp_response( $response );
		} catch ( Exception $e ) {
			return new WP_REST_Response(
				[
					'error'             => 'server_error',
					'error_description' => $e->getMessage(),
				],
				500
			);
		}
	}

	/**
	 * Handle OAuth Authorization Server Metadata request.
	 *
	 * Returns RFC 8414 metadata for OAuth clients to discover endpoints.
	 *
	 * @return WP_REST_Response The metadata response.
	 * @since 1.0.0
	 */
	public function handle_authorization_server_metadata(): WP_REST_Response {
		$base_url = $this->get_base_url();

		$metadata = [
			'issuer'                                => $base_url,
			'authorization_endpoint'                => $base_url . '/oauth/authorize',
			'token_endpoint'                        => $this->get_rest_url( Plugin::rest_namespace() . '/oauth/token' ),
			'registration_endpoint'                 => $this->get_rest_url( Plugin::rest_namespace() . '/oauth/register' ),
			'response_types_supported'              => [ 'code' ],
			'grant_types_supported'                 => [ 'authorization_code', 'refresh_token' ],
			'token_endpoint_auth_methods_supported' => [ 'client_secret_post', 'client_secret_basic' ],
			'code_challenge_methods_supported'      => [ 'S256' ],
			'scopes_supported'                      => [ 'default' ],
		];

		$response = new WP_REST_Response( $metadata, 200 );
		$response->header( 'Cache-Control', 'public, max-age=3600' );

		return $response;
	}

	/**
	 * Handle OAuth Protected Resource Metadata request.
	 *
	 * Returns RFC 9728 metadata that tells MCP clients where to find the authorization server.
	 *
	 * @return WP_REST_Response The metadata response.
	 * @since 1.0.0
	 */
	public function handle_protected_resource_metadata(): WP_REST_Response {
		$base_url = $this->get_base_url();

		$metadata = [
			'resource'              => $this->get_rest_url( Plugin::rest_namespace() . '/mcp' ),
			'authorization_servers' => [ $this->get_rest_url( Plugin::rest_namespace() . '/oauth/metadata' ) ],
			'scopes_supported'      => [ 'default' ],
		];

		$response = new WP_REST_Response( $metadata, 200 );
		$response->header( 'Cache-Control', 'public, max-age=3600' );

		return $response;
	}

	/**
	 * Get the base URL for OAuth endpoints.
	 *
	 * Uses the external URL setting if configured and developer mode is enabled,
	 * otherwise falls back to home_url().
	 *
	 * @return string The base URL.
	 * @since 1.0.0
	 */
	private function get_base_url(): string {
		$external_url = (string) apply_filters( 'albert/mcp/external_url', '' );
		$external_url = rtrim( $external_url, '/' );

		if ( $external_url !== '' ) {
			$validated = wp_http_validate_url( $external_url );
			if ( $validated !== false ) {
				return $validated;
			}
		}

		return home_url();
	}

	/**
	 * Get a REST URL using the current base URL.
	 *
	 * @param string $path The REST route path.
	 *
	 * @return string The full REST URL.
	 * @since 1.0.0
	 */
	private function get_rest_url( string $path ): string {
		$base_url = $this->get_base_url();
		return $base_url . '/wp-json/' . ltrim( $path, '/' );
	}
}
