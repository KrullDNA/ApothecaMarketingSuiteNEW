<?php

namespace Apotheca\Marketing\Reviews;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Nightly Action Scheduler job to refresh the reviews cache.
 *
 * Fetches product reviews from Site A via the WooCommerce REST API,
 * then enriches with kdna meta via the ams-bridge endpoint.
 * Runs at 3 AM UTC daily; batches of 200 per run.
 */
class ReviewsCacheJob {

    private const HOOK       = 'ams_refresh_reviews';
    private const BATCH_SIZE = 200;
    private const STALE_HOURS = 48;

    /**
     * Register the Action Scheduler recurring hook.
     */
    public function register(): void {
        add_action( self::HOOK, [ $this, 'run' ] );

        // Schedule the recurring job if not already scheduled.
        if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::HOOK ) ) {
            // Next 3 AM UTC.
            $next_3am = strtotime( 'tomorrow 03:00:00 UTC' );
            as_schedule_recurring_action( $next_3am, DAY_IN_SECONDS, self::HOOK, [], 'ams_reviews' );
        }
    }

    /**
     * Execute the cache refresh.
     */
    public function run(): void {
        $settings  = get_option( 'ams_settings', [] );
        $store_url = $settings['store_url'] ?? '';

        if ( empty( $store_url ) ) {
            return;
        }

        $reviews_settings = get_option( 'ams_reviews_settings', [] );
        $min_rating       = (int) ( $reviews_settings['min_rating'] ?? 3 );

        $page     = 1;
        $fetched  = 0;
        $all_ids  = [];

        while ( $fetched < self::BATCH_SIZE ) {
            $reviews = $this->fetch_reviews_page( $store_url, $page, $min_rating );
            if ( empty( $reviews ) ) {
                break;
            }

            foreach ( $reviews as $review ) {
                $this->upsert_review( $review );
                $all_ids[] = (int) $review['id'];
                $fetched++;

                if ( $fetched >= self::BATCH_SIZE ) {
                    break;
                }
            }

            $page++;
        }

        // Fetch kdna meta for the batch.
        if ( ! empty( $all_ids ) ) {
            $this->enrich_with_kdna_meta( $store_url, $all_ids );
        }

        // Delete stale entries older than 48h.
        $this->purge_stale();
    }

    /**
     * Trigger a manual cache refresh (called from admin settings).
     */
    public function manual_refresh(): array {
        $this->run();

        global $wpdb;
        $table = $wpdb->prefix . 'ams_reviews_cache';
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

        return [
            'success' => true,
            'count'   => $count,
            'time'    => current_time( 'mysql', true ),
        ];
    }

    /**
     * Fetch a page of approved reviews from Site A via WC REST API.
     */
    private function fetch_reviews_page( string $store_url, int $page, int $min_rating ): array {
        $settings = get_option( 'ams_settings', [] );
        $secret   = $settings['sync_shared_secret'] ?? '';

        $url = trailingslashit( $store_url ) . 'wp-json/wc/v3/products/reviews';
        $url = add_query_arg( [
            'per_page' => 100,
            'page'     => $page,
            'status'   => 'approved',
        ], $url );

        $response = wp_remote_get( $url, [
            'timeout' => 30,
            'headers' => [
                'X-AMS-Signature' => hash_hmac( 'sha256', 'reviews_fetch', $secret ),
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return [];
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return [];
        }

        $reviews = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $reviews ) ) {
            return [];
        }

        // Filter by minimum rating.
        return array_filter( $reviews, function ( $r ) use ( $min_rating ) {
            return ( (int) ( $r['rating'] ?? 0 ) ) >= $min_rating;
        } );
    }

    /**
     * Upsert a single review into the cache table.
     */
    private function upsert_review( array $review ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_reviews_cache';

        $comment_id = (int) ( $review['id'] ?? 0 );
        if ( ! $comment_id ) {
            return;
        }

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE woo_comment_id = %d",
            $comment_id
        ) );

        $data = [
            'source'            => 'woocommerce',
            'product_id'        => (int) ( $review['product_id'] ?? 0 ),
            'woo_comment_id'    => $comment_id,
            'reviewer_name'     => sanitize_text_field( $review['reviewer'] ?? '' ),
            'rating'            => (int) ( $review['rating'] ?? 0 ),
            'review_body'       => wp_kses_post( $review['review'] ?? '' ),
            'review_date'       => $review['date_created'] ?? current_time( 'mysql', true ),
            'verified_purchase' => ! empty( $review['verified'] ) ? 1 : 0,
            'cached_at'         => current_time( 'mysql', true ),
        ];

        if ( $existing ) {
            $wpdb->update( $table, $data, [ 'id' => $existing ] );
        } else {
            $wpdb->insert( $table, $data );
        }
    }

    /**
     * Enrich cached reviews with kdna meta from the bridge endpoint.
     *
     * Calls: GET {store_url}/wp-json/ams-bridge/v1/review-meta?ids=1,2,3
     * This endpoint is provided by apotheca-marketing-sync on Site A (Session 11).
     */
    private function enrich_with_kdna_meta( string $store_url, array $comment_ids ): void {
        $settings = get_option( 'ams_settings', [] );
        $secret   = $settings['sync_shared_secret'] ?? '';

        $url = trailingslashit( $store_url ) . 'wp-json/ams-bridge/v1/review-meta';
        $url = add_query_arg( [ 'ids' => implode( ',', $comment_ids ) ], $url );

        $response = wp_remote_get( $url, [
            'timeout' => 30,
            'headers' => [
                'X-AMS-Signature' => hash_hmac( 'sha256', 'review_meta_fetch', $secret ),
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return;
        }

        $meta_data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $meta_data ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ams_reviews_cache';

        foreach ( $meta_data as $comment_id => $meta ) {
            $fields = [];

            if ( ! empty( $meta['_kdna_review_title'] ) ) {
                $fields['review_title'] = sanitize_text_field( $meta['_kdna_review_title'] );
            }

            if ( ! empty( $meta['_kdna_attachment_ids'] ) ) {
                $fields['attachment_ids'] = wp_json_encode( $meta['_kdna_attachment_ids'] );
            }

            if ( ! empty( $meta['_kdna_video_url'] ) ) {
                $fields['video_url'] = esc_url_raw( $meta['_kdna_video_url'] );
            }

            if ( isset( $meta['_kdna_positive_votes'] ) ) {
                $fields['positive_votes'] = (int) $meta['_kdna_positive_votes'];
            }

            if ( isset( $meta['_kdna_negative_votes'] ) ) {
                $fields['negative_votes'] = (int) $meta['_kdna_negative_votes'];
            }

            if ( ! empty( $meta['product_name'] ) ) {
                $fields['product_name'] = sanitize_text_field( $meta['product_name'] );
            }

            if ( ! empty( $meta['product_image_url'] ) ) {
                $fields['product_image_url'] = esc_url_raw( $meta['product_image_url'] );
            }

            if ( ! empty( $meta['product_url'] ) ) {
                $fields['product_url'] = esc_url_raw( $meta['product_url'] );
            }

            // Mark as kdna source if we got kdna-specific meta.
            if ( ! empty( $meta['_kdna_review_title'] ) || ! empty( $meta['_kdna_positive_votes'] ) ) {
                $fields['source'] = 'kdna';
            }

            if ( ! empty( $fields ) ) {
                $wpdb->update( $table, $fields, [ 'woo_comment_id' => (int) $comment_id ] );
            }
        }
    }

    /**
     * Delete cache entries older than 48 hours.
     */
    private function purge_stale(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_reviews_cache';
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE cached_at < DATE_SUB(%s, INTERVAL %d HOUR)",
            current_time( 'mysql', true ),
            self::STALE_HOURS
        ) );
    }

    /**
     * Get cache stats for the admin UI.
     */
    public function get_stats(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_reviews_cache';

        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( ! $table_exists ) {
            return [
                'total'          => 0,
                'kdna_count'     => 0,
                'woo_count'      => 0,
                'last_refreshed' => null,
            ];
        }

        return [
            'total'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
            'kdna_count'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE source = 'kdna'" ),
            'woo_count'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE source = 'woocommerce'" ),
            'last_refreshed' => $wpdb->get_var( "SELECT MAX(cached_at) FROM {$table}" ),
        ];
    }
}
