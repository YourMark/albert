<?php
/**
 * Minimal WP_REST_Response stub for unit tests.
 *
 * @package Albert\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Minimal WP_REST_Response stub — carries the response data and status.
	 */
	class WP_REST_Response {

		/**
		 * Response data.
		 *
		 * @var mixed
		 */
		protected $data;

		/**
		 * HTTP status code.
		 *
		 * @var int
		 */
		protected int $status;

		/**
		 * Response headers.
		 *
		 * @var array<string, mixed>
		 */
		protected array $headers = [];

		/**
		 * Constructor.
		 *
		 * @param mixed $data   Response data.
		 * @param int   $status HTTP status code.
		 */
		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		/**
		 * Set a response header.
		 *
		 * @param string $key     Header name.
		 * @param mixed  $value   Header value.
		 * @param bool   $replace Whether to replace an existing header of the same name (ignored by the stub).
		 *
		 * @return void
		 */
		public function header( string $key, $value, bool $replace = true ): void {
			$this->headers[ $key ] = $value;
		}

		/**
		 * Get the response headers.
		 *
		 * @return array<string, mixed>
		 */
		public function get_headers(): array {
			return $this->headers;
		}

		/**
		 * Get the response data.
		 *
		 * @return mixed
		 */
		public function get_data() {
			return $this->data;
		}

		/**
		 * Get the HTTP status code.
		 *
		 * @return int
		 */
		public function get_status(): int {
			return $this->status;
		}
	}
}

// phpcs:enable
