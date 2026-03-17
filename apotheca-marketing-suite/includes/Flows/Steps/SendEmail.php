<?php

namespace Apotheca\Marketing\Flows\Steps;

use Apotheca\Marketing\Flows\StepExecutorInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SendEmail implements StepExecutorInterface {

    public function execute( object $step, object $subscriber, object $enrolment ): mixed {
        global $wpdb;

        $subject      = $this->replace_tokens( $step->subject ?? '', $subscriber );
        $preview_text = $this->replace_tokens( $step->preview_text ?? '', $subscriber );
        $body_html    = $this->replace_tokens( $step->body_html ?? '', $subscriber );
        $body_text    = $this->replace_tokens( $step->body_text ?? '', $subscriber );

        // Add unsubscribe link.
        $unsub_url = home_url( '/ams-unsubscribe/?token=' . urlencode( $subscriber->unsubscribe_token ) );
        $body_html .= '<p style="font-size:12px;color:#999;"><a href="' . esc_url( $unsub_url ) . '">Unsubscribe</a></p>';
        if ( $body_text ) {
            $body_text .= "\n\nUnsubscribe: " . $unsub_url;
        }

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'List-Unsubscribe: <' . $unsub_url . '>',
        ];

        if ( $preview_text ) {
            $body_html = '<div style="display:none;max-height:0;overflow:hidden;">' . esc_html( $preview_text ) . '</div>' . $body_html;
        }

        $sent = wp_mail( $subscriber->email, $subject, $body_html, $headers );

        // Record send.
        $sends_table = $wpdb->prefix . 'ams_sends';
        $wpdb->insert( $sends_table, [
            'flow_step_id'  => (int) $step->id,
            'subscriber_id' => (int) $subscriber->id,
            'channel'       => 'email',
            'status'        => $sent ? 'sent' : 'failed',
            'sent_at'       => $sent ? current_time( 'mysql', true ) : null,
            'created_at'    => current_time( 'mysql', true ),
        ] );

        // Log event.
        $logger = new \Apotheca\Marketing\Subscriber\EventLogger();
        $logger->log( (int) $subscriber->id, 'flow_email_sent', [
            'flow_id'      => (int) $enrolment->flow_id,
            'flow_step_id' => (int) $step->id,
            'subject'      => $subject,
            'success'      => $sent,
        ] );

        return $sent;
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
