<?php

namespace Apotheca\Marketing\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FlowsPage {

    public function __construct() {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
    }

    public function enqueue_scripts( string $hook ): void {
        // Only load on the flows page.
        if ( ! str_contains( $hook, 'ams-flows' ) ) {
            return;
        }

        wp_enqueue_script(
            'ams-flow-builder',
            AMS_PLUGIN_URL . 'assets/js/ams-flow-builder.min.js',
            [ 'wp-element', 'wp-components', 'wp-api-fetch' ],
            AMS_VERSION,
            true
        );

        wp_localize_script( 'ams-flow-builder', 'amsFlowBuilder', [
            'restUrl'          => rest_url( 'ams/v1/admin/flows' ),
            'templatesRestUrl' => rest_url( 'ams/v1/admin/email-templates' ),
            'nonce'            => wp_create_nonce( 'wp_rest' ),
            'triggerTypes'     => \Apotheca\Marketing\Flows\TriggerManager::valid_types(),
            'stepTypes'        => \Apotheca\Marketing\Flows\StepExecutorFactory::valid_types(),
            'listUrl'          => admin_url( 'admin.php?page=ams-flows' ),
        ] );

        wp_enqueue_style( 'wp-components' );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $view    = sanitize_text_field( $_GET['view'] ?? 'list' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $flow_id = absint( $_GET['flow_id'] ?? 0 );

        if ( 'builder' === $view && $flow_id ) {
            $this->render_builder( $flow_id );
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
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Automated Flows', 'apotheca-marketing-suite' ); ?></h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ams-flows&view=new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Create Flow', 'apotheca-marketing-suite' ); ?></a>

            <div style="margin-top:12px;">
                <h3><?php esc_html_e( 'Import from Template', 'apotheca-marketing-suite' ); ?></h3>
                <div id="ams-flow-templates" style="display:flex;gap:12px;flex-wrap:wrap;"></div>
            </div>

            <div id="ams-flows-list" style="margin-top:20px;"></div>
        </div>
        <?php
    }

    private function render_new(): void {
        ?>
        <div class="wrap">
            <h1>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ams-flows' ) ); ?>">&larr;</a>
                <?php esc_html_e( 'New Flow', 'apotheca-marketing-suite' ); ?>
            </h1>
            <div id="ams-flow-create"></div>
        </div>
        <?php
    }

    private function render_builder( int $flow_id ): void {
        ?>
        <div class="wrap">
            <h1>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ams-flows' ) ); ?>">&larr;</a>
                <?php esc_html_e( 'Flow Builder', 'apotheca-marketing-suite' ); ?>
            </h1>
            <div id="ams-flow-builder" data-flow-id="<?php echo esc_attr( $flow_id ); ?>"></div>
        </div>
        <?php
    }
}
