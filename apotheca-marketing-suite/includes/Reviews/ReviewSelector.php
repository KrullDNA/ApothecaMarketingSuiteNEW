<?php

namespace Apotheca\Marketing\Reviews;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Selects reviews from the local cache for use in email blocks and widgets.
 *
 * Modes:
 *   auto_contextual — picks reviews based on subscriber context
 *   specific_product — by product_id
 *   top_rated — ORDER BY rating DESC
 *   most_recent — WHERE rating >= 4 ORDER BY review_date DESC
 */
class ReviewSelector {

    /**
     * Get reviews based on mode and context.
     *
     * @param array $ctx {
     *     @type string $mode             Selection mode.
     *     @type int    $product_id       For specific_product mode.
     *     @type int    $limit            Max reviews to return (default 3).
     *     @type array  $cart_product_ids Product IDs in subscriber's cart (for abandoned_cart).
     *     @type string $top_category     Subscriber's top category slug (for win_back).
     *     @type string $trigger_type     Flow trigger type (abandoned_cart, win_back, etc.).
     * }
     * @return array Array of review objects from ams_reviews_cache.
     */
    public function get( array $ctx = [] ): array {
        $mode  = $ctx['mode'] ?? 'auto_contextual';
        $limit = (int) ( $ctx['limit'] ?? 3 );

        switch ( $mode ) {
            case 'specific_product':
                return $this->by_product( (int) ( $ctx['product_id'] ?? 0 ), $limit );

            case 'top_rated':
                return $this->top_rated( $limit );

            case 'most_recent':
                return $this->most_recent( $limit );

            case 'auto_contextual':
            default:
                return $this->auto_contextual( $ctx, $limit );
        }
    }

    /**
     * Auto-contextual selection based on subscriber activity.
     */
    private function auto_contextual( array $ctx, int $limit ): array {
        $trigger = $ctx['trigger_type'] ?? '';

        // Abandoned cart: reviews for products in the subscriber's cart.
        if ( 'abandoned_cart' === $trigger && ! empty( $ctx['cart_product_ids'] ) ) {
            $reviews = $this->by_product_ids( (array) $ctx['cart_product_ids'], $limit );
            if ( ! empty( $reviews ) ) {
                return $reviews;
            }
        }

        // Win-back: reviews for products in the subscriber's top category.
        if ( 'win_back' === $trigger && ! empty( $ctx['top_category'] ) ) {
            $reviews = $this->by_category( $ctx['top_category'], $limit );
            if ( ! empty( $reviews ) ) {
                return $reviews;
            }
        }

        // Fallback: most recent 5-star verified reviews sitewide.
        return $this->fallback_five_star( $limit );
    }

    /**
     * Reviews for a specific product.
     */
    private function by_product( int $product_id, int $limit ): array {
        if ( $product_id <= 0 ) {
            return $this->top_rated( $limit );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ams_reviews_cache';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE product_id = %d
             ORDER BY rating DESC, review_date DESC
             LIMIT %d",
            $product_id,
            $limit
        ) );
    }

    /**
     * Reviews for multiple product IDs (cart items).
     */
    private function by_product_ids( array $product_ids, int $limit ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_reviews_cache';

        $ids = array_map( 'absint', $product_ids );
        $ids = array_filter( $ids );
        if ( empty( $ids ) ) {
            return [];
        }

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE product_id IN ({$placeholders})
             ORDER BY rating DESC, review_date DESC
             LIMIT %d",
            ...array_merge( $ids, [ $limit ] )
        ) );
    }

    /**
     * Reviews matching a product category (by product_name keyword match).
     * Since we don't store categories in cache, this is a best-effort text match.
     */
    private function by_category( string $category, int $limit ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_reviews_cache';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE product_name LIKE %s AND rating >= 4
             ORDER BY rating DESC, review_date DESC
             LIMIT %d",
            '%' . $wpdb->esc_like( $category ) . '%',
            $limit
        ) );
    }

    /**
     * Top rated reviews sitewide.
     */
    private function top_rated( int $limit ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_reviews_cache';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             ORDER BY rating DESC, positive_votes DESC, review_date DESC
             LIMIT %d",
            $limit
        ) );
    }

    /**
     * Most recent reviews with rating >= 4.
     */
    private function most_recent( int $limit ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_reviews_cache';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE rating >= 4
             ORDER BY review_date DESC
             LIMIT %d",
            $limit
        ) );
    }

    /**
     * Fallback: most recent 5-star verified reviews sitewide.
     */
    private function fallback_five_star( int $limit ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_reviews_cache';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE rating = 5 AND verified_purchase = 1
             ORDER BY review_date DESC
             LIMIT %d",
            $limit
        ) );
    }
}
