<?php

namespace Apotheca\Marketing\Flows\Triggers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Birthday trigger: fires when subscriber birthday field matches today.
 *
 * Runs daily via Action Scheduler.
 */
class Birthday {

    private const HOOK       = 'ams_trigger_birthday';
    private const BATCH_SIZE = 200;

    public function register(): void {
        add_action( self::HOOK, [ $this, 'check_birthdays' ] );

        if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::HOOK ) ) {
            $next_6am = strtotime( 'tomorrow 06:00:00 UTC' );
            as_schedule_recurring_action( $next_6am, DAY_IN_SECONDS, self::HOOK, [], 'ams_flows' );
        }
    }

    public function check_birthdays(): void {
        global $wpdb;
        $subs_table = $wpdb->prefix . 'ams_subscribers';

        $today_md = gmdate( 'm-d' );

        // Find subscribers whose birthday custom field matches today (MM-DD format).
        // Birthday is stored in custom_fields JSON as { "birthday": "1990-03-17" } or "03-17".
        $subscribers = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, custom_fields FROM {$subs_table}
             WHERE status = 'active'
               AND custom_fields IS NOT NULL
               AND custom_fields LIKE %s
             LIMIT %d",
            '%birthday%',
            self::BATCH_SIZE
        ) );

        $engine = new \Apotheca\Marketing\Flows\FlowEngine();

        foreach ( $subscribers as $sub ) {
            $custom   = json_decode( $sub->custom_fields, true ) ?: [];
            $birthday = $custom['birthday'] ?? '';

            if ( empty( $birthday ) ) {
                continue;
            }

            // Support both YYYY-MM-DD and MM-DD formats.
            $bday_md = strlen( $birthday ) > 5 ? substr( $birthday, 5 ) : $birthday;

            if ( $bday_md === $today_md ) {
                do_action( 'ams_flow_trigger', 'birthday', (int) $sub->id, [
                    'trigger_source' => 'auto_check',
                    'birthday'       => $birthday,
                ] );
            }
        }
    }
}
