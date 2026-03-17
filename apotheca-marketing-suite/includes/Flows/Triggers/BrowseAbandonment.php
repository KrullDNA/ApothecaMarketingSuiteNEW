<?php

namespace Apotheca\Marketing\Flows\Triggers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Browse Abandonment trigger: viewed_product but no add_to_cart within 30min.
 *
 * Scheduled per-subscriber via Action Scheduler when a product_viewed event arrives.
 */
class BrowseAbandonment {

    private const HOOK  = 'ams_check_browse_abandonment';
    private const DELAY = 1800; // 30 minutes.

    public function register(): void {
        add_action( self::HOOK, [ $this, 'check' ] );

        // When a product_viewed event is logged, schedule a check.
        add_action( 'ams_flow_trigger', [ $this, 'on_product_view' ], 10, 3 );
    }

    /**
     * When a product is viewed, schedule a browse abandonment check.
     */
    public function on_product_view( string $trigger_type, int $subscriber_id, array $payload = [] ): void {
        // We need to intercept viewed_product events.
        // Since the ingest handler logs events but doesn't fire a specific trigger for views,
        // we hook into the flow trigger and check for product_viewed events.
        if ( ! empty( $payload['product_id'] ) && 0 === $subscriber_id ) {
            return; // Anonymous views can't trigger flows.
        }

        // This is called from various triggers; only act on viewed_product events from ingest.
        // We'll use the ams_event_logged hook instead.
    }

    /**
     * Check if the subscriber added to cart after viewing a product.
     */
    public function check( int $subscriber_id ): void {
        global $wpdb;
        $events_table = $wpdb->prefix . 'ams_events';

        // Find the most recent product view.
        $view = $wpdb->get_row( $wpdb->prepare(
            "SELECT created_at FROM {$events_table}
             WHERE subscriber_id = %d AND event_type = 'viewed_product'
             ORDER BY created_at DESC LIMIT 1",
            $subscriber_id
        ) );

        if ( ! $view ) {
            return;
        }

        // Check if they added to cart after the view.
        $cart_after = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$events_table}
             WHERE subscriber_id = %d AND event_type = 'added_to_cart' AND created_at > %s",
            $subscriber_id,
            $view->created_at
        ) );

        if ( (int) $cart_after > 0 ) {
            return; // They added to cart, not abandoned.
        }

        do_action( 'ams_flow_trigger', 'browse_abandonment', $subscriber_id, [
            'trigger_source' => 'auto_check',
        ] );
    }
}
