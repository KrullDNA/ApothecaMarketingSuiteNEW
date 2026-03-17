<?php

namespace Apotheca\Marketing\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Last-click revenue attribution engine.
 *
 * On order_placed ingest event, looks back X days in ams_sends for the
 * most recent opened or clicked send for this subscriber. If found,
 * inserts an ams_attributions row and updates revenue_attributed on the send.
 */
class AttributionEngine {

    private const DEFAULT_WINDOW_DAYS = 5;

    /**
     * Register the hook that fires after an order_placed event is ingested.
     */
    public function register(): void {
        add_action( 'ams_order_placed', [ $this, 'attribute' ], 10, 3 );
    }

    /**
     * Attribute revenue for an order to the most recent send interaction.
     *
     * @param int   $subscriber_id Subscriber ID.
     * @param int   $order_id      WooCommerce order ID.
     * @param float $order_total   Order total amount.
     */
    public function attribute( int $subscriber_id, int $order_id, float $order_total ): void {
        if ( $subscriber_id <= 0 || $order_total <= 0 ) {
            return;
        }

        // Check for duplicate attribution.
        global $wpdb;
        $attr_table = $wpdb->prefix . 'ams_attributions';

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$attr_table} WHERE subscriber_id = %d AND woo_order_id = %d LIMIT 1",
            $subscriber_id,
            $order_id
        ) );

        if ( $existing ) {
            return;
        }

        $send = $this->find_attributable_send( $subscriber_id );
        if ( ! $send ) {
            return;
        }

        // Insert attribution record.
        $wpdb->insert( $attr_table, [
            'send_id'       => (int) $send->id,
            'campaign_id'   => $send->campaign_id ? (int) $send->campaign_id : null,
            'flow_id'       => $send->flow_step_id ? $this->get_flow_id_for_step( (int) $send->flow_step_id ) : null,
            'flow_step_id'  => $send->flow_step_id ? (int) $send->flow_step_id : null,
            'subscriber_id' => $subscriber_id,
            'woo_order_id'  => $order_id,
            'order_total'   => $order_total,
            'attributed_at' => current_time( 'mysql', true ),
        ] );

        // Update revenue_attributed on the send.
        $sends_table = $wpdb->prefix . 'ams_sends';
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$sends_table} SET revenue_attributed = revenue_attributed + %f WHERE id = %d",
            $order_total,
            (int) $send->id
        ) );
    }

    /**
     * Find the most recent send that the subscriber opened or clicked within the attribution window.
     */
    private function find_attributable_send( int $subscriber_id ): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_sends';

        $settings    = get_option( 'ams_analytics_settings', [] );
        $window_days = (int) ( $settings['attribution_window_days'] ?? self::DEFAULT_WINDOW_DAYS );

        $send = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, campaign_id, flow_step_id, channel
             FROM {$table}
             WHERE subscriber_id = %d
               AND (opened_at IS NOT NULL OR clicked_at IS NOT NULL)
               AND sent_at >= DATE_SUB(%s, INTERVAL %d DAY)
             ORDER BY COALESCE(clicked_at, opened_at) DESC
             LIMIT 1",
            $subscriber_id,
            current_time( 'mysql', true ),
            $window_days
        ) );

        return $send ?: null;
    }

    /**
     * Look up the flow_id for a given flow_step_id.
     */
    private function get_flow_id_for_step( int $step_id ): ?int {
        global $wpdb;
        $flow_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT flow_id FROM {$wpdb->prefix}ams_flow_steps WHERE id = %d",
            $step_id
        ) );
        return $flow_id ? (int) $flow_id : null;
    }
}
