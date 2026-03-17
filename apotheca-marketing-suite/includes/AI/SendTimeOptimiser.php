<?php

namespace Apotheca\Marketing\AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Send-time optimisation.
 *
 * Nightly Action Scheduler job: for each subscriber with >= 5 sends,
 * groups opens by hour of day, finds the mode, and stores in
 * ams_subscribers.best_send_hour. Default 10 for subscribers with < 5 sends.
 */
class SendTimeOptimiser {

    private const HOOK           = 'ams_optimise_send_times';
    private const MIN_SENDS      = 5;
    private const DEFAULT_HOUR   = 10;
    private const BATCH_SIZE     = 500;

    /**
     * Register the recurring Action Scheduler job.
     */
    public function register(): void {
        add_action( self::HOOK, [ $this, 'run' ] );

        if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::HOOK ) ) {
            $next = strtotime( 'tomorrow 04:00:00 UTC' );
            as_schedule_recurring_action( $next, DAY_IN_SECONDS, self::HOOK, [], 'ams_ai' );
        }
    }

    /**
     * Run the optimisation for all eligible subscribers.
     */
    public function run(): void {
        global $wpdb;
        $sends = $wpdb->prefix . 'ams_sends';
        $subs  = $wpdb->prefix . 'ams_subscribers';

        // Find subscribers with >= MIN_SENDS opens.
        $eligible = $wpdb->get_col( $wpdb->prepare(
            "SELECT subscriber_id FROM {$sends}
             WHERE opened_at IS NOT NULL
             GROUP BY subscriber_id
             HAVING COUNT(*) >= %d
             LIMIT %d",
            self::MIN_SENDS,
            self::BATCH_SIZE
        ) );

        foreach ( $eligible as $sub_id ) {
            $best_hour = $this->calculate_best_hour( (int) $sub_id );
            $wpdb->update(
                $subs,
                [ 'best_send_hour' => $best_hour ],
                [ 'id' => (int) $sub_id ]
            );
        }

        // Reset subscribers with < MIN_SENDS to default.
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$subs} s SET s.best_send_hour = %d
             WHERE s.id NOT IN (
                 SELECT subscriber_id FROM {$sends}
                 WHERE opened_at IS NOT NULL
                 GROUP BY subscriber_id
                 HAVING COUNT(*) >= %d
             )",
            self::DEFAULT_HOUR,
            self::MIN_SENDS
        ) );
    }

    /**
     * Calculate the best send hour for a subscriber (mode of open hours).
     */
    private function calculate_best_hour( int $subscriber_id ): int {
        global $wpdb;
        $sends = $wpdb->prefix . 'ams_sends';

        $hour = $wpdb->get_var( $wpdb->prepare(
            "SELECT HOUR(opened_at) AS h FROM {$sends}
             WHERE subscriber_id = %d AND opened_at IS NOT NULL
             GROUP BY h
             ORDER BY COUNT(*) DESC
             LIMIT 1",
            $subscriber_id
        ) );

        return null !== $hour ? (int) $hour : self::DEFAULT_HOUR;
    }
}
