<?php

namespace Apotheca\Marketing\REST;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Apotheca\Marketing\Segments\SegmentEvaluator;
use Apotheca\Marketing\Segments\SegmentRecalculator;

class SegmentsEndpoint {

    private const NAMESPACE = 'ams/v1';

    public function register_routes(): void {
        // List + Create.
        register_rest_route( self::NAMESPACE, '/admin/segments', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'list_segments' ],
                'permission_callback' => [ $this, 'admin_check' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'create_segment' ],
                'permission_callback' => [ $this, 'admin_check' ],
            ],
        ] );

        // Get, Update, Delete.
        register_rest_route( self::NAMESPACE, '/admin/segments/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_segment' ],
                'permission_callback' => [ $this, 'admin_check' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ $this, 'update_segment' ],
                'permission_callback' => [ $this, 'admin_check' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'delete_segment' ],
                'permission_callback' => [ $this, 'admin_check' ],
            ],
        ] );

        // Live count preview (debounced from the React UI).
        register_rest_route( self::NAMESPACE, '/admin/segments/preview-count', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'preview_count' ],
            'permission_callback' => [ $this, 'admin_check' ],
        ] );

        // Condition types for the builder UI.
        register_rest_route( self::NAMESPACE, '/admin/segments/condition-types', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_condition_types' ],
            'permission_callback' => [ $this, 'admin_check' ],
        ] );

        // RFM segment summary.
        register_rest_route( self::NAMESPACE, '/admin/segments/rfm-summary', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_rfm_summary' ],
            'permission_callback' => [ $this, 'admin_check' ],
        ] );
    }

    public function admin_check( \WP_REST_Request $request ): bool {
        return current_user_can( 'manage_options' ) && wp_verify_nonce(
            $request->get_header( 'X-WP-Nonce' ),
            'wp_rest'
        );
    }

    public function list_segments( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_segments';

        $segments = $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY created_at DESC"
        );

        foreach ( $segments as &$seg ) {
            $seg->conditions = json_decode( $seg->conditions, true ) ?: [];
        }

        return new \WP_REST_Response( $segments, 200 );
    }

    public function get_segment( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $id = absint( $request['id'] );

        $segment = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ams_segments WHERE id = %d",
            $id
        ) );

        if ( ! $segment ) {
            return new \WP_REST_Response( [ 'error' => 'Not found.' ], 404 );
        }

        $segment->conditions = json_decode( $segment->conditions, true ) ?: [];

        return new \WP_REST_Response( $segment, 200 );
    }

    public function create_segment( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $data = $request->get_json_params();

        $name       = sanitize_text_field( $data['name'] ?? '' );
        $conditions = $data['conditions'] ?? [];

        if ( ! $name ) {
            return new \WP_REST_Response( [ 'error' => 'Name is required.' ], 400 );
        }

        // Validate conditions structure.
        if ( ! $this->validate_conditions( $conditions ) ) {
            return new \WP_REST_Response( [ 'error' => 'Invalid conditions structure.' ], 400 );
        }

        // Calculate initial count.
        $evaluator = new SegmentEvaluator();
        $count     = $evaluator->count_matching( $conditions );

        $wpdb->insert( $wpdb->prefix . 'ams_segments', [
            'name'             => $name,
            'conditions'       => wp_json_encode( $conditions ),
            'subscriber_count' => $count,
            'last_calculated'  => current_time( 'mysql', true ),
            'created_at'       => current_time( 'mysql', true ),
            'updated_at'       => current_time( 'mysql', true ),
        ] );

        $id = (int) $wpdb->insert_id;

        return new \WP_REST_Response( [
            'id'               => $id,
            'subscriber_count' => $count,
        ], 201 );
    }

    public function update_segment( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $id   = absint( $request['id'] );
        $data = $request->get_json_params();

        $fields = [ 'updated_at' => current_time( 'mysql', true ) ];

        if ( isset( $data['name'] ) ) {
            $fields['name'] = sanitize_text_field( $data['name'] );
        }

        if ( isset( $data['conditions'] ) ) {
            if ( ! $this->validate_conditions( $data['conditions'] ) ) {
                return new \WP_REST_Response( [ 'error' => 'Invalid conditions structure.' ], 400 );
            }
            $fields['conditions'] = wp_json_encode( $data['conditions'] );

            // Recalculate count on condition change.
            $evaluator               = new SegmentEvaluator();
            $fields['subscriber_count'] = $evaluator->count_matching( $data['conditions'] );
            $fields['last_calculated']  = current_time( 'mysql', true );
        }

        $wpdb->update( $wpdb->prefix . 'ams_segments', $fields, [ 'id' => $id ] );

        return $this->get_segment( $request );
    }

    public function delete_segment( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $id = absint( $request['id'] );

        $wpdb->delete( $wpdb->prefix . 'ams_segments', [ 'id' => $id ] );

        return new \WP_REST_Response( [ 'status' => 'deleted' ], 200 );
    }

    /**
     * Live preview count endpoint (called from React builder with 500ms debounce).
     */
    public function preview_count( \WP_REST_Request $request ): \WP_REST_Response {
        $data       = $request->get_json_params();
        $conditions = $data['conditions'] ?? [];

        if ( empty( $conditions ) || empty( $conditions['rules'] ) ) {
            global $wpdb;
            $total = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ams_subscribers WHERE status = 'active'"
            );
            return new \WP_REST_Response( [ 'count' => $total ], 200 );
        }

        $evaluator = new SegmentEvaluator();
        $count     = $evaluator->count_matching( $conditions );

        return new \WP_REST_Response( [ 'count' => $count ], 200 );
    }

    /**
     * Return available condition types for the segment builder UI.
     */
    public function get_condition_types( \WP_REST_Request $request ): \WP_REST_Response {
        return new \WP_REST_Response( [
            'conditions'   => SegmentEvaluator::get_condition_types(),
            'rfm_segments' => SegmentEvaluator::rfm_segment_labels(),
        ], 200 );
    }

    /**
     * RFM summary: count subscribers per RFM segment.
     */
    public function get_rfm_summary( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_subscribers';

        $rows = $wpdb->get_results(
            "SELECT rfm_segment, COUNT(*) as count
             FROM {$table}
             WHERE status = 'active' AND rfm_segment IS NOT NULL AND rfm_segment != ''
             GROUP BY rfm_segment
             ORDER BY count DESC"
        );

        $summary = [];
        $labels  = SegmentEvaluator::rfm_segment_labels();

        foreach ( $rows as $row ) {
            $summary[] = [
                'segment' => $row->rfm_segment,
                'label'   => $labels[ $row->rfm_segment ] ?? $row->rfm_segment,
                'count'   => (int) $row->count,
            ];
        }

        return new \WP_REST_Response( $summary, 200 );
    }

    /**
     * Validate conditions structure (basic checks).
     */
    private function validate_conditions( array $conditions ): bool {
        if ( empty( $conditions ) ) {
            return true;
        }

        if ( ! isset( $conditions['match'] ) || ! in_array( $conditions['match'], [ 'all', 'any' ], true ) ) {
            return false;
        }

        if ( ! isset( $conditions['rules'] ) || ! is_array( $conditions['rules'] ) ) {
            return false;
        }

        return $this->validate_rules( $conditions['rules'], 0 );
    }

    /**
     * Recursively validate rules (max 3 levels).
     */
    private function validate_rules( array $rules, int $depth ): bool {
        if ( $depth > 2 ) {
            return false;
        }

        foreach ( $rules as $rule ) {
            if ( isset( $rule['match'] ) && isset( $rule['rules'] ) ) {
                if ( ! in_array( $rule['match'], [ 'all', 'any' ], true ) ) {
                    return false;
                }
                if ( ! $this->validate_rules( $rule['rules'], $depth + 1 ) ) {
                    return false;
                }
            } elseif ( ! isset( $rule['field'] ) || ! isset( $rule['operator'] ) ) {
                return false;
            }
        }

        return true;
    }
}
