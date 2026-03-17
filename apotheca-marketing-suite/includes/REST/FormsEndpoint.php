<?php

namespace Apotheca\Marketing\REST;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FormsEndpoint {

    private const NAMESPACE = 'ams/v1';

    public function register_routes(): void {
        // Public: get active forms for a page.
        register_rest_route( self::NAMESPACE, '/forms/active', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_active_forms' ],
            'permission_callback' => '__return_true',
        ] );

        // Public: submit a form.
        register_rest_route( self::NAMESPACE, '/forms/submit', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'submit_form' ],
            'permission_callback' => '__return_true',
        ] );

        // Public: record form view.
        register_rest_route( self::NAMESPACE, '/forms/view', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'record_view' ],
            'permission_callback' => '__return_true',
        ] );

        // Admin: CRUD.
        register_rest_route( self::NAMESPACE, '/admin/forms', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'admin_list' ],
                'permission_callback' => [ $this, 'admin_check' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'admin_create' ],
                'permission_callback' => [ $this, 'admin_check' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/admin/forms/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'admin_get' ],
                'permission_callback' => [ $this, 'admin_check' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ $this, 'admin_update' ],
                'permission_callback' => [ $this, 'admin_check' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'admin_delete' ],
                'permission_callback' => [ $this, 'admin_check' ],
            ],
        ] );
    }

    public function admin_check( \WP_REST_Request $request ): bool {
        return current_user_can( 'manage_options' ) && wp_verify_nonce(
            $request->get_header( 'X-WP-Nonce' ),
            'wp_rest'
        );
    }

    /**
     * Public: get active forms for a page.
     */
    public function get_active_forms( \WP_REST_Request $request ): \WP_REST_Response {
        $page_id = absint( $request->get_param( 'page_id' ) );
        $manager = new \Apotheca\Marketing\Forms\FormsManager();
        $forms   = $manager->get_active_for_page( $page_id );

        $output = [];
        foreach ( $forms as $form ) {
            $output[] = [
                'id'             => (int) $form->id,
                'type'           => $form->type,
                'fields'         => $form->fields,
                'design_config'  => $form->design_config,
                'trigger_config' => $form->trigger_config,
            ];
        }

        return new \WP_REST_Response( $output, 200 );
    }

    /**
     * Public: submit a form.
     */
    public function submit_form( \WP_REST_Request $request ): \WP_REST_Response {
        // Rate limiting: 10 submissions per IP per minute.
        $ip          = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
        $rate_key    = 'ams_form_rate_' . md5( $ip );
        $submissions = (int) get_transient( $rate_key );
        if ( $submissions >= 10 ) {
            return new \WP_REST_Response( [ 'error' => 'Rate limit exceeded.' ], 429 );
        }
        set_transient( $rate_key, $submissions + 1, 60 );

        $form_id = absint( $request->get_param( 'form_id' ) );
        $fields  = $request->get_json_params();

        $forms_manager = new \Apotheca\Marketing\Forms\FormsManager();
        $form          = $forms_manager->get( $form_id );
        if ( ! $form || 'active' !== $form->status ) {
            return new \WP_REST_Response( [ 'error' => 'Form not found.' ], 404 );
        }

        $email = sanitize_email( $fields['email'] ?? '' );
        if ( ! is_email( $email ) ) {
            return new \WP_REST_Response( [ 'error' => 'Valid email required.' ], 400 );
        }

        $sub_manager = new \Apotheca\Marketing\Subscriber\SubscriberManager();
        $is_new      = null === $sub_manager->find_by_email( $email );

        $sub_data = [
            'first_name'   => $fields['first_name'] ?? '',
            'last_name'    => $fields['last_name'] ?? '',
            'phone'        => $fields['phone'] ?? '',
            'source'       => 'form',
            'gdpr_consent' => ! empty( $fields['gdpr_consent'] ) ? 1 : 0,
        ];

        if ( ! empty( $fields['sms_opt_in'] ) ) {
            $sub_data['sms_opt_in'] = 1;
        }

        // Check for double opt-in setting.
        $design = $form->design_config;
        $double_optin = ! empty( $design['double_optin'] );

        if ( $double_optin ) {
            $sub_data['status'] = 'pending';
        }

        $sub_id = $sub_manager->create_or_update( $email, $sub_data );

        // Log form submission event.
        $logger = new \Apotheca\Marketing\Subscriber\EventLogger();
        $logger->log( $sub_id, 'form_submitted', [
            'form_id'   => $form_id,
            'form_type' => $form->type,
            'fields'    => $fields,
        ] );

        $forms_manager->increment_submissions( $form_id );

        // Send double opt-in confirmation email if enabled.
        if ( $double_optin ) {
            $sub = $sub_manager->get( $sub_id );
            if ( $sub ) {
                do_action( 'ams_send_double_optin', $sub );
            }
        }

        // If new subscriber (and not double opt-in), fire welcome flow trigger.
        if ( $is_new && ! $double_optin && $sub_id ) {
            do_action( 'ams_flow_trigger', 'welcome', $sub_id, [ 'source' => 'form', 'form_id' => $form_id ] );
        }

        return new \WP_REST_Response( [ 'status' => 'ok', 'subscriber_id' => $sub_id ], 200 );
    }

    /**
     * Public: record form view.
     */
    public function record_view( \WP_REST_Request $request ): \WP_REST_Response {
        $form_id = absint( $request->get_param( 'form_id' ) );
        if ( $form_id ) {
            $manager = new \Apotheca\Marketing\Forms\FormsManager();
            $manager->increment_views( $form_id );
        }
        return new \WP_REST_Response( [ 'status' => 'ok' ], 200 );
    }

    // Admin CRUD endpoints.
    public function admin_list( \WP_REST_Request $request ): \WP_REST_Response {
        $manager = new \Apotheca\Marketing\Forms\FormsManager();
        return new \WP_REST_Response( $manager->list_forms( [
            'page'     => absint( $request->get_param( 'page' ) ) ?: 1,
            'per_page' => absint( $request->get_param( 'per_page' ) ) ?: 25,
        ] ), 200 );
    }

    public function admin_create( \WP_REST_Request $request ): \WP_REST_Response {
        $manager = new \Apotheca\Marketing\Forms\FormsManager();
        $id      = $manager->create( $request->get_json_params() );
        $form    = $manager->get( $id );
        return new \WP_REST_Response( $form, 201 );
    }

    public function admin_get( \WP_REST_Request $request ): \WP_REST_Response {
        $manager = new \Apotheca\Marketing\Forms\FormsManager();
        $form    = $manager->get( absint( $request['id'] ) );
        if ( ! $form ) {
            return new \WP_REST_Response( [ 'error' => 'Not found.' ], 404 );
        }
        return new \WP_REST_Response( $form, 200 );
    }

    public function admin_update( \WP_REST_Request $request ): \WP_REST_Response {
        $manager = new \Apotheca\Marketing\Forms\FormsManager();
        $id      = absint( $request['id'] );
        $manager->update( $id, $request->get_json_params() );
        return new \WP_REST_Response( $manager->get( $id ), 200 );
    }

    public function admin_delete( \WP_REST_Request $request ): \WP_REST_Response {
        $manager = new \Apotheca\Marketing\Forms\FormsManager();
        $manager->delete( absint( $request['id'] ) );
        return new \WP_REST_Response( [ 'status' => 'deleted' ], 200 );
    }
}
