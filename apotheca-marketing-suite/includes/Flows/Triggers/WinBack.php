<?php

namespace Apotheca\Marketing\Flows\Triggers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Win-Back trigger: fires when last_order_date > X days (default 90).
 *
 * Runs as a daily Action Scheduler job to check for lapsed customers.
 */
class WinBack {

    private const HOOK       = 'ams_trigger_win_back';
    private const BATCH_SIZE = 200;

    public function register(): void {
        add_action( self::HOOK, [ $this, 'check_lapsed' ] );

        if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::HOOK ) ) {
            $next_3am = strtotime( 'tomorrow 03:00:00 UTC' );
            as_schedule_recurring_action( $next_3am, DAY_IN_SECONDS, self::HOOK, [], 'ams_flows' );
        }
    }

    public function check_lapsed(): void {
        global $wpdb;
        $subs_table  = $wpdb->prefix . 'ams_subscribers';
        $flows_table = $wpdb->prefix . 'ams_flows';

        // Get all active win_back flows and their config.
        $flows = $wpdb->get_results(
            "SELECT id, trigger_config FROM {$flows_table}
             WHERE trigger_type = 'win_back' AND status = 'active'"
        );

        if ( empty( $flows ) ) {
            return;
        }

        foreach ( $flows as $flow ) {
            $config    = json_decode( $flow->trigger_config ?? '{}', true ) ?: [];
            $days      = max( 1, (int) ( $config['days_since_order'] ?? 90 ) );
            $threshold = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

            $subscribers = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$subs_table}
                 WHERE status = 'active'
                   AND total_orders > 0
                   AND last_order_date IS NOT NULL
                   AND last_order_date < %s
                 LIMIT %d",
                $threshold,
                self::BATCH_SIZE
            ) );

            $engine = new \Apotheca\Marketing\Flows\FlowEngine();
            foreach ( $subscribers as $sub_id ) {
                $engine->enrol( (int) $flow->id, (int) $sub_id, [ 'trigger' => 'win_back' ] );
            }
        }
    }
}
