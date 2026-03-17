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

    /**
     * Register Elementor widgets.
     */
    public function register_elementor_widgets( \Elementor\Widgets_Manager $widgets_manager ): void {
        $widgets_manager->register( new Elementor\CampaignArchiveWidget() );
    }

    private function init(): void {
        // REST API endpoints.
        add_action( 'rest_api_init', [ new REST\IngestEndpoint(), 'register_routes' ] );
        add_action( 'rest_api_init', [ new REST\FormsEndpoint(), 'register_routes' ] );
        add_action( 'rest_api_init', [ new REST\FlowsEndpoint(), 'register_routes' ] );

        // SSO receiver.
        $sso = new SSO\Receiver();
        add_action( 'init', [ $sso, 'add_rewrite_rules' ] );
        add_filter( 'query_vars', [ $sso, 'query_vars' ] );
        add_action( 'template_redirect', [ $sso, 'handle_request' ] );

        // Unsubscribe handler.
        $unsub = new GDPR\UnsubscribeHandler();
        add_action( 'init', [ $unsub, 'add_rewrite_rules' ] );
        add_filter( 'query_vars', [ $unsub, 'query_vars' ] );
        add_action( 'template_redirect', [ $unsub, 'handle_request' ] );

        // Double opt-in handler.
        new GDPR\DoubleOptIn();

        // Front-end forms loader (only enqueues JS when needed).
        new Forms\FrontendLoader();

        // RFM scoring job.
        $rfm = new Jobs\RFMScoring();
        $rfm->register();

        // Flows engine.
        $flow_engine = new Flows\FlowEngine();
        $flow_engine->register();

        // Flow trigger handlers.
        $triggers = new Flows\TriggerManager();
        $triggers->register();

        // Segments REST endpoint.
        add_action( 'rest_api_init', [ new REST\SegmentsEndpoint(), 'register_routes' ] );

        // Segment recalculator (every 6 hours via Action Scheduler).
        $segment_recalc = new Segments\SegmentRecalculator();
        $segment_recalc->register();

        // Campaigns REST endpoint.
        add_action( 'rest_api_init', [ new REST\CampaignsEndpoint(), 'register_routes' ] );

        // Campaign scheduled sending via Action Scheduler.
        $campaign_mgr = new Campaigns\CampaignManager();
        $campaign_mgr->register();

        // Elementor integration.
        add_action( 'elementor/widgets/register', [ $this, 'register_elementor_widgets' ] );

        // Admin.
        if ( is_admin() ) {
            new Admin\Menu();
            new Admin\SettingsPage();
            new Admin\SubscribersPage();
            new Admin\FlowsPage();
            new Admin\SegmentsPage();
            new Admin\CampaignsPage();
        }
    }
}
