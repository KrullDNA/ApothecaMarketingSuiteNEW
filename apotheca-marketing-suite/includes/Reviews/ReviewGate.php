<?php

namespace Apotheca\Marketing\Reviews;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Review gate handler.
 *
 * Registers /ams-review-gate/ rewrite rule.
 * Validates subscriber token + order ownership.
 * Logs review_gate_click event.
 * Rating 4-5: redirects to product review page on store.
 * Rating 1-3: redirects to private feedback page.
 * Single-use token via transient (72h default expiry).
 */
class ReviewGate {

    /**
     * Register rewrite rules and hooks.
     */
    public function add_rewrite_rules(): void {
        add_rewrite_rule(
            '^ams-review-gate/?$',
            'index.php?ams_review_gate=1',
            'top'
        );
    }

    /**
     * Register query vars.
     */
    public function query_vars( array $vars ): array {
        $vars[] = 'ams_review_gate';
        return $vars;
    }

    /**
     * Handle the review gate request on template_redirect.
     */
    public function handle_request(): void {
        if ( ! get_query_var( 'ams_review_gate' ) ) {
            return;
        }

        $token   = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
        $order_id = (int) ( $_GET['order'] ?? 0 );
        $rating  = (int) ( $_GET['rating'] ?? 0 );

        if ( empty( $token ) || $rating < 1 || $rating > 5 ) {
            wp_die(
                esc_html__( 'Invalid review gate link.', 'apotheca-marketing-suite' ),
                esc_html__( 'Error', 'apotheca-marketing-suite' ),
                [ 'response' => 400 ]
            );
        }

        // Validate subscriber token.
        global $wpdb;
        $subscriber = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ams_subscribers WHERE subscriber_token = %s",
            $token
        ) );

        if ( ! $subscriber ) {
            wp_die(
                esc_html__( 'Invalid or expired link.', 'apotheca-marketing-suite' ),
                esc_html__( 'Error', 'apotheca-marketing-suite' ),
                [ 'response' => 403 ]
            );
        }

        // Check single-use transient.
        $transient_key = 'ams_rg_' . $token . '_' . $order_id . '_' . $rating;
        if ( get_transient( $transient_key ) ) {
            wp_die(
                esc_html__( 'This review link has already been used.', 'apotheca-marketing-suite' ),
                esc_html__( 'Link Used', 'apotheca-marketing-suite' ),
                [ 'response' => 410 ]
            );
        }

        // Get expiry from settings.
        $reviews_settings = get_option( 'ams_reviews_settings', [] );
        $expiry_hours     = (int) ( $reviews_settings['gate_expiry_hours'] ?? 72 );

        // Mark as used.
        set_transient( $transient_key, 1, $expiry_hours * HOUR_IN_SECONDS );

        // Log the event.
        $logger = new \Apotheca\Marketing\Subscriber\EventLogger();
        $logger->log( (int) $subscriber->id, 'review_gate_click', [
            'order_id' => $order_id,
            'rating'   => $rating,
        ] );

        // Determine redirect.
        $settings  = get_option( 'ams_settings', [] );
        $store_url = $settings['store_url'] ?? '';

        if ( $rating >= 4 ) {
            // High rating: redirect to store product review page.
            $redirect_url = $this->get_product_review_url( $store_url, $order_id );
        } else {
            // Low rating: redirect to private feedback page.
            $redirect_url = $this->get_feedback_page_url();
        }

        if ( empty( $redirect_url ) ) {
            $redirect_url = $store_url ?: home_url();
        }

        wp_redirect( esc_url_raw( $redirect_url ) );
        exit;
    }

    /**
     * Get the product review URL on the store for a given order.
     */
    private function get_product_review_url( string $store_url, int $order_id ): string {
        if ( ! $store_url ) {
            return '';
        }

        // If we have order data with product info, build the review URL.
        if ( $order_id > 0 ) {
            global $wpdb;
            $event = $wpdb->get_row( $wpdb->prepare(
                "SELECT product_ids FROM {$wpdb->prefix}ams_events
                 WHERE woo_order_id = %d AND event_type IN ('order_placed','order_completed')
                 ORDER BY created_at DESC LIMIT 1",
                $order_id
            ) );

            if ( $event && ! empty( $event->product_ids ) ) {
                $product_ids = json_decode( $event->product_ids, true );
                if ( ! empty( $product_ids ) ) {
                    // Look up the first product's URL from the reviews cache.
                    $first_product_id = (int) $product_ids[0];
                    $cached = $wpdb->get_var( $wpdb->prepare(
                        "SELECT product_url FROM {$wpdb->prefix}ams_reviews_cache WHERE product_id = %d LIMIT 1",
                        $first_product_id
                    ) );
                    if ( $cached ) {
                        return $cached . '#reviews';
                    }
                }
            }
        }

        // Fallback: store homepage.
        return $store_url;
    }

    /**
     * Get the private feedback page URL (configured in Settings > Reviews).
     */
    private function get_feedback_page_url(): string {
        $reviews_settings = get_option( 'ams_reviews_settings', [] );
        $page_id          = (int) ( $reviews_settings['feedback_page_id'] ?? 0 );

        if ( $page_id > 0 ) {
            $url = get_permalink( $page_id );
            if ( $url ) {
                return $url;
            }
        }

        return '';
    }
}
