<?php

namespace Apotheca\Marketing\Flows\Steps;

use Apotheca\Marketing\Flows\StepExecutorInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExitStep implements StepExecutorInterface {

    /**
     * Exit step — signals the engine to exit the enrolment.
     */
    public function execute( object $step, object $subscriber, object $enrolment ): mixed {
        $logger = new \Apotheca\Marketing\Subscriber\EventLogger();
        $logger->log( (int) $subscriber->id, 'flow_exited', [
            'flow_id'    => (int) $enrolment->flow_id,
            'reason'     => 'exit_step',
        ] );

        return true;
    }
}
