<?php
/**
 * MCP Server with OAuth Authentication
 *
 * @package Albert
 * @subpackage MCP
 * @since      1.0.0
 */

namespace Albert\MCP;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\Core\Plugin;
use Albert\Logging\ObservabilityHandler;
use Albert\MCP\Skills\SkillLoader;
use Albert\OAuth\Server\TokenValidator;
use Albert\Vendor\WP\MCP\Core\McpAdapter;
use Albert\Vendor\WP\MCP\Domain\Prompts\McpPrompt;
use Albert\Vendor\WP\MCP\Domain\Resources\McpResource;
use Albert\Vendor\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use Albert\Vendor\WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;
use Albert\Vendor\WP\MCP\Transport\HttpTransport;
use WP_Error;
use WP_REST_Request;

/**
 * Server class
 *
 * Creates and configures an MCP server that authenticates via OAuth 2.0 Bearer tokens.
 * This allows AI clients like Claude Desktop to connect using OAuth authentication.
 *
 * @since 1.0.0
 */
class Server implements Hookable {

	/**
	 * Server ID.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const SERVER_ID = 'albert';

	/**
	 * Server route.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const ROUTE = 'mcp';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_hooks(): void {
		add_action( 'mcp_adapter_init', [ $this, 'create_server' ] );
		add_filter( 'rest_request_before_callbacks', [ $this, 'add_oauth_discovery_headers' ], 10, 3 );

		// Improve LLM-facing tool errors and log failures rejected before the
		// ability runs (e.g. input-schema validation).
		( new ToolCallObserver() )->register_hooks();
	}

	/**
	 * Add OAuth discovery headers for unauthorized MCP requests.
	 *
	 * When a request to our MCP endpoint fails authentication, we need to tell
	 * the client where to find OAuth authorization server metadata.
	 *
	 * @param mixed                                 $response The response.
	 * @param array<string, mixed>                  $handler  The handler.
	 * @param WP_REST_Request<array<string, mixed>> $request  The request.
	 *
	 * @return mixed The response.
	 * @since 1.0.0
	 */
	public function add_oauth_discovery_headers( $response, $handler, $request ) {
		// Only handle our MCP endpoint.
		$route = $request->get_route();
		if ( strpos( $route, '/' . Plugin::rest_namespace() . '/' . self::ROUTE ) === false ) {
			return $response;
		}

		// Check if there's no Bearer token - add discovery headers.
		$token = TokenValidator::get_bearer_token( $request );
		if ( empty( $token ) ) {
			// Send headers for OAuth discovery per MCP spec (RFC 6750).
			// Point to REST API resource endpoint for OAuth discovery.
			$resource_url = self::get_base_url() . '/wp-json/' . Plugin::rest_namespace() . '/oauth/resource';
			header( 'WWW-Authenticate: Bearer realm="MCP", resource="' . $resource_url . '"' );
		}

		return $response;
	}

	/**
	 * Create the MCP server.
	 *
	 * Bound to the global `mcp_adapter_init` action, which is fired by EVERY loaded
	 * copy of the MCP adapter — including the unscoped `WP\MCP\…` copy WooCommerce
	 * bundles. Mozart only rewrites class names, not the literal hook string, so
	 * both our scoped adapter and a foreign one fire the same action. We must build
	 * the Albert server only against our own Mozart-scoped adapter; any other
	 * instance is ignored (otherwise a foreign copy triggers a TypeError here, or
	 * we'd build our server against the wrong adapter).
	 *
	 * @param object $adapter The MCP adapter instance fired on the global hook.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function create_server( object $adapter ): void {
		if ( ! $adapter instanceof McpAdapter ) {
			return;
		}

		/**
		 * Filters the MCP observability handler class used for the Albert server.
		 *
		 * Premium (or any addon) can replace Free's handler by returning a
		 * class-string that implements McpObservabilityHandlerInterface.
		 * The class must be instantiable with no constructor arguments.
		 * Classes that do not implement the interface are ignored and the
		 * default handler is used instead.
		 *
		 * @since 1.2.0
		 *
		 * @param class-string<McpObservabilityHandlerInterface> $handler_class Fully-qualified class name. Default ObservabilityHandler::class.
		 */
		$filtered = apply_filters( 'albert/mcp/observability_handler', ObservabilityHandler::class );

		// Validate the filtered value implements the required interface;
		// fall back to the default handler if the class is unknown or invalid.
		$observability_handler = ObservabilityHandler::class;
		if (
			is_string( $filtered )
			&& class_exists( $filtered )
			&& is_a( $filtered, McpObservabilityHandlerInterface::class, true )
		) {
			$observability_handler = $filtered;
		}

		$adapter->create_server(
			self::SERVER_ID,
			Plugin::rest_namespace(),
			self::ROUTE,
			__( 'Albert MCP Server', 'albert-ai-butler' ),
			__( 'MCP server for AI assistants to interact with WordPress', 'albert-ai-butler' ),
			ALBERT_VERSION,
			[ HttpTransport::class ],
			ErrorLogMcpErrorHandler::class,
			$observability_handler,
			$this->get_tools(),
			$this->get_resources(),
			$this->get_prompts(),
			[ $this, 'permission_callback' ]
		);
	}

	/**
	 * The MCP adapter's own tool abilities, registered on every Albert server.
	 *
	 * Required for protocol discovery and execution — unregistering any of them
	 * breaks MCP entirely — so they are also treated as protected abilities that
	 * can never be disabled. See {@see AbilitiesRegistry::get_protected_abilities()}.
	 *
	 * @since 1.3.0
	 * @var list<string>
	 */
	public const CORE_TOOL_ABILITIES = [
		'mcp-adapter/discover-abilities',
		'mcp-adapter/get-ability-info',
		'mcp-adapter/execute-ability',
	];

	/**
	 * Get the tools to register for this server.
	 *
	 * Uses the same core abilities as the default MCP server.
	 *
	 * @return list<string> The tool names.
	 * @since 1.0.0
	 */
	private function get_tools(): array {
		return self::CORE_TOOL_ABILITIES;
	}

	/**
	 * Get the resources to register for this server.
	 *
	 * Resources are built McpResource instances (not abilities), assembled by
	 * {@see ResourceLoader} — mirroring how prompts are supplied.
	 *
	 * @return list<McpResource> The resource instances to expose.
	 * @since 1.2.0
	 */
	private function get_resources(): array {
		return ( new ResourceLoader() )->resources();
	}

	/**
	 * Get the prompts (skills) to register for this server.
	 *
	 * Skills are bundled Markdown playbooks loaded as built McpPrompt instances
	 * (not abilities), mirroring how resources are supplied. See {@see SkillLoader}.
	 *
	 * @return array<int, McpPrompt> The prompt instances to expose.
	 * @since 1.2.0
	 */
	private function get_prompts(): array {
		return ( new SkillLoader() )->prompts();
	}

	/**
	 * Permission callback for OAuth authentication.
	 *
	 * Validates OAuth 2.0 Bearer tokens and sets the current WordPress user.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The REST request.
	 *
	 * @return bool|WP_Error True if authenticated, WP_Error otherwise.
	 * @since 1.0.0
	 */
	public function permission_callback( WP_REST_Request $request ): bool|WP_Error {
		// Check for Bearer token.
		$token = TokenValidator::get_bearer_token( $request );

		if ( empty( $token ) ) {
			return new WP_Error(
				'oauth_missing_token',
				__( 'OAuth Bearer token required. Include an Authorization header with a valid Bearer token.', 'albert-ai-butler' ),
				[ 'status' => 401 ]
			);
		}

		// Validate the token.
		$user = TokenValidator::validate_request( $request );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		// Set the current user for the request.
		wp_set_current_user( $user->ID );

		return true;
	}

	/**
	 * Get the base URL for OAuth endpoints.
	 *
	 * Consults the `albert/mcp/external_url` filter. Returns `home_url()` when
	 * the filter is empty or returns a value that fails
	 * {@see wp_http_validate_url()}.
	 *
	 * @return string The base URL.
	 * @since 1.0.0
	 */
	public static function get_base_url(): string {
		$state = self::get_external_url_state();

		if ( $state['state'] === 'active' ) {
			return $state['value'];
		}

		return home_url();
	}

	/**
	 * Get the server endpoint URL.
	 *
	 * Consults the `albert/mcp/external_url` filter for the base URL. If the
	 * filter returns a non-empty value that fails {@see wp_http_validate_url()},
	 * emits a `_doing_it_wrong()` notice and falls back to {@see rest_url()}.
	 *
	 * @return string The full URL to the MCP server endpoint.
	 * @since 1.0.0
	 */
	public static function get_endpoint_url(): string {
		$state = self::get_external_url_state();

		if ( $state['state'] === 'active' ) {
			return $state['value'] . '/wp-json/' . Plugin::rest_namespace() . '/' . self::ROUTE;
		}

		return rest_url( Plugin::rest_namespace() . '/' . self::ROUTE );
	}

	/**
	 * Get the current state of the `albert/mcp/external_url` filter.
	 *
	 * Used by both the endpoint resolver and the Connections admin screen.
	 * The filter is evaluated once per request and the result is memoised.
	 *
	 * Possible states:
	 *  - `inactive` — filter returns an empty string (no override).
	 *  - `active`   — filter returns a non-empty, valid URL; `value` is the URL.
	 *  - `invalid`  — filter returns a non-empty string that fails
	 *                 {@see wp_http_validate_url()}; `value` is the raw filter
	 *                 output so the UI can surface it to the admin.
	 *
	 * @since 1.1.0
	 *
	 * @return array{state: 'inactive'|'active'|'invalid', value: string}
	 */
	public static function get_external_url_state(): array {
		static $cache = null;
		if ( $cache !== null ) {
			return $cache;
		}

		/**
		 * Filters the external base URL used for the MCP endpoint.
		 *
		 * Return a fully-qualified URL (including scheme) to replace the host
		 * portion of the MCP endpoint — useful when the site is reachable
		 * through a tunnel or reverse proxy during development. Return an
		 * empty string (the default) to use {@see rest_url()} as-is.
		 *
		 * Invalid URLs are ignored with a `_doing_it_wrong()` notice.
		 *
		 * @since 1.1.0
		 *
		 * @param string $external_url Empty string by default.
		 */
		$filtered = (string) apply_filters( 'albert/mcp/external_url', '' );
		$filtered = rtrim( $filtered, '/' );

		if ( $filtered === '' ) {
			$cache = [
				'state' => 'inactive',
				'value' => '',
			];
			return $cache;
		}

		$validated = wp_http_validate_url( $filtered );
		if ( $validated === false ) {
			_doing_it_wrong(
				'albert/mcp/external_url',
				sprintf(
					/* translators: %s: invalid URL returned by the filter */
					esc_html__( 'Filter returned an invalid URL: %s. Falling back to rest_url().', 'albert-ai-butler' ),
					esc_html( $filtered )
				),
				'1.1.0'
			);
			$cache = [
				'state' => 'invalid',
				'value' => $filtered,
			];
			return $cache;
		}

		$cache = [
			'state' => 'active',
			'value' => $validated,
		];
		return $cache;
	}
}
