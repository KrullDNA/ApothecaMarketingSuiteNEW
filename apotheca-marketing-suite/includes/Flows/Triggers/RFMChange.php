<?php

namespace Apotheca\Marketing\Flows\Triggers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * RFM Change trigger: fires when a subscriber's rfm_segment changes.
 *
 * Hooks into the RFM scoring job to detect segment changes.
 */
class RFMChange {

    public function register(): void {
        add_action( 'ams_rfm_segment_changed', [ $this, 'on_segment_changed' ], 10, 3 );
    }

    /**
     * @param int    $subscriber_id
     * @param string $old_segment
     * @param string $new_segment
     */
    public function on_segment_changed( int $subscriber_id, string $old_segment, string $new_segment ): void {
        do_action( 'ams_flow_trigger', 'rfm_change', $subscriber_id, [
            'old_segment' => $old_segment,
            'new_segment' => $new_segment,
        ] );
    }
}
