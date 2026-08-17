<?php
/**
 * Minimal WP_Abilities_Registry stub for unit tests.
 *
 * Albert's bookkeeping paths read the registry directly rather than through
 * `wp_get_abilities()`, so that the WordPress 7.1 filter pipeline cannot narrow
 * what Albert believes is registered — see
 * {@see \Albert\Core\AbilitiesRegistry::get_all_raw()}.
 *
 * This stub is backed by the same `$GLOBALS['albert_test_abilities']` array the
 * `wp_get_abilities()` stub reads, so a test populates one global and both
 * access paths agree. Keeping them in sync is deliberate: a test that sets up
 * abilities should not have to know which of the two paths the code under test
 * happens to use.
 *
 * @package Albert
 */

if ( ! class_exists( 'WP_Abilities_Registry' ) ) {
	/**
	 * Stub registry exposing the singleton accessor and the raw getter.
	 */
	class WP_Abilities_Registry {

		/**
		 * The singleton instance.
		 *
		 * @var WP_Abilities_Registry|null
		 */
		private static ?WP_Abilities_Registry $instance = null;

		/**
		 * Whether get_instance() should report the registry as unavailable.
		 *
		 * @var bool
		 */
		private static bool $unavailable = false;

		/**
		 * Get the singleton instance.
		 *
		 * Core's signature is nullable — it returns null before the registry is
		 * initialised — so the stub keeps the nullable return type to match, and
		 * tests can reproduce that state via {@see self::set_unavailable()}.
		 *
		 * @return WP_Abilities_Registry|null
		 */
		public static function get_instance(): ?WP_Abilities_Registry {
			if ( self::$unavailable ) {
				return null;
			}

			if ( self::$instance === null ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Make get_instance() return null, as core does before the API boots.
		 *
		 * @param bool $unavailable Whether the registry should report as absent.
		 *
		 * @return void
		 */
		public static function set_unavailable( bool $unavailable ): void {
			self::$unavailable = $unavailable;
		}

		/**
		 * Reset the stub to its default, available state.
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$instance    = null;
			self::$unavailable = false;
		}

		/**
		 * All registered abilities, keyed by ability name.
		 *
		 * Reads the same global as the `wp_get_abilities()` stub. Entries are
		 * re-keyed by name so the shape matches core, whose registry is a name =>
		 * ability map, even when a test supplies a plain list.
		 *
		 * @return array<string, object>
		 */
		public function get_all_registered(): array {
			$abilities = (array) ( $GLOBALS['albert_test_abilities'] ?? [] );
			$keyed     = [];

			foreach ( $abilities as $key => $ability ) {
				$name = is_object( $ability ) && method_exists( $ability, 'get_name' )
					? (string) $ability->get_name()
					: (string) $key;

				$keyed[ $name ] = $ability;
			}

			return $keyed;
		}
	}
}
