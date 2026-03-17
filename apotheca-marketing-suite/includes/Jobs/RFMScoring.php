<?php

namespace Apotheca\Marketing\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RFMScoring {

    private const BATCH_SIZE = 500;
    private const HOOK       = 'ams_rfm_scoring';

    /**
     * Register the recurring Action Scheduler job.
     */
    public function register(): void {
        add_action( self::HOOK, [ $this, 'process_batch' ] );

        // Schedule nightly at 2am UTC if not already scheduled.
        if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::HOOK ) ) {
            $next_2am = strtotime( 'tomorrow 02:00:00 UTC' );
            as_schedule_recurring_action( $next_2am, DAY_IN_SECONDS, self::HOOK, [], 'ams' );
        }
    }

    /**
     * Process a batch of subscribers for RFM scoring.
     */
    public function process_batch(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_subscribers';

        $offset = (int) get_option( 'ams_rfm_offset', 0 );

        $subscribers = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, total_orders, total_spent, last_order_date, created_at, rfm_segment
             FROM {$table}
             WHERE status = 'active'
             ORDER BY id ASC
             LIMIT %d OFFSET %d",
            self::BATCH_SIZE,
            $offset
        ) );

        if ( empty( $subscribers ) ) {
            // All done, reset offset for next run.
            delete_option( 'ams_rfm_offset' );
            return;
        }

        $now = time();

        foreach ( $subscribers as $sub ) {
            $scores = $this->calculate_rfm( $sub, $now );

            $old_segment = $sub->rfm_segment ?? '';

            $wpdb->update( $table, [
                'rfm_score'            => $scores['rfm_score'],
                'rfm_segment'          => $scores['rfm_segment'],
                'predicted_clv'        => $scores['predicted_clv'],
                'predicted_next_order' => $scores['predicted_next_order'],
                'churn_risk_score'     => $scores['churn_risk_score'],
                'updated_at'           => current_time( 'mysql', true ),
            ], [ 'id' => $sub->id ] );

            // Fire RFM segment change hook if segment changed.
            if ( $old_segment && $old_segment !== $scores['rfm_segment'] ) {
                do_action( 'ams_rfm_segment_changed', (int) $sub->id, $old_segment, $scores['rfm_segment'] );
            }
        }

        // If we got a full batch, schedule continuation.
        if ( count( $subscribers ) === self::BATCH_SIZE ) {
            update_option( 'ams_rfm_offset', $offset + self::BATCH_SIZE );
            if ( function_exists( 'as_schedule_single_action' ) ) {
                as_schedule_single_action( time() + 10, self::HOOK, [], 'ams' );
            }
        } else {
            delete_option( 'ams_rfm_offset' );
        }
    }

    /**
     * Calculate RFM scores for a subscriber.
     */
    private function calculate_rfm( object $sub, int $now ): array {
        // Recency: days since last order.
        $last_order_ts = $sub->last_order_date ? strtotime( $sub->last_order_date ) : strtotime( $sub->created_at );
        $days_since    = max( 0, (int) floor( ( $now - $last_order_ts ) / DAY_IN_SECONDS ) );

        // Recency score (5 = most recent).
        $r = match ( true ) {
            $days_since <= 30  => 5,
            $days_since <= 60  => 4,
            $days_since <= 90  => 3,
            $days_since <= 180 => 2,
            default            => 1,
        };

        // Frequency score.
        $orders = (int) $sub->total_orders;
        $f = match ( true ) {
            $orders >= 10 => 5,
            $orders >= 6  => 4,
            $orders >= 3  => 3,
            $orders >= 2  => 2,
            default       => 1,
        };

        // Monetary score.
        $spent = (float) $sub->total_spent;
        $m = match ( true ) {
            $spent >= 500  => 5,
            $spent >= 250  => 4,
            $spent >= 100  => 3,
            $spent >= 50   => 2,
            default        => 1,
        };

        $rfm_score = "{$r}{$f}{$m}";

        // Map to named segment.
        $rfm_segment = $this->map_segment( $r, $f, $m );

        // Predicted CLV: simple multiplier based on average order value and frequency.
        $avg_order   = $orders > 0 ? $spent / $orders : 0;
        $annual_freq = $orders > 0
            ? min( 52, $orders * ( 365 / max( 1, $days_since + 30 ) ) )
            : 0;
        $predicted_clv = round( $avg_order * $annual_freq, 2 );

        // Predicted next order: average interval between orders.
        $avg_interval = $orders > 1
            ? max( 7, (int) floor( max( 1, ( $now - strtotime( $sub->created_at ) ) / DAY_IN_SECONDS ) / $orders ) )
            : 30;
        $predicted_next = date( 'Y-m-d', $last_order_ts + ( $avg_interval * DAY_IN_SECONDS ) );

        // Churn risk: 0-100 score.
        $expected_days = $avg_interval;
        $overdue_ratio = $expected_days > 0 ? $days_since / $expected_days : 0;
        $churn_risk    = min( 100, max( 0, (int) round( $overdue_ratio * 33 ) ) );

        return [
            'rfm_score'            => $rfm_score,
            'rfm_segment'          => $rfm_segment,
            'predicted_clv'        => $predicted_clv,
            'predicted_next_order' => $predicted_next,
            'churn_risk_score'     => $churn_risk,
        ];
    }

    /**
     * Map RFM score to a named segment.
     */
    private function map_segment( int $r, int $f, int $m ): string {
        $total = $r + $f + $m;

        if ( $r >= 4 && $f >= 4 && $m >= 4 ) {
            return 'champions';
        }
        if ( $r >= 4 && $f >= 2 ) {
            return 'loyal_customers';
        }
        if ( $r >= 3 && $f >= 3 ) {
            return 'potential_loyalists';
        }
        if ( $r >= 4 && $f <= 1 ) {
            return 'new_customers';
        }
        if ( $r >= 3 && $f <= 2 && $m >= 3 ) {
            return 'promising';
        }
        if ( $r <= 2 && $f >= 3 ) {
            return 'at_risk';
        }
        if ( $r <= 2 && $f >= 4 && $m >= 4 ) {
            return 'cant_lose_them';
        }
        if ( $r <= 1 ) {
            return 'lost';
        }

        return 'need_attention';
    }
}
