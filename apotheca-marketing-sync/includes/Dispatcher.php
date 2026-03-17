<?php

namespace Apotheca\Marketing\Sync;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Sends HMAC-signed JSON payloads to the marketing subdomain ingest endpoint.
 *
 * Retry strategy: HTTP 5xx / timeout → retry up to 3 times (5 min, 15 min, 45 min).
 * HTTP 4xx → permanent failure, no retry.
 */
class Dispatcher {

    /**
     * Retry intervals in seconds: 5 min, 15 min, 45 min.
     */
    private const RETRY_DELAYS = [ 300, 900, 2700 ];

    /**
     * Schedule the dispatch via Action Scheduler.
     */
    public static function schedule( string $event_type, array $payload ): void {
        as_enqueue_async_action(
            'ams_sync_dispatch',
            [ $event_type, $payload, 1 ],
            'ams-sync'
        );
    }

    /**
     * Execute the dispatch (called by Action Scheduler).
     */
    public static function send( string $event_type, array $payload, int $attempt = 1 ): void {
        $settings = Plugin::get_settings();
        $endpoint = trailingslashit( $settings['endpoint_url'] ) . 'wp-json/ams/v1/sync/ingest';
        $secret   = Plugin::get_shared_secret();

        if ( empty( $settings['endpoint_url'] ) || empty( $secret ) ) {
            self::log( $event_type, $payload, 0, $attempt, 'Missing endpoint URL or shared secret.' );
            return;
        }

        $timestamp   = time();
        $json_body   = wp_json_encode( [
            'event_type' => $event_type,
            'payload'    => $payload,
            'timestamp'  => $timestamp,
            'site_url'   => home_url(),
        ] );
        $hmac = hash_hmac( 'sha256', wp_json_encode( $payload ) . $timestamp, $secret );

        $response = wp_remote_post( $endpoint, [
            'timeout' => 30,
            'headers' => [
                'Content-Type'    => 'application/json',
                'X-AMS-Signature' => $hmac,
                'X-AMS-Timestamp' => (string) $timestamp,
            ],
            'body' => $json_body,
        ] );

        if ( is_wp_error( $response ) ) {
            $error_msg = $response->get_error_message();
            self::log( $event_type, $payload, 0, $attempt, 'WP Error: ' . $error_msg );
            self::maybe_retry( $event_type, $payload, $attempt );
            return;
        }

        $http_status   = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );

        self::log( $event_type, $payload, $http_status, $attempt, substr( $response_body, 0, 500 ) );

        if ( $http_status >= 200 && $http_status < 300 ) {
            // Success — done.
            return;
        }

        if ( $http_status >= 400 && $http_status < 500 ) {
            // Permanent failure — no retry.
            return;
        }

        // 5xx or other server error — retry.
        self::maybe_retry( $event_type, $payload, $attempt );
    }

    /**
     * Schedule a retry if attempts remain.
     */
    private static function maybe_retry( string $event_type, array $payload, int $attempt ): void {
        if ( $attempt >= 4 ) {
            return;
        }
        $delay_index = $attempt - 1;
        $delay       = self::RETRY_DELAYS[ $delay_index ] ?? 2700;

        as_schedule_single_action(
            time() + $delay,
            'ams_sync_dispatch',
            [ $event_type, $payload, $attempt + 1 ],
            'ams-sync'
        );
    }

    /**
     * Log a dispatch attempt.
     */
    private static function log( string $event_type, array $payload, int $http_status, int $attempt, string $response_body ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'ams_sync_log', [
            'event_type'     => $event_type,
            'payload_hash'   => substr( md5( wp_json_encode( $payload ) ), 0, 16 ),
            'http_status'    => $http_status,
            'attempt_number' => $attempt,
            'response_body'  => substr( $response_body, 0, 500 ),
            'dispatched_at'  => current_time( 'mysql', true ),
            'created_at'     => current_time( 'mysql', true ),
        ], [ '%s', '%s', '%d', '%d', '%s', '%s', '%s' ] );
    }

    /**
     * Re-queue failed events from the last 24 hours.
     */
    public static function retry_recent_failures(): int {
        global $wpdb;

        $since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
        // Get distinct payload hashes that only have failure statuses.
        $failures = $wpdb->get_results( $wpdb->prepare(
            "SELECT event_type, payload_hash
             FROM {$wpdb->prefix}ams_sync_log
             WHERE dispatched_at >= %s
               AND (http_status = 0 OR http_status >= 500)
             GROUP BY event_type, payload_hash
             HAVING MAX(http_status) < 200 OR MAX(http_status) >= 500
             LIMIT 100",
            $since
        ) );

        $count = 0;
        foreach ( $failures as $f ) {
            as_enqueue_async_action(
                'ams_sync_retry_failed',
                [ $f->event_type, $f->payload_hash ],
                'ams-sync'
            );
            $count++;
        }

        return $count;
    }
}
