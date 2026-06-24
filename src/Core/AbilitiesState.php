<?php
/**
 * Abilities State
 *
 * Single owner of the enabled/disabled state for registered abilities.
 *
 * @package Albert
 * @subpackage Core
 * @since      1.3.0
 */

namespace Albert\Core;

defined( 'ABSPATH' ) || exit;

/**
 * AbilitiesState class
 *
 * Centralizes read/write access to the `albert_disabled_abilities` blocklist
 * option. The admin page, the REST API, and the legacy AJAX handler all go
 * through here so the option is mutated identically everywhere — there is one
 * place that knows the fresh-install default and the "saved" marker.
 *
 * @since 1.3.0
 */
class AbilitiesState {

	/**
	 * Option storing the disabled-abilities blocklist (array of ability IDs).
	 *
	 * @since 1.3.0
	 * @var string
	 */
	public const OPTION = 'albert_disabled_abilities';

	/**
	 * Option flag set once the admin has saved their toggles at least once.
	 *
	 * Until it is set, an empty blocklist means "use the fresh-install default"
	 * rather than "everything enabled".
	 *
	 * @since 1.3.0
	 * @var string
	 */
	public const SAVED_OPTION = 'albert_abilities_saved';

	/**
	 * Get the list of disabled ability IDs.
	 *
	 * On a fresh install (no saved toggles yet) returns the default blocklist
	 * (non-readonly abilities) from {@see AbilitiesRegistry::get_default_disabled_abilities()}.
	 *
	 * @return array<int, string>
	 * @since 1.3.0
	 */
	public static function disabled(): array {
		$disabled = get_option( self::OPTION, [] );

		if ( empty( $disabled ) && ! get_option( self::SAVED_OPTION ) ) {
			return AbilitiesRegistry::get_default_disabled_abilities();
		}

		return array_values( array_map( 'strval', (array) $disabled ) );
	}

	/**
	 * Whether an ability is currently enabled.
	 *
	 * @param string $ability_id Ability ID.
	 *
	 * @return bool
	 * @since 1.3.0
	 */
	public static function is_enabled( string $ability_id ): bool {
		return ! in_array( $ability_id, self::disabled(), true );
	}

	/**
	 * Enable or disable a single ability.
	 *
	 * Mutates only the requested ability so two concurrent toggles can't clobber
	 * each other, and records that toggles have been saved.
	 *
	 * @param string $ability_id Ability ID.
	 * @param bool   $enabled    Desired enabled state.
	 *
	 * @return void
	 * @since 1.3.0
	 */
	public static function set_enabled( string $ability_id, bool $enabled ): void {
		$disabled = self::disabled();

		if ( $enabled ) {
			$disabled = array_values( array_diff( $disabled, [ $ability_id ] ) );
		} elseif ( ! in_array( $ability_id, $disabled, true ) ) {
			$disabled[] = $ability_id;
		}

		update_option( self::OPTION, $disabled );
		update_option( self::SAVED_OPTION, true );
	}

	/**
	 * Enable or disable many abilities at once with a single option write.
	 *
	 * @param array<int, string> $ability_ids Ability IDs to change.
	 * @param bool               $enabled     Desired enabled state for all of them.
	 *
	 * @return void
	 * @since 1.3.0
	 */
	public static function set_enabled_bulk( array $ability_ids, bool $enabled ): void {
		if ( empty( $ability_ids ) ) {
			return;
		}

		$disabled = array_fill_keys( self::disabled(), true );

		foreach ( $ability_ids as $ability_id ) {
			if ( $enabled ) {
				unset( $disabled[ $ability_id ] );
			} else {
				$disabled[ $ability_id ] = true;
			}
		}

		update_option( self::OPTION, array_values( array_map( 'strval', array_keys( $disabled ) ) ) );
		update_option( self::SAVED_OPTION, true );
	}
}
