<?php

namespace Apotheca\Marketing\Sync;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Loads a < 2kb vanilla JS beacon ONLY on single product pages.
 * Beacon POSTs to admin-ajax.php, AJAX handler queues AS job.
 */
class ProductViewBeacon {

    public function __construct() {
        add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue' ] );
        add_action( 'wp_ajax_ams_sync_product_view', [ $this, 'handle_ajax' ] );
        add_action( 'wp_ajax_nopriv_ams_sync_product_view', [ $this, 'handle_ajax' ] );
        add_action( 'ams_sync_dispatch_product_view', [ $this, 'dispatch_product_view' ], 10, 1 );
    }

    /**
     * Enqueue beacon JS only on single product pages.
     */
    public function maybe_enqueue(): void {
        if ( ! is_singular( 'product' ) ) {
            return;
        }

        if ( ! Plugin::is_event_enabled( 'product_viewed' ) ) {
            return;
        }

        global $post;
        $product = wc_get_product( $post->ID );
        if ( ! $product ) {
            return;
        }

        wp_enqueue_script(
            'ams-sync-beacon',
            AMS_SYNC_URL . 'assets/js/ams-beacon.min.js',
            [],
            AMS_SYNC_VERSION,
            true
        );

        wp_localize_script( 'ams-sync-beacon', 'amsSyncBeacon', [
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'productId'  => $product->get_id(),
            'nonce'      => wp_create_nonce( 'ams_product_view' ),
        ] );
    }

    /**
     * AJAX handler for product view beacon.
     */
    public function handle_ajax(): void {
        check_ajax_referer( 'ams_product_view', 'nonce' );

        $product_id       = absint( $_POST['product_id'] ?? 0 );
        $subscriber_token = sanitize_text_field( $_POST['subscriber_token'] ?? '' );

        if ( ! $product_id ) {
            wp_send_json_error( 'Invalid product ID.', 400 );
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            wp_send_json_error( 'Product not found.', 404 );
        }

        $category_ids = $product->get_category_ids();
        $session_id   = WC()->session ? WC()->session->get_customer_id() : '';

        as_enqueue_async_action(
            'ams_sync_dispatch_product_view',
            [ [
                'product_id'       => $product_id,
                'product_name'     => $product->get_name(),
                'category_ids'     => $category_ids,
                'subscriber_token' => $subscriber_token ?: null,
                'session_id'       => $session_id,
                'viewed_at'        => current_time( 'mysql', true ),
            ] ],
            'ams-sync'
        );

        wp_send_json_success();
    }

    /**
     * Dispatch the product_viewed event (called by Action Scheduler).
     */
    public function dispatch_product_view( array $payload ): void {
        Dispatcher::send( 'product_viewed', $payload );
    }
}
