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

        // Resolve product showcase blocks (before simple tokens, since they contain their own HTML).
        $content = $this->resolve_product_showcase( $content );

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
     * Resolve product showcase blocks.
     *
     * Looks for: <div class="ams-product-showcase" data-products="JSON">{{product_showcase}}</div>
     * Replaces with rendered WooCommerce product cards.
     */
    private function resolve_product_showcase( string $content ): string {
        $font = "'Montserrat','Century Gothic','Trebuchet MS',Arial,Helvetica,sans-serif";

        return preg_replace_callback(
            '/<div\s+class="ams-product-showcase"\s+data-products="([^"]+)"[^>]*>\{\{product_showcase\}\}<\/div>/s',
            function ( $matches ) use ( $font ) {
                $json = html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' );
                $data = json_decode( $json, true );
                if ( ! $data || empty( $data['ids'] ) ) {
                    return '';
                }

                $ids        = array_map( 'intval', array_filter( explode( ',', $data['ids'] ) ) );
                $btn_text   = sanitize_text_field( $data['btn'] ?? 'SHOP NOW' );
                $btn_bg     = sanitize_hex_color( $data['btnBg'] ?? '#000000' ) ?: '#000000';
                $btn_color  = sanitize_hex_color( $data['btnColor'] ?? '#ffffff' ) ?: '#ffffff';
                $card_bg    = sanitize_hex_color( $data['cardBg'] ?? '#f5f5f5' ) ?: '#f5f5f5';
                $show_desc  = $data['desc'] ?? true;
                $html       = '';

                foreach ( $ids as $product_id ) {
                    $product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
                    if ( ! $product ) {
                        continue;
                    }

                    $name      = esc_html( $product->get_name() );
                    $price     = $product->get_price_html();
                    $permalink = esc_url( $product->get_permalink() );
                    $image_id  = $product->get_image_id();
                    $image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'medium_large' ) : '';
                    $desc      = '';

                    if ( $show_desc ) {
                        $desc = $product->get_short_description();
                        if ( empty( $desc ) ) {
                            $desc = wp_trim_words( $product->get_description(), 30, '...' );
                        }
                        $desc = wp_strip_all_tags( $desc );
                    }

                    $html .= '<div style="background:' . $card_bg . ';border-radius:8px;margin-bottom:12px;overflow:hidden;">';
                    $html .= '<table class="ams-stack" cellpadding="0" cellspacing="0" border="0" width="100%" style="font-family:' . $font . ';">';
                    $html .= '<tr>';

                    // Product image — left column.
                    $html .= '<td style="width:45%;padding:0;vertical-align:middle;text-align:center;">';
                    if ( $image_url ) {
                        $html .= '<a href="' . $permalink . '" style="text-decoration:none;"><img src="' . esc_url( $image_url ) . '" alt="' . $name . '" style="width:100%;height:auto;display:block;" /></a>';
                    } else {
                        $html .= '<div style="padding:40px;background:#e8e8e8;text-align:center;color:#999;">No Image</div>';
                    }
                    $html .= '</td>';

                    // Product info — right column.
                    $html .= '<td style="width:55%;padding:28px 32px;vertical-align:top;">';
                    $html .= '<h3 style="margin:0 0 12px;font-size:22px;font-weight:400;font-family:' . $font . ';color:#333;">' . $name . '</h3>';

                    if ( $show_desc && ! empty( $desc ) ) {
                        $html .= '<p style="margin:0 0 16px;color:#888;font-size:14px;line-height:1.6;font-family:' . $font . ';">' . esc_html( $desc ) . '</p>';
                    }

                    $html .= '<p style="margin:0 0 20px;font-size:20px;font-weight:700;color:#000;font-family:' . $font . ';">' . $price . '</p>';
                    $html .= '<a href="' . $permalink . '" style="display:inline-block;background:' . $btn_bg . ';color:' . $btn_color . ';font-size:13px;font-weight:700;padding:16px 36px;letter-spacing:1px;text-transform:uppercase;text-decoration:none;font-family:' . $font . ';">' . esc_html( $btn_text ) . '</a>';
                    $html .= '</td>';

                    $html .= '</tr></table>';
                    $html .= '</div>';
                }

                return $html;
            },
            $content
        );
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
