<?php

namespace Apotheca\Marketing\Forms;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FrontendLoader {

    public function __construct() {
        add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue' ] );
    }

    /**
     * Only enqueue forms JS if active forms target the current page.
     */
    public function maybe_enqueue(): void {
        if ( is_admin() ) {
            return;
        }

        $manager = new FormsManager();
        $page_id = get_queried_object_id();
        $forms   = $manager->get_active_for_page( $page_id );

        if ( empty( $forms ) ) {
            return;
        }

        wp_enqueue_script(
            'ams-forms',
            AMS_PLUGIN_URL . 'assets/js/ams-forms.min.js',
            [],
            AMS_VERSION,
            [ 'strategy' => 'defer', 'in_footer' => true ]
        );

        wp_localize_script( 'ams-forms', 'amsFormsConfig', [
            'restUrl' => rest_url( 'ams/v1/forms/' ),
            'pageId'  => $page_id,
        ] );
    }
}
