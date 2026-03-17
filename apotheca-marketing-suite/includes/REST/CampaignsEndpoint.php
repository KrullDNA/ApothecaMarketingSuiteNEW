<?php

namespace Apotheca\Marketing\REST;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Apotheca\Marketing\Campaigns\CampaignManager;
use Apotheca\Marketing\Campaigns\CSSInliner;
use Apotheca\Marketing\Campaigns\TokenReplacer;

class CampaignsEndpoint {

    private const NAMESPACE = 'ams/v1';

    public function register_routes(): void {
        // List + Create.
        register_rest_route( self::NAMESPACE, '/admin/campaigns', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'list_campaigns' ],
                'permission_callback' => [ $this, 'admin_check' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'create_campaign' ],
                'permission_callback' => [ $this, 'admin_check' ],
            ],
        ] );

        // Get, Update, Delete.
        register_rest_route( self::NAMESPACE, '/admin/campaigns/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_campaign' ],
                'permission_callback' => [ $this, 'admin_check' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ $this, 'update_campaign' ],
                'permission_callback' => [ $this, 'admin_check' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'delete_campaign' ],
                'permission_callback' => [ $this, 'admin_check' ],
            ],
        ] );

        // Schedule.
        register_rest_route( self::NAMESPACE, '/admin/campaigns/(?P<id>\d+)/schedule', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'schedule_campaign' ],
            'permission_callback' => [ $this, 'admin_check' ],
        ] );

        // Cancel.
        register_rest_route( self::NAMESPACE, '/admin/campaigns/(?P<id>\d+)/cancel', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'cancel_campaign' ],
            'permission_callback' => [ $this, 'admin_check' ],
        ] );

        // Send now.
        register_rest_route( self::NAMESPACE, '/admin/campaigns/(?P<id>\d+)/send', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'send_campaign' ],
            'permission_callback' => [ $this, 'admin_check' ],
        ] );

        // Stats.
        register_rest_route( self::NAMESPACE, '/admin/campaigns/(?P<id>\d+)/stats', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_stats' ],
            'permission_callback' => [ $this, 'admin_check' ],
        ] );

        // Preview (inline CSS + token preview).
        register_rest_route( self::NAMESPACE, '/admin/campaigns/preview', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'preview' ],
            'permission_callback' => [ $this, 'admin_check' ],
        ] );

        // Inline CSS endpoint.
        register_rest_route( self::NAMESPACE, '/admin/campaigns/inline-css', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'inline_css' ],
            'permission_callback' => [ $this, 'admin_check' ],
        ] );

        // Segments list (for the segment picker).
        register_rest_route( self::NAMESPACE, '/admin/campaigns/segments', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list_segments' ],
            'permission_callback' => [ $this, 'admin_check' ],
        ] );
    }

    public function admin_check( \WP_REST_Request $request ): bool {
        return current_user_can( 'manage_options' ) && wp_verify_nonce(
            $request->get_header( 'X-WP-Nonce' ),
            'wp_rest'
        );
    }

    public function list_campaigns( \WP_REST_Request $request ): \WP_REST_Response {
        $manager = new CampaignManager();
        return new \WP_REST_Response( $manager->list_campaigns(), 200 );
    }

    public function get_campaign( \WP_REST_Request $request ): \WP_REST_Response {
        $manager  = new CampaignManager();
        $campaign = $manager->get( absint( $request['id'] ) );

        if ( ! $campaign ) {
            return new \WP_REST_Response( [ 'error' => 'Not found.' ], 404 );
        }

        // Attach stats.
        $campaign->stats = $manager->get_stats( (int) $campaign->id );

        return new \WP_REST_Response( $campaign, 200 );
    }

    public function create_campaign( \WP_REST_Request $request ): \WP_REST_Response {
        $manager = new CampaignManager();
        $data    = $request->get_json_params();

        // If body_html provided, inline CSS.
        if ( ! empty( $data['body_html'] ) ) {
            $inliner = new CSSInliner();
            $data['body_html'] = $inliner->inline( $data['body_html'] );
        }

        $id = $manager->create( $data );

        return new \WP_REST_Response( [ 'id' => $id ], 201 );
    }

    public function update_campaign( \WP_REST_Request $request ): \WP_REST_Response {
        $manager = new CampaignManager();
        $id      = absint( $request['id'] );
        $data    = $request->get_json_params();

        // If body_html provided, inline CSS.
        if ( ! empty( $data['body_html'] ) ) {
            $inliner = new CSSInliner();
            $data['body_html'] = $inliner->inline( $data['body_html'] );
        }

        $manager->update( $id, $data );

        return $this->get_campaign( $request );
    }

    public function delete_campaign( \WP_REST_Request $request ): \WP_REST_Response {
        $manager = new CampaignManager();
        $manager->delete( absint( $request['id'] ) );
        return new \WP_REST_Response( [ 'status' => 'deleted' ], 200 );
    }

    public function schedule_campaign( \WP_REST_Request $request ): \WP_REST_Response {
        $manager = new CampaignManager();
        $data    = $request->get_json_params();
        $id      = absint( $request['id'] );

        $scheduled_at = sanitize_text_field( $data['scheduled_at'] ?? '' );

        $result = $manager->schedule( $id, $scheduled_at ?: null );

        if ( ! $result ) {
            return new \WP_REST_Response( [ 'error' => 'Could not schedule. Campaign must be in draft status.' ], 400 );
        }

        return new \WP_REST_Response( [ 'status' => 'scheduled' ], 200 );
    }

    public function cancel_campaign( \WP_REST_Request $request ): \WP_REST_Response {
        $manager = new CampaignManager();
        $result  = $manager->cancel( absint( $request['id'] ) );

        if ( ! $result ) {
            return new \WP_REST_Response( [ 'error' => 'Could not cancel. Campaign must be in scheduled status.' ], 400 );
        }

        return new \WP_REST_Response( [ 'status' => 'cancelled' ], 200 );
    }

    public function send_campaign( \WP_REST_Request $request ): \WP_REST_Response {
        $manager = new CampaignManager();
        $result  = $manager->schedule( absint( $request['id'] ) );

        if ( ! $result ) {
            return new \WP_REST_Response( [ 'error' => 'Could not send. Campaign must be in draft status.' ], 400 );
        }

        return new \WP_REST_Response( [ 'status' => 'sending' ], 200 );
    }

    public function get_stats( \WP_REST_Request $request ): \WP_REST_Response {
        $manager = new CampaignManager();
        return new \WP_REST_Response( $manager->get_stats( absint( $request['id'] ) ), 200 );
    }

    public function preview( \WP_REST_Request $request ): \WP_REST_Response {
        $data = $request->get_json_params();
        $html = $data['body_html'] ?? '';

        // Inline CSS.
        $inliner = new CSSInliner();
        $html    = $inliner->inline( $html );

        // Replace tokens with sample data.
        $replacer = new TokenReplacer();
        $context  = [
            'first_name'      => 'Jane',
            'last_name'       => 'Doe',
            'email'           => 'jane@example.com',
            'phone'           => '+1234567890',
            'shop_name'       => get_bloginfo( 'name' ),
            'shop_url'        => home_url(),
            'unsubscribe_url' => '#',
            'order_number'    => '1234',
            'order_total'     => '$79.99',
            'order_date'      => gmdate( 'F j, Y' ),
            'order_status'    => 'Processing',
            'cart_url'        => '#',
            'cart_total'      => '$79.99',
            'product_name'    => 'Sample Product',
            'product_url'     => '#',
            'product_image_url' => '',
            'product_price'   => '$29.99',
            'coupon_code'     => 'WELCOME10',
            'ai_product_recommendations' => '',
        ];

        $html = $replacer->replace( $html, $context );

        // Also generate plain text.
        $text = $this->html_to_text( $html );

        return new \WP_REST_Response( [
            'html' => $html,
            'text' => $text,
        ], 200 );
    }

    public function inline_css( \WP_REST_Request $request ): \WP_REST_Response {
        $data    = $request->get_json_params();
        $html    = $data['html'] ?? '';
        $inliner = new CSSInliner();

        return new \WP_REST_Response( [ 'html' => $inliner->inline( $html ) ], 200 );
    }

    public function list_segments( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $segments = $wpdb->get_results(
            "SELECT id, name, subscriber_count FROM {$wpdb->prefix}ams_segments ORDER BY name ASC"
        );
        return new \WP_REST_Response( $segments, 200 );
    }

    /**
     * Convert HTML to plain text.
     */
    private function html_to_text( string $html ): string {
        // Replace <br>, <p>, <div> with newlines.
        $text = preg_replace( '/<br\s*\/?>/i', "\n", $html );
        $text = preg_replace( '/<\/(?:p|div|h[1-6]|li|tr)>/i', "\n", $text );
        $text = preg_replace( '/<(?:hr)\s*\/?>/i', "\n---\n", $text );

        // Convert links to text format.
        $text = preg_replace( '/<a[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/si', '$2 ($1)', $text );

        // Strip remaining HTML.
        $text = wp_strip_all_tags( $text );

        // Clean up whitespace.
        $text = preg_replace( '/[ \t]+/', ' ', $text );
        $text = preg_replace( '/\n{3,}/', "\n\n", $text );

        return trim( $text );
    }
}
