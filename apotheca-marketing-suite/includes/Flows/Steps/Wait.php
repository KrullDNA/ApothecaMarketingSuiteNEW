<?php

namespace Apotheca\Marketing\Flows\Steps;

use Apotheca\Marketing\Flows\StepExecutorInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Wait implements StepExecutorInterface {

    /**
     * Wait step — the delay is handled by the scheduler, so execution is a no-op.
     */
    public function execute( object $step, object $subscriber, object $enrolment ): mixed {
        return true;
    }
}
