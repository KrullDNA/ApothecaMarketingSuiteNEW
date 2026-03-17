<?php

namespace Apotheca\Marketing\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SegmentsPage {

    public function __construct() {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
    }

    public function enqueue_scripts( string $hook ): void {
        if ( ! str_contains( $hook, 'ams-segments' ) ) {
            return;
        }

        wp_enqueue_script(
            'ams-segment-builder',
            AMS_PLUGIN_URL . 'assets/js/ams-segment-builder.min.js',
            [ 'wp-element', 'wp-components', 'wp-api-fetch' ],
            AMS_VERSION,
            true
        );

        wp_localize_script( 'ams-segment-builder', 'amsSegmentBuilder', [
            'restUrl'       => rest_url( 'ams/v1/admin/segments' ),
            'nonce'         => wp_create_nonce( 'wp_rest' ),
            'listUrl'       => admin_url( 'admin.php?page=ams-segments' ),
            'conditionTypes'=> \Apotheca\Marketing\Segments\SegmentEvaluator::get_condition_types(),
            'rfmSegments'   => \Apotheca\Marketing\Segments\SegmentEvaluator::rfm_segment_labels(),
        ] );

        wp_enqueue_style( 'wp-components' );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $view       = sanitize_text_field( $_GET['view'] ?? 'list' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $segment_id = absint( $_GET['segment_id'] ?? 0 );

        if ( 'builder' === $view && $segment_id ) {
            $this->render_builder( $segment_id );
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
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Segments', 'apotheca-marketing-suite' ); ?></h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ams-segments&view=new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Create Segment', 'apotheca-marketing-suite' ); ?></a>

            <div id="ams-rfm-summary" style="margin-top:20px;"></div>
            <div id="ams-segments-list" style="margin-top:20px;"></div>
        </div>
        <?php
    }

    private function render_new(): void {
        ?>
        <div class="wrap">
            <h1>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ams-segments' ) ); ?>">&larr;</a>
                <?php esc_html_e( 'New Segment', 'apotheca-marketing-suite' ); ?>
            </h1>
            <div id="ams-segment-builder"></div>
        </div>
        <?php
    }

    private function render_builder( int $segment_id ): void {
        ?>
        <div class="wrap">
            <h1>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ams-segments' ) ); ?>">&larr;</a>
                <?php esc_html_e( 'Segment Builder', 'apotheca-marketing-suite' ); ?>
            </h1>
            <div id="ams-segment-builder" data-segment-id="<?php echo esc_attr( $segment_id ); ?>"></div>
        </div>
        <?php
    }
}
