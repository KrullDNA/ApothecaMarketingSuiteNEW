<?php

namespace Apotheca\Marketing\REST;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST API endpoints for the analytics dashboard.
 *
 * All routes read from ams_analytics_daily (pre-aggregated)
 * except for detail tables that query ams_sends / ams_attributions directly.
 */
class AnalyticsEndpoint {

    public function register_routes(): void {
        $ns = 'ams/v1/admin/analytics';

        // Overview metrics.
        register_rest_route( $ns, '/overview', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'overview' ],
            'permission_callback' => [ $this, 'check_admin' ],
        ] );

        // Revenue time series.
        register_rest_route( $ns, '/revenue-series', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'revenue_series' ],
            'permission_callback' => [ $this, 'check_admin' ],
        ] );

        // Subscriber growth time series.
        register_rest_route( $ns, '/subscriber-growth', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'subscriber_growth' ],
            'permission_callback' => [ $this, 'check_admin' ],
        ] );

        // Email performance table.
        register_rest_route( $ns, '/email-performance', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'email_performance' ],
            'permission_callback' => [ $this, 'check_admin' ],
        ] );

        // Bounce log.
        register_rest_route( $ns, '/bounce-log', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'bounce_log' ],
            'permission_callback' => [ $this, 'check_admin' ],
        ] );

        // SMS performance table.
        register_rest_route( $ns, '/sms-performance', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'sms_performance' ],
            'permission_callback' => [ $this, 'check_admin' ],
        ] );

        // Subscriber insights.
        register_rest_route( $ns, '/subscriber-insights', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'subscriber_insights' ],
            'permission_callback' => [ $this, 'check_admin' ],
        ] );

        // Flow analytics.
        register_rest_route( $ns, '/flow-analytics', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'flow_analytics' ],
            'permission_callback' => [ $this, 'check_admin' ],
        ] );

        // CSV export.
        register_rest_route( $ns, '/export', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'export_csv' ],
            'permission_callback' => [ $this, 'check_admin' ],
        ] );

        // Settings (attribution window).
        register_rest_route( $ns, '/settings', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_settings' ],
            'permission_callback' => [ $this, 'check_admin' ],
        ] );
        register_rest_route( $ns, '/settings', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'save_settings' ],
            'permission_callback' => [ $this, 'check_admin' ],
        ] );
    }

    public function check_admin(): bool {
        return current_user_can( 'manage_options' );
    }

    /**
     * Overview: metric cards for the dashboard.
     */
    public function overview( \WP_REST_Request $request ): \WP_REST_Response {
        $days = (int) ( $request->get_param( 'days' ) ?? 30 );
        $from = $request->get_param( 'from' );
        $to   = $request->get_param( 'to' );

        if ( $from && $to ) {
            $start_date = sanitize_text_field( $from );
            $end_date   = sanitize_text_field( $to );
        } else {
            $end_date   = gmdate( 'Y-m-d' );
            $start_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
        }

        global $wpdb;
        $ad    = $wpdb->prefix . 'ams_analytics_daily';
        $attr  = $wpdb->prefix . 'ams_attributions';
        $subs  = $wpdb->prefix . 'ams_subscribers';

        // Revenue from analytics_daily for the period.
        $period_revenue = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(metric_value), 0) FROM {$ad}
             WHERE metric_key = 'revenue_total' AND date BETWEEN %s AND %s",
            $start_date, $end_date
        ) );

        $email_revenue = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(metric_value), 0) FROM {$ad}
             WHERE metric_key = 'revenue_email' AND date BETWEEN %s AND %s",
            $start_date, $end_date
        ) );

        $sms_revenue = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(metric_value), 0) FROM {$ad}
             WHERE metric_key = 'revenue_sms' AND date BETWEEN %s AND %s",
            $start_date, $end_date
        ) );

        // All-time revenue.
        $alltime_revenue = (float) $wpdb->get_var(
            "SELECT COALESCE(SUM(order_total), 0) FROM {$attr}"
        );

        // Active subscribers (cached, 1h TTL).
        $active_subs = wp_cache_get( 'ams_active_subscriber_count', 'ams' );
        if ( false === $active_subs ) {
            $active_subs = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$subs} WHERE status = 'active'"
            );
            wp_cache_set( 'ams_active_subscriber_count', $active_subs, 'ams', HOUR_IN_SECONDS );
        }

        // Avg order value for the period.
        $avg_aov = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(AVG(order_total), 0) FROM {$attr}
             WHERE DATE(attributed_at) BETWEEN %s AND %s",
            $start_date, $end_date
        ) );

        // 7d revenue.
        $d7 = gmdate( 'Y-m-d', strtotime( '-7 days' ) );
        $revenue_7d = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(metric_value), 0) FROM {$ad}
             WHERE metric_key = 'revenue_total' AND date >= %s",
            $d7
        ) );

        // Deliverability score (1 - bounce_rate).
        $email_sent = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(metric_value), 0) FROM {$ad}
             WHERE metric_key = 'email_sent' AND date BETWEEN %s AND %s",
            $start_date, $end_date
        ) );
        $email_bounced = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(metric_value), 0) FROM {$ad}
             WHERE metric_key = 'email_bounced' AND date BETWEEN %s AND %s",
            $start_date, $end_date
        ) );
        $deliverability = $email_sent > 0 ? round( ( 1 - $email_bounced / $email_sent ) * 100, 1 ) : 100;

        return new \WP_REST_Response( [
            'period_revenue'    => round( $period_revenue, 2 ),
            'alltime_revenue'   => round( $alltime_revenue, 2 ),
            'revenue_7d'        => round( $revenue_7d, 2 ),
            'email_revenue'     => round( $email_revenue, 2 ),
            'sms_revenue'       => round( $sms_revenue, 2 ),
            'active_subscribers'=> $active_subs,
            'avg_order_value'   => round( $avg_aov, 2 ),
            'deliverability'    => $deliverability,
            'start_date'        => $start_date,
            'end_date'          => $end_date,
        ] );
    }

    /**
     * Revenue by channel time series (bar chart).
     */
    public function revenue_series( \WP_REST_Request $request ): \WP_REST_Response {
        $days = (int) ( $request->get_param( 'days' ) ?? 30 );
        $start = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

        global $wpdb;
        $ad = $wpdb->prefix . 'ams_analytics_daily';

        $email = $wpdb->get_results( $wpdb->prepare(
            "SELECT date, metric_value AS value FROM {$ad}
             WHERE metric_key = 'revenue_email' AND date >= %s ORDER BY date ASC",
            $start
        ) );

        $sms = $wpdb->get_results( $wpdb->prepare(
            "SELECT date, metric_value AS value FROM {$ad}
             WHERE metric_key = 'revenue_sms' AND date >= %s ORDER BY date ASC",
            $start
        ) );

        return new \WP_REST_Response( [
            'email' => $email,
            'sms'   => $sms,
        ] );
    }

    /**
     * Subscriber growth time series (line chart).
     */
    public function subscriber_growth( \WP_REST_Request $request ): \WP_REST_Response {
        $days = (int) ( $request->get_param( 'days' ) ?? 30 );
        $start = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

        global $wpdb;
        $ad = $wpdb->prefix . 'ams_analytics_daily';

        $data = $wpdb->get_results( $wpdb->prepare(
            "SELECT date, metric_value AS value FROM {$ad}
             WHERE metric_key = 'active_subscribers' AND date >= %s ORDER BY date ASC",
            $start
        ) );

        $new_subs = $wpdb->get_results( $wpdb->prepare(
            "SELECT date, metric_value AS value FROM {$ad}
             WHERE metric_key = 'new_subscribers' AND date >= %s ORDER BY date ASC",
            $start
        ) );

        return new \WP_REST_Response( [
            'total'   => $data,
            'new'     => $new_subs,
        ] );
    }

    /**
     * Email performance table: per-campaign/flow stats.
     */
    public function email_performance( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $sends = $wpdb->prefix . 'ams_sends';
        $camps = $wpdb->prefix . 'ams_campaigns';
        $steps = $wpdb->prefix . 'ams_flow_steps';
        $flows = $wpdb->prefix . 'ams_flows';

        // Campaign performance.
        $campaigns = $wpdb->get_results(
            "SELECT
                c.id, c.name, 'campaign' AS source_type,
                COUNT(s.id) AS sent,
                SUM(CASE WHEN s.bounced_at IS NULL AND s.sent_at IS NOT NULL THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN s.opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened,
                SUM(CASE WHEN s.clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked,
                SUM(CASE WHEN s.unsubscribed_at IS NOT NULL THEN 1 ELSE 0 END) AS unsubscribed,
                SUM(CASE WHEN s.bounced_at IS NOT NULL THEN 1 ELSE 0 END) AS bounced,
                COALESCE(SUM(s.revenue_attributed), 0) AS revenue
             FROM {$camps} c
             LEFT JOIN {$sends} s ON s.campaign_id = c.id AND s.channel = 'email'
             WHERE c.type = 'email'
             GROUP BY c.id
             ORDER BY c.created_at DESC"
        );

        // Flow step performance.
        $flow_steps = $wpdb->get_results(
            "SELECT
                f.id AS flow_id, CONCAT(f.name, ' / Step ', fs.step_order) AS name, 'flow' AS source_type,
                COUNT(s.id) AS sent,
                SUM(CASE WHEN s.bounced_at IS NULL AND s.sent_at IS NOT NULL THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN s.opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened,
                SUM(CASE WHEN s.clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked,
                SUM(CASE WHEN s.unsubscribed_at IS NOT NULL THEN 1 ELSE 0 END) AS unsubscribed,
                SUM(CASE WHEN s.bounced_at IS NOT NULL THEN 1 ELSE 0 END) AS bounced,
                COALESCE(SUM(s.revenue_attributed), 0) AS revenue
             FROM {$flows} f
             INNER JOIN {$steps} fs ON fs.flow_id = f.id AND fs.step_type = 'email'
             LEFT JOIN {$sends} s ON s.flow_step_id = fs.id AND s.channel = 'email'
             GROUP BY fs.id
             ORDER BY f.name ASC, fs.step_order ASC"
        );

        $rows = [];
        foreach ( array_merge( $campaigns, $flow_steps ) as $row ) {
            $sent = (int) $row->sent;
            $rows[] = [
                'name'             => $row->name,
                'source_type'      => $row->source_type,
                'sent'             => $sent,
                'delivered'        => (int) $row->delivered,
                'open_rate'        => $sent > 0 ? round( (int) $row->opened / $sent * 100, 1 ) : 0,
                'click_rate'       => $sent > 0 ? round( (int) $row->clicked / $sent * 100, 1 ) : 0,
                'unsubscribe_rate' => $sent > 0 ? round( (int) $row->unsubscribed / $sent * 100, 2 ) : 0,
                'bounced'          => (int) $row->bounced,
                'revenue'          => round( (float) $row->revenue, 2 ),
                'revenue_per_send' => $sent > 0 ? round( (float) $row->revenue / $sent, 2 ) : 0,
            ];
        }

        return new \WP_REST_Response( $rows );
    }

    /**
     * Bounce log (exportable).
     */
    public function bounce_log( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $sends = $wpdb->prefix . 'ams_sends';
        $subs  = $wpdb->prefix . 'ams_subscribers';

        $limit  = min( (int) ( $request->get_param( 'per_page' ) ?? 100 ), 500 );
        $offset = (int) ( $request->get_param( 'offset' ) ?? 0 );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT sub.email, s.campaign_id, s.flow_step_id, s.bounced_at, s.status
             FROM {$sends} s
             INNER JOIN {$subs} sub ON sub.id = s.subscriber_id
             WHERE s.bounced_at IS NOT NULL
             ORDER BY s.bounced_at DESC
             LIMIT %d OFFSET %d",
            $limit, $offset
        ) );

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$sends} WHERE bounced_at IS NOT NULL"
        );

        return new \WP_REST_Response( [
            'rows'  => $rows,
            'total' => $total,
        ] );
    }

    /**
     * SMS performance table.
     */
    public function sms_performance( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $sends = $wpdb->prefix . 'ams_sends';
        $camps = $wpdb->prefix . 'ams_campaigns';

        $rows = $wpdb->get_results(
            "SELECT
                c.id, c.name,
                COUNT(s.id) AS sent,
                SUM(CASE WHEN s.status = 'delivered' THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN s.unsubscribed_at IS NOT NULL THEN 1 ELSE 0 END) AS opted_out,
                COALESCE(SUM(s.revenue_attributed), 0) AS revenue
             FROM {$camps} c
             LEFT JOIN {$sends} s ON s.campaign_id = c.id AND s.channel = 'sms'
             WHERE c.type = 'sms'
             GROUP BY c.id
             ORDER BY c.created_at DESC"
        );

        $result = [];
        foreach ( $rows as $row ) {
            $sent = (int) $row->sent;
            $result[] = [
                'name'        => $row->name,
                'sent'        => $sent,
                'delivered'   => (int) $row->delivered,
                'opt_out_rate'=> $sent > 0 ? round( (int) $row->opted_out / $sent * 100, 2 ) : 0,
                'revenue'     => round( (float) $row->revenue, 2 ),
            ];
        }

        // SMS delivery rate time series (30d).
        $ad = $wpdb->prefix . 'ams_analytics_daily';
        $start = gmdate( 'Y-m-d', strtotime( '-30 days' ) );

        $sms_sent_series = $wpdb->get_results( $wpdb->prepare(
            "SELECT date, metric_value AS value FROM {$ad}
             WHERE metric_key = 'sms_sent' AND date >= %s ORDER BY date ASC",
            $start
        ) );
        $sms_delivered_series = $wpdb->get_results( $wpdb->prepare(
            "SELECT date, metric_value AS value FROM {$ad}
             WHERE metric_key = 'sms_delivered' AND date >= %s ORDER BY date ASC",
            $start
        ) );

        return new \WP_REST_Response( [
            'campaigns'      => $result,
            'delivery_trend' => [
                'sent'      => $sms_sent_series,
                'delivered' => $sms_delivered_series,
            ],
        ] );
    }

    /**
     * Subscriber insights: RFM heatmap, segment doughnut, churn bar, CLV histogram.
     */
    public function subscriber_insights( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $subs = $wpdb->prefix . 'ams_subscribers';
        $segs = $wpdb->prefix . 'ams_segments';

        // RFM heatmap: 5x5 grid of R/F scores with count.
        $rfm_raw = $wpdb->get_results(
            "SELECT
                SUBSTRING(rfm_score, 1, 1) AS r,
                SUBSTRING(rfm_score, 2, 1) AS f,
                COUNT(*) AS count
             FROM {$subs}
             WHERE status = 'active' AND rfm_score IS NOT NULL AND LENGTH(rfm_score) >= 2
             GROUP BY r, f"
        );

        $rfm_grid = [];
        foreach ( $rfm_raw as $row ) {
            $rfm_grid[] = [
                'r'     => (int) $row->r,
                'f'     => (int) $row->f,
                'count' => (int) $row->count,
            ];
        }

        // Segment doughnut.
        $segments = $wpdb->get_results(
            "SELECT name, subscriber_count FROM {$segs} ORDER BY subscriber_count DESC"
        );

        // Churn risk bar chart.
        $churn = [
            'low'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$subs} WHERE status = 'active' AND churn_risk_score <= 30" ),
            'medium' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$subs} WHERE status = 'active' AND churn_risk_score > 30 AND churn_risk_score <= 60" ),
            'high'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$subs} WHERE status = 'active' AND churn_risk_score > 60" ),
        ];

        // CLV histogram (buckets: 0-50, 50-100, 100-250, 250-500, 500-1000, 1000+).
        $clv_buckets = [
            '0-50'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$subs} WHERE status = 'active' AND predicted_clv IS NOT NULL AND predicted_clv < 50" ),
            '50-100'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$subs} WHERE status = 'active' AND predicted_clv >= 50 AND predicted_clv < 100" ),
            '100-250'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$subs} WHERE status = 'active' AND predicted_clv >= 100 AND predicted_clv < 250" ),
            '250-500'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$subs} WHERE status = 'active' AND predicted_clv >= 250 AND predicted_clv < 500" ),
            '500-1000' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$subs} WHERE status = 'active' AND predicted_clv >= 500 AND predicted_clv < 1000" ),
            '1000+'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$subs} WHERE status = 'active' AND predicted_clv >= 1000" ),
        ];

        return new \WP_REST_Response( [
            'rfm_grid'    => $rfm_grid,
            'segments'    => $segments,
            'churn_risk'  => $churn,
            'clv_buckets' => $clv_buckets,
        ] );
    }

    /**
     * Flow analytics: per-flow funnel.
     */
    public function flow_analytics( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $flows       = $wpdb->prefix . 'ams_flows';
        $steps       = $wpdb->prefix . 'ams_flow_steps';
        $enrolments  = $wpdb->prefix . 'ams_flow_enrolments';
        $sends       = $wpdb->prefix . 'ams_sends';

        $all_flows = $wpdb->get_results( "SELECT id, name FROM {$flows} ORDER BY name ASC" );

        $result = [];
        foreach ( $all_flows as $flow ) {
            $flow_id = (int) $flow->id;

            $enrolled = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$enrolments} WHERE flow_id = %d",
                $flow_id
            ) );
            $completed = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$enrolments} WHERE flow_id = %d AND status = 'completed'",
                $flow_id
            ) );
            $exited = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$enrolments} WHERE flow_id = %d AND status = 'exited'",
                $flow_id
            ) );

            // Per-step stats.
            $flow_steps = $wpdb->get_results( $wpdb->prepare(
                "SELECT fs.id, fs.step_order, fs.step_type,
                        COUNT(s.id) AS sent,
                        SUM(CASE WHEN s.opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened,
                        SUM(CASE WHEN s.clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked
                 FROM {$steps} fs
                 LEFT JOIN {$sends} s ON s.flow_step_id = fs.id
                 WHERE fs.flow_id = %d
                 GROUP BY fs.id
                 ORDER BY fs.step_order ASC",
                $flow_id
            ) );

            $step_data = [];
            $prev_count = $enrolled;
            foreach ( $flow_steps as $step ) {
                $sent = (int) $step->sent;
                $dropoff = $prev_count > 0 ? round( ( $prev_count - $sent ) / $prev_count * 100, 1 ) : 0;
                $step_data[] = [
                    'step_order' => (int) $step->step_order,
                    'step_type'  => $step->step_type,
                    'sent'       => $sent,
                    'opened'     => (int) $step->opened,
                    'clicked'    => (int) $step->clicked,
                    'dropoff'    => $dropoff,
                    'highlight'  => $dropoff > 20,
                ];
                $prev_count = $sent;
            }

            $result[] = [
                'id'        => $flow_id,
                'name'      => $flow->name,
                'enrolled'  => $enrolled,
                'completed' => $completed,
                'exited'    => $exited,
                'steps'     => $step_data,
            ];
        }

        return new \WP_REST_Response( $result );
    }

    /**
     * CSV export for any analytics table.
     */
    public function export_csv( \WP_REST_Request $request ): \WP_REST_Response {
        $type = sanitize_text_field( $request->get_param( 'type' ) ?? 'email' );

        // Delegate to the appropriate method and format as CSV.
        switch ( $type ) {
            case 'email':
                $data = $this->email_performance( $request )->get_data();
                break;
            case 'sms':
                $resp = $this->sms_performance( $request )->get_data();
                $data = $resp['campaigns'] ?? [];
                break;
            case 'bounces':
                $resp = $this->bounce_log( $request )->get_data();
                $data = $resp['rows'] ?? [];
                break;
            default:
                return new \WP_REST_Response( [ 'error' => 'Unknown export type.' ], 400 );
        }

        if ( empty( $data ) ) {
            return new \WP_REST_Response( [ 'csv' => '' ] );
        }

        $csv = '';
        // Header row.
        if ( is_array( $data[0] ) ) {
            $csv .= implode( ',', array_keys( $data[0] ) ) . "\n";
            foreach ( $data as $row ) {
                $csv .= implode( ',', array_map( function ( $v ) {
                    return '"' . str_replace( '"', '""', (string) $v ) . '"';
                }, array_values( $row ) ) ) . "\n";
            }
        } elseif ( is_object( $data[0] ) ) {
            $csv .= implode( ',', array_keys( (array) $data[0] ) ) . "\n";
            foreach ( $data as $row ) {
                $csv .= implode( ',', array_map( function ( $v ) {
                    return '"' . str_replace( '"', '""', (string) $v ) . '"';
                }, array_values( (array) $row ) ) ) . "\n";
            }
        }

        return new \WP_REST_Response( [ 'csv' => $csv, 'filename' => "ams-{$type}-export.csv" ] );
    }

    /**
     * Get analytics settings.
     */
    public function get_settings( \WP_REST_Request $request ): \WP_REST_Response {
        $settings = get_option( 'ams_analytics_settings', [] );
        return new \WP_REST_Response( [
            'attribution_window_days' => (int) ( $settings['attribution_window_days'] ?? 5 ),
        ] );
    }

    /**
     * Save analytics settings.
     */
    public function save_settings( \WP_REST_Request $request ): \WP_REST_Response {
        $window = max( 1, min( 30, (int) $request->get_param( 'attribution_window_days' ) ) );
        update_option( 'ams_analytics_settings', [
            'attribution_window_days' => $window,
        ] );
        return new \WP_REST_Response( [ 'success' => true ] );
    }
}
