<?php

namespace Apotheca\Marketing\Flows;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FlowEngine {

    private const EMAIL_CAP_24H = 3;
    private const SMS_CAP_24H   = 2;
    private const SEND_HOUR_MIN = 8;
    private const SEND_HOUR_MAX = 21;
    private const EXECUTE_HOOK  = 'ams_execute_flow_step';
    private const TRIGGER_HOOK  = 'ams_flow_trigger';

    public function register(): void {
        add_action( self::TRIGGER_HOOK, [ $this, 'handle_trigger' ], 10, 3 );
        add_action( 'ams_flow_trigger_direct', [ $this, 'handle_trigger' ], 10, 3 );
        add_action( self::EXECUTE_HOOK, [ $this, 'execute_step' ], 10, 2 );

        // Auto-exit enrolments on unsubscribe.
        add_action( 'ams_subscriber_unsubscribed', [ $this, 'exit_all_enrolments' ] );
    }

    /**
     * Handle a flow trigger event.
     * Find all active flows matching the trigger and enrol the subscriber.
     */
    public function handle_trigger( string $trigger_type, int $subscriber_id, array $payload = [] ): void {
        global $wpdb;
        $flows_table = $wpdb->prefix . 'ams_flows';

        $flows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$flows_table} WHERE trigger_type = %s AND status = 'active'",
            $trigger_type
        ) );

        if ( empty( $flows ) ) {
            return;
        }

        foreach ( $flows as $flow ) {
            $this->enrol( (int) $flow->id, $subscriber_id, $payload );
        }
    }

    /**
     * Enrol a subscriber in a flow (with deduplication).
     */
    public function enrol( int $flow_id, int $subscriber_id, array $payload = [] ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_flow_enrolments';

        // Deduplication: skip if subscriber already active in this flow.
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE flow_id = %d AND subscriber_id = %d AND status = 'active'",
            $flow_id,
            $subscriber_id
        ) );

        if ( $existing ) {
            return false;
        }

        // Get first step.
        $steps_table = $wpdb->prefix . 'ams_flow_steps';
        $first_step  = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$steps_table} WHERE flow_id = %d ORDER BY step_order ASC LIMIT 1",
            $flow_id
        ) );

        if ( ! $first_step ) {
            return false;
        }

        $wpdb->insert( $table, [
            'flow_id'         => $flow_id,
            'subscriber_id'   => $subscriber_id,
            'current_step_id' => (int) $first_step->id,
            'status'          => 'active',
            'enrolled_at'     => current_time( 'mysql', true ),
        ] );

        $enrolment_id = (int) $wpdb->insert_id;
        if ( ! $enrolment_id ) {
            return false;
        }

        // Schedule first step execution.
        $this->schedule_step( $enrolment_id, (int) $first_step->id );

        return true;
    }

    /**
     * Schedule a flow step execution via Action Scheduler.
     */
    public function schedule_step( int $enrolment_id, int $step_id ): void {
        global $wpdb;
        $steps_table = $wpdb->prefix . 'ams_flow_steps';

        $step = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$steps_table} WHERE id = %d",
            $step_id
        ) );

        if ( ! $step ) {
            return;
        }

        $delay = $this->calculate_delay( $step );

        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action(
                time() + $delay,
                self::EXECUTE_HOOK,
                [ $enrolment_id, $step_id ],
                'ams_flows'
            );
        }
    }

    /**
     * Execute a flow step (called by Action Scheduler).
     */
    public function execute_step( int $enrolment_id, int $step_id ): void {
        global $wpdb;

        $enrolments_table = $wpdb->prefix . 'ams_flow_enrolments';
        $steps_table      = $wpdb->prefix . 'ams_flow_steps';

        $enrolment = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$enrolments_table} WHERE id = %d",
            $enrolment_id
        ) );

        if ( ! $enrolment || 'active' !== $enrolment->status ) {
            return;
        }

        $step = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$steps_table} WHERE id = %d",
            $step_id
        ) );

        if ( ! $step ) {
            $this->exit_enrolment( $enrolment_id, 'step_not_found' );
            return;
        }

        $sub_manager = new \Apotheca\Marketing\Subscriber\SubscriberManager();
        $subscriber  = $sub_manager->get( (int) $enrolment->subscriber_id );

        if ( ! $subscriber || 'active' !== $subscriber->status ) {
            $this->exit_enrolment( $enrolment_id, 'subscriber_inactive' );
            return;
        }

        // Execute the step based on type.
        $executor = StepExecutorFactory::create( $step->step_type );
        if ( ! $executor ) {
            $this->advance_to_next_step( $enrolment_id, (int) $step->flow_id, (int) $step->step_order );
            return;
        }

        // For send steps, check frequency cap and send-time window.
        if ( in_array( $step->step_type, [ 'send_email', 'send_sms' ], true ) ) {
            $channel = 'send_email' === $step->step_type ? 'email' : 'sms';

            if ( $this->exceeds_frequency_cap( (int) $subscriber->id, $channel ) ) {
                // Reschedule for 1 hour later.
                $this->schedule_step_delayed( $enrolment_id, $step_id, 3600 );
                return;
            }

            if ( ! $this->within_send_window( $subscriber ) ) {
                // Reschedule for next valid send hour.
                $delay = $this->delay_until_send_window( $subscriber );
                $this->schedule_step_delayed( $enrolment_id, $step_id, $delay );
                return;
            }
        }

        $result = $executor->execute( $step, $subscriber, $enrolment );

        // For condition steps, the executor returns the next step order to branch to.
        if ( 'condition' === $step->step_type && is_int( $result ) ) {
            $this->advance_to_step_order( $enrolment_id, (int) $step->flow_id, $result );
            return;
        }

        // For exit steps, exit the enrolment.
        if ( 'exit' === $step->step_type ) {
            $this->exit_enrolment( $enrolment_id, 'flow_completed' );
            return;
        }

        // Advance to next step.
        $this->advance_to_next_step( $enrolment_id, (int) $step->flow_id, (int) $step->step_order );
    }

    /**
     * Advance to the next step in the flow.
     */
    private function advance_to_next_step( int $enrolment_id, int $flow_id, int $current_order ): void {
        global $wpdb;
        $steps_table = $wpdb->prefix . 'ams_flow_steps';

        $next_step = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$steps_table} WHERE flow_id = %d AND step_order > %d ORDER BY step_order ASC LIMIT 1",
            $flow_id,
            $current_order
        ) );

        if ( ! $next_step ) {
            $this->complete_enrolment( $enrolment_id );
            return;
        }

        $wpdb->update(
            $wpdb->prefix . 'ams_flow_enrolments',
            [ 'current_step_id' => (int) $next_step->id ],
            [ 'id' => $enrolment_id ]
        );

        $this->schedule_step( $enrolment_id, (int) $next_step->id );
    }

    /**
     * Advance to a specific step order (for condition branches).
     */
    private function advance_to_step_order( int $enrolment_id, int $flow_id, int $target_order ): void {
        global $wpdb;
        $steps_table = $wpdb->prefix . 'ams_flow_steps';

        $step = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$steps_table} WHERE flow_id = %d AND step_order = %d LIMIT 1",
            $flow_id,
            $target_order
        ) );

        if ( ! $step ) {
            $this->complete_enrolment( $enrolment_id );
            return;
        }

        $wpdb->update(
            $wpdb->prefix . 'ams_flow_enrolments',
            [ 'current_step_id' => (int) $step->id ],
            [ 'id' => $enrolment_id ]
        );

        $this->schedule_step( $enrolment_id, (int) $step->id );
    }

    /**
     * Complete an enrolment.
     */
    private function complete_enrolment( int $enrolment_id ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'ams_flow_enrolments',
            [
                'status'       => 'completed',
                'completed_at' => current_time( 'mysql', true ),
            ],
            [ 'id' => $enrolment_id ]
        );
    }

    /**
     * Exit an enrolment with a reason.
     */
    public function exit_enrolment( int $enrolment_id, string $reason ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'ams_flow_enrolments',
            [
                'status'      => 'exited',
                'exited_at'   => current_time( 'mysql', true ),
                'exit_reason' => sanitize_text_field( $reason ),
            ],
            [ 'id' => $enrolment_id ]
        );
    }

    /**
     * Exit all active enrolments for a subscriber (e.g., on unsubscribe).
     */
    public function exit_all_enrolments( int $subscriber_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_flow_enrolments';
        $now   = current_time( 'mysql', true );

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = 'exited', exited_at = %s, exit_reason = 'unsubscribed'
             WHERE subscriber_id = %d AND status = 'active'",
            $now,
            $subscriber_id
        ) );
    }

    /**
     * Check if a send exceeds the 24h frequency cap.
     */
    private function exceeds_frequency_cap( int $subscriber_id, string $channel ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_sends';
        $cap   = 'email' === $channel ? self::EMAIL_CAP_24H : self::SMS_CAP_24H;

        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE subscriber_id = %d AND channel = %s AND sent_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            $subscriber_id,
            $channel
        ) );

        return $count >= $cap;
    }

    /**
     * Check if current time is within the subscriber's send window.
     */
    private function within_send_window( object $subscriber ): bool {
        $hour = $this->get_subscriber_local_hour( $subscriber );
        return $hour >= self::SEND_HOUR_MIN && $hour < self::SEND_HOUR_MAX;
    }

    /**
     * Calculate seconds until the next send window opens.
     */
    private function delay_until_send_window( object $subscriber ): int {
        $offset = $this->get_timezone_offset( $subscriber );
        $local  = time() + ( $offset * 3600 );
        $hour   = (int) gmdate( 'G', $local );

        if ( $hour >= self::SEND_HOUR_MAX ) {
            // Wait until 8am tomorrow.
            return ( 24 - $hour + self::SEND_HOUR_MIN ) * 3600;
        }
        if ( $hour < self::SEND_HOUR_MIN ) {
            return ( self::SEND_HOUR_MIN - $hour ) * 3600;
        }
        return 0;
    }

    /**
     * Get subscriber's local hour (0-23).
     */
    private function get_subscriber_local_hour( object $subscriber ): int {
        $offset = $this->get_timezone_offset( $subscriber );
        $local  = time() + ( $offset * 3600 );
        return (int) gmdate( 'G', $local );
    }

    /**
     * Get timezone offset in hours from subscriber's country.
     */
    private function get_timezone_offset( object $subscriber ): int {
        $custom = json_decode( $subscriber->custom_fields ?? '{}', true ) ?: [];
        $country = strtoupper( $custom['billing_country'] ?? '' );

        // Common country→offset mapping (simplified).
        $offsets = [
            'US' => -5, 'CA' => -5, 'MX' => -6, 'GB' => 0, 'IE' => 0,
            'FR' => 1, 'DE' => 1, 'IT' => 1, 'ES' => 1, 'NL' => 1,
            'AU' => 10, 'NZ' => 12, 'JP' => 9, 'IN' => 5, 'BR' => -3,
            'ZA' => 2, 'AE' => 4, 'SG' => 8, 'HK' => 8, 'KR' => 9,
        ];

        return $offsets[ $country ] ?? 0;
    }

    /**
     * Calculate the delay in seconds for a step.
     */
    private function calculate_delay( object $step ): int {
        $value = max( 0, (int) $step->delay_value );
        return match ( $step->delay_unit ) {
            'minutes' => $value * 60,
            'hours'   => $value * 3600,
            'days'    => $value * 86400,
            default   => 0,
        };
    }

    /**
     * Schedule a step with a specific delay (for rescheduling).
     */
    private function schedule_step_delayed( int $enrolment_id, int $step_id, int $delay ): void {
        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action(
                time() + $delay,
                self::EXECUTE_HOOK,
                [ $enrolment_id, $step_id ],
                'ams_flows'
            );
        }
    }
}
