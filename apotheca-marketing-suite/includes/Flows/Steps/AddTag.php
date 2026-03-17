<?php

namespace Apotheca\Marketing\Flows\Steps;

use Apotheca\Marketing\Flows\StepExecutorInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AddTag implements StepExecutorInterface {

    public function execute( object $step, object $subscriber, object $enrolment ): mixed {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_subscribers';

        $conditions = json_decode( $step->conditions ?? '{}', true ) ?: [];
        $tag        = sanitize_text_field( $conditions['tag'] ?? '' );
        if ( empty( $tag ) ) {
            return false;
        }

        $tags = json_decode( $subscriber->tags ?? '[]', true ) ?: [];
        if ( ! in_array( $tag, $tags, true ) ) {
            $tags[] = $tag;
            $wpdb->update( $table, [
                'tags'       => wp_json_encode( $tags ),
                'updated_at' => current_time( 'mysql', true ),
            ], [ 'id' => (int) $subscriber->id ] );
        }

        return true;
    }
}
