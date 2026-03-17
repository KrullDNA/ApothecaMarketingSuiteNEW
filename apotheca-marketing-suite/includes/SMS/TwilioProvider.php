<?php

namespace Apotheca\Marketing\SMS;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Twilio REST API provider.
 *
 * Uses wp_remote_post only — no Twilio PHP SDK.
 * Credentials stored encrypted (AES-256-CBC) using WordPress AUTH_KEY.
 * All API calls dispatched async via Action Scheduler.
 */
class TwilioProvider {

    private const API_BASE     = 'https://api.twilio.com/2010-04-01';
    private const SEND_HOOK    = 'ams_twilio_send_sms';
    private const RETRY_HOOK   = 'ams_twilio_retry_sms';
    private const RETRY_DELAY  = 1800; // 30 minutes.

    /**
     * Register Action Scheduler hooks.
     */
    public function register(): void {
        add_action( self::SEND_HOOK, [ $this, 'do_send' ], 10, 3 );
        add_action( self::RETRY_HOOK, [ $this, 'do_send' ], 10, 3 );

        // Listen for ams_send_sms action from campaigns and flows.
        add_action( 'ams_send_sms', [ $this, 'queue_send' ], 10, 3 );
    }

    /**
     * Queue an SMS for async sending via Action Scheduler.
     *
     * @param string $to        Phone number.
     * @param string $body      SMS body.
     * @param int    $send_id   ams_sends row ID.
     */
    public function queue_send( string $to, string $body, int $send_id = 0 ): void {
        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action(
                time(),
                self::SEND_HOOK,
                [ $to, $body, $send_id ],
                'ams_sms'
            );
        }
    }

    /**
     * Execute the Twilio API call (called by Action Scheduler).
     */
    public function do_send( string $to, string $body, int $send_id = 0 ): void {
        $creds = $this->get_credentials();
        if ( ! $creds['account_sid'] || ! $creds['auth_token'] ) {
            $this->update_send_status( $send_id, 'failed' );
            return;
        }

        $from = $creds['messaging_service_sid'] ?: $creds['from_number'];
        if ( ! $from ) {
            $this->update_send_status( $send_id, 'failed' );
            return;
        }

        // Append TCPA-compliant opt-out copy.
        $body = $this->append_tcpa_copy( $body );

        $url = self::API_BASE . '/Accounts/' . $creds['account_sid'] . '/Messages.json';

        $params = [
            'To'   => $to,
            'Body' => $body,
        ];

        // Use MessagingServiceSid or From number.
        if ( $creds['messaging_service_sid'] ) {
            $params['MessagingServiceSid'] = $creds['messaging_service_sid'];
        } else {
            $params['From'] = $creds['from_number'];
        }

        // Add status callback URL.
        $params['StatusCallback'] = rest_url( 'ams/v1/sms/status' );

        $response = wp_remote_post( $url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $creds['account_sid'] . ':' . $creds['auth_token'] ),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body'    => $params,
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            $this->handle_failure( $send_id, $to, $body );
            return;
        }

        $code    = wp_remote_retrieve_response_code( $response );
        $result  = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 200 && $code < 300 ) {
            $twilio_sid = $result['sid'] ?? '';
            $this->update_send_status( $send_id, 'sent', $twilio_sid );
        } else {
            $this->handle_failure( $send_id, $to, $body );
        }
    }

    /**
     * Handle a send failure — retry once after 30 min, then mark permanently_failed.
     */
    private function handle_failure( int $send_id, string $to, string $body ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_sends';

        if ( $send_id > 0 ) {
            $current = $wpdb->get_var( $wpdb->prepare(
                "SELECT status FROM {$table} WHERE id = %d",
                $send_id
            ) );

            // If already retried once, mark permanently failed.
            if ( 'retry' === $current ) {
                $this->update_send_status( $send_id, 'permanently_failed' );
                return;
            }

            // Mark as retry and schedule one more attempt.
            $this->update_send_status( $send_id, 'retry' );

            if ( function_exists( 'as_schedule_single_action' ) ) {
                as_schedule_single_action(
                    time() + self::RETRY_DELAY,
                    self::RETRY_HOOK,
                    [ $to, $body, $send_id ],
                    'ams_sms'
                );
            }
        }
    }

    /**
     * Update ams_sends status and optionally store Twilio SID.
     */
    private function update_send_status( int $send_id, string $status, string $twilio_sid = '' ): void {
        if ( $send_id <= 0 ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ams_sends';

        $fields = [ 'status' => $status ];

        if ( 'sent' === $status || 'delivered' === $status ) {
            $fields['sent_at'] = current_time( 'mysql', true );
        }

        $wpdb->update( $table, $fields, [ 'id' => $send_id ] );
    }

    /**
     * Append TCPA-compliant opt-out text.
     */
    private function append_tcpa_copy( string $body ): string {
        $stop_text = "\nReply STOP to unsubscribe.";

        // Don't double-append.
        if ( stripos( $body, 'Reply STOP' ) !== false ) {
            return $body;
        }

        return $body . $stop_text;
    }

    /**
     * Validate Twilio X-Twilio-Signature for webhook requests.
     */
    public function validate_signature( string $url, array $params, string $signature ): bool {
        $creds = $this->get_credentials();
        if ( ! $creds['auth_token'] ) {
            return false;
        }

        // Build the data string: URL + sorted params.
        ksort( $params );
        $data = $url;
        foreach ( $params as $key => $value ) {
            $data .= $key . $value;
        }

        $expected = base64_encode( hash_hmac( 'sha1', $data, $creds['auth_token'], true ) );

        return hash_equals( $expected, $signature );
    }

    /**
     * Send a test SMS (synchronous, for admin test button).
     */
    public function send_test( string $to, string $body ): array {
        $creds = $this->get_credentials();
        if ( ! $creds['account_sid'] || ! $creds['auth_token'] ) {
            return [ 'success' => false, 'error' => 'SMS credentials not configured.' ];
        }

        $from = $creds['messaging_service_sid'] ?: $creds['from_number'];
        if ( ! $from ) {
            return [ 'success' => false, 'error' => 'From number or Messaging Service SID not configured.' ];
        }

        $url = self::API_BASE . '/Accounts/' . $creds['account_sid'] . '/Messages.json';

        $params = [
            'To'   => $to,
            'Body' => $body . "\nReply STOP to unsubscribe.",
        ];

        if ( $creds['messaging_service_sid'] ) {
            $params['MessagingServiceSid'] = $creds['messaging_service_sid'];
        } else {
            $params['From'] = $creds['from_number'];
        }

        $response = wp_remote_post( $url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $creds['account_sid'] . ':' . $creds['auth_token'] ),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body'    => $params,
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }

        $code   = wp_remote_retrieve_response_code( $response );
        $result = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 200 && $code < 300 ) {
            return [ 'success' => true, 'sid' => $result['sid'] ?? '' ];
        }

        return [ 'success' => false, 'error' => $result['message'] ?? 'HTTP ' . $code ];
    }

    /**
     * Get decrypted Twilio credentials.
     */
    public function get_credentials(): array {
        $settings = get_option( 'ams_sms_settings', [] );

        return [
            'account_sid'          => $this->decrypt( $settings['account_sid'] ?? '' ),
            'auth_token'           => $this->decrypt( $settings['auth_token'] ?? '' ),
            'from_number'          => $this->decrypt( $settings['from_number'] ?? '' ),
            'messaging_service_sid'=> $this->decrypt( $settings['messaging_service_sid'] ?? '' ),
            'help_text'            => $settings['help_text'] ?? 'Reply STOP to unsubscribe or HELP for help.',
        ];
    }

    /**
     * Encrypt using AES-256-CBC with WordPress AUTH_KEY.
     */
    public static function encrypt( string $value ): string {
        if ( empty( $value ) ) {
            return '';
        }
        $key       = substr( hash( 'sha256', AUTH_KEY ), 0, 32 );
        $iv        = openssl_random_pseudo_bytes( openssl_cipher_iv_length( 'aes-256-cbc' ) );
        $encrypted = openssl_encrypt( $value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
        return base64_encode( $iv . $encrypted );
    }

    /**
     * Decrypt using AES-256-CBC with WordPress AUTH_KEY.
     */
    public static function decrypt( string $encrypted ): string {
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
        $encrypted_raw = substr( $decoded, $iv_length );
        $decrypted = openssl_decrypt( $encrypted_raw, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
        return false === $decrypted ? '' : $decrypted;
    }
}
