<?php

namespace Apotheca\Marketing;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Plugin {

    private static ?Plugin $instance = null;

    public static function instance(): Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init();
    }

    private function init(): void {
        // REST API endpoints.
        add_action( 'rest_api_init', [ new REST\IngestEndpoint(), 'register_routes' ] );

        // SSO receiver.
        $sso = new SSO\Receiver();
        add_action( 'init', [ $sso, 'add_rewrite_rules' ] );
        add_filter( 'query_vars', [ $sso, 'query_vars' ] );
        add_action( 'template_redirect', [ $sso, 'handle_request' ] );

        // Admin.
        if ( is_admin() ) {
            new Admin\Menu();
            new Admin\SettingsPage();
        }
    }
}
