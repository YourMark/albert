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
