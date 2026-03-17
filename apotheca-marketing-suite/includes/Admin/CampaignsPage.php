<?php

namespace Apotheca\Marketing\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CampaignsPage {

    public function __construct() {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
    }

    public function enqueue_scripts( string $hook ): void {
        if ( ! str_contains( $hook, 'ams-campaigns' ) ) {
            return;
        }

        wp_enqueue_script(
            'ams-email-editor',
            AMS_PLUGIN_URL . 'assets/js/ams-email-editor.min.js',
            [ 'wp-element', 'wp-components', 'wp-api-fetch' ],
            AMS_VERSION,
            true
        );

        wp_localize_script( 'ams-email-editor', 'amsEmailEditor', [
            'restUrl'  => rest_url( 'ams/v1/admin/campaigns' ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'listUrl'  => admin_url( 'admin.php?page=ams-campaigns' ),
            'homeUrl'  => home_url(),
            'siteName' => get_bloginfo( 'name' ),
        ] );

        wp_enqueue_style( 'wp-components' );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $view        = sanitize_text_field( $_GET['view'] ?? 'list' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $campaign_id = absint( $_GET['campaign_id'] ?? 0 );

        if ( 'editor' === $view && $campaign_id ) {
            $this->render_editor( $campaign_id );
            return;
        }

        if ( 'new' === $view ) {
            $this->render_new();
            return;
        }

        $this->render_list();
    }

    private function render_list(): void {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Campaigns', 'apotheca-marketing-suite' ); ?></h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ams-campaigns&view=new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Create Campaign', 'apotheca-marketing-suite' ); ?></a>
            <div id="ams-campaigns-list" style="margin-top:20px;"></div>
        </div>
        <?php
    }

    private function render_new(): void {
        ?>
        <div class="wrap">
            <h1>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ams-campaigns' ) ); ?>">&larr;</a>
                <?php esc_html_e( 'New Campaign', 'apotheca-marketing-suite' ); ?>
            </h1>
            <div id="ams-campaign-create"></div>
        </div>
        <?php
    }

    private function render_editor( int $campaign_id ): void {
        ?>
        <div class="wrap" style="max-width:100%;margin:0;">
            <div style="display:flex;align-items:center;gap:12px;padding:8px 20px;background:#fff;border-bottom:1px solid #c3c4c7;">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ams-campaigns' ) ); ?>">&larr; <?php esc_html_e( 'Back', 'apotheca-marketing-suite' ); ?></a>
                <strong><?php esc_html_e( 'Email Editor', 'apotheca-marketing-suite' ); ?></strong>
            </div>
            <div id="ams-email-editor" data-campaign-id="<?php echo esc_attr( $campaign_id ); ?>"></div>
        </div>
        <?php
    }
}
