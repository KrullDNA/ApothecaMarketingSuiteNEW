<?php

namespace Apotheca\Marketing\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Analytics dashboard admin page.
 *
 * Renders a React SPA mount point and enqueues the analytics JS bundle.
 */
class AnalyticsPage {

    public function __construct() {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /**
     * Enqueue the analytics dashboard JS/CSS only on the analytics page.
     */
    public function enqueue_assets( string $hook ): void {
        if ( 'apotheca-marketing_page_ams-analytics' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'ams-analytics',
            plugins_url( 'assets/css/ams-analytics.css', AMS_PLUGIN_FILE ),
            [],
            AMS_VERSION
        );

        wp_enqueue_script(
            'ams-analytics',
            plugins_url( 'assets/js/ams-analytics.min.js', AMS_PLUGIN_FILE ),
            [ 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ],
            AMS_VERSION,
            true
        );

        wp_localize_script( 'ams-analytics', 'amsAnalytics', [
            'restBase' => rest_url( 'ams/v1/admin/analytics' ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
        ] );
    }

    /**
     * Render the analytics page container.
     */
    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        echo '<div class="wrap"><div id="ams-analytics-root"></div></div>';
    }
}
