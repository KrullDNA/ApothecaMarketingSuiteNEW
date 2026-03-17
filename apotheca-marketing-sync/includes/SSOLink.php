<?php

namespace Apotheca\Marketing\Sync;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adds a "Marketing Suite" link to the WP admin toolbar.
 * Generates a signed SSO URL that opens the marketing subdomain in a new tab.
 */
class SSOLink {

    public function __construct() {
        add_action( 'admin_bar_menu', [ $this, 'add_toolbar_link' ], 90 );
    }

    /**
     * Add the SSO link to the admin toolbar.
     */
    public function add_toolbar_link( \WP_Admin_Bar $admin_bar ): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $settings = Plugin::get_settings();
        if ( empty( $settings['endpoint_url'] ) ) {
            return;
        }

        $sso_url = $this->generate_sso_url();
        if ( empty( $sso_url ) ) {
            return;
        }

        $admin_bar->add_node( [
            'id'    => 'ams-marketing-suite',
            'title' => 'Marketing Suite',
            'href'  => $sso_url,
            'meta'  => [
                'target' => '_blank',
                'title'  => 'Open Apotheca Marketing Suite',
            ],
        ] );
    }

    /**
     * Generate a signed SSO URL.
     */
    private function generate_sso_url(): string {
        $user   = wp_get_current_user();
        $secret = Plugin::get_shared_secret();

        if ( ! $user->ID || empty( $secret ) ) {
            return '';
        }

        $settings  = Plugin::get_settings();
        $subdomain = trailingslashit( $settings['endpoint_url'] );

        $token_data = wp_json_encode( [
            'user_id' => $user->ID,
            'email'   => $user->user_email,
            'expires' => time() + 60,
            'nonce'   => wp_generate_password( 16, false ),
        ] );

        $token = base64_encode( $token_data );
        $sig   = hash_hmac( 'sha256', $token, $secret );

        return $subdomain . 'ams-sso/?' . http_build_query( [
            'token' => $token,
            'sig'   => $sig,
        ] );
    }
}
