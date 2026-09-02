<?php
/**
 * Minimal WP_Ability stub for unit tests.
 *
 * Only the accessors Albert reads are implemented: get_name / get_meta for
 * AbilitiesRegistry::resolve_required_capability(), and get_input_schema for
 * MCP\ToolCallObserver.
 *
 * @package Albert\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

if ( ! class_exists( 'WP_Ability' ) ) {
	/**
	 * Stub WP_Ability for unit tests.
	 */
	class WP_Ability {

		/**
		 * Ability name (ID).
		 *
		 * @var string
		 */
		private string $name;

		/**
		 * Ability meta.
		 *
		 * @var array<string, mixed>
		 */
		private array $meta;

		/**
		 * Input schema.
		 *
		 * @var array<string, mixed>
		 */
		private array $input_schema;

		/**
		 * Constructor.
		 *
		 * @param string               $name         Ability name (ID).
		 * @param array<string, mixed> $meta         Ability meta.
		 * @param array<string, mixed> $input_schema Input schema.
		 */
		public function __construct( string $name, array $meta = [], array $input_schema = [] ) {
			$this->name         = $name;
			$this->meta         = $meta;
			$this->input_schema = $input_schema;
		}

		/**
		 * Get the ability name.
		 *
		 * @return string
		 */
		public function get_name(): string {
			return $this->name;
		}

		/**
		 * Get the ability meta.
		 *
		 * @return array<string, mixed>
		 */
		public function get_meta(): array {
			return $this->meta;
		}

		/**
		 * Get the input schema.
		 *
		 * @return array<string, mixed>
		 */
		public function get_input_schema(): array {
			return $this->input_schema;
		}
	}
}

// phpcs:enable
