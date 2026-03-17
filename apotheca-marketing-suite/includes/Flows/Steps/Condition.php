<?php

namespace Apotheca\Marketing\Flows\Steps;

use Apotheca\Marketing\Flows\StepExecutorInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Condition implements StepExecutorInterface {

    /**
     * Evaluate a condition and return the target step_order to branch to.
     *
     * conditions JSON: {
     *   "field": "total_orders",
     *   "operator": ">",
     *   "value": "3",
     *   "yes_step_order": 5,
     *   "no_step_order": 6
     * }
     *
     * @return int The step_order to branch to.
     */
    public function execute( object $step, object $subscriber, object $enrolment ): mixed {
        $conditions = json_decode( $step->conditions ?? '{}', true ) ?: [];
        $field      = $conditions['field'] ?? '';
        $operator   = $conditions['operator'] ?? '==';
        $value      = $conditions['value'] ?? '';
        $yes_order  = (int) ( $conditions['yes_step_order'] ?? 0 );
        $no_order   = (int) ( $conditions['no_step_order'] ?? 0 );

        $actual = $this->get_field_value( $subscriber, $field );
        $match  = $this->evaluate( $actual, $operator, $value );

        return $match ? $yes_order : $no_order;
    }

    private function get_field_value( object $subscriber, string $field ): string {
        // Direct fields.
        $direct = [
            'email', 'phone', 'first_name', 'last_name', 'status', 'source',
            'total_orders', 'total_spent', 'rfm_score', 'rfm_segment',
            'churn_risk_score', 'sms_opt_in', 'gdpr_consent',
        ];

        if ( in_array( $field, $direct, true ) && isset( $subscriber->$field ) ) {
            return (string) $subscriber->$field;
        }

        // Check engagement data (has opened, has clicked).
        if ( 'has_opened' === $field || 'has_clicked' === $field ) {
            global $wpdb;
            $sends_table = $wpdb->prefix . 'ams_sends';
            $col         = 'has_opened' === $field ? 'opened_at' : 'clicked_at';
            $count       = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$sends_table} WHERE subscriber_id = %d AND {$col} IS NOT NULL",
                (int) $subscriber->id
            ) );
            return (string) $count;
        }

        // Custom fields.
        $custom = json_decode( $subscriber->custom_fields ?? '{}', true ) ?: [];
        return (string) ( $custom[ $field ] ?? '' );
    }

    private function evaluate( string $actual, string $operator, string $expected ): bool {
        return match ( $operator ) {
            '=='         => $actual === $expected,
            '!='         => $actual !== $expected,
            '>'          => (float) $actual > (float) $expected,
            '<'          => (float) $actual < (float) $expected,
            '>='         => (float) $actual >= (float) $expected,
            '<='         => (float) $actual <= (float) $expected,
            'contains'   => str_contains( strtolower( $actual ), strtolower( $expected ) ),
            'not_empty'  => '' !== $actual,
            'empty'      => '' === $actual,
            default      => false,
        };
    }
}
