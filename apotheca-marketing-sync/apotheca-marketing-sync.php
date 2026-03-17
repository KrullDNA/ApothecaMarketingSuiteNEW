<?php
/**
 * Plugin Name: Apotheca Marketing Sync
 * Plugin URI: https://apotheca.io
 * Description: Pushes WooCommerce events to the Apotheca Marketing Suite on the marketing subdomain.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * Author: Apotheca
 * Author URI: https://apotheca.io
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: apotheca-marketing-sync
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'AMS_SYNC_VERSION', '1.0.0' );
define( 'AMS_SYNC_FILE', __FILE__ );
define( 'AMS_SYNC_DIR', plugin_dir_path( __FILE__ ) );
define( 'AMS_SYNC_URL', plugin_dir_url( __FILE__ ) );
define( 'AMS_SYNC_BASENAME', plugin_basename( __FILE__ ) );

// Autoloader.
spl_autoload_register( function ( string $class ): void {
    $prefix = 'Apotheca\\Marketing\\Sync\\';
    if ( ! str_starts_with( $class, $prefix ) ) {
        return;
    }
    $relative = str_replace( $prefix, '', $class );
    $file     = AMS_SYNC_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

// Activation.
register_activation_hook( __FILE__, [ Apotheca\Marketing\Sync\Activator::class, 'activate' ] );

// Boot.
add_action( 'plugins_loaded', function (): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }
    Apotheca\Marketing\Sync\Plugin::instance();
} );
