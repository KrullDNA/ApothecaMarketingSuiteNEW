<?php
/**
 * Apotheca Marketing Sync — Uninstall.
 *
 * Removes all plugin data: option, sync log table, and pending AS jobs.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Drop sync log table.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ams_sync_log" );

// Delete options.
delete_option( 'ams_sync_settings' );
delete_option( 'ams_sync_db_version' );

// Delete transients.
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ams_abandoned_%' OR option_name LIKE '_transient_timeout_ams_abandoned_%'"
);

// Unschedule all Action Scheduler jobs in the ams-sync group.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
    as_unschedule_all_actions( 'ams_sync_dispatch' );
    as_unschedule_all_actions( 'ams_sync_dispatch_product_view' );
    as_unschedule_all_actions( 'ams_sync_check_abandoned_carts' );
    as_unschedule_all_actions( 'ams_sync_retry_failed' );
}
