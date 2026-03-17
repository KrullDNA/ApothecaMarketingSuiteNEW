<?php

namespace Apotheca\Marketing\Subscriber;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EventLogger {

    /**
     * Insert an event into ams_events.
     *
     * @return int Event ID.
     */
    public function log( int $subscriber_id, string $event_type, array $event_data = [], int $woo_order_id = 0, array $product_ids = [] ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_events';

        $wpdb->insert( $table, [
            'subscriber_id' => $subscriber_id,
            'event_type'    => sanitize_text_field( $event_type ),
            'event_data'    => wp_json_encode( $event_data ),
            'woo_order_id'  => $woo_order_id ?: null,
            'product_ids'   => ! empty( $product_ids ) ? wp_json_encode( $product_ids ) : null,
            'created_at'    => current_time( 'mysql', true ),
        ], [ '%d', '%s', '%s', '%d', '%s', '%s' ] );

        return (int) $wpdb->insert_id;
    }

    /**
     * Get events for a subscriber.
     */
    public function get_subscriber_events( int $subscriber_id, int $limit = 50, int $offset = 0 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_events';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE subscriber_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $subscriber_id,
            $limit,
            $offset
        ) );
    }
}
