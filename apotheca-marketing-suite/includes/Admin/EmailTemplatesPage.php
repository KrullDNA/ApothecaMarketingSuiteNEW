<?php

namespace Apotheca\Marketing\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EmailTemplatesPage {

    public function __construct() {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
    }

    public function enqueue_scripts( string $hook ): void {
        if ( ! str_contains( $hook, 'ams-email-templates' ) ) {
            return;
        }

        wp_enqueue_script(
            'ams-email-templates',
            AMS_PLUGIN_URL . 'assets/js/ams-email-templates.min.js',
            [ 'jquery', 'jquery-ui-sortable', 'wp-element', 'wp-components', 'wp-api-fetch' ],
            AMS_VERSION,
            true
        );

        wp_localize_script( 'ams-email-templates', 'amsEmailTemplates', [
            'restUrl'  => rest_url( 'ams/v1/admin/email-templates' ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'listUrl'  => admin_url( 'admin.php?page=ams-email-templates' ),
            'homeUrl'  => home_url(),
            'siteName' => get_bloginfo( 'name' ),
        ] );

        wp_enqueue_style( 'wp-components' );
        wp_enqueue_style(
            'ams-email-editor',
            AMS_PLUGIN_URL . 'assets/css/ams-email-editor.css',
            [ 'wp-components', 'dashicons' ],
            AMS_VERSION
        );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $view = sanitize_text_field( $_GET['view'] ?? 'list' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $template_id = absint( $_GET['template_id'] ?? 0 );

        if ( 'editor' === $view && $template_id ) {
            $this->render_editor( $template_id );
            return;
        }

        if ( 'new' === $view ) {
            $this->render_editor( 0 );
            return;
        }

        $this->render_list();
    }

    private function render_list(): void {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Email Templates', 'apotheca-marketing-suite' ); ?></h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ams-email-templates&view=new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Create Template', 'apotheca-marketing-suite' ); ?></a>
            <div id="ams-email-templates-list" style="margin-top:20px;"></div>
        </div>
        <?php
    }

    private function render_editor( int $template_id ): void {
        ?>
        <div class="wrap" style="max-width:100%;margin:0;">
            <div style="display:flex;align-items:center;gap:12px;padding:8px 20px;background:#fff;border-bottom:1px solid #c3c4c7;">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ams-email-templates' ) ); ?>">&larr; <?php esc_html_e( 'Back', 'apotheca-marketing-suite' ); ?></a>
                <strong><?php esc_html_e( 'Template Editor', 'apotheca-marketing-suite' ); ?></strong>
            </div>
            <div id="ams-email-template-editor" data-template-id="<?php echo esc_attr( $template_id ); ?>"></div>
        </div>
        <?php
    }
}
