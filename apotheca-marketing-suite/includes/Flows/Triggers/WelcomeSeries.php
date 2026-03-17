<?php

namespace Apotheca\Marketing\Flows\Triggers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Welcome Series trigger: fires on new subscriber opt-in.
 *
 * Listens to the ams_flow_trigger action with 'welcome' type.
 * The IngestEndpoint and FormsEndpoint already fire this when a
 * new subscriber is created.
 */
class WelcomeSeries {

    public function register(): void {
        // The welcome trigger is already fired via do_action('ams_flow_trigger', 'welcome', ...)
        // by IngestEndpoint::handle_customer_registered() and FormsEndpoint::submit_form().
        // The FlowEngine::handle_trigger() listens for trigger_type = 'welcome_series'.
        //
        // Bridge: translate 'welcome' to 'welcome_series' for flows using that trigger type.
        add_action( 'ams_flow_trigger', [ $this, 'bridge' ], 5, 3 );
    }

    public function bridge( string $trigger_type, int $subscriber_id, array $payload = [] ): void {
        if ( 'welcome' === $trigger_type ) {
            do_action( 'ams_flow_trigger_direct', 'welcome_series', $subscriber_id, $payload );
        }
    }
}
