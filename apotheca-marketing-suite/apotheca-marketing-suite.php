<?php
/**
 * Plugin Name: Apotheca Marketing Suite
 * Plugin URI: https://apotheca.io
 * Description: Full-featured marketing automation suite for WordPress. Receives data from your WooCommerce store via the companion sync plugin and powers email, SMS, flows, segments, and analytics.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: Apotheca
 * Author URI: https://apotheca.io
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: apotheca-marketing-suite
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'AMS_VERSION', '1.0.0' );
define( 'AMS_PLUGIN_FILE', __FILE__ );
define( 'AMS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AMS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AMS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Load Action Scheduler before anything else.
if ( ! class_exists( 'ActionScheduler' ) ) {
    require_once AMS_PLUGIN_DIR . 'lib/action-scheduler/action-scheduler.php';
}

// Autoloader for Apotheca\Marketing namespace.
spl_autoload_register( function ( string $class ) {
    $prefix = 'Apotheca\\Marketing\\';
    if ( strpos( $class, $prefix ) !== 0 ) {
        return;
    }
    $relative = substr( $class, strlen( $prefix ) );
    $file     = AMS_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

// Activation hook.
register_activation_hook( __FILE__, [ 'Apotheca\\Marketing\\Activator', 'activate' ] );

// Deactivation hook.
register_deactivation_hook( __FILE__, [ 'Apotheca\\Marketing\\Activator', 'deactivate' ] );

// Boot the plugin.
add_action( 'plugins_loaded', function () {
    Apotheca\Marketing\Plugin::instance();
} );
