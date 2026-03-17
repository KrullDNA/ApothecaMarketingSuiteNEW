<?php

namespace Apotheca\Marketing\REST;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FlowsEndpoint {

    private const NAMESPACE = 'ams/v1';

    public function register_routes(): void {
        // List + Create.
        register_rest_route( self::NAMESPACE, '/admin/flows', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'list_flows' ],
                'permission_callback' => [ $this, 'admin_check' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'create_flow' ],
                'permission_callback' => [ $this, 'admin_check' ],
            ],
        ] );

        // Get, Update, Delete.
        register_rest_route( self::NAMESPACE, '/admin/flows/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_flow' ],
                'permission_callback' => [ $this, 'admin_check' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ $this, 'update_flow' ],
                'permission_callback' => [ $this, 'admin_check' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'delete_flow' ],
                'permission_callback' => [ $this, 'admin_check' ],
            ],
        ] );

        // Flow steps.
        register_rest_route( self::NAMESPACE, '/admin/flows/(?P<flow_id>\d+)/steps', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_steps' ],
                'permission_callback' => [ $this, 'admin_check' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ $this, 'save_steps' ],
                'permission_callback' => [ $this, 'admin_check' ],
            ],
        ] );

        // Import template.
        register_rest_route( self::NAMESPACE, '/admin/flows/import-template', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'import_template' ],
            'permission_callback' => [ $this, 'admin_check' ],
        ] );

        // Templates list.
        register_rest_route( self::NAMESPACE, '/admin/flows/templates', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list_templates' ],
            'permission_callback' => [ $this, 'admin_check' ],
        ] );
    }

    public function admin_check( \WP_REST_Request $request ): bool {
        return current_user_can( 'manage_options' ) && wp_verify_nonce(
            $request->get_header( 'X-WP-Nonce' ),
            'wp_rest'
        );
    }

    public function list_flows( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_flows';

        $flows = $wpdb->get_results(
            "SELECT f.*, COUNT(e.id) as enrolment_count
             FROM {$table} f
             LEFT JOIN {$wpdb->prefix}ams_flow_enrolments e ON e.flow_id = f.id
             GROUP BY f.id
             ORDER BY f.created_at DESC"
        );

        return new \WP_REST_Response( $flows, 200 );
    }

    public function get_flow( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $id = absint( $request['id'] );

        $flow = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ams_flows WHERE id = %d",
            $id
        ) );

        if ( ! $flow ) {
            return new \WP_REST_Response( [ 'error' => 'Not found.' ], 404 );
        }

        $flow->trigger_config = json_decode( $flow->trigger_config, true ) ?: [];
        $flow->steps = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ams_flow_steps WHERE flow_id = %d ORDER BY step_order ASC",
            $id
        ) );

        foreach ( $flow->steps as &$step ) {
            $step->conditions = json_decode( $step->conditions, true ) ?: [];
        }

        return new \WP_REST_Response( $flow, 200 );
    }

    public function create_flow( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $data = $request->get_json_params();

        $wpdb->insert( $wpdb->prefix . 'ams_flows', [
            'name'           => sanitize_text_field( $data['name'] ?? '' ),
            'trigger_type'   => sanitize_text_field( $data['trigger_type'] ?? '' ),
            'trigger_config' => wp_json_encode( $data['trigger_config'] ?? [] ),
            'status'         => 'draft',
            'created_at'     => current_time( 'mysql', true ),
            'updated_at'     => current_time( 'mysql', true ),
        ] );

        $id = (int) $wpdb->insert_id;

        // Save steps if provided.
        if ( ! empty( $data['steps'] ) ) {
            $this->save_flow_steps( $id, $data['steps'] );
        }

        return new \WP_REST_Response( [ 'id' => $id ], 201 );
    }

    public function update_flow( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $id   = absint( $request['id'] );
        $data = $request->get_json_params();

        $fields = [ 'updated_at' => current_time( 'mysql', true ) ];

        if ( isset( $data['name'] ) ) {
            $fields['name'] = sanitize_text_field( $data['name'] );
        }
        if ( isset( $data['trigger_type'] ) ) {
            $fields['trigger_type'] = sanitize_text_field( $data['trigger_type'] );
        }
        if ( isset( $data['trigger_config'] ) ) {
            $fields['trigger_config'] = wp_json_encode( $data['trigger_config'] );
        }
        if ( isset( $data['status'] ) ) {
            $status = sanitize_text_field( $data['status'] );
            if ( in_array( $status, [ 'draft', 'active', 'paused' ], true ) ) {
                $fields['status'] = $status;
            }
        }

        $wpdb->update( $wpdb->prefix . 'ams_flows', $fields, [ 'id' => $id ] );

        // Update steps if provided.
        if ( isset( $data['steps'] ) ) {
            $this->save_flow_steps( $id, $data['steps'] );
        }

        return $this->get_flow( $request );
    }

    public function delete_flow( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $id = absint( $request['id'] );

        $wpdb->delete( $wpdb->prefix . 'ams_flow_steps', [ 'flow_id' => $id ] );
        $wpdb->delete( $wpdb->prefix . 'ams_flows', [ 'id' => $id ] );

        return new \WP_REST_Response( [ 'status' => 'deleted' ], 200 );
    }

    public function get_steps( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $flow_id = absint( $request['flow_id'] );

        $steps = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ams_flow_steps WHERE flow_id = %d ORDER BY step_order ASC",
            $flow_id
        ) );

        foreach ( $steps as &$step ) {
            $step->conditions = json_decode( $step->conditions, true ) ?: [];
        }

        return new \WP_REST_Response( $steps, 200 );
    }

    public function save_steps( \WP_REST_Request $request ): \WP_REST_Response {
        $flow_id = absint( $request['flow_id'] );
        $steps   = $request->get_json_params();

        $this->save_flow_steps( $flow_id, $steps );

        return $this->get_steps( $request );
    }

    public function import_template( \WP_REST_Request $request ): \WP_REST_Response {
        $data     = $request->get_json_params();
        $template = sanitize_text_field( $data['template'] ?? '' );

        $template_file = AMS_PLUGIN_DIR . 'assets/templates/' . $template . '.json';
        if ( ! file_exists( $template_file ) ) {
            return new \WP_REST_Response( [ 'error' => 'Template not found.' ], 404 );
        }

        $json = file_get_contents( $template_file );
        $tmpl = json_decode( $json, true );
        if ( ! $tmpl ) {
            return new \WP_REST_Response( [ 'error' => 'Invalid template.' ], 400 );
        }

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'ams_flows', [
            'name'           => sanitize_text_field( $tmpl['name'] ?? 'Imported Flow' ),
            'trigger_type'   => sanitize_text_field( $tmpl['trigger_type'] ?? '' ),
            'trigger_config' => wp_json_encode( $tmpl['trigger_config'] ?? [] ),
            'status'         => 'draft',
            'created_at'     => current_time( 'mysql', true ),
            'updated_at'     => current_time( 'mysql', true ),
        ] );

        $flow_id = (int) $wpdb->insert_id;

        if ( ! empty( $tmpl['steps'] ) ) {
            $this->save_flow_steps( $flow_id, $tmpl['steps'] );
        }

        return new \WP_REST_Response( [ 'id' => $flow_id, 'name' => $tmpl['name'] ], 201 );
    }

    public function list_templates( \WP_REST_Request $request ): \WP_REST_Response {
        $dir   = AMS_PLUGIN_DIR . 'assets/templates/';
        $files = glob( $dir . '*.json' );
        $list  = [];

        foreach ( $files as $file ) {
            $json = file_get_contents( $file );
            $tmpl = json_decode( $json, true );
            if ( $tmpl ) {
                $list[] = [
                    'slug'         => basename( $file, '.json' ),
                    'name'         => $tmpl['name'] ?? basename( $file, '.json' ),
                    'trigger_type' => $tmpl['trigger_type'] ?? '',
                    'step_count'   => count( $tmpl['steps'] ?? [] ),
                    'description'  => $tmpl['description'] ?? '',
                ];
            }
        }

        return new \WP_REST_Response( $list, 200 );
    }

    /**
     * Save steps for a flow (replace all).
     */
    private function save_flow_steps( int $flow_id, array $steps ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_flow_steps';

        // Delete existing steps.
        $wpdb->delete( $table, [ 'flow_id' => $flow_id ] );

        foreach ( $steps as $order => $step ) {
            $wpdb->insert( $table, [
                'flow_id'      => $flow_id,
                'step_type'    => sanitize_text_field( $step['step_type'] ?? '' ),
                'step_order'   => (int) ( $step['step_order'] ?? $order ),
                'delay_value'  => absint( $step['delay_value'] ?? 0 ),
                'delay_unit'   => in_array( $step['delay_unit'] ?? '', [ 'minutes', 'hours', 'days' ], true )
                    ? $step['delay_unit'] : 'minutes',
                'subject'      => sanitize_text_field( $step['subject'] ?? '' ),
                'preview_text' => sanitize_text_field( $step['preview_text'] ?? '' ),
                'body_html'    => wp_kses_post( $step['body_html'] ?? '' ),
                'body_text'    => sanitize_textarea_field( $step['body_text'] ?? '' ),
                'sms_body'     => sanitize_textarea_field( $step['sms_body'] ?? '' ),
                'conditions'   => wp_json_encode( $step['conditions'] ?? [] ),
                'created_at'   => current_time( 'mysql', true ),
            ] );
        }
    }
}
