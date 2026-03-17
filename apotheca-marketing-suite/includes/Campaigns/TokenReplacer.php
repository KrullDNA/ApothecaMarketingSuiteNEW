<?php

namespace Apotheca\Marketing\Campaigns;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TokenReplacer {

    /**
     * Replace personalisation tokens in content.
     *
     * Supported tokens:
     *   {{first_name}} {{last_name}} {{email}} {{phone}}
     *   {{shop_name}} {{shop_url}} {{unsubscribe_url}}
     *   {{order_number}} {{order_total}} {{order_date}} {{order_status}}
     *   {{cart_url}} {{cart_total}} {{product_name}} {{product_url}}
     *   {{product_image_url}} {{product_price}} {{coupon_code}}
     *   {{review_link}} {{ai_product_recommendations}} (filled in Session 9)
     *
     * Conditionals:
     *   {{if first_name}}Hi {{first_name}}{{else}}Hi there{{/if}}
     */
    public function replace( string $content, array $context = [] ): string {
        if ( '' === $content ) {
            return $content;
        }

        // Process conditionals first.
        $content = $this->process_conditionals( $content, $context );

        // Replace simple tokens.
        $content = $this->replace_tokens( $content, $context );

        return $content;
    }

    /**
     * Process conditional blocks: {{if field}}...{{else}}...{{/if}}
     */
    private function process_conditionals( string $content, array $context ): string {
        // Pattern: {{if field_name}}true_content{{else}}false_content{{/if}}
        // The else block is optional.
        $pattern = '/\{\{if\s+([a-zA-Z_][a-zA-Z0-9_]*)\}\}(.*?)(?:\{\{else\}\}(.*?))?\{\{\/if\}\}/s';

        return preg_replace_callback( $pattern, function ( $matches ) use ( $context ) {
            $field         = $matches[1];
            $true_content  = $matches[2];
            $false_content = $matches[3] ?? '';

            $value = $context[ $field ] ?? '';

            if ( ! empty( $value ) ) {
                return $true_content;
            }
            return $false_content;
        }, $content );
    }

    /**
     * Replace {{token}} placeholders with context values.
     */
    private function replace_tokens( string $content, array $context ): string {
        return preg_replace_callback( '/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/', function ( $matches ) use ( $context ) {
            $key = $matches[1];
            return $context[ $key ] ?? '';
        }, $content );
    }

    /**
     * Build a full context array from subscriber, order, and product data.
     */
    public static function build_context(
        ?object $subscriber = null,
        array $order_data = [],
        array $product_data = [],
        array $extra = []
    ): array {
        $settings  = get_option( 'ams_settings', [] );
        $store_url = $settings['store_url'] ?? '';

        $context = [
            // Subscriber tokens.
            'first_name'      => $subscriber->first_name ?? '',
            'last_name'       => $subscriber->last_name ?? '',
            'email'           => $subscriber->email ?? '',
            'phone'           => $subscriber->phone ?? '',

            // Shop tokens.
            'shop_name'       => get_bloginfo( 'name' ),
            'shop_url'        => $store_url ?: home_url(),
            'unsubscribe_url' => $subscriber
                ? home_url( '/ams-unsubscribe/' . ( $subscriber->unsubscribe_token ?? '' ) )
                : '',

            // Order tokens.
            'order_number'    => $order_data['order_number'] ?? '',
            'order_total'     => $order_data['order_total'] ?? '',
            'order_date'      => $order_data['order_date'] ?? '',
            'order_status'    => $order_data['order_status'] ?? '',

            // Cart tokens.
            'cart_url'        => $order_data['cart_url'] ?? '',
            'cart_total'      => $order_data['cart_total'] ?? '',

            // Product tokens.
            'product_name'      => $product_data['product_name'] ?? '',
            'product_url'       => $product_data['product_url'] ?? '',
            'product_image_url' => $product_data['product_image_url'] ?? '',
            'product_price'     => $product_data['product_price'] ?? '',

            // Coupon.
            'coupon_code'     => $extra['coupon_code'] ?? '',

            // Review link (product page #reviews section).
            'review_link' => ! empty( $product_data['product_url'] )
                ? $product_data['product_url'] . '#reviews'
                : ( $store_url ? $store_url : '' ),

            // AI product recommendations.
            'ai_product_recommendations' => ! empty( $extra['ai_product_recommendations'] )
                ? $extra['ai_product_recommendations']
                : self::generate_recommendations( $subscriber ),
        ];

        return array_merge( $context, $extra );
    }

    /**
     * Generate product recommendations HTML for a subscriber.
     */
    private static function generate_recommendations( ?object $subscriber ): string {
        if ( ! $subscriber || empty( $subscriber->id ) ) {
            return '';
        }

        $ai_settings = get_option( 'ams_ai_settings', [] );
        $enabled     = $ai_settings['enabled_features'] ?? [];
        if ( ! empty( $enabled ) && ! in_array( 'product_recs', $enabled, true ) ) {
            return '';
        }

        $recommender = new \Apotheca\Marketing\AI\ProductRecommender();
        return $recommender->recommend( (int) $subscriber->id );
    }
}
