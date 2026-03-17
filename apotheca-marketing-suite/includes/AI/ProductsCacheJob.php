<?php

namespace Apotheca\Marketing\AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Nightly product cache refresh.
 *
 * Fetches products from Site A via WC REST API and caches them
 * in ams_products_cache for use by the product recommendation engine.
 */
class ProductsCacheJob {

    private const HOOK       = 'ams_refresh_products_cache';
    private const BATCH_SIZE = 100;

    /**
     * Register the recurring Action Scheduler job.
     */
    public function register(): void {
        add_action( self::HOOK, [ $this, 'run' ] );

        if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::HOOK ) ) {
            $next = strtotime( 'tomorrow 03:30:00 UTC' );
            as_schedule_recurring_action( $next, DAY_IN_SECONDS, self::HOOK, [], 'ams_ai' );
        }
    }

    /**
     * Run the cache refresh.
     */
    public function run(): void {
        $settings  = get_option( 'ams_settings', [] );
        $store_url = $settings['store_url'] ?? '';

        if ( empty( $store_url ) ) {
            return;
        }

        $page = 1;
        $fetched = 0;

        while ( true ) {
            $products = $this->fetch_page( $store_url, $page );
            if ( empty( $products ) ) {
                break;
            }

            foreach ( $products as $product ) {
                $this->upsert( $product );
                $fetched++;
            }

            if ( count( $products ) < self::BATCH_SIZE ) {
                break;
            }
            $page++;
        }
    }

    /**
     * Fetch a page of products from the store.
     */
    private function fetch_page( string $store_url, int $page ): array {
        $settings = get_option( 'ams_settings', [] );
        $secret   = $settings['sync_shared_secret'] ?? '';

        $url = trailingslashit( $store_url ) . 'wp-json/wc/v3/products';
        $url = add_query_arg( [
            'per_page' => self::BATCH_SIZE,
            'page'     => $page,
            'status'   => 'publish',
        ], $url );

        $response = wp_remote_get( $url, [
            'timeout' => 30,
            'headers' => [
                'X-AMS-Signature' => hash_hmac( 'sha256', 'products_fetch', $secret ),
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return [];
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return [];
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return is_array( $data ) ? $data : [];
    }

    /**
     * Upsert a product into the cache.
     */
    private function upsert( array $product ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_products_cache';

        $product_id = (int) ( $product['id'] ?? 0 );
        if ( ! $product_id ) {
            return;
        }

        $categories = [];
        if ( ! empty( $product['categories'] ) ) {
            foreach ( $product['categories'] as $cat ) {
                $categories[] = [
                    'id'   => (int) ( $cat['id'] ?? 0 ),
                    'name' => $cat['name'] ?? '',
                    'slug' => $cat['slug'] ?? '',
                ];
            }
        }

        $image_url = '';
        if ( ! empty( $product['images'][0]['src'] ) ) {
            $image_url = $product['images'][0]['src'];
        }

        $data = [
            'product_id'     => $product_id,
            'name'           => sanitize_text_field( $product['name'] ?? '' ),
            'slug'           => sanitize_text_field( $product['slug'] ?? '' ),
            'price'          => (float) ( $product['price'] ?? 0 ),
            'sale_price'     => ! empty( $product['sale_price'] ) ? (float) $product['sale_price'] : null,
            'on_sale'        => ! empty( $product['on_sale'] ) ? 1 : 0,
            'categories'     => wp_json_encode( $categories ),
            'image_url'      => esc_url_raw( $image_url ),
            'product_url'    => esc_url_raw( $product['permalink'] ?? '' ),
            'average_rating' => (float) ( $product['average_rating'] ?? 0 ),
            'date_created'   => $product['date_created'] ?? null,
            'status'         => 'publish',
            'cached_at'      => current_time( 'mysql', true ),
        ];

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE product_id = %d",
            $product_id
        ) );

        if ( $existing ) {
            $wpdb->update( $table, $data, [ 'id' => $existing ] );
        } else {
            $wpdb->insert( $table, $data );
        }
    }

    /**
     * Get cached products by IDs.
     */
    public function get_by_ids( array $product_ids ): array {
        if ( empty( $product_ids ) ) {
            return [];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ams_products_cache';
        $ids = array_map( 'absint', $product_ids );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE product_id IN ({$placeholders})",
            ...$ids
        ) );
    }

    /**
     * Get all cached products.
     */
    public function get_all( int $limit = 1000 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_products_cache';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = 'publish' ORDER BY cached_at DESC LIMIT %d",
            $limit
        ) );
    }
}
