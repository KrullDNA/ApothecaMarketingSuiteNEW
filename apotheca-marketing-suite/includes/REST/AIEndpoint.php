<?php

namespace Apotheca\Marketing\REST;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST API endpoints for AI features.
 */
class AIEndpoint {

    public function register_routes(): void {
        $ns = 'ams/v1/admin/ai';

        // Subject line generator.
        register_rest_route( $ns, '/subject-lines', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'generate_subject_lines' ],
            'permission_callback' => [ $this, 'check_admin' ],
        ] );
        register_rest_route( $ns, '/subject-lines/(?P<key>[a-zA-Z0-9_]+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_subject_lines_result' ],
            'permission_callback' => [ $this, 'check_admin' ],
        ] );

        // Email body generator.
        register_rest_route( $ns, '/email-body', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'generate_email_body' ],
            'permission_callback' => [ $this, 'check_admin' ],
        ] );
        register_rest_route( $ns, '/email-body/(?P<key>[a-zA-Z0-9_]+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_email_body_result' ],
            'permission_callback' => [ $this, 'check_admin' ],
        ] );

        // Segment suggestions.
        register_rest_route( $ns, '/segment-suggestions', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'suggest_segments' ],
            'permission_callback' => [ $this, 'check_admin' ],
        ] );
        register_rest_route( $ns, '/segment-suggestions/(?P<key>[a-zA-Z0-9_]+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_segment_suggestions_result' ],
            'permission_callback' => [ $this, 'check_admin' ],
        ] );

        // AI usage stats.
        register_rest_route( $ns, '/usage', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_usage' ],
            'permission_callback' => [ $this, 'check_admin' ],
        ] );
    }

    public function check_admin(): bool {
        return current_user_can( 'manage_options' );
    }

    /**
     * POST: Start subject line generation.
     */
    public function generate_subject_lines( \WP_REST_Request $request ): \WP_REST_Response {
        $gen = new \Apotheca\Marketing\AI\SubjectLineGenerator();
        $key = $gen->schedule(
            sanitize_text_field( $request->get_param( 'body_summary' ) ?? '' ),
            sanitize_text_field( $request->get_param( 'segment_name' ) ?? '' )
        );
        return new \WP_REST_Response( [ 'key' => $key, 'status' => 'processing' ] );
    }

    /**
     * GET: Poll subject line result.
     */
    public function get_subject_lines_result( \WP_REST_Request $request ): \WP_REST_Response {
        $gen = new \Apotheca\Marketing\AI\SubjectLineGenerator();
        return new \WP_REST_Response( $gen->get_result( $request->get_param( 'key' ) ) );
    }

    /**
     * POST: Start email body generation.
     */
    public function generate_email_body( \WP_REST_Request $request ): \WP_REST_Response {
        $gen = new \Apotheca\Marketing\AI\EmailBodyGenerator();
        $key = $gen->schedule( [
            'goal'          => sanitize_text_field( $request->get_param( 'goal' ) ?? 'welcome' ),
            'tone'          => sanitize_text_field( $request->get_param( 'tone' ) ?? 'friendly' ),
            'key_message'   => sanitize_text_field( $request->get_param( 'key_message' ) ?? '' ),
            'product_names' => $request->get_param( 'product_names' ) ?? [],
        ] );
        return new \WP_REST_Response( [ 'key' => $key, 'status' => 'processing' ] );
    }

    /**
     * GET: Poll email body result.
     */
    public function get_email_body_result( \WP_REST_Request $request ): \WP_REST_Response {
        $gen = new \Apotheca\Marketing\AI\EmailBodyGenerator();
        return new \WP_REST_Response( $gen->get_result( $request->get_param( 'key' ) ) );
    }

    /**
     * POST: Start segment suggestions.
     */
    public function suggest_segments( \WP_REST_Request $request ): \WP_REST_Response {
        $suggester = new \Apotheca\Marketing\AI\SegmentSuggester();
        $key = $suggester->schedule();
        return new \WP_REST_Response( [ 'key' => $key, 'status' => 'processing' ] );
    }

    /**
     * GET: Poll segment suggestions result.
     */
    public function get_segment_suggestions_result( \WP_REST_Request $request ): \WP_REST_Response {
        $suggester = new \Apotheca\Marketing\AI\SegmentSuggester();
        return new \WP_REST_Response( $suggester->get_result( $request->get_param( 'key' ) ) );
    }

    /**
     * GET: AI usage statistics.
     */
    public function get_usage( \WP_REST_Request $request ): \WP_REST_Response {
        $provider = new \Apotheca\Marketing\AI\OpenAIProvider();
        $ai_settings = get_option( 'ams_ai_settings', [] );
        $budget = (int) ( $ai_settings['monthly_token_budget'] ?? 0 );
        $used = $provider->get_monthly_usage();
        $cost = $provider->get_monthly_cost();

        return new \WP_REST_Response( [
            'monthly_tokens_used'   => $used,
            'monthly_cost_usd'      => round( $cost, 4 ),
            'monthly_token_budget'  => $budget,
            'budget_used_pct'       => $budget > 0 ? round( $used / $budget * 100, 1 ) : 0,
        ] );
    }
}
