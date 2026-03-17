<?php

namespace Apotheca\Marketing\Flows\Triggers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Custom Event trigger: fires for any ams_events entry with a custom type.
 *
 * Flows with trigger_type = 'custom_event' can specify an event_name in
 * trigger_config: { "event_name": "my_custom_event" }.
 * When that event is logged, the flow enrols the subscriber.
 */
class CustomEvent {

    public function register(): void {
        add_action( 'ams_custom_event', [ $this, 'handle' ], 10, 3 );
    }

    public function handle( string $event_name, int $subscriber_id, array $data = [] ): void {
        global $wpdb;
        $flows_table = $wpdb->prefix . 'ams_flows';

        $flows = $wpdb->get_results(
            "SELECT id, trigger_config FROM {$flows_table}
             WHERE trigger_type = 'custom_event' AND status = 'active'"
        );

        $engine = new \Apotheca\Marketing\Flows\FlowEngine();

        foreach ( $flows as $flow ) {
            $config      = json_decode( $flow->trigger_config ?? '{}', true ) ?: [];
            $target_name = $config['event_name'] ?? '';

            if ( $target_name === $event_name ) {
                $engine->enrol( (int) $flow->id, $subscriber_id, $data );
            }
        }
    }
}
