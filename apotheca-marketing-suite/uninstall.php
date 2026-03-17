<?php
/**
 * Apotheca Marketing Suite — Uninstall.
 *
 * Offers two modes controlled by the ams_delete_all_data option:
 *   true  → drops all ams_* tables, deletes options and transients.
 *   false → keeps data intact (default).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Default: keep data.
$delete_data = get_option( 'ams_delete_all_data', false );

if ( ! $delete_data ) {
    return;
}

global $wpdb;

// Drop all ams_* tables.
$tables = [
    'ams_subscribers',
    'ams_events',
    'ams_flows',
    'ams_flow_steps',
    'ams_flow_enrolments',
    'ams_campaigns',
    'ams_segments',
    'ams_sends',
    'ams_forms',
    'ams_attributions',
    'ams_analytics_daily',
    'ams_sync_log',
    'ams_reviews_cache',
    'ams_ai_log',
    'ams_products_cache',
];

foreach ( $tables as $table ) {
    $full_name = $wpdb->prefix . $table;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query( "DROP TABLE IF EXISTS {$full_name}" );
}

// Delete options.
$options = [
    'ams_settings',
    'ams_db_version',
    'ams_delete_all_data',
    'ams_sms_settings',
    'ams_reviews_settings',
    'ams_analytics_settings',
    'ams_ai_settings',
];

foreach ( $options as $opt ) {
    delete_option( $opt );
}

// Delete transients.
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ams_%' OR option_name LIKE '_transient_timeout_ams_%'"
);

// Unschedule all Action Scheduler jobs in the ams group.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
    $hooks = [
        'ams_rfm_scoring',
        'ams_aggregate_analytics',
        'ams_refresh_reviews',
        'ams_optimise_send_times',
        'ams_refresh_products_cache',
        'ams_segment_recalculate',
        'ams_trigger_birthday',
        'ams_trigger_win_back',
        'ams_check_abandoned_cart',
        'ams_campaign_send_batch',
        'ams_execute_flow_step',
        'ams_ai_generate_subject_lines',
        'ams_ai_generate_email_body',
        'ams_ai_suggest_segments',
    ];
    foreach ( $hooks as $hook ) {
        as_unschedule_all_actions( $hook );
    }
}

// Flush rewrite rules to remove custom endpoints.
flush_rewrite_rules();
