<?php

namespace Apotheca\Marketing;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Activator {

    public static function activate(): void {
        self::create_tables();
        self::set_default_settings();

        // Flush rewrite rules for SSO, unsubscribe, and confirm endpoints.
        $sso = new SSO\Receiver();
        $sso->add_rewrite_rules();
        $unsub = new GDPR\UnsubscribeHandler();
        $unsub->add_rewrite_rules();
        $doi = new GDPR\DoubleOptIn();
        $doi->add_rewrite_rules();
        $review_gate = new Reviews\ReviewGate();
        $review_gate->add_rewrite_rules();
        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
    }

    private static function set_default_settings(): void {
        if ( false === get_option( 'ams_settings' ) ) {
            update_option( 'ams_settings', [
                'store_url'          => '',
                'sync_shared_secret' => '',
            ] );
        }
    }

    private static function create_tables(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $prefix          = $wpdb->prefix . 'ams_';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tables = self::get_table_schemas( $prefix, $charset_collate );

        foreach ( $tables as $sql ) {
            dbDelta( $sql );
        }

        update_option( 'ams_db_version', AMS_VERSION );
    }

    private static function get_table_schemas( string $prefix, string $charset_collate ): array {
        $tables = [];

        // ams_subscribers
        $tables[] = "CREATE TABLE {$prefix}subscribers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(255) NOT NULL,
            phone VARCHAR(30) DEFAULT '',
            first_name VARCHAR(100) DEFAULT '',
            last_name VARCHAR(100) DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            source VARCHAR(30) NOT NULL DEFAULT 'sync_order',
            gdpr_consent TINYINT NOT NULL DEFAULT 0,
            gdpr_timestamp DATETIME DEFAULT NULL,
            sms_opt_in TINYINT NOT NULL DEFAULT 0,
            unsubscribe_token VARCHAR(64) DEFAULT NULL,
            subscriber_token VARCHAR(64) DEFAULT NULL,
            tags JSON DEFAULT NULL,
            custom_fields JSON DEFAULT NULL,
            total_orders INT NOT NULL DEFAULT 0,
            total_spent DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            last_order_date DATETIME DEFAULT NULL,
            rfm_score VARCHAR(3) DEFAULT NULL,
            rfm_segment VARCHAR(30) DEFAULT NULL,
            predicted_clv DECIMAL(12,2) DEFAULT NULL,
            predicted_next_order DATE DEFAULT NULL,
            churn_risk_score TINYINT NOT NULL DEFAULT 0,
            best_send_hour TINYINT NOT NULL DEFAULT 10,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY email (email),
            UNIQUE KEY unsubscribe_token (unsubscribe_token),
            UNIQUE KEY subscriber_token (subscriber_token),
            KEY status (status),
            KEY rfm_segment (rfm_segment),
            KEY churn_risk_score (churn_risk_score)
        ) $charset_collate;";

        // ams_events
        $tables[] = "CREATE TABLE {$prefix}events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            subscriber_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            event_type VARCHAR(50) NOT NULL DEFAULT '',
            event_data JSON DEFAULT NULL,
            woo_order_id BIGINT UNSIGNED DEFAULT NULL,
            product_ids JSON DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY subscriber_id (subscriber_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) $charset_collate;";

        // ams_flows
        $tables[] = "CREATE TABLE {$prefix}flows (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(200) NOT NULL DEFAULT '',
            trigger_type VARCHAR(50) NOT NULL DEFAULT '',
            trigger_config JSON DEFAULT NULL,
            status ENUM('draft','active','paused') NOT NULL DEFAULT 'draft',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        // ams_flow_steps
        $tables[] = "CREATE TABLE {$prefix}flow_steps (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            flow_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            step_type VARCHAR(30) NOT NULL DEFAULT '',
            step_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
            delay_value INT UNSIGNED NOT NULL DEFAULT 0,
            delay_unit ENUM('minutes','hours','days') NOT NULL DEFAULT 'minutes',
            subject VARCHAR(255) DEFAULT '',
            preview_text VARCHAR(150) DEFAULT '',
            body_html LONGTEXT DEFAULT NULL,
            body_text LONGTEXT DEFAULT NULL,
            sms_body TEXT DEFAULT NULL,
            conditions JSON DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY flow_id (flow_id)
        ) $charset_collate;";

        // ams_flow_enrolments
        $tables[] = "CREATE TABLE {$prefix}flow_enrolments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            flow_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            subscriber_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            current_step_id BIGINT UNSIGNED DEFAULT NULL,
            status ENUM('active','completed','exited') NOT NULL DEFAULT 'active',
            enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME DEFAULT NULL,
            exited_at DATETIME DEFAULT NULL,
            exit_reason VARCHAR(100) DEFAULT '',
            PRIMARY KEY (id),
            KEY subscriber_id (subscriber_id),
            KEY flow_id (flow_id),
            KEY status (status)
        ) $charset_collate;";

        // ams_campaigns
        $tables[] = "CREATE TABLE {$prefix}campaigns (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(200) NOT NULL DEFAULT '',
            type ENUM('email','sms') NOT NULL DEFAULT 'email',
            status ENUM('draft','scheduled','sent','cancelled') NOT NULL DEFAULT 'draft',
            segment_id BIGINT UNSIGNED DEFAULT NULL,
            subject VARCHAR(255) DEFAULT '',
            preview_text VARCHAR(150) DEFAULT '',
            body_html LONGTEXT DEFAULT NULL,
            body_text LONGTEXT DEFAULT NULL,
            sms_body TEXT DEFAULT NULL,
            scheduled_at DATETIME DEFAULT NULL,
            sent_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        // ams_segments
        $tables[] = "CREATE TABLE {$prefix}segments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(200) NOT NULL DEFAULT '',
            conditions JSON DEFAULT NULL,
            subscriber_count INT NOT NULL DEFAULT 0,
            last_calculated DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        // ams_sends
        $tables[] = "CREATE TABLE {$prefix}sends (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id BIGINT UNSIGNED DEFAULT NULL,
            flow_step_id BIGINT UNSIGNED DEFAULT NULL,
            subscriber_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            channel ENUM('email','sms') NOT NULL DEFAULT 'email',
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            sent_at DATETIME DEFAULT NULL,
            opened_at DATETIME DEFAULT NULL,
            clicked_at DATETIME DEFAULT NULL,
            bounced_at DATETIME DEFAULT NULL,
            unsubscribed_at DATETIME DEFAULT NULL,
            revenue_attributed DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY subscriber_id (subscriber_id),
            KEY campaign_id (campaign_id),
            KEY flow_step_id (flow_step_id),
            KEY status (status),
            KEY sent_at (sent_at)
        ) $charset_collate;";

        // ams_forms
        $tables[] = "CREATE TABLE {$prefix}forms (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(200) NOT NULL DEFAULT '',
            type VARCHAR(30) NOT NULL DEFAULT '',
            trigger_config JSON DEFAULT NULL,
            targeting_config JSON DEFAULT NULL,
            fields JSON DEFAULT NULL,
            design_config JSON DEFAULT NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'inactive',
            views INT UNSIGNED NOT NULL DEFAULT 0,
            submissions INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        // ams_attributions
        $tables[] = "CREATE TABLE {$prefix}attributions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            send_id BIGINT UNSIGNED DEFAULT NULL,
            campaign_id BIGINT UNSIGNED DEFAULT NULL,
            flow_id BIGINT UNSIGNED DEFAULT NULL,
            flow_step_id BIGINT UNSIGNED DEFAULT NULL,
            subscriber_id BIGINT UNSIGNED DEFAULT NULL,
            woo_order_id BIGINT UNSIGNED DEFAULT NULL,
            order_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            attributed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        // ams_analytics_daily
        $tables[] = "CREATE TABLE {$prefix}analytics_daily (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            date DATE NOT NULL,
            metric_key VARCHAR(80) NOT NULL DEFAULT '',
            metric_value DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            PRIMARY KEY (id),
            UNIQUE KEY date_metric (date, metric_key)
        ) $charset_collate;";

        // ams_sync_log
        $tables[] = "CREATE TABLE {$prefix}sync_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(50) NOT NULL DEFAULT '',
            source_site_url VARCHAR(255) DEFAULT '',
            payload_hash VARCHAR(16) DEFAULT '',
            http_response_sent SMALLINT DEFAULT NULL,
            status ENUM('processed','auth_failed','unknown_event','error') NOT NULL DEFAULT 'processed',
            received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event_type (event_type),
            KEY received_at (received_at),
            KEY status (status)
        ) $charset_collate;";

        // ams_reviews_cache
        $tables[] = "CREATE TABLE {$prefix}reviews_cache (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source ENUM('kdna','woocommerce') NOT NULL DEFAULT 'woocommerce',
            product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            woo_comment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            reviewer_name VARCHAR(100) DEFAULT '',
            rating TINYINT UNSIGNED NOT NULL DEFAULT 0,
            review_title VARCHAR(255) DEFAULT '',
            review_body TEXT DEFAULT NULL,
            review_date DATETIME DEFAULT NULL,
            verified_purchase TINYINT NOT NULL DEFAULT 0,
            positive_votes INT NOT NULL DEFAULT 0,
            negative_votes INT NOT NULL DEFAULT 0,
            attachment_ids JSON DEFAULT NULL,
            video_url VARCHAR(500) DEFAULT '',
            product_name VARCHAR(255) DEFAULT '',
            product_image_url VARCHAR(500) DEFAULT '',
            product_url VARCHAR(500) DEFAULT '',
            cached_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY source (source),
            KEY rating (rating),
            KEY cached_at (cached_at),
            UNIQUE KEY woo_comment_id (woo_comment_id)
        ) $charset_collate;";

        // ams_ai_log
        $tables[] = "CREATE TABLE {$prefix}ai_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            feature VARCHAR(50) NOT NULL DEFAULT '',
            input_summary TEXT DEFAULT NULL,
            output_summary TEXT DEFAULT NULL,
            tokens_used INT NOT NULL DEFAULT 0,
            cost_usd DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
            subscriber_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY feature (feature),
            KEY created_at (created_at)
        ) $charset_collate;";

        // ams_products_cache
        $tables[] = "CREATE TABLE {$prefix}products_cache (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            name VARCHAR(255) DEFAULT '',
            slug VARCHAR(255) DEFAULT '',
            price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            sale_price DECIMAL(12,2) DEFAULT NULL,
            on_sale TINYINT NOT NULL DEFAULT 0,
            categories JSON DEFAULT NULL,
            image_url VARCHAR(500) DEFAULT '',
            product_url VARCHAR(500) DEFAULT '',
            average_rating DECIMAL(3,2) NOT NULL DEFAULT 0.00,
            date_created DATETIME DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'publish',
            cached_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY product_id (product_id),
            KEY on_sale (on_sale),
            KEY cached_at (cached_at)
        ) $charset_collate;";

        return $tables;
    }
}
