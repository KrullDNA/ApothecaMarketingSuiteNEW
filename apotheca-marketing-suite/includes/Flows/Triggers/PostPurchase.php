<?php

namespace Apotheca\Marketing\Flows\Triggers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Post-Purchase trigger: fires on order placed or status=completed.
 *
 * IngestEndpoint already fires do_action('ams_flow_trigger', 'post_purchase', ...)
 * when order_status_changed to 'completed'. This trigger class is a no-op
 * since the FlowEngine listens directly for 'post_purchase' trigger type.
 */
class PostPurchase {

    public function register(): void {
        // Already handled by FlowEngine::handle_trigger() for trigger_type = 'post_purchase'.
        // No additional registration needed.
    }
}
