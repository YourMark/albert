<?php
/**
 * Uninstall Albert.
 *
 * @package Albert
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$autoload = __DIR__ . '/vendor/autoload.php';

if ( ! file_exists( $autoload ) ) {
	return;
}

require_once $autoload;

Albert\OAuth\Database\Installer::uninstall();
Albert\Logging\Installer::uninstall();
