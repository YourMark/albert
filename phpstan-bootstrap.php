<?php
/**
 * PHPStan bootstrap file.
 *
 * Defines constants that are normally defined by the main plugin file.
 *
 * @package ExtendedAbilities
 */

// Define plugin constants for PHPStan analysis.
if ( ! defined( 'EXTENDED_ABILITIES_VERSION' ) ) {
	define( 'EXTENDED_ABILITIES_VERSION', '1.0.0' );
}

if ( ! defined( 'EXTENDED_ABILITIES_PLUGIN_FILE' ) ) {
	define( 'EXTENDED_ABILITIES_PLUGIN_FILE', dirname( __FILE__ ) . '/extended-abilities.php' );
}

if ( ! defined( 'EXTENDED_ABILITIES_PLUGIN_DIR' ) ) {
	define( 'EXTENDED_ABILITIES_PLUGIN_DIR', dirname( __FILE__ ) . '/' );
}

if ( ! defined( 'EXTENDED_ABILITIES_PLUGIN_URL' ) ) {
	define( 'EXTENDED_ABILITIES_PLUGIN_URL', 'https://example.com/wp-content/plugins/extended-abilities/' );
}

if ( ! defined( 'EXTENDED_ABILITIES_PLUGIN_BASENAME' ) ) {
	define( 'EXTENDED_ABILITIES_PLUGIN_BASENAME', 'extended-abilities/extended-abilities.php' );
}
