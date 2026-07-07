<?php
/**
 * Minimal WP_REST_Request stub for unit tests.
 *
 * @package Albert\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Minimal WP_REST_Request stub.
	 *
	 * Carries just enough state for unit-level code under test: headers, method,
	 * route. Body/query handling is intentionally omitted — anything that needs
	 * it belongs in the integration suite against the real WordPress class.
	 */
	class WP_REST_Request implements \ArrayAccess {

		/**
		 * HTTP method.
		 *
		 * @var string
		 */
		protected string $method;

		/**
		 * Route.
		 *
		 * @var string
		 */
		protected string $route;

		/**
		 * Lower-cased header map.
		 *
		 * @var array<string, string>
		 */
		protected array $headers = [];

		/**
		 * Request parameters.
		 *
		 * @var array<string, mixed>
		 */
		protected array $params = [];

		/**
		 * Constructor.
		 *
		 * @param string $method HTTP method.
		 * @param string $route  Route.
		 */
		public function __construct( string $method = 'GET', string $route = '' ) {
			$this->method = $method;
			$this->route  = $route;
		}

		/**
		 * Get a header value.
		 *
		 * @param string $key Header name (case-insensitive).
		 *
		 * @return string|null
		 */
		public function get_header( string $key ): ?string {
			$key = strtolower( $key );

			return $this->headers[ $key ] ?? null;
		}

		/**
		 * Set a header value.
		 *
		 * @param string $key   Header name.
		 * @param string $value Header value.
		 */
		public function set_header( string $key, string $value ): void {
			$this->headers[ strtolower( $key ) ] = $value;
		}

		/**
		 * Set a request parameter.
		 *
		 * @param string $key   Parameter name.
		 * @param mixed  $value Parameter value.
		 */
		public function set_param( string $key, $value ): void {
			$this->params[ $key ] = $value;
		}

		/**
		 * Get a request parameter.
		 *
		 * @param string $key Parameter name.
		 *
		 * @return mixed
		 */
		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		/**
		 * Get all request parameters.
		 *
		 * @return array<string, mixed>
		 */
		public function get_params(): array {
			return $this->params;
		}

		/**
		 * Get the request method.
		 *
		 * @return string
		 */
		public function get_method(): string {
			return $this->method;
		}

		/**
		 * Get the route.
		 *
		 * @return string
		 */
		public function get_route(): string {
			return $this->route;
		}

		/**
		 * Whether a parameter is set (ArrayAccess).
		 *
		 * @param mixed $offset Parameter name.
		 *
		 * @return bool
		 */
		public function offsetExists( mixed $offset ): bool {
			return isset( $this->params[ $offset ] );
		}

		/**
		 * Get a parameter (ArrayAccess).
		 *
		 * @param mixed $offset Parameter name.
		 *
		 * @return mixed
		 */
		public function offsetGet( mixed $offset ): mixed {
			return $this->params[ $offset ] ?? null;
		}

		/**
		 * Set a parameter (ArrayAccess).
		 *
		 * @param mixed $offset Parameter name.
		 * @param mixed $value  Parameter value.
		 *
		 * @return void
		 */
		public function offsetSet( mixed $offset, mixed $value ): void {
			$this->params[ $offset ] = $value;
		}

		/**
		 * Unset a parameter (ArrayAccess).
		 *
		 * @param mixed $offset Parameter name.
		 *
		 * @return void
		 */
		public function offsetUnset( mixed $offset ): void {
			unset( $this->params[ $offset ] );
		}
	}
}

// phpcs:enable
