<?php

namespace Apotheca\Marketing\Flows;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TriggerManager {

    private const TRIGGERS = [
        'welcome_series'      => Triggers\WelcomeSeries::class,
        'abandoned_cart'      => Triggers\AbandonedCart::class,
        'post_purchase'       => Triggers\PostPurchase::class,
        'win_back'            => Triggers\WinBack::class,
        'browse_abandonment'  => Triggers\BrowseAbandonment::class,
        'birthday'            => Triggers\Birthday::class,
        'rfm_change'          => Triggers\RFMChange::class,
        'custom_event'        => Triggers\CustomEvent::class,
    ];

    /**
     * Register all trigger listeners.
     */
    public function register(): void {
        foreach ( self::TRIGGERS as $type => $class ) {
            if ( class_exists( $class ) ) {
                $trigger = new $class();
                $trigger->register();
            }
        }
    }

    /**
     * Get valid trigger types.
     */
    public static function valid_types(): array {
        return array_keys( self::TRIGGERS );
    }
}
