<?php

namespace Apotheca\Marketing\Sync;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Hooks into WooCommerce events and schedules dispatch jobs.
 */
class EventHooks {

    public function __construct() {
        // Register the AS dispatch handler.
        add_action( 'ams_sync_dispatch', [ Dispatcher::class, 'send' ], 10, 3 );

        // 1. customer_registered
        add_action( 'user_register', [ $this, 'on_customer_registered' ] );

        // 2. order_placed
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'on_order_placed' ], 10, 3 );

        // 3. order_status_changed
        add_action( 'woocommerce_order_status_changed', [ $this, 'on_order_status_changed' ], 10, 3 );

        // 4. cart_updated
        add_action( 'woocommerce_cart_updated', [ $this, 'on_cart_updated' ] );

        // 5. product_viewed — handled by ProductViewBeacon (AJAX).

        // 6. checkout_started
        add_action( 'woocommerce_before_checkout_form', [ $this, 'on_checkout_started' ] );
    }

    /**
     * 1. customer_registered
     */
    public function on_customer_registered( int $user_id ): void {
        if ( ! Plugin::is_event_enabled( 'customer_registered' ) ) {
            return;
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }

        Dispatcher::schedule( 'customer_registered', [
            'user_id'       => $user_id,
            'email'         => $user->user_email,
            'first_name'    => $user->first_name,
            'last_name'     => $user->last_name,
            'registered_at' => $user->user_registered,
        ] );
    }

    /**
     * 2. order_placed
     */
    public function on_order_placed( int $order_id, array $posted_data, \WC_Order $order ): void {
        if ( ! Plugin::is_event_enabled( 'order_placed' ) ) {
            return;
        }

        $items = [];
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $items[] = [
                'id'    => $product ? $product->get_id() : 0,
                'name'  => $item->get_name(),
                'price' => (float) $item->get_total(),
                'qty'   => $item->get_quantity(),
            ];
        }

        $coupons = [];
        foreach ( $order->get_coupon_codes() as $code ) {
            $coupons[] = $code;
        }

        Dispatcher::schedule( 'order_placed', [
            'order_id'           => $order_id,
            'customer_email'     => $order->get_billing_email(),
            'customer_id'        => $order->get_customer_id(),
            'order_total'        => (float) $order->get_total(),
            'order_status'       => $order->get_status(),
            'product_ids'        => $items,
            'billing_first_name' => $order->get_billing_first_name(),
            'billing_last_name'  => $order->get_billing_last_name(),
            'billing_city'       => $order->get_billing_city(),
            'billing_country'    => $order->get_billing_country(),
            'coupon_codes'       => $coupons,
            'created_at'         => $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d H:i:s' ) : current_time( 'mysql', true ),
        ] );
    }

    /**
     * 3. order_status_changed
     */
    public function on_order_status_changed( int $order_id, string $old_status, string $new_status ): void {
        if ( ! Plugin::is_event_enabled( 'order_status_changed' ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        Dispatcher::schedule( 'order_status_changed', [
            'order_id'       => $order_id,
            'customer_email' => $order->get_billing_email(),
            'old_status'     => $old_status,
            'new_status'     => $new_status,
            'changed_at'     => current_time( 'mysql', true ),
        ] );
    }

    /**
     * 4. cart_updated
     */
    public function on_cart_updated(): void {
        if ( ! Plugin::is_event_enabled( 'cart_updated' ) ) {
            return;
        }

        $cart = WC()->cart;
        if ( ! $cart ) {
            return;
        }

        $customer_email = '';
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            $customer_email = $user->user_email;
        }

        $items = [];
        foreach ( $cart->get_cart() as $item ) {
            $product = $item['data'] ?? null;
            $items[] = [
                'product_id' => $item['product_id'],
                'qty'        => $item['quantity'],
                'price'      => $product ? (float) $product->get_price() : 0,
            ];
        }

        $session_id = WC()->session ? WC()->session->get_customer_id() : '';

        Dispatcher::schedule( 'cart_updated', [
            'customer_email' => $customer_email,
            'session_id'     => $session_id,
            'cart_items'     => $items,
            'cart_total'     => (float) $cart->get_total( 'raw' ),
            'updated_at'     => current_time( 'mysql', true ),
        ] );
    }

    /**
     * 6. checkout_started
     */
    public function on_checkout_started(): void {
        if ( ! Plugin::is_event_enabled( 'checkout_started' ) ) {
            return;
        }

        $cart = WC()->cart;
        if ( ! $cart ) {
            return;
        }

        $customer_email = '';
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            $customer_email = $user->user_email;
        }

        $items = [];
        foreach ( $cart->get_cart() as $item ) {
            $product = $item['data'] ?? null;
            $items[] = [
                'product_id' => $item['product_id'],
                'qty'        => $item['quantity'],
                'price'      => $product ? (float) $product->get_price() : 0,
            ];
        }

        $session_id = WC()->session ? WC()->session->get_customer_id() : '';

        Dispatcher::schedule( 'checkout_started', [
            'customer_email' => $customer_email,
            'cart_items'     => $items,
            'cart_total'     => (float) $cart->get_total( 'raw' ),
            'session_id'     => $session_id,
            'started_at'     => current_time( 'mysql', true ),
        ] );
    }
}
