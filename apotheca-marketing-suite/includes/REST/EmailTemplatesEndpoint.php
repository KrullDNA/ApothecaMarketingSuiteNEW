<?php

namespace Apotheca\Marketing\REST;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EmailTemplatesEndpoint {

    public function register_routes(): void {
        register_rest_route( 'ams/v1/admin', '/email-templates', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'list_templates' ],
                'permission_callback' => [ $this, 'check_admin' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'create_template' ],
                'permission_callback' => [ $this, 'check_admin' ],
            ],
        ] );

        register_rest_route( 'ams/v1/admin', '/email-templates/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_template' ],
                'permission_callback' => [ $this, 'check_admin' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ $this, 'update_template' ],
                'permission_callback' => [ $this, 'check_admin' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'delete_template' ],
                'permission_callback' => [ $this, 'check_admin' ],
            ],
        ] );
    }

    public function check_admin(): bool {
        return current_user_can( 'manage_options' );
    }

    public function list_templates( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_email_templates';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $templates = $wpdb->get_results(
            "SELECT id, name, description, created_at, updated_at FROM {$table} ORDER BY updated_at DESC",
            ARRAY_A
        );

        return new \WP_REST_Response( $templates ?: [], 200 );
    }

    public function get_template( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_email_templates';
        $id    = absint( $request['id'] );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $template = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
            ARRAY_A
        );

        if ( ! $template ) {
            return new \WP_REST_Response( [ 'message' => 'Template not found.' ], 404 );
        }

        return new \WP_REST_Response( $template, 200 );
    }

    public function create_template( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_email_templates';

        $name        = sanitize_text_field( $request->get_param( 'name' ) ?: 'Untitled Template' );
        $description = sanitize_text_field( $request->get_param( 'description' ) ?: '' );
        $json        = $request->get_param( 'structure_json' ) ?: '{"settings":{},"rows":[]}';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert( $table, [
            'name'           => $name,
            'description'    => $description,
            'structure_json' => $json,
            'created_at'     => current_time( 'mysql', true ),
            'updated_at'     => current_time( 'mysql', true ),
        ] );

        $id = $wpdb->insert_id;
        if ( ! $id ) {
            return new \WP_REST_Response( [ 'message' => 'Failed to create template.' ], 500 );
        }

        return new \WP_REST_Response( [ 'id' => $id, 'name' => $name ], 201 );
    }

    public function update_template( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_email_templates';
        $id    = absint( $request['id'] );

        $data = [];
        if ( $request->has_param( 'name' ) ) {
            $data['name'] = sanitize_text_field( $request->get_param( 'name' ) );
        }
        if ( $request->has_param( 'description' ) ) {
            $data['description'] = sanitize_text_field( $request->get_param( 'description' ) );
        }
        if ( $request->has_param( 'structure_json' ) ) {
            $data['structure_json'] = $request->get_param( 'structure_json' );
        }

        if ( empty( $data ) ) {
            return new \WP_REST_Response( [ 'message' => 'No data to update.' ], 400 );
        }

        $data['updated_at'] = current_time( 'mysql', true );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update( $table, $data, [ 'id' => $id ] );

        return new \WP_REST_Response( [ 'message' => 'Updated.' ], 200 );
    }

    public function delete_template( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_email_templates';
        $id    = absint( $request['id'] );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete( $table, [ 'id' => $id ] );

        return new \WP_REST_Response( [ 'message' => 'Deleted.' ], 200 );
    }
}
