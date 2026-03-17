<?php

namespace Apotheca\Marketing\Flows\Triggers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Abandoned Cart trigger: fires when no order within 60min of cart event.
 *
 * The IngestEndpoint::handle_cart_updated() schedules an AS job
 * 'ams_check_abandoned_cart' for 60 minutes after the last cart update.
 * This class listens to that job and checks if an order was placed.
 */
class AbandonedCart {

    public function register(): void {
        add_action( 'ams_check_abandoned_cart', [ $this, 'check' ] );
    }

    /**
     * Check if the subscriber has placed an order since the cart event.
     */
    public function check( int $subscriber_id ): void {
        global $wpdb;
        $events_table = $wpdb->prefix . 'ams_events';

        // Find the most recent cart event.
        $cart_event = $wpdb->get_row( $wpdb->prepare(
            "SELECT created_at FROM {$events_table}
             WHERE subscriber_id = %d AND event_type = 'added_to_cart'
             ORDER BY created_at DESC LIMIT 1",
            $subscriber_id
        ) );

        if ( ! $cart_event ) {
            return;
        }

        // Check if an order was placed after the cart event.
        $order_after = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$events_table}
             WHERE subscriber_id = %d AND event_type = 'placed_order' AND created_at > %s",
            $subscriber_id,
            $cart_event->created_at
        ) );

        if ( (int) $order_after > 0 ) {
            return; // Order was placed, not abandoned.
        }

        // Fire the abandoned_cart trigger for flows.
        do_action( 'ams_flow_trigger', 'abandoned_cart', $subscriber_id, [
            'trigger_source' => 'auto_check',
        ] );
    }
}
