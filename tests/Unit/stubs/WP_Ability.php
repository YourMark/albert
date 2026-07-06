<?php
/**
 * Minimal WP_Ability stub for unit tests.
 *
 * Only the accessors AbilitiesRegistry::resolve_required_capability() reads
 * (get_name / get_meta) are implemented.
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
		 * Constructor.
		 *
		 * @param string               $name Ability name (ID).
		 * @param array<string, mixed> $meta Ability meta.
		 */
		public function __construct( string $name, array $meta = [] ) {
			$this->name = $name;
			$this->meta = $meta;
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
	}
}

// phpcs:enable
