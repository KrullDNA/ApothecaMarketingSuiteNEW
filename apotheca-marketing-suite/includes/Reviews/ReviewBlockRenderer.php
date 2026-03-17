<?php

namespace Apotheca\Marketing\Reviews;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Server-side renderer for the three review block modes in emails.
 *
 * Modes:
 *   review_request  — CTA block linking to product review page
 *   social_proof    — 1-3 review cards from ams_reviews_cache
 *   review_gate     — 5 linked star images for rating collection
 */
class ReviewBlockRenderer {

    private const FONT_STACK = "'Montserrat','Century Gothic','Trebuchet MS',Arial,Helvetica,sans-serif";

    /**
     * Render a review block to HTML for email embedding.
     *
     * @param array  $block      Block configuration from the email editor.
     * @param object $subscriber Subscriber object.
     * @param array  $ctx        Additional context (order_id, product data, trigger_type, etc.).
     * @return string HTML output.
     */
    public function render( array $block, ?object $subscriber = null, array $ctx = [] ): string {
        $mode = $block['mode'] ?? 'review_request';

        switch ( $mode ) {
            case 'review_request':
                return $this->render_review_request( $block, $subscriber, $ctx );
            case 'social_proof':
                return $this->render_social_proof( $block, $subscriber, $ctx );
            case 'review_gate':
                return $this->render_review_gate( $block, $subscriber, $ctx );
            default:
                return '';
        }
    }

    /**
     * Mode: review_request — CTA block with heading, body, and button.
     */
    private function render_review_request( array $block, ?object $subscriber, array $ctx ): string {
        $settings  = get_option( 'ams_settings', [] );
        $store_url = $settings['store_url'] ?? '';

        $heading     = esc_html( $block['heading'] ?? 'How was your purchase?' );
        $button_text = esc_html( $block['buttonText'] ?? 'Leave a Review' );
        $button_color= esc_attr( $block['buttonColor'] ?? '#2271b1' );
        $show_image  = ! empty( $block['showProductImage'] );
        $show_stars  = ! empty( $block['showStars'] );

        $product_url  = $ctx['product_url'] ?? '';
        $product_name = esc_html( $ctx['product_name'] ?? '' );
        $product_img  = esc_url( $ctx['product_image_url'] ?? '' );
        $review_link  = $product_url ? $product_url . '#reviews' : $store_url;

        $font = self::FONT_STACK;

        $html = '<div style="text-align:center;padding:24px;font-family:' . $font . ';background:#f9f9f9;border-radius:8px;">';

        if ( $show_image && $product_img ) {
            $html .= '<img src="' . $product_img . '" alt="' . $product_name . '" style="max-width:120px;height:auto;border-radius:6px;margin-bottom:12px;" />';
        }

        $html .= '<h3 style="margin:0 0 8px;font-family:' . $font . ';font-size:20px;color:#333;">' . $heading . '</h3>';

        if ( $show_stars ) {
            $html .= '<div style="font-size:24px;color:#f5a623;margin-bottom:8px;">&#9733;&#9733;&#9733;&#9733;&#9733;</div>';
        }

        if ( $product_name ) {
            $html .= '<p style="font-family:' . $font . ';color:#666;margin:0 0 16px;font-size:14px;">' . $product_name . '</p>';
        }

        $html .= '<a href="' . esc_url( $review_link ) . '" target="_blank" style="display:inline-block;background:' . $button_color . ';color:#ffffff;font-family:' . $font . ';font-size:14px;font-weight:600;text-decoration:none;padding:12px 28px;border-radius:4px;">' . $button_text . '</a>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Mode: social_proof — 1-3 review cards from the cache.
     */
    private function render_social_proof( array $block, ?object $subscriber, array $ctx ): string {
        $selector = new ReviewSelector();

        $selector_ctx = [
            'mode'             => $block['selectionMode'] ?? 'auto_contextual',
            'product_id'       => (int) ( $block['productId'] ?? 0 ),
            'limit'            => min( (int) ( $block['cardCount'] ?? 3 ), 3 ),
            'cart_product_ids' => $ctx['cart_product_ids'] ?? [],
            'top_category'     => $ctx['top_category'] ?? '',
            'trigger_type'     => $ctx['trigger_type'] ?? '',
        ];

        $reviews = $selector->get( $selector_ctx );
        if ( empty( $reviews ) ) {
            return '';
        }

        $font = self::FONT_STACK;

        // Design controls with defaults.
        $star_color     = esc_attr( $block['starColor'] ?? '#f5a623' );
        $star_size      = esc_attr( $block['starSize'] ?? '18px' );
        $name_size      = esc_attr( $block['nameSize'] ?? '14px' );
        $name_weight    = esc_attr( $block['nameWeight'] ?? '600' );
        $name_color     = esc_attr( $block['nameColor'] ?? '#333' );
        $show_title     = ! isset( $block['showTitle'] ) || $block['showTitle'];
        $body_size      = esc_attr( $block['bodySize'] ?? '13px' );
        $body_color     = esc_attr( $block['bodyColor'] ?? '#555' );
        $body_lh        = esc_attr( $block['bodyLineHeight'] ?? '1.5' );
        $max_chars      = (int) ( $block['maxChars'] ?? 200 );
        $show_date      = ! isset( $block['showDate'] ) || $block['showDate'];
        $show_badge     = ! isset( $block['showVerifiedBadge'] ) || $block['showVerifiedBadge'];
        $badge_text     = esc_html( $block['badgeText'] ?? 'Verified Buyer' );
        $badge_bg       = esc_attr( $block['badgeBgColor'] ?? '#e8f5e9' );
        $badge_radius   = esc_attr( $block['badgeRadius'] ?? '3px' );
        $show_thumb     = ! empty( $block['showProductThumbnail'] );
        $thumb_size     = esc_attr( $block['thumbnailSize'] ?? '48px' );
        $thumb_radius   = esc_attr( $block['thumbnailRadius'] ?? '4px' );
        $card_bg        = esc_attr( $block['cardBackground'] ?? '#ffffff' );
        $card_border_c  = esc_attr( $block['cardBorderColor'] ?? '#e0e0e0' );
        $card_border_w  = esc_attr( $block['cardBorderWidth'] ?? '1px' );
        $card_border_s  = esc_attr( $block['cardBorderStyle'] ?? 'solid' );
        $card_radius    = esc_attr( $block['cardRadius'] ?? '6px' );
        $card_padding   = esc_attr( $block['cardPadding'] ?? '16px' );
        $card_gap       = esc_attr( $block['cardGap'] ?? '12px' );

        $html = '';

        foreach ( $reviews as $review ) {
            $stars_html = '';
            $rating = (int) $review->rating;
            for ( $i = 1; $i <= 5; $i++ ) {
                $stars_html .= '<span style="color:' . ( $i <= $rating ? $star_color : '#ddd' ) . ';font-size:' . $star_size . ';">&#9733;</span>';
            }

            $body = wp_strip_all_tags( $review->review_body );
            if ( $max_chars > 0 && mb_strlen( $body ) > $max_chars ) {
                $body = mb_substr( $body, 0, $max_chars ) . '&hellip;';
            }

            $html .= '<div style="background:' . $card_bg . ';border:' . $card_border_w . ' ' . $card_border_s . ' ' . $card_border_c . ';border-radius:' . $card_radius . ';padding:' . $card_padding . ';margin-bottom:' . $card_gap . ';font-family:' . $font . ';">';

            // Product thumbnail.
            if ( $show_thumb && ! empty( $review->product_image_url ) ) {
                $html .= '<img src="' . esc_url( $review->product_image_url ) . '" alt="" style="width:' . $thumb_size . ';height:' . $thumb_size . ';object-fit:cover;border-radius:' . $thumb_radius . ';float:left;margin-right:12px;" />';
            }

            // Stars.
            $html .= '<div style="margin-bottom:4px;">' . $stars_html . '</div>';

            // Review title.
            if ( $show_title && ! empty( $review->review_title ) ) {
                $html .= '<p style="margin:0 0 4px;font-family:' . $font . ';font-size:' . $name_size . ';font-weight:700;color:' . $name_color . ';">' . esc_html( $review->review_title ) . '</p>';
            }

            // Review body.
            $html .= '<p style="margin:0 0 8px;font-family:' . $font . ';font-size:' . $body_size . ';color:' . $body_color . ';line-height:' . $body_lh . ';font-style:italic;">&ldquo;' . esc_html( $body ) . '&rdquo;</p>';

            // Reviewer name + badge.
            $html .= '<p style="margin:0;font-family:' . $font . ';font-size:' . $name_size . ';font-weight:' . $name_weight . ';color:' . $name_color . ';">';
            $html .= '&mdash; ' . esc_html( $review->reviewer_name );

            if ( $show_badge && $review->verified_purchase ) {
                $html .= ' <span style="display:inline-block;background:' . $badge_bg . ';color:#2e7d32;font-size:11px;padding:2px 6px;border-radius:' . $badge_radius . ';font-weight:600;">' . $badge_text . '</span>';
            }
            $html .= '</p>';

            // Date.
            if ( $show_date && ! empty( $review->review_date ) ) {
                $html .= '<p style="margin:4px 0 0;font-family:' . $font . ';font-size:11px;color:#999;">' . esc_html( date_i18n( get_option( 'date_format' ), strtotime( $review->review_date ) ) ) . '</p>';
            }

            // Clear float from thumbnail.
            if ( $show_thumb && ! empty( $review->product_image_url ) ) {
                $html .= '<div style="clear:both;"></div>';
            }

            $html .= '</div>';
        }

        return $html;
    }

    /**
     * Mode: review_gate — 5 linked star PNG images (Outlook-safe).
     */
    private function render_review_gate( array $block, ?object $subscriber, array $ctx ): string {
        if ( ! $subscriber ) {
            return '';
        }

        $font       = self::FONT_STACK;
        $heading    = esc_html( $block['heading'] ?? 'How would you rate your experience?' );
        $order_id   = (int) ( $ctx['order_id'] ?? 0 );
        $token      = $subscriber->subscriber_token ?? '';
        $stars_base = plugins_url( 'assets/img/stars/', AMS_PLUGIN_FILE );

        $html  = '<div style="text-align:center;padding:24px;font-family:' . $font . ';">';
        $html .= '<p style="font-family:' . $font . ';font-size:16px;color:#333;margin:0 0 16px;">' . $heading . '</p>';
        $html .= '<div style="display:inline-block;">';

        for ( $star = 1; $star <= 5; $star++ ) {
            $gate_url = home_url( '/ams-review-gate/' );
            $gate_url = add_query_arg( [
                'token'   => $token,
                'order'   => $order_id,
                'rating'  => $star,
            ], $gate_url );

            $img_url = $stars_base . 'star-full-' . $star . '.png';

            $html .= '<a href="' . esc_url( $gate_url ) . '" target="_blank" style="text-decoration:none;display:inline-block;margin:0 2px;">';
            $html .= '<img src="' . esc_url( $img_url ) . '" alt="' . $star . ' star" width="28" height="28" style="display:inline-block;border:0;" />';
            $html .= '</a>';
        }

        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }
}
