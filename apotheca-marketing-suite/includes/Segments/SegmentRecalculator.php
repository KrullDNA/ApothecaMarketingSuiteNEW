<?php

namespace Apotheca\Marketing\Segments;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SegmentRecalculator {

    private const HOOK = 'ams_segment_recalculate';

    /**
     * Register the recurring Action Scheduler job (every 6 hours).
     */
    public function register(): void {
        add_action( self::HOOK, [ $this, 'recalculate_all' ] );

        if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::HOOK ) ) {
            as_schedule_recurring_action( time() + HOUR_IN_SECONDS, 6 * HOUR_IN_SECONDS, self::HOOK, [], 'ams' );
        }
    }

    /**
     * Recalculate subscriber counts for all segments.
     */
    public function recalculate_all(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_segments';

        $segments = $wpdb->get_results( "SELECT id, conditions FROM {$table} ORDER BY id ASC" );

        if ( empty( $segments ) ) {
            return;
        }

        $evaluator = new SegmentEvaluator();

        foreach ( $segments as $segment ) {
            $conditions = json_decode( $segment->conditions, true ) ?: [];
            $count      = $evaluator->count_matching( $conditions );

            $wpdb->update( $table, [
                'subscriber_count' => $count,
                'last_calculated'  => current_time( 'mysql', true ),
                'updated_at'       => current_time( 'mysql', true ),
            ], [ 'id' => $segment->id ] );
        }
    }

    /**
     * Recalculate a single segment.
     */
    public function recalculate_single( int $segment_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_segments';

        $segment = $wpdb->get_row( $wpdb->prepare(
            "SELECT conditions FROM {$table} WHERE id = %d",
            $segment_id
        ) );

        if ( ! $segment ) {
            return 0;
        }

        $conditions = json_decode( $segment->conditions, true ) ?: [];
        $evaluator  = new SegmentEvaluator();
        $count      = $evaluator->count_matching( $conditions );

        $wpdb->update( $table, [
            'subscriber_count' => $count,
            'last_calculated'  => current_time( 'mysql', true ),
            'updated_at'       => current_time( 'mysql', true ),
        ], [ 'id' => $segment_id ] );

        return $count;
    }
}
