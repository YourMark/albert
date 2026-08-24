<?php
/**
 * Integration tests for the RFC 7591 dynamic client registration response.
 *
 * Covers the 1.5.0 fix for two related interop gaps: the registration
 * response was missing client_id_issued_at, grant_types, response_types,
 * scope, and (for confidential clients) client_secret_expires_at — all
 * present in the RFC, several of them REQUIRED. Separately, every
 * loopback/native client (RFC 8252 — every local MCP bridge) is registered
 * with 'none' as its auth method, which the server metadata previously did
 * not declare as supported at all.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\OAuth;

use Albert\OAuth\Endpoints\ClientRegistration;
use Albert\Tests\TestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * ClientRegistration integration tests.
 *
 * @covers \Albert\OAuth\Endpoints\ClientRegistration
 */
class ClientRegistrationTest extends TestCase {

	/**
	 * Subject under test.
	 *
	 * @var ClientRegistration
	 */
	private ClientRegistration $registration;

	/**
	 * Reset state before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->registration = new ClientRegistration();
	}

	/**
	 * Build a registration request with a JSON body.
	 *
	 * @param array<string, mixed> $body Request body.
	 *
	 * @return WP_REST_Request
	 */
	private function request( array $body ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/albert/v1/oauth/register' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );

		return $request;
	}

	/**
	 * A loopback client (RFC 8252, native/local — every MCP bridge) is public:
	 * 'none' auth method, no secret, but every other RFC 7591 field present.
	 *
	 * @return void
	 */
	public function test_public_client_response_carries_required_rfc7591_fields(): void {
		$response = $this->registration->handle_registration(
			$this->request(
				[
					'client_name'   => 'Test MCP Bridge',
					'redirect_uris' => [ 'http://localhost:51000/callback' ],
				]
			)
		);

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();

		$this->assertSame( 'none', $data['token_endpoint_auth_method'] );
		$this->assertArrayNotHasKey( 'client_secret', $data );
		$this->assertArrayNotHasKey( 'client_secret_expires_at', $data );

		$this->assertIsInt( $data['client_id_issued_at'] );
		$this->assertSame( [ 'authorization_code', 'refresh_token' ], $data['grant_types'] );
		$this->assertSame( [ 'code' ], $data['response_types'] );
		$this->assertSame( 'default', $data['scope'] );
	}

	/**
	 * A confidential (https redirect) client gets a secret, and
	 * client_secret_expires_at — REQUIRED by RFC 7591 §3.2.1 whenever a
	 * client_secret is issued — is present alongside it.
	 *
	 * @return void
	 */
	public function test_confidential_client_response_carries_secret_expiry(): void {
		// A literal public IP, not a hostname: is_safe_https_host() only does a
		// DNS lookup for hostnames, and this sandbox's resolver does not behave
		// like the open internet (everything resolves into reserved space),
		// which would otherwise reject any https hostname here regardless of
		// how legitimate it is.
		$response = $this->registration->handle_registration(
			$this->request(
				[
					'client_name'   => 'Test Confidential Client',
					'redirect_uris' => [ 'https://1.1.1.1/callback' ],
				]
			)
		);

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();

		$this->assertSame( 'client_secret_post', $data['token_endpoint_auth_method'] );
		$this->assertNotEmpty( $data['client_secret'] );
		$this->assertSame( 0, $data['client_secret_expires_at'] );
	}
}
