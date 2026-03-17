<?php

namespace Apotheca\Marketing\Sync;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Activator {

    public static function activate(): void {
        self::create_tables();
        self::set_defaults();
    }

    private static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ams_sync_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(50) NOT NULL,
            payload_hash VARCHAR(16) NOT NULL,
            http_status SMALLINT UNSIGNED DEFAULT NULL,
            attempt_number TINYINT UNSIGNED NOT NULL DEFAULT 1,
            response_body VARCHAR(500) DEFAULT NULL,
            dispatched_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_event_type (event_type),
            KEY idx_dispatched_at (dispatched_at),
            KEY idx_http_status (http_status)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'ams_sync_db_version', AMS_SYNC_VERSION );
    }

    private static function set_defaults(): void {
        if ( get_option( 'ams_sync_settings' ) ) {
            return;
        }

        update_option( 'ams_sync_settings', [
            'endpoint_url'   => '',
            'shared_secret'  => '',
            'events'         => [
                'customer_registered' => true,
                'order_placed'        => true,
                'order_status_changed'=> true,
                'cart_updated'        => true,
                'product_viewed'      => true,
                'checkout_started'    => true,
                'abandoned_cart'      => true,
            ],
        ], false );
    }
}
