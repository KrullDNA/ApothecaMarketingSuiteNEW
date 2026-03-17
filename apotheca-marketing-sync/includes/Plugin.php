<?php

namespace Apotheca\Marketing\Sync;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Plugin {

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init();
    }

    private function init(): void {
        // Settings page under WooCommerce.
        $settings_page = new Admin\SettingsPage();

        // Event hooks.
        $event_hooks = new EventHooks();

        // Abandoned cart checker cron.
        $abandoned = new AbandonedCartChecker();

        // Review meta bridge REST endpoint.
        add_action( 'rest_api_init', function (): void {
            ( new REST\ReviewMetaBridge() )->register_routes();
        } );

        // SSO admin bar link.
        $sso = new SSOLink();

        // Product view beacon.
        $beacon = new ProductViewBeacon();
    }

    /**
     * Get plugin settings.
     */
    public static function get_settings(): array {
        $defaults = [
            'endpoint_url'  => '',
            'shared_secret' => '',
            'events'        => [
                'customer_registered' => true,
                'order_placed'        => true,
                'order_status_changed'=> true,
                'cart_updated'        => true,
                'product_viewed'      => true,
                'checkout_started'    => true,
                'abandoned_cart'      => true,
            ],
        ];
        $settings = get_option( 'ams_sync_settings', [] );
        return wp_parse_args( $settings, $defaults );
    }

    /**
     * Check if an event type is enabled.
     */
    public static function is_event_enabled( string $event_type ): bool {
        $settings = self::get_settings();
        return ! empty( $settings['events'][ $event_type ] );
    }

    /**
     * Get the shared secret (decrypted).
     */
    public static function get_shared_secret(): string {
        $settings = self::get_settings();
        $encrypted = $settings['shared_secret'] ?? '';
        if ( empty( $encrypted ) ) {
            return '';
        }
        return self::decrypt( $encrypted );
    }

    /**
     * Encrypt a value using AES-256-CBC.
     */
    public static function encrypt( string $plain ): string {
        $key    = hash( 'sha256', AUTH_KEY, true );
        $iv     = random_bytes( 16 );
        $cipher = openssl_encrypt( $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
        return base64_encode( $iv . $cipher );
    }

    /**
     * Decrypt a value encrypted with encrypt().
     */
    public static function decrypt( string $encoded ): string {
        $key  = hash( 'sha256', AUTH_KEY, true );
        $data = base64_decode( $encoded, true );
        if ( false === $data || strlen( $data ) < 17 ) {
            return '';
        }
        $iv     = substr( $data, 0, 16 );
        $cipher = substr( $data, 16 );
        $plain  = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
        return false === $plain ? '' : $plain;
    }
}
