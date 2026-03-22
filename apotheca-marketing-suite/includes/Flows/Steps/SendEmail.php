<?php

namespace Apotheca\Marketing\Flows\Steps;

use Apotheca\Marketing\Flows\StepExecutorInterface;
use Apotheca\Marketing\Campaigns\TokenReplacer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SendEmail implements StepExecutorInterface {

    public function execute( object $step, object $subscriber, object $enrolment ): mixed {
        global $wpdb;

        // Build full context with all available tokens (order, product, cart, coupon, AI, etc.).
        $enrolment_data = $this->get_enrolment_data( $enrolment, $subscriber );
        $context        = TokenReplacer::build_context(
            $subscriber,
            $enrolment_data['order'] ?? [],
            $enrolment_data['product'] ?? [],
            $enrolment_data['extra'] ?? []
        );

        $replacer     = new TokenReplacer();
        $subject      = $replacer->replace( $step->subject ?? '', $context );
        $preview_text = $replacer->replace( $step->preview_text ?? '', $context );
        $body_html    = $replacer->replace( $step->body_html ?? '', $context );
        $body_text    = $replacer->replace( $step->body_text ?? '', $context );

        // Add unsubscribe link.
        $unsub_url = home_url( '/ams-unsubscribe/?token=' . urlencode( $subscriber->unsubscribe_token ) );
        $body_html .= '<p style="font-size:12px;color:#999;"><a href="' . esc_url( $unsub_url ) . '">Unsubscribe</a></p>';
        if ( $body_text ) {
            $body_text .= "\n\nUnsubscribe: " . $unsub_url;
        }

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'List-Unsubscribe: <' . $unsub_url . '>',
        ];

        if ( $preview_text ) {
            $body_html = '<div style="display:none;max-height:0;overflow:hidden;">' . esc_html( $preview_text ) . '</div>' . $body_html;
        }

        $sent = wp_mail( $subscriber->email, $subject, $body_html, $headers );

        // Record send.
        $sends_table = $wpdb->prefix . 'ams_sends';
        $wpdb->insert( $sends_table, [
            'flow_step_id'  => (int) $step->id,
            'subscriber_id' => (int) $subscriber->id,
            'channel'       => 'email',
            'status'        => $sent ? 'sent' : 'failed',
            'sent_at'       => $sent ? current_time( 'mysql', true ) : null,
            'created_at'    => current_time( 'mysql', true ),
        ] );

        // Log event.
        $logger = new \Apotheca\Marketing\Subscriber\EventLogger();
        $logger->log( (int) $subscriber->id, 'flow_email_sent', [
            'flow_id'      => (int) $enrolment->flow_id,
            'flow_step_id' => (int) $step->id,
            'subject'      => $subject,
            'success'      => $sent,
        ] );

        return $sent;
    }

    /**
     * Gather order/product/cart data from recent events for token replacement.
     */
    private function get_enrolment_data( object $enrolment, object $subscriber ): array {
        global $wpdb;

        $events_table = $wpdb->prefix . 'ams_events';
        $order        = [];
        $product      = [];
        $extra        = [];

        // Look for the most recent order event for this subscriber.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $order_event = $wpdb->get_row( $wpdb->prepare(
            "SELECT properties FROM {$events_table}
             WHERE subscriber_id = %d AND event_type IN ('placed_order', 'order_completed')
             ORDER BY created_at DESC LIMIT 1",
            (int) $subscriber->id
        ) );

        if ( $order_event && $order_event->properties ) {
            $props = json_decode( $order_event->properties, true ) ?: [];
            $order = [
                'order_number' => $props['order_number'] ?? $props['order_id'] ?? '',
                'order_total'  => $props['order_total'] ?? $props['total'] ?? '',
                'order_date'   => $props['order_date'] ?? $props['created_at'] ?? '',
                'order_status' => $props['order_status'] ?? $props['status'] ?? '',
                'cart_url'     => $props['cart_url'] ?? '',
                'cart_total'   => $props['cart_total'] ?? $props['order_total'] ?? '',
            ];
        }

        // Look for the most recent product/cart event.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $product_event = $wpdb->get_row( $wpdb->prepare(
            "SELECT properties FROM {$events_table}
             WHERE subscriber_id = %d AND event_type IN ('added_to_cart', 'viewed_product')
             ORDER BY created_at DESC LIMIT 1",
            (int) $subscriber->id
        ) );

        if ( $product_event && $product_event->properties ) {
            $props   = json_decode( $product_event->properties, true ) ?: [];
            $product = [
                'product_name'      => $props['product_name'] ?? $props['name'] ?? '',
                'product_url'       => $props['product_url'] ?? $props['url'] ?? '',
                'product_image_url' => $props['product_image_url'] ?? $props['image_url'] ?? '',
                'product_price'     => $props['product_price'] ?? $props['price'] ?? '',
            ];
        }

        // Cart URL fallback from settings.
        if ( empty( $order['cart_url'] ) ) {
            $settings          = get_option( 'ams_settings', [] );
            $store_url         = $settings['store_url'] ?? home_url();
            $order['cart_url'] = rtrim( $store_url, '/' ) . '/cart';
        }

        return [
            'order'   => $order,
            'product' => $product,
            'extra'   => $extra,
        ];
    }
}
