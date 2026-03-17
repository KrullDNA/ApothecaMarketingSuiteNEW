<?php

namespace Apotheca\Marketing\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Menu {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menus' ] );
    }

    public function register_menus(): void {
        // Top-level menu.
        add_menu_page(
            __( 'Apotheca Marketing', 'apotheca-marketing-suite' ),
            __( 'Apotheca Marketing', 'apotheca-marketing-suite' ),
            'manage_options',
            'ams-dashboard',
            [ $this, 'render_dashboard' ],
            'dashicons-email-alt',
            30
        );

        // Sub-menus.
        $submenus = [
            [ 'ams-dashboard',   __( 'Dashboard', 'apotheca-marketing-suite' ),   [ $this, 'render_dashboard' ] ],
            [ 'ams-subscribers', __( 'Subscribers', 'apotheca-marketing-suite' ), [ $this, 'render_subscribers' ] ],
            [ 'ams-flows',       __( 'Flows', 'apotheca-marketing-suite' ),       [ $this, 'render_flows' ] ],
            [ 'ams-campaigns',   __( 'Campaigns', 'apotheca-marketing-suite' ),   [ $this, 'render_campaigns' ] ],
            [ 'ams-segments',    __( 'Segments', 'apotheca-marketing-suite' ),    [ $this, 'render_segments' ] ],
            [ 'ams-forms',       __( 'Forms', 'apotheca-marketing-suite' ),       [ $this, 'render_forms' ] ],
            [ 'ams-sms',         __( 'SMS', 'apotheca-marketing-suite' ),         [ $this, 'render_stub' ] ],
            [ 'ams-analytics',   __( 'Analytics', 'apotheca-marketing-suite' ),   [ $this, 'render_analytics' ] ],
            [ 'ams-settings',    __( 'Settings', 'apotheca-marketing-suite' ),    [ $this, 'render_settings' ] ],
        ];

        foreach ( $submenus as $submenu ) {
            add_submenu_page(
                'ams-dashboard',
                $submenu[1],
                $submenu[1],
                'manage_options',
                $submenu[0],
                $submenu[2]
            );
        }
    }

    public function render_dashboard(): void {
        echo '<div class="wrap"><h1>' . esc_html__( 'Apotheca Marketing Dashboard', 'apotheca-marketing-suite' ) . '</h1>';
        echo '<p>' . esc_html__( 'Welcome to Apotheca Marketing Suite. Configure your sync settings to get started.', 'apotheca-marketing-suite' ) . '</p></div>';
    }

    public function render_stub(): void {
        echo '<div class="wrap"><h1>' . esc_html__( 'Coming Soon', 'apotheca-marketing-suite' ) . '</h1>';
        echo '<p>' . esc_html__( 'This feature will be available in a future session.', 'apotheca-marketing-suite' ) . '</p></div>';
    }

    public function render_subscribers(): void {
        $page = new SubscribersPage();
        $page->render();
    }

    public function render_flows(): void {
        $page = new FlowsPage();
        $page->render();
    }

    public function render_forms(): void {
        $page = new FormsPage();
        $page->render();
    }

    public function render_campaigns(): void {
        $page = new CampaignsPage();
        $page->render();
    }

    public function render_segments(): void {
        $page = new SegmentsPage();
        $page->render();
    }

    public function render_analytics(): void {
        $page = new AnalyticsPage();
        $page->render();
    }

    public function render_settings(): void {
        $settings_page = new SettingsPage();
        $settings_page->render();
    }
}
