<?php

namespace Apotheca\Marketing\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Nightly aggregation job that rolls up ams_sends + ams_attributions
 * into ams_analytics_daily for fast dashboard queries.
 *
 * Runs at 2 AM UTC daily via Action Scheduler.
 */
class AnalyticsAggregator {

    private const HOOK = 'ams_aggregate_analytics';

    /**
     * Register the recurring Action Scheduler job.
     */
    public function register(): void {
        add_action( self::HOOK, [ $this, 'run' ] );

        if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::HOOK ) ) {
            $next_2am = strtotime( 'tomorrow 02:00:00 UTC' );
            as_schedule_recurring_action( $next_2am, DAY_IN_SECONDS, self::HOOK, [], 'ams_analytics' );
        }
    }

    /**
     * Run the aggregation for yesterday (or a specific date).
     */
    public function run( ?string $date = null ): void {
        if ( ! $date ) {
            $date = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
        }

        $this->aggregate_sends( $date );
        $this->aggregate_attributions( $date );
        $this->aggregate_subscribers( $date );
    }

    /**
     * Aggregate send metrics for a given date.
     */
    private function aggregate_sends( string $date ): void {
        global $wpdb;
        $sends = $wpdb->prefix . 'ams_sends';

        $metrics = [
            'email_sent'          => "SELECT COUNT(*) FROM {$sends} WHERE channel = 'email' AND DATE(sent_at) = %s",
            'email_opened'        => "SELECT COUNT(*) FROM {$sends} WHERE channel = 'email' AND DATE(opened_at) = %s",
            'email_clicked'       => "SELECT COUNT(*) FROM {$sends} WHERE channel = 'email' AND DATE(clicked_at) = %s",
            'email_bounced'       => "SELECT COUNT(*) FROM {$sends} WHERE channel = 'email' AND DATE(bounced_at) = %s",
            'email_unsubscribed'  => "SELECT COUNT(*) FROM {$sends} WHERE channel = 'email' AND DATE(unsubscribed_at) = %s",
            'sms_sent'            => "SELECT COUNT(*) FROM {$sends} WHERE channel = 'sms' AND DATE(sent_at) = %s",
            'sms_delivered'       => "SELECT COUNT(*) FROM {$sends} WHERE channel = 'sms' AND status = 'delivered' AND DATE(sent_at) = %s",
            'sms_unsubscribed'    => "SELECT COUNT(*) FROM {$sends} WHERE channel = 'sms' AND DATE(unsubscribed_at) = %s",
        ];

        foreach ( $metrics as $key => $sql ) {
            $value = (float) $wpdb->get_var( $wpdb->prepare( $sql, $date ) );
            $this->upsert_metric( $date, $key, $value );
        }
    }

    /**
     * Aggregate attribution/revenue metrics for a given date.
     */
    private function aggregate_attributions( string $date ): void {
        global $wpdb;
        $attr  = $wpdb->prefix . 'ams_attributions';
        $sends = $wpdb->prefix . 'ams_sends';

        // Total revenue attributed on this date.
        $total_revenue = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(order_total), 0) FROM {$attr} WHERE DATE(attributed_at) = %s",
            $date
        ) );
        $this->upsert_metric( $date, 'revenue_total', $total_revenue );

        // Email revenue.
        $email_revenue = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(a.order_total), 0) FROM {$attr} a
             INNER JOIN {$sends} s ON s.id = a.send_id
             WHERE DATE(a.attributed_at) = %s AND s.channel = 'email'",
            $date
        ) );
        $this->upsert_metric( $date, 'revenue_email', $email_revenue );

        // SMS revenue.
        $sms_revenue = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(a.order_total), 0) FROM {$attr} a
             INNER JOIN {$sends} s ON s.id = a.send_id
             WHERE DATE(a.attributed_at) = %s AND s.channel = 'sms'",
            $date
        ) );
        $this->upsert_metric( $date, 'revenue_sms', $sms_revenue );

        // Attribution count.
        $attr_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$attr} WHERE DATE(attributed_at) = %s",
            $date
        ) );
        $this->upsert_metric( $date, 'attributions_count', (float) $attr_count );

        // Average order value (from attributed orders).
        $avg_aov = $attr_count > 0 ? $total_revenue / $attr_count : 0;
        $this->upsert_metric( $date, 'avg_order_value', $avg_aov );
    }

    /**
     * Aggregate subscriber metrics for a given date.
     */
    private function aggregate_subscribers( string $date ): void {
        global $wpdb;
        $subs = $wpdb->prefix . 'ams_subscribers';

        // Active subscribers count as of that date.
        $active = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$subs} WHERE status = 'active' AND DATE(created_at) <= %s",
            $date
        ) );
        $this->upsert_metric( $date, 'active_subscribers', (float) $active );

        // New subscribers on that date.
        $new = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$subs} WHERE DATE(created_at) = %s",
            $date
        ) );
        $this->upsert_metric( $date, 'new_subscribers', (float) $new );

        // Churn risk distribution.
        $churn_low = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$subs} WHERE status = 'active' AND churn_risk_score <= 30 AND DATE(created_at) <= %s",
            $date
        ) );
        $churn_med = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$subs} WHERE status = 'active' AND churn_risk_score > 30 AND churn_risk_score <= 60 AND DATE(created_at) <= %s",
            $date
        ) );
        $churn_high = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$subs} WHERE status = 'active' AND churn_risk_score > 60 AND DATE(created_at) <= %s",
            $date
        ) );
        $this->upsert_metric( $date, 'churn_risk_low', (float) $churn_low );
        $this->upsert_metric( $date, 'churn_risk_medium', (float) $churn_med );
        $this->upsert_metric( $date, 'churn_risk_high', (float) $churn_high );
    }

    /**
     * Upsert a metric into ams_analytics_daily.
     */
    private function upsert_metric( string $date, string $key, float $value ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_analytics_daily';

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE date = %s AND metric_key = %s",
            $date,
            $key
        ) );

        if ( $existing ) {
            $wpdb->update( $table, [ 'metric_value' => $value ], [ 'id' => $existing ] );
        } else {
            $wpdb->insert( $table, [
                'date'         => $date,
                'metric_key'   => $key,
                'metric_value' => $value,
            ] );
        }
    }

    /**
     * Backfill aggregation for a date range (admin utility).
     */
    public function backfill( string $start_date, string $end_date ): int {
        $current = strtotime( $start_date );
        $end     = strtotime( $end_date );
        $count   = 0;

        while ( $current <= $end ) {
            $this->run( gmdate( 'Y-m-d', $current ) );
            $current += DAY_IN_SECONDS;
            $count++;
        }

        return $count;
    }
}
