<?php

namespace Apotheca\Marketing\Sync;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Checks for abandoned carts (inactive > 60 min) and dispatches events.
 *
 * Runs via Action Scheduler every 15 minutes, processes up to 200 per run.
 */
class AbandonedCartChecker {

    private const HOOK = 'ams_sync_check_abandoned_carts';

    public function __construct() {
        add_action( self::HOOK, [ $this, 'check' ] );

        // Schedule recurring check if not already scheduled.
        if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::HOOK ) ) {
            as_schedule_recurring_action( time() + 300, 900, self::HOOK, [], 'ams-sync' );
        }
    }

    /**
     * Find carts inactive > 60 minutes and dispatch abandoned_cart events.
     */
    public function check(): void {
        if ( ! Plugin::is_event_enabled( 'abandoned_cart' ) ) {
            return;
        }

        global $wpdb;

        $cutoff = gmdate( 'Y-m-d H:i:s', time() - 3600 );

        // WooCommerce stores sessions in wp_woocommerce_sessions.
        $sessions = $wpdb->get_results( $wpdb->prepare(
            "SELECT session_key, session_value, session_expiry
             FROM {$wpdb->prefix}woocommerce_sessions
             WHERE FROM_UNIXTIME(session_expiry - 172800) < %s
             LIMIT 200",
            $cutoff
        ) );

        if ( empty( $sessions ) ) {
            return;
        }

        foreach ( $sessions as $session ) {
            $data = maybe_unserialize( $session->session_value );
            if ( ! is_array( $data ) ) {
                continue;
            }

            $cart = isset( $data['cart'] ) ? maybe_unserialize( $data['cart'] ) : [];
            if ( empty( $cart ) ) {
                continue;
            }

            // Check if already dispatched for this session.
            $dispatched_key = 'ams_abandoned_' . md5( $session->session_key );
            if ( get_transient( $dispatched_key ) ) {
                continue;
            }

            $customer_email = '';
            if ( isset( $data['customer'] ) ) {
                $customer = maybe_unserialize( $data['customer'] );
                if ( is_array( $customer ) && ! empty( $customer['email'] ) ) {
                    $customer_email = $customer['email'];
                }
            }

            // Skip if no email — we can't send abandoned cart emails without one.
            if ( empty( $customer_email ) ) {
                continue;
            }

            $items      = [];
            $cart_total  = 0;
            foreach ( $cart as $item ) {
                $product_id = $item['product_id'] ?? 0;
                $qty        = $item['quantity'] ?? 1;
                $price      = 0;
                if ( ! empty( $item['line_total'] ) ) {
                    $price = (float) $item['line_total'] / max( 1, $qty );
                }
                $items[] = [
                    'product_id' => $product_id,
                    'qty'        => $qty,
                    'price'      => $price,
                ];
                $cart_total += (float) ( $item['line_total'] ?? 0 );
            }

            Dispatcher::schedule( 'abandoned_cart', [
                'customer_email' => $customer_email,
                'cart_items'     => $items,
                'cart_total'     => $cart_total,
                'session_id'     => $session->session_key,
            ] );

            // Mark as dispatched for 24 hours to avoid duplicates.
            set_transient( $dispatched_key, 1, DAY_IN_SECONDS );
        }
    }
}
