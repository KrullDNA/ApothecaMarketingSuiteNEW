<?php

namespace Apotheca\Marketing\Campaigns;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CampaignManager {

    private const SEND_HOOK   = 'ams_campaign_send';
    private const BATCH_HOOK  = 'ams_campaign_send_batch';
    private const BATCH_SIZE  = 100;
    private const EMAIL_CAP_24H = 3;
    private const SMS_CAP_24H   = 2;

    /**
     * Register Action Scheduler hooks.
     */
    public function register(): void {
        add_action( self::SEND_HOOK, [ $this, 'start_send' ] );
        add_action( self::BATCH_HOOK, [ $this, 'send_batch' ], 10, 2 );
    }

    /**
     * Create a campaign.
     */
    public function create( array $data ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_campaigns';

        $wpdb->insert( $table, [
            'name'         => sanitize_text_field( $data['name'] ?? '' ),
            'type'         => in_array( $data['type'] ?? '', [ 'email', 'sms' ], true ) ? $data['type'] : 'email',
            'status'       => 'draft',
            'segment_id'   => absint( $data['segment_id'] ?? 0 ) ?: null,
            'subject'      => sanitize_text_field( $data['subject'] ?? '' ),
            'preview_text' => sanitize_text_field( $data['preview_text'] ?? '' ),
            'body_html'    => wp_kses_post( $data['body_html'] ?? '' ),
            'body_text'    => sanitize_textarea_field( $data['body_text'] ?? '' ),
            'sms_body'     => sanitize_textarea_field( $data['sms_body'] ?? '' ),
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'created_at'   => current_time( 'mysql', true ),
            'updated_at'   => current_time( 'mysql', true ),
        ] );

        return (int) $wpdb->insert_id;
    }

    /**
     * Update a campaign.
     */
    public function update( int $id, array $data ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_campaigns';

        $fields = [ 'updated_at' => current_time( 'mysql', true ) ];

        $string_fields = [ 'name', 'subject', 'preview_text' ];
        foreach ( $string_fields as $f ) {
            if ( isset( $data[ $f ] ) ) {
                $fields[ $f ] = sanitize_text_field( $data[ $f ] );
            }
        }

        if ( isset( $data['type'] ) && in_array( $data['type'], [ 'email', 'sms' ], true ) ) {
            $fields['type'] = $data['type'];
        }
        if ( isset( $data['segment_id'] ) ) {
            $fields['segment_id'] = absint( $data['segment_id'] ) ?: null;
        }
        if ( isset( $data['body_html'] ) ) {
            $fields['body_html'] = wp_kses_post( $data['body_html'] );
        }
        if ( isset( $data['body_text'] ) ) {
            $fields['body_text'] = sanitize_textarea_field( $data['body_text'] );
        }
        if ( isset( $data['sms_body'] ) ) {
            $fields['sms_body'] = sanitize_textarea_field( $data['sms_body'] );
        }
        if ( isset( $data['scheduled_at'] ) ) {
            $fields['scheduled_at'] = $data['scheduled_at'];
        }
        if ( isset( $data['status'] ) ) {
            $valid = [ 'draft', 'scheduled', 'sent', 'cancelled' ];
            if ( in_array( $data['status'], $valid, true ) ) {
                $fields['status'] = $data['status'];
            }
        }

        $result = $wpdb->update( $table, $fields, [ 'id' => $id ] );
        return false !== $result;
    }

    /**
     * Get a campaign by ID.
     */
    public function get( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ams_campaigns WHERE id = %d",
            $id
        ) );
    }

    /**
     * List campaigns.
     */
    public function list_campaigns(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT c.*, s.name as segment_name, s.subscriber_count
             FROM {$wpdb->prefix}ams_campaigns c
             LEFT JOIN {$wpdb->prefix}ams_segments s ON s.id = c.segment_id
             ORDER BY c.created_at DESC"
        );
    }

    /**
     * Delete a campaign.
     */
    public function delete( int $id ): bool {
        global $wpdb;
        return false !== $wpdb->delete( $wpdb->prefix . 'ams_campaigns', [ 'id' => $id ] );
    }

    /**
     * Schedule a campaign for sending.
     */
    public function schedule( int $campaign_id, ?string $scheduled_at = null ): bool {
        $campaign = $this->get( $campaign_id );
        if ( ! $campaign || 'draft' !== $campaign->status ) {
            return false;
        }

        $timestamp = $scheduled_at ? strtotime( $scheduled_at ) : time();
        if ( ! $timestamp ) {
            return false;
        }

        $this->update( $campaign_id, [
            'status'       => 'scheduled',
            'scheduled_at' => gmdate( 'Y-m-d H:i:s', $timestamp ),
        ] );

        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action( $timestamp, self::SEND_HOOK, [ $campaign_id ], 'ams_campaigns' );
        }

        return true;
    }

    /**
     * Cancel a scheduled campaign.
     */
    public function cancel( int $campaign_id ): bool {
        $campaign = $this->get( $campaign_id );
        if ( ! $campaign || 'scheduled' !== $campaign->status ) {
            return false;
        }

        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( self::SEND_HOOK, [ $campaign_id ], 'ams_campaigns' );
        }

        return $this->update( $campaign_id, [ 'status' => 'cancelled' ] );
    }

    /**
     * Start sending a campaign (called by Action Scheduler).
     */
    public function start_send( int $campaign_id ): void {
        $campaign = $this->get( $campaign_id );
        if ( ! $campaign || ! in_array( $campaign->status, [ 'scheduled', 'draft' ], true ) ) {
            return;
        }

        // Update status to sending (we use 'sent' since enum only has draft/scheduled/sent/cancelled).
        $this->update( $campaign_id, [ 'status' => 'sent', 'sent_at' => current_time( 'mysql', true ) ] );

        // Schedule the first batch.
        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action( time(), self::BATCH_HOOK, [ $campaign_id, 0 ], 'ams_campaigns' );
        }
    }

    /**
     * Send a batch of the campaign (called by Action Scheduler).
     */
    public function send_batch( int $campaign_id, int $offset ): void {
        global $wpdb;

        $campaign = $this->get( $campaign_id );
        if ( ! $campaign ) {
            return;
        }

        // Get segment subscriber IDs.
        $subscriber_ids = $this->get_segment_subscriber_ids( (int) $campaign->segment_id, $offset );

        if ( empty( $subscriber_ids ) ) {
            return; // All done.
        }

        $token_replacer = new TokenReplacer();
        $sub_manager    = new \Apotheca\Marketing\Subscriber\SubscriberManager();
        $channel        = $campaign->type;

        foreach ( $subscriber_ids as $sub_id ) {
            $subscriber = $sub_manager->get( $sub_id );
            if ( ! $subscriber || 'active' !== $subscriber->status ) {
                continue;
            }

            // Frequency cap check.
            if ( $this->exceeds_frequency_cap( $sub_id, $channel ) ) {
                continue;
            }

            // Replace tokens in content.
            $context = $this->build_token_context( $subscriber, $campaign );

            if ( 'email' === $channel ) {
                $html    = $token_replacer->replace( $campaign->body_html ?? '', $context );
                $text    = $token_replacer->replace( $campaign->body_text ?? '', $context );
                $subject = $token_replacer->replace( $campaign->subject ?? '', $context );

                // Record the send.
                $wpdb->insert( $wpdb->prefix . 'ams_sends', [
                    'campaign_id'   => $campaign_id,
                    'subscriber_id' => $sub_id,
                    'channel'       => 'email',
                    'status'        => 'sent',
                    'sent_at'       => current_time( 'mysql', true ),
                    'created_at'    => current_time( 'mysql', true ),
                ] );

                do_action( 'ams_send_email', $subscriber->email, $subject, $html, $text, (int) $wpdb->insert_id );
            } else {
                $sms_body = $token_replacer->replace( $campaign->sms_body ?? '', $context );

                $wpdb->insert( $wpdb->prefix . 'ams_sends', [
                    'campaign_id'   => $campaign_id,
                    'subscriber_id' => $sub_id,
                    'channel'       => 'sms',
                    'status'        => 'sent',
                    'sent_at'       => current_time( 'mysql', true ),
                    'created_at'    => current_time( 'mysql', true ),
                ] );

                do_action( 'ams_send_sms', $subscriber->phone, $sms_body, (int) $wpdb->insert_id );
            }
        }

        // Schedule next batch if we got a full batch.
        if ( count( $subscriber_ids ) === self::BATCH_SIZE ) {
            if ( function_exists( 'as_schedule_single_action' ) ) {
                as_schedule_single_action(
                    time() + 5,
                    self::BATCH_HOOK,
                    [ $campaign_id, $offset + self::BATCH_SIZE ],
                    'ams_campaigns'
                );
            }
        }
    }

    /**
     * Get subscriber IDs for a segment (or all active if no segment).
     */
    private function get_segment_subscriber_ids( int $segment_id, int $offset ): array {
        global $wpdb;

        if ( $segment_id > 0 ) {
            $segment = $wpdb->get_row( $wpdb->prepare(
                "SELECT conditions FROM {$wpdb->prefix}ams_segments WHERE id = %d",
                $segment_id
            ) );

            if ( $segment ) {
                $conditions = json_decode( $segment->conditions, true ) ?: [];
                $evaluator  = new \Apotheca\Marketing\Segments\SegmentEvaluator();
                $all_ids    = $evaluator->get_matching_ids( $conditions );
                return array_slice( $all_ids, $offset, self::BATCH_SIZE );
            }
        }

        // Fallback: all active subscribers.
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ams_subscribers WHERE status = 'active' ORDER BY id ASC LIMIT %d OFFSET %d",
            self::BATCH_SIZE,
            $offset
        ) ) );
    }

    /**
     * Check frequency cap.
     */
    private function exceeds_frequency_cap( int $subscriber_id, string $channel ): bool {
        global $wpdb;
        $cap = 'email' === $channel ? self::EMAIL_CAP_24H : self::SMS_CAP_24H;

        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ams_sends
             WHERE subscriber_id = %d AND channel = %s AND sent_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            $subscriber_id,
            $channel
        ) );

        return $count >= $cap;
    }

    /**
     * Build token context for a subscriber + campaign.
     */
    private function build_token_context( object $subscriber, object $campaign ): array {
        $settings = get_option( 'ams_settings', [] );
        $store_url = $settings['store_url'] ?? '';

        return [
            'first_name'      => $subscriber->first_name ?? '',
            'last_name'       => $subscriber->last_name ?? '',
            'email'           => $subscriber->email ?? '',
            'phone'           => $subscriber->phone ?? '',
            'shop_name'       => get_bloginfo( 'name' ),
            'shop_url'        => $store_url ?: home_url(),
            'unsubscribe_url' => home_url( '/ams-unsubscribe/' . ( $subscriber->unsubscribe_token ?? '' ) ),
        ];
    }

    /**
     * Get campaign send stats.
     */
    public function get_stats( int $campaign_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_sends';

        return [
            'total'       => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE campaign_id = %d", $campaign_id ) ),
            'sent'        => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE campaign_id = %d AND sent_at IS NOT NULL", $campaign_id ) ),
            'opened'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE campaign_id = %d AND opened_at IS NOT NULL", $campaign_id ) ),
            'clicked'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE campaign_id = %d AND clicked_at IS NOT NULL", $campaign_id ) ),
            'bounced'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE campaign_id = %d AND bounced_at IS NOT NULL", $campaign_id ) ),
            'unsubscribed'=> (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE campaign_id = %d AND unsubscribed_at IS NOT NULL", $campaign_id ) ),
        ];
    }
}
