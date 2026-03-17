<?php

namespace Apotheca\Marketing\REST;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class IngestEndpoint {

    private const NAMESPACE = 'ams/v1';
    private const TIMESTAMP_WINDOW = 300; // 5 minutes.

    /**
     * Valid event types that the ingest endpoint accepts.
     */
    private const VALID_EVENTS = [
        'customer_registered',
        'order_placed',
        'order_status_changed',
        'cart_updated',
        'checkout_started',
        'product_viewed',
        'abandoned_cart',
    ];

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/sync/ingest', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_ingest' ],
            'permission_callback' => '__return_true', // Auth via HMAC only.
        ] );
    }

    public function handle_ingest( \WP_REST_Request $request ): \WP_REST_Response {
        $signature = $request->get_header( 'X-AMS-Signature' );
        $timestamp = $request->get_header( 'X-AMS-Timestamp' );
        $raw_body  = $request->get_body();

        // Validate required headers.
        if ( empty( $signature ) || empty( $timestamp ) ) {
            $this->log_event( '', '', 401, 'auth_failed' );
            return new \WP_REST_Response( [ 'error' => 'Missing authentication headers.' ], 401 );
        }

        // Validate timestamp window.
        $ts = (int) $timestamp;
        if ( abs( time() - $ts ) > self::TIMESTAMP_WINDOW ) {
            $this->log_event( '', '', 401, 'auth_failed' );
            return new \WP_REST_Response( [ 'error' => 'Timestamp outside acceptable window.' ], 401 );
        }

        // Validate HMAC signature.
        $settings      = get_option( 'ams_settings', [] );
        $shared_secret = $this->decrypt_secret( $settings['sync_shared_secret'] ?? '' );

        if ( empty( $shared_secret ) ) {
            $this->log_event( '', '', 500, 'error' );
            return new \WP_REST_Response( [ 'error' => 'Shared secret not configured.' ], 500 );
        }

        $expected = hash_hmac( 'sha256', $raw_body, $shared_secret );
        if ( ! hash_equals( $expected, $signature ) ) {
            $this->log_event( '', '', 401, 'auth_failed' );
            return new \WP_REST_Response( [ 'error' => 'Invalid signature.' ], 401 );
        }

        // Parse the payload.
        $data = json_decode( $raw_body, true );
        if ( ! is_array( $data ) || empty( $data['event_type'] ) ) {
            $this->log_event( '', $raw_body, 400, 'error' );
            return new \WP_REST_Response( [ 'error' => 'Invalid payload.' ], 400 );
        }

        $event_type  = sanitize_text_field( $data['event_type'] );
        $payload     = $data['payload'] ?? [];
        $source_url  = sanitize_text_field( $data['site_url'] ?? '' );

        // Validate event type.
        if ( ! in_array( $event_type, self::VALID_EVENTS, true ) ) {
            $this->log_event( $event_type, $raw_body, 400, 'unknown_event', $source_url );
            return new \WP_REST_Response( [ 'error' => 'Unknown event type.', 'event' => $event_type ], 400 );
        }

        // Route to handler (stubs — filled in Sessions 2-3).
        $this->route_event( $event_type, $payload );

        // Log success.
        $this->log_event( $event_type, $raw_body, 200, 'processed', $source_url );

        return new \WP_REST_Response( [ 'status' => 'ok', 'event' => $event_type ], 200 );
    }

    /**
     * Route an event to its handler method.
     * Stubs for now — filled in Sessions 2-3.
     */
    private function route_event( string $event_type, array $payload ): void {
        $method = 'handle_' . $event_type;
        if ( method_exists( $this, $method ) ) {
            $this->$method( $payload );
        }
    }

    private function handle_customer_registered( array $payload ): void {
        $manager = new \Apotheca\Marketing\Subscriber\SubscriberManager();
        $logger  = new \Apotheca\Marketing\Subscriber\EventLogger();

        $email = sanitize_email( $payload['email'] ?? '' );
        if ( ! is_email( $email ) ) {
            return;
        }

        $is_new = null === $manager->find_by_email( $email );

        $sub_id = $manager->create_or_update( $email, [
            'first_name' => $payload['first_name'] ?? '',
            'last_name'  => $payload['last_name'] ?? '',
            'source'     => 'sync_registration',
        ] );

        $logger->log( $sub_id, 'customer_registered', $payload );

        // If new subscriber, fire welcome flow trigger.
        if ( $is_new && $sub_id ) {
            do_action( 'ams_flow_trigger', 'welcome', $sub_id, $payload );
        }
    }

    private function handle_order_placed( array $payload ): void {
        $manager = new \Apotheca\Marketing\Subscriber\SubscriberManager();
        $logger  = new \Apotheca\Marketing\Subscriber\EventLogger();

        $email = sanitize_email( $payload['customer_email'] ?? '' );
        if ( ! is_email( $email ) ) {
            return;
        }

        $sub_id = $manager->create_or_update( $email, [
            'first_name' => $payload['billing_first_name'] ?? '',
            'last_name'  => $payload['billing_last_name'] ?? '',
            'phone'      => $payload['billing_phone'] ?? '',
            'source'     => 'sync_order',
        ] );

        $order_id    = absint( $payload['order_id'] ?? 0 );
        $order_total = (float) ( $payload['order_total'] ?? 0 );
        $product_ids = array_map( 'absint', $payload['product_ids'] ?? [] );

        $logger->log( $sub_id, 'placed_order', $payload, $order_id, $product_ids );
        $manager->increment_order_stats( $sub_id, $order_total, current_time( 'mysql', true ) );

        // Fire attribution hook for revenue attribution.
        do_action( 'ams_order_placed', $sub_id, $order_id, $order_total );
    }

    private function handle_order_status_changed( array $payload ): void {
        $manager = new \Apotheca\Marketing\Subscriber\SubscriberManager();
        $logger  = new \Apotheca\Marketing\Subscriber\EventLogger();

        $email = sanitize_email( $payload['customer_email'] ?? '' );
        $sub   = $email ? $manager->find_by_email( $email ) : null;
        if ( ! $sub ) {
            return;
        }

        $order_id   = absint( $payload['order_id'] ?? 0 );
        $new_status = sanitize_text_field( $payload['new_status'] ?? '' );

        $logger->log( (int) $sub->id, 'order_status_changed', $payload, $order_id );

        if ( 'completed' === $new_status ) {
            do_action( 'ams_flow_trigger', 'post_purchase', (int) $sub->id, $payload );
        }
        if ( 'refunded' === $new_status ) {
            $logger->log( (int) $sub->id, 'refund_requested', $payload, $order_id );
        }
    }

    private function handle_cart_updated( array $payload ): void {
        $manager = new \Apotheca\Marketing\Subscriber\SubscriberManager();
        $logger  = new \Apotheca\Marketing\Subscriber\EventLogger();

        $sub = null;
        $email = sanitize_email( $payload['customer_email'] ?? '' );
        if ( $email ) {
            $sub = $manager->find_by_email( $email );
        }
        if ( ! $sub && ! empty( $payload['subscriber_token'] ) ) {
            $sub = $manager->find_by_token( sanitize_text_field( $payload['subscriber_token'] ) );
        }
        if ( ! $sub ) {
            return;
        }

        $product_ids = array_map( 'absint', $payload['product_ids'] ?? [] );
        $logger->log( (int) $sub->id, 'added_to_cart', $payload, 0, $product_ids );

        // Reset abandoned cart timer — schedule check in 60 minutes.
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( 'ams_check_abandoned_cart', [ (int) $sub->id ], 'ams' );
        }
        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action( time() + 3600, 'ams_check_abandoned_cart', [ (int) $sub->id ], 'ams' );
        }
    }

    private function handle_checkout_started( array $payload ): void {
        $manager = new \Apotheca\Marketing\Subscriber\SubscriberManager();
        $logger  = new \Apotheca\Marketing\Subscriber\EventLogger();

        $email = sanitize_email( $payload['customer_email'] ?? '' );
        if ( ! is_email( $email ) ) {
            return;
        }

        $sub_id = $manager->create_or_update( $email, [
            'source' => 'sync_order',
        ] );

        $logger->log( $sub_id, 'started_checkout', $payload );
    }

    private function handle_product_viewed( array $payload ): void {
        $manager = new \Apotheca\Marketing\Subscriber\SubscriberManager();
        $logger  = new \Apotheca\Marketing\Subscriber\EventLogger();

        $sub = null;
        if ( ! empty( $payload['subscriber_token'] ) ) {
            $sub = $manager->find_by_token( sanitize_text_field( $payload['subscriber_token'] ) );
        }
        if ( ! $sub ) {
            return;
        }

        $product_ids = [ absint( $payload['product_id'] ?? 0 ) ];
        $logger->log( (int) $sub->id, 'viewed_product', $payload, 0, array_filter( $product_ids ) );
    }

    private function handle_abandoned_cart( array $payload ): void {
        $manager = new \Apotheca\Marketing\Subscriber\SubscriberManager();
        $logger  = new \Apotheca\Marketing\Subscriber\EventLogger();

        $email = sanitize_email( $payload['customer_email'] ?? '' );
        $sub   = $email ? $manager->find_by_email( $email ) : null;
        if ( ! $sub ) {
            return;
        }

        $product_ids = array_map( 'absint', $payload['product_ids'] ?? [] );
        $logger->log( (int) $sub->id, 'abandoned_cart', $payload, 0, $product_ids );

        do_action( 'ams_flow_trigger', 'abandoned_cart', (int) $sub->id, $payload );
    }

    /**
     * Log an ingest event to ams_sync_log.
     */
    private function log_event( string $event_type, string $raw_body, int $http_code, string $status, string $source_url = '' ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_sync_log';

        $wpdb->insert( $table, [
            'event_type'         => $event_type,
            'source_site_url'    => $source_url,
            'payload_hash'       => $raw_body ? substr( md5( $raw_body ), 0, 16 ) : '',
            'http_response_sent' => $http_code,
            'status'             => $status,
            'received_at'        => current_time( 'mysql', true ),
        ], [ '%s', '%s', '%s', '%d', '%s', '%s' ] );
    }

    /**
     * Decrypt the shared secret stored in settings.
     */
    private function decrypt_secret( string $encrypted ): string {
        if ( empty( $encrypted ) ) {
            return '';
        }

        $key = substr( hash( 'sha256', AUTH_KEY ), 0, 32 );

        $decoded = base64_decode( $encrypted, true );
        if ( false === $decoded ) {
            return '';
        }

        $iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
        if ( strlen( $decoded ) < $iv_length ) {
            return '';
        }

        $iv        = substr( $decoded, 0, $iv_length );
        $encrypted = substr( $decoded, $iv_length );

        $decrypted = openssl_decrypt( $encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

        return false === $decrypted ? '' : $decrypted;
    }
}
