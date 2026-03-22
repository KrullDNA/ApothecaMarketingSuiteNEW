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
        $widgets_manager->register( new Elementor\OptInFormWidget() );
        $widgets_manager->register( new Elementor\SubscriberCountBadgeWidget() );
        $widgets_manager->register( new Elementor\CampaignArchiveWidget() );
        $widgets_manager->register( new Elementor\PreferenceCentreWidget() );
    }

    /**
     * Register widget frontend styles and scripts.
     */
    public function register_widget_assets(): void {
        wp_register_style(
            'ams-widgets',
            AMS_PLUGIN_URL . 'assets/css/ams-widgets.css',
            [],
            AMS_VERSION
        );

        wp_register_script(
            'ams-preference-centre',
            AMS_PLUGIN_URL . 'assets/js/ams-preference-centre.min.js',
            [],
            AMS_VERSION,
            true
        );

        wp_localize_script( 'ams-preference-centre', 'amsPreferenceCentre', [
            'restUrl'  => esc_url_raw( rest_url( '/' ) ),
            'saveText' => esc_html__( 'Save Preferences', 'apotheca-marketing-suite' ),
        ] );
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

        // Email Templates REST endpoint.
        add_action( 'rest_api_init', [ new REST\EmailTemplatesEndpoint(), 'register_routes' ] );

        // Campaign scheduled sending via Action Scheduler.
        $campaign_mgr = new Campaigns\CampaignManager();
        $campaign_mgr->register();

        // SMS: Twilio provider (Action Scheduler hooks + ams_send_sms listener).
        $twilio = new SMS\TwilioProvider();
        $twilio->register();

        // SMS webhook endpoints (inbound + delivery status).
        add_action( 'rest_api_init', [ new REST\SMSWebhookEndpoint(), 'register_routes' ] );

        // Reviews: nightly cache refresh job.
        $reviews_job = new Reviews\ReviewsCacheJob();
        $reviews_job->register();

        // Reviews: review gate rewrite rules.
        $review_gate = new Reviews\ReviewGate();
        add_action( 'init', [ $review_gate, 'add_rewrite_rules' ] );
        add_filter( 'query_vars', [ $review_gate, 'query_vars' ] );
        add_action( 'template_redirect', [ $review_gate, 'handle_request' ] );

        // AI: subject line + email body generators (Action Scheduler hooks).
        $sl_gen = new AI\SubjectLineGenerator();
        $sl_gen->register();
        $eb_gen = new AI\EmailBodyGenerator();
        $eb_gen->register();

        // AI: send-time optimisation (nightly job).
        $sto = new AI\SendTimeOptimiser();
        $sto->register();

        // AI: products cache refresh (nightly job).
        $products_cache = new AI\ProductsCacheJob();
        $products_cache->register();

        // AI: segment suggester (Action Scheduler hook).
        $seg_suggest = new AI\SegmentSuggester();
        $seg_suggest->register();

        // AI REST endpoints.
        add_action( 'rest_api_init', [ new REST\AIEndpoint(), 'register_routes' ] );

        // Analytics: attribution engine (listens for ams_order_placed action).
        $attribution = new Analytics\AttributionEngine();
        $attribution->register();

        // Analytics: nightly aggregation job.
        $aggregator = new Analytics\AnalyticsAggregator();
        $aggregator->register();

        // Analytics REST endpoints.
        add_action( 'rest_api_init', [ new REST\AnalyticsEndpoint(), 'register_routes' ] );

        // Elementor integration.
        add_action( 'elementor/widgets/register', [ $this, 'register_elementor_widgets' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'register_widget_assets' ] );
        add_action( 'elementor/editor/before_enqueue_scripts', [ $this, 'register_widget_assets' ] );

        // Admin.
        if ( is_admin() ) {
            new Admin\Menu();
            new Admin\SettingsPage();
            new Admin\SubscribersPage();
            new Admin\FlowsPage();
            new Admin\SegmentsPage();
            new Admin\CampaignsPage();
            new Admin\EmailTemplatesPage();
            new Admin\AnalyticsPage();
        }
    }
}
