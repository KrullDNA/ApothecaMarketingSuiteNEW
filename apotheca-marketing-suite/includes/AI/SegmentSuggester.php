<?php

namespace Apotheca\Marketing\AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AI-powered segment suggestion engine.
 *
 * Sends RFM distribution, avg CLV, top product categories,
 * and recent campaign performance to OpenAI.
 * Returns 5 segment suggestions with name, description, and condition JSON.
 */
class SegmentSuggester {

    private const HOOK = 'ams_ai_suggest_segments';

    public function register(): void {
        add_action( self::HOOK, [ $this, 'process' ], 10, 1 );
    }

    /**
     * Schedule an async segment suggestion.
     */
    public function schedule(): string {
        $key = 'ams_ai_seg_' . wp_generate_password( 12, false );
        set_transient( $key, [ 'status' => 'processing' ], HOUR_IN_SECONDS );

        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action( time(), self::HOOK, [ $key ], 'ams_ai' );
        } else {
            $this->process( $key );
        }

        return $key;
    }

    /**
     * Process the suggestion (called by Action Scheduler).
     */
    public function process( string $key ): void {
        $context = $this->gather_context();

        $system = 'You are a marketing analytics expert. Based on the subscriber data provided, '
            . 'suggest exactly 5 audience segments that would be valuable for email marketing campaigns. '
            . 'Return a JSON array where each item has: "name" (string), "description" (string), '
            . '"conditions" (array of condition objects with "field", "operator", "value"). '
            . 'Valid fields: total_orders, total_spent, rfm_segment, churn_risk_score, last_order_date, tags, status. '
            . 'Valid operators: equals, not_equals, greater_than, less_than, contains, date_before, date_after. '
            . 'Return only valid JSON.';

        $user = "Subscriber Analytics:\n" . $context;

        $provider = new OpenAIProvider();
        $result   = $provider->chat( $system, $user, 'segment_suggestions', 0, 0.7, 1500 );

        if ( $result['success'] ) {
            $parsed = json_decode( $result['content'], true );
            if ( ! is_array( $parsed ) ) {
                if ( preg_match( '/\[.*\]/s', $result['content'], $m ) ) {
                    $parsed = json_decode( $m[0], true );
                }
            }
            set_transient( $key, [
                'status'      => 'complete',
                'suggestions' => is_array( $parsed ) ? $parsed : [],
            ], HOUR_IN_SECONDS );
        } else {
            set_transient( $key, [
                'status' => 'error',
                'error'  => $result['error'],
            ], HOUR_IN_SECONDS );
        }
    }

    /**
     * Get the result.
     */
    public function get_result( string $key ): array {
        $data = get_transient( $key );
        if ( ! $data ) {
            return [ 'status' => 'not_found' ];
        }
        return $data;
    }

    /**
     * Gather subscriber context for the AI prompt.
     */
    private function gather_context(): string {
        global $wpdb;
        $subs = $wpdb->prefix . 'ams_subscribers';
        $sends = $wpdb->prefix . 'ams_sends';
        $camps = $wpdb->prefix . 'ams_campaigns';

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$subs} WHERE status = 'active'" );
        $avg_clv = (float) $wpdb->get_var( "SELECT COALESCE(AVG(predicted_clv), 0) FROM {$subs} WHERE status = 'active' AND predicted_clv IS NOT NULL" );
        $avg_orders = (float) $wpdb->get_var( "SELECT COALESCE(AVG(total_orders), 0) FROM {$subs} WHERE status = 'active'" );
        $avg_spent = (float) $wpdb->get_var( "SELECT COALESCE(AVG(total_spent), 0) FROM {$subs} WHERE status = 'active'" );

        // RFM distribution.
        $rfm = $wpdb->get_results(
            "SELECT rfm_segment, COUNT(*) AS cnt FROM {$subs}
             WHERE status = 'active' AND rfm_segment IS NOT NULL
             GROUP BY rfm_segment ORDER BY cnt DESC LIMIT 10"
        );
        $rfm_text = '';
        foreach ( $rfm as $row ) {
            $rfm_text .= "  {$row->rfm_segment}: {$row->cnt}\n";
        }

        // Churn distribution.
        $churn_low  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$subs} WHERE status = 'active' AND churn_risk_score <= 30" );
        $churn_med  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$subs} WHERE status = 'active' AND churn_risk_score > 30 AND churn_risk_score <= 60" );
        $churn_high = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$subs} WHERE status = 'active' AND churn_risk_score > 60" );

        // Recent campaign performance.
        $recent_campaigns = $wpdb->get_results(
            "SELECT c.name,
                    COUNT(s.id) AS sent,
                    SUM(CASE WHEN s.opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened,
                    SUM(CASE WHEN s.clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked
             FROM {$camps} c
             LEFT JOIN {$sends} s ON s.campaign_id = c.id
             WHERE c.status = 'sent'
             GROUP BY c.id
             ORDER BY c.sent_at DESC LIMIT 5"
        );
        $camp_text = '';
        foreach ( $recent_campaigns as $c ) {
            $sent = (int) $c->sent;
            $or = $sent > 0 ? round( (int) $c->opened / $sent * 100, 1 ) : 0;
            $cr = $sent > 0 ? round( (int) $c->clicked / $sent * 100, 1 ) : 0;
            $camp_text .= "  {$c->name}: sent={$sent}, open_rate={$or}%, click_rate={$cr}%\n";
        }

        return "Total active subscribers: {$total}\n"
            . "Average CLV: \${$avg_clv}\n"
            . "Average orders: {$avg_orders}\n"
            . "Average total spent: \${$avg_spent}\n"
            . "RFM Segment Distribution:\n{$rfm_text}"
            . "Churn Risk: low={$churn_low}, medium={$churn_med}, high={$churn_high}\n"
            . "Recent Campaign Performance:\n{$camp_text}";
    }
}
