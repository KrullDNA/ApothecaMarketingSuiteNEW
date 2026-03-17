<?php

namespace Apotheca\Marketing\Flows\Steps;

use Apotheca\Marketing\Flows\StepExecutorInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UpdateField implements StepExecutorInterface {

    public function execute( object $step, object $subscriber, object $enrolment ): mixed {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_subscribers';

        $conditions = json_decode( $step->conditions ?? '{}', true ) ?: [];
        $field      = sanitize_text_field( $conditions['field'] ?? '' );
        $value      = sanitize_text_field( $conditions['value'] ?? '' );

        if ( empty( $field ) ) {
            return false;
        }

        // Direct subscriber fields.
        $direct_fields = [ 'first_name', 'last_name', 'phone', 'status' ];
        if ( in_array( $field, $direct_fields, true ) ) {
            $wpdb->update( $table, [
                $field       => $value,
                'updated_at' => current_time( 'mysql', true ),
            ], [ 'id' => (int) $subscriber->id ] );
            return true;
        }

        // Custom fields.
        $custom = json_decode( $subscriber->custom_fields ?? '{}', true ) ?: [];
        $custom[ $field ] = $value;

        $wpdb->update( $table, [
            'custom_fields' => wp_json_encode( $custom ),
            'updated_at'    => current_time( 'mysql', true ),
        ], [ 'id' => (int) $subscriber->id ] );

        return true;
    }
}
