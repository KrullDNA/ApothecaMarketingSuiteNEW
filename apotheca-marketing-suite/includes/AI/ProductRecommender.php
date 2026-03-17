<?php

namespace Apotheca\Marketing\AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Algorithmic product recommendation engine (no OpenAI).
 *
 * Scoring: same category +3, on sale +2, high rating +1, new (< 30 days) +1.
 * Returns top 3 products as HTML product card block.
 */
class ProductRecommender {

    private const FONT_STACK = "'Montserrat','Century Gothic','Trebuchet MS',Arial,Helvetica,sans-serif";

    /**
     * Generate product recommendations HTML for a subscriber.
     *
     * @param int $subscriber_id Subscriber ID.
     * @return string HTML product cards (top 3).
     */
    public function recommend( int $subscriber_id ): string {
        $subscriber_cats = $this->get_subscriber_categories( $subscriber_id );
        $viewed_ids      = $this->get_viewed_product_ids( $subscriber_id );
        $purchased_ids   = $this->get_purchased_product_ids( $subscriber_id );

        $cache = new ProductsCacheJob();
        $all_products = $cache->get_all( 500 );

        if ( empty( $all_products ) ) {
            return '';
        }

        // Score each product.
        $scored = [];
        foreach ( $all_products as $product ) {
            $pid = (int) $product->product_id;

            // Skip already purchased.
            if ( in_array( $pid, $purchased_ids, true ) ) {
                continue;
            }

            $score = 0;
            $cats = json_decode( $product->categories, true ) ?: [];
            $cat_slugs = array_column( $cats, 'slug' );

            // Same category as subscriber's purchase history: +3.
            foreach ( $subscriber_cats as $sub_cat ) {
                if ( in_array( $sub_cat, $cat_slugs, true ) ) {
                    $score += 3;
                    break;
                }
            }

            // On sale: +2.
            if ( $product->on_sale ) {
                $score += 2;
            }

            // High rating (>= 4): +1.
            if ( (float) $product->average_rating >= 4.0 ) {
                $score += 1;
            }

            // New product (< 30 days): +1.
            if ( $product->date_created ) {
                $created = strtotime( $product->date_created );
                if ( $created && ( time() - $created ) < 30 * DAY_IN_SECONDS ) {
                    $score += 1;
                }
            }

            // Viewed but not purchased: bonus +1.
            if ( in_array( $pid, $viewed_ids, true ) ) {
                $score += 1;
            }

            $scored[] = [ 'product' => $product, 'score' => $score ];
        }

        // Sort by score descending, then by rating.
        usort( $scored, function ( $a, $b ) {
            if ( $b['score'] !== $a['score'] ) {
                return $b['score'] - $a['score'];
            }
            return (float) $b['product']->average_rating <=> (float) $a['product']->average_rating;
        } );

        // Take top 3.
        $top = array_slice( $scored, 0, 3 );

        if ( empty( $top ) ) {
            return '';
        }

        return $this->render_cards( $top );
    }

    /**
     * Get category slugs from the subscriber's purchase history.
     */
    private function get_subscriber_categories( int $subscriber_id ): array {
        global $wpdb;
        $events = $wpdb->prefix . 'ams_events';
        $pcache = $wpdb->prefix . 'ams_products_cache';

        // Get product IDs from placed_order events.
        $product_ids_raw = $wpdb->get_col( $wpdb->prepare(
            "SELECT product_ids FROM {$events}
             WHERE subscriber_id = %d AND event_type = 'placed_order' AND product_ids IS NOT NULL
             ORDER BY created_at DESC LIMIT 10",
            $subscriber_id
        ) );

        $all_pids = [];
        foreach ( $product_ids_raw as $json ) {
            $ids = json_decode( $json, true );
            if ( is_array( $ids ) ) {
                $all_pids = array_merge( $all_pids, $ids );
            }
        }
        $all_pids = array_unique( array_map( 'absint', $all_pids ) );

        if ( empty( $all_pids ) ) {
            return [];
        }

        // Get categories from cached products.
        $placeholders = implode( ',', array_fill( 0, count( $all_pids ), '%d' ) );
        $cat_jsons = $wpdb->get_col( $wpdb->prepare(
            "SELECT categories FROM {$pcache} WHERE product_id IN ({$placeholders})",
            ...$all_pids
        ) );

        $slugs = [];
        foreach ( $cat_jsons as $json ) {
            $cats = json_decode( $json, true );
            if ( is_array( $cats ) ) {
                foreach ( $cats as $cat ) {
                    if ( ! empty( $cat['slug'] ) ) {
                        $slugs[] = $cat['slug'];
                    }
                }
            }
        }

        return array_unique( $slugs );
    }

    /**
     * Get product IDs the subscriber has viewed.
     */
    private function get_viewed_product_ids( int $subscriber_id ): array {
        global $wpdb;
        $events = $wpdb->prefix . 'ams_events';

        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT product_ids FROM {$events}
             WHERE subscriber_id = %d AND event_type = 'product_viewed' AND product_ids IS NOT NULL
             ORDER BY created_at DESC LIMIT 20",
            $subscriber_id
        ) );

        $ids = [];
        foreach ( $rows as $json ) {
            $decoded = json_decode( $json, true );
            if ( is_array( $decoded ) ) {
                $ids = array_merge( $ids, $decoded );
            }
        }

        return array_unique( array_map( 'absint', $ids ) );
    }

    /**
     * Get product IDs the subscriber has purchased.
     */
    private function get_purchased_product_ids( int $subscriber_id ): array {
        global $wpdb;
        $events = $wpdb->prefix . 'ams_events';

        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT product_ids FROM {$events}
             WHERE subscriber_id = %d AND event_type = 'placed_order' AND product_ids IS NOT NULL",
            $subscriber_id
        ) );

        $ids = [];
        foreach ( $rows as $json ) {
            $decoded = json_decode( $json, true );
            if ( is_array( $decoded ) ) {
                $ids = array_merge( $ids, $decoded );
            }
        }

        return array_unique( array_map( 'absint', $ids ) );
    }

    /**
     * Render HTML product cards for email embedding.
     */
    private function render_cards( array $scored_products ): string {
        $font = self::FONT_STACK;
        $html = '<div style="font-family:' . $font . ';">';

        foreach ( $scored_products as $item ) {
            $p = $item['product'];
            $name  = esc_html( $p->name );
            $url   = esc_url( $p->product_url );
            $img   = esc_url( $p->image_url );
            $price = number_format( (float) $p->price, 2 );

            $sale_html = '';
            if ( $p->on_sale && $p->sale_price ) {
                $sale_html = '<span style="text-decoration:line-through;color:#999;margin-right:6px;">$' . number_format( (float) $p->price, 2 ) . '</span>';
                $price = number_format( (float) $p->sale_price, 2 );
            }

            $html .= '<div style="display:inline-block;width:30%;vertical-align:top;text-align:center;padding:8px;box-sizing:border-box;">';
            if ( $img ) {
                $html .= '<a href="' . $url . '" target="_blank" style="text-decoration:none;">';
                $html .= '<img src="' . $img . '" alt="' . $name . '" style="max-width:100%;height:auto;border-radius:4px;margin-bottom:8px;" />';
                $html .= '</a>';
            }
            $html .= '<p style="margin:0 0 4px;font-family:' . $font . ';font-size:13px;font-weight:600;color:#333;">';
            $html .= '<a href="' . $url . '" target="_blank" style="color:#333;text-decoration:none;">' . $name . '</a></p>';
            $html .= '<p style="margin:0;font-family:' . $font . ';font-size:14px;color:#2271b1;font-weight:700;">' . $sale_html . '$' . $price . '</p>';
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }
}
