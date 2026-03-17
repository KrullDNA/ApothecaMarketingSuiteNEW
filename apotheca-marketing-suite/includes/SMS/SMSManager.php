<?php

namespace Apotheca\Marketing\SMS;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SMS consent management and sending orchestration.
 *
 * Handles sms_opt_in flag, TCPA compliance, and character counting.
 */
class SMSManager {

    private const SMS_CAP_24H = 2;

    /**
     * Update SMS opt-in for a subscriber.
     */
    public function opt_in( int $subscriber_id ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'ams_subscribers',
            [ 'sms_opt_in' => 1, 'updated_at' => current_time( 'mysql', true ) ],
            [ 'id' => $subscriber_id ]
        );

        $logger = new \Apotheca\Marketing\Subscriber\EventLogger();
        $logger->log( $subscriber_id, 'sms_opt_in', [] );
    }

    /**
     * Update SMS opt-out for a subscriber.
     */
    public function opt_out( int $subscriber_id ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'ams_subscribers',
            [ 'sms_opt_in' => 0, 'updated_at' => current_time( 'mysql', true ) ],
            [ 'id' => $subscriber_id ]
        );

        $logger = new \Apotheca\Marketing\Subscriber\EventLogger();
        $logger->log( $subscriber_id, 'sms_opt_out', [] );
    }

    /**
     * Find subscriber by phone number.
     */
    public function find_by_phone( string $phone ): ?object {
        global $wpdb;
        $phone = $this->normalize_phone( $phone );
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ams_subscribers WHERE phone = %s",
            $phone
        ) );
    }

    /**
     * Normalize phone number (strip spaces, keep +).
     */
    public function normalize_phone( string $phone ): string {
        return preg_replace( '/[^\d+]/', '', trim( $phone ) );
    }

    /**
     * Check if subscriber is opted in for SMS.
     */
    public function is_opted_in( int $subscriber_id ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT sms_opt_in FROM {$wpdb->prefix}ams_subscribers WHERE id = %d",
            $subscriber_id
        ) );
    }

    /**
     * Check SMS frequency cap (2 per 24h).
     */
    public function exceeds_frequency_cap( int $subscriber_id ): bool {
        global $wpdb;
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ams_sends
             WHERE subscriber_id = %d AND channel = 'sms' AND sent_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            $subscriber_id
        ) );
        return $count >= self::SMS_CAP_24H;
    }

    /**
     * Send an SMS via the Twilio provider, with all compliance checks.
     *
     * @return int|false send_id or false if skipped.
     */
    public function send( int $subscriber_id, string $body, ?int $campaign_id = null, ?int $flow_step_id = null, ?string $media_url = null ): int|false {
        global $wpdb;

        // Check opt-in.
        if ( ! $this->is_opted_in( $subscriber_id ) ) {
            return false;
        }

        // Check frequency cap.
        if ( $this->exceeds_frequency_cap( $subscriber_id ) ) {
            return false;
        }

        // Get subscriber phone.
        $subscriber = ( new \Apotheca\Marketing\Subscriber\SubscriberManager() )->get( $subscriber_id );
        if ( ! $subscriber || empty( $subscriber->phone ) ) {
            return false;
        }

        // Record the send.
        $wpdb->insert( $wpdb->prefix . 'ams_sends', [
            'campaign_id'   => $campaign_id,
            'flow_step_id'  => $flow_step_id,
            'subscriber_id' => $subscriber_id,
            'channel'       => 'sms',
            'status'        => 'queued',
            'created_at'    => current_time( 'mysql', true ),
        ] );
        $send_id = (int) $wpdb->insert_id;

        // Dispatch via Twilio provider (async via Action Scheduler).
        do_action( 'ams_send_sms', $subscriber->phone, $body, $send_id );

        return $send_id;
    }

    /**
     * Calculate SMS character count info.
     *
     * @return array{ chars: int, segments: int, remaining: int, has_unicode: bool }
     */
    public static function char_count( string $body ): array {
        $has_unicode = (bool) preg_match( '/[^\x00-\x7F]/', $body );
        $chars = mb_strlen( $body, 'UTF-8' );

        // TCPA copy will add ~27 chars.
        if ( stripos( $body, 'Reply STOP' ) === false ) {
            $chars += 27;
        }

        if ( $has_unicode ) {
            $single_limit = 70;
            $multi_limit  = 67;
        } else {
            $single_limit = 160;
            $multi_limit  = 153;
        }

        if ( $chars <= $single_limit ) {
            return [
                'chars'       => $chars,
                'segments'    => 1,
                'remaining'   => $single_limit - $chars,
                'has_unicode' => $has_unicode,
            ];
        }

        $segments  = (int) ceil( $chars / $multi_limit );
        $remaining = ( $segments * $multi_limit ) - $chars;

        return [
            'chars'       => $chars,
            'segments'    => $segments,
            'remaining'   => $remaining,
            'has_unicode' => $has_unicode,
        ];
    }
}
