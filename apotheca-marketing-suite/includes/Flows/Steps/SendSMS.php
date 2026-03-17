<?php

namespace Apotheca\Marketing\Flows\Steps;

use Apotheca\Marketing\Flows\StepExecutorInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SendSMS implements StepExecutorInterface {

    public function execute( object $step, object $subscriber, object $enrolment ): mixed {
        global $wpdb;

        if ( empty( $subscriber->phone ) || ! $subscriber->sms_opt_in ) {
            return false;
        }

        $body = $this->replace_tokens( $step->sms_body ?? '', $subscriber );
        if ( empty( $body ) ) {
            return false;
        }

        // SMS send will be implemented in Session 6 (Twilio integration).
        // For now, log the intent and record as queued.
        $sends_table = $wpdb->prefix . 'ams_sends';
        $wpdb->insert( $sends_table, [
            'flow_step_id'  => (int) $step->id,
            'subscriber_id' => (int) $subscriber->id,
            'channel'       => 'sms',
            'status'        => 'queued',
            'created_at'    => current_time( 'mysql', true ),
        ] );

        $logger = new \Apotheca\Marketing\Subscriber\EventLogger();
        $logger->log( (int) $subscriber->id, 'flow_sms_queued', [
            'flow_id'      => (int) $enrolment->flow_id,
            'flow_step_id' => (int) $step->id,
            'body'         => $body,
        ] );

        return true;
    }

    private function replace_tokens( string $text, object $subscriber ): string {
        $tokens = [
            '{{email}}'      => $subscriber->email,
            '{{first_name}}' => $subscriber->first_name,
            '{{last_name}}'  => $subscriber->last_name,
            '{{phone}}'      => $subscriber->phone,
            '{{full_name}}'  => trim( $subscriber->first_name . ' ' . $subscriber->last_name ),
        ];

        return str_replace( array_keys( $tokens ), array_values( $tokens ), $text );
    }
}
