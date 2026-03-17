<?php

namespace Apotheca\Marketing\Flows;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface StepExecutorInterface {
    /**
     * Execute a flow step for a subscriber.
     *
     * @return mixed True on success, int for condition branch target step_order, false on failure.
     */
    public function execute( object $step, object $subscriber, object $enrolment ): mixed;
}
