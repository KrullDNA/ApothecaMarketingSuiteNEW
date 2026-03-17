<?php

namespace Apotheca\Marketing\Flows\Steps;

use Apotheca\Marketing\Flows\StepExecutorInterface;
use Apotheca\Marketing\SMS\SMSManager;
use Apotheca\Marketing\Campaigns\TokenReplacer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SendSMS implements StepExecutorInterface {

    public function execute( object $step, object $subscriber, object $enrolment ): mixed {
        // Skip silently if sms_opt_in=0.
        if ( empty( $subscriber->phone ) || ! $subscriber->sms_opt_in ) {
            return false;
        }

        // Replace personalisation tokens.
        $replacer = new TokenReplacer();
        $context  = TokenReplacer::build_context( $subscriber );
        $body     = $replacer->replace( $step->sms_body ?? '', $context );

        if ( empty( $body ) ) {
            return false;
        }

        // Send via SMSManager (handles opt-in check, frequency cap, async dispatch).
        $sms_manager = new SMSManager();
        $send_id     = $sms_manager->send(
            (int) $subscriber->id,
            $body,
            null,
            (int) $step->id
        );

        if ( false === $send_id ) {
            return false;
        }

        // Log the event.
        $logger = new \Apotheca\Marketing\Subscriber\EventLogger();
        $logger->log( (int) $subscriber->id, 'flow_sms_sent', [
            'flow_id'      => (int) $enrolment->flow_id,
            'flow_step_id' => (int) $step->id,
            'send_id'      => $send_id,
        ] );

        return true;
    }
}
