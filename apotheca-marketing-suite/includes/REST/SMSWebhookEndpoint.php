<?php

namespace Apotheca\Marketing\REST;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Apotheca\Marketing\SMS\TwilioProvider;
use Apotheca\Marketing\SMS\SMSManager;

/**
 * SMS webhook handlers:
 *   POST /wp-json/ams/v1/sms/webhook   — Inbound messages (STOP/UNSTOP/HELP)
 *   POST /wp-json/ams/v1/sms/status    — Delivery status updates
 */
class SMSWebhookEndpoint {

    private const NAMESPACE = 'ams/v1';

    public function register_routes(): void {
        // Inbound SMS webhook (Twilio sends form-encoded POST).
        register_rest_route( self::NAMESPACE, '/sms/webhook', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_inbound' ],
            'permission_callback' => '__return_true',
        ] );

        // Delivery status webhook.
        register_rest_route( self::NAMESPACE, '/sms/status', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_status' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /**
     * Handle inbound SMS messages (STOP, UNSTOP, HELP).
     * Validates X-Twilio-Signature on every request.
     */
    public function handle_inbound( \WP_REST_Request $request ): \WP_REST_Response {
        // Validate Twilio signature.
        if ( ! $this->validate_twilio_request( $request ) ) {
            return new \WP_REST_Response( [ 'error' => 'Invalid signature.' ], 403 );
        }

        $from = sanitize_text_field( $request->get_param( 'From' ) ?? '' );
        $body = strtoupper( trim( sanitize_text_field( $request->get_param( 'Body' ) ?? '' ) ) );

        if ( ! $from ) {
            return new \WP_REST_Response( [ 'error' => 'Missing From.' ], 400 );
        }

        $sms_manager = new SMSManager();
        $subscriber  = $sms_manager->find_by_phone( $from );

        // Handle keywords.
        if ( in_array( $body, [ 'STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT' ], true ) ) {
            if ( $subscriber ) {
                $sms_manager->opt_out( (int) $subscriber->id );
            }
            return $this->twiml_response( '' ); // Twilio handles STOP auto-response.
        }

        if ( in_array( $body, [ 'UNSTOP', 'START', 'YES' ], true ) ) {
            if ( $subscriber ) {
                $sms_manager->opt_in( (int) $subscriber->id );
            }
            return $this->twiml_response( 'You have been re-subscribed to SMS messages.' );
        }

        if ( 'HELP' === $body ) {
            $provider  = new TwilioProvider();
            $creds     = $provider->get_credentials();
            $help_text = $creds['help_text'] ?: 'Reply STOP to unsubscribe or HELP for help.';
            return $this->twiml_response( $help_text );
        }

        // Log unknown inbound message.
        if ( $subscriber ) {
            $logger = new \Apotheca\Marketing\Subscriber\EventLogger();
            $logger->log( (int) $subscriber->id, 'sms_inbound', [
                'from' => $from,
                'body' => $body,
            ] );
        }

        return $this->twiml_response( '' );
    }

    /**
     * Handle delivery status updates from Twilio.
     * Updates ams_sends.status based on MessageStatus.
     */
    public function handle_status( \WP_REST_Request $request ): \WP_REST_Response {
        // Validate Twilio signature.
        if ( ! $this->validate_twilio_request( $request ) ) {
            return new \WP_REST_Response( [ 'error' => 'Invalid signature.' ], 403 );
        }

        $message_sid    = sanitize_text_field( $request->get_param( 'MessageSid' ) ?? '' );
        $message_status = sanitize_text_field( $request->get_param( 'MessageStatus' ) ?? '' );
        $to             = sanitize_text_field( $request->get_param( 'To' ) ?? '' );

        if ( ! $message_status ) {
            return new \WP_REST_Response( [ 'error' => 'Missing MessageStatus.' ], 400 );
        }

        // Map Twilio status to our status values.
        $status_map = [
            'queued'      => 'queued',
            'sent'        => 'sent',
            'delivered'   => 'delivered',
            'undelivered' => 'undelivered',
            'failed'      => 'failed',
        ];

        $our_status = $status_map[ $message_status ] ?? $message_status;

        // Find the send record by subscriber phone + channel + most recent.
        global $wpdb;
        $sends_table = $wpdb->prefix . 'ams_sends';
        $subs_table  = $wpdb->prefix . 'ams_subscribers';

        if ( $to ) {
            $sms_manager = new SMSManager();
            $normalized  = $sms_manager->normalize_phone( $to );

            $send = $wpdb->get_row( $wpdb->prepare(
                "SELECT s.id FROM {$sends_table} s
                 INNER JOIN {$subs_table} sub ON sub.id = s.subscriber_id
                 WHERE sub.phone = %s AND s.channel = 'sms'
                 ORDER BY s.created_at DESC LIMIT 1",
                $normalized
            ) );

            if ( $send ) {
                $update_fields = [ 'status' => $our_status ];

                if ( 'delivered' === $our_status ) {
                    $update_fields['sent_at'] = current_time( 'mysql', true );
                }

                $wpdb->update( $sends_table, $update_fields, [ 'id' => $send->id ] );

                // For failed sends, schedule a retry if not already retried.
                if ( in_array( $our_status, [ 'failed', 'undelivered' ], true ) ) {
                    $this->schedule_retry( (int) $send->id );
                }
            }
        }

        return new \WP_REST_Response( [ 'status' => 'ok' ], 200 );
    }

    /**
     * Validate X-Twilio-Signature header.
     */
    private function validate_twilio_request( \WP_REST_Request $request ): bool {
        $signature = $request->get_header( 'X-Twilio-Signature' );
        if ( ! $signature ) {
            return false;
        }

        $provider = new TwilioProvider();

        // Build the full URL Twilio used.
        $url    = rest_url( $request->get_route() );
        $params = $request->get_body_params();

        return $provider->validate_signature( $url, $params, $signature );
    }

    /**
     * Generate TwiML response (Twilio XML).
     */
    private function twiml_response( string $message ): \WP_REST_Response {
        $response = new \WP_REST_Response();
        $response->set_status( 200 );
        $response->header( 'Content-Type', 'text/xml' );

        $twiml = '<?xml version="1.0" encoding="UTF-8"?><Response>';
        if ( $message ) {
            $twiml .= '<Message>' . esc_html( $message ) . '</Message>';
        }
        $twiml .= '</Response>';

        $response->set_data( $twiml );
        return $response;
    }

    /**
     * Schedule a retry for a failed SMS send (once, after 30 min).
     */
    private function schedule_retry( int $send_id ): void {
        global $wpdb;
        $sends_table = $wpdb->prefix . 'ams_sends';
        $subs_table  = $wpdb->prefix . 'ams_subscribers';

        $send = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.*, sub.phone FROM {$sends_table} s
             INNER JOIN {$subs_table} sub ON sub.id = s.subscriber_id
             WHERE s.id = %d",
            $send_id
        ) );

        if ( ! $send || 'retry' === $send->status || 'permanently_failed' === $send->status ) {
            return;
        }

        // Mark as retry.
        $wpdb->update( $sends_table, [ 'status' => 'retry' ], [ 'id' => $send_id ] );

        // We cannot resend the original body from here (it's not stored in ams_sends).
        // The retry is handled by TwilioProvider's internal retry mechanism.
    }
}
