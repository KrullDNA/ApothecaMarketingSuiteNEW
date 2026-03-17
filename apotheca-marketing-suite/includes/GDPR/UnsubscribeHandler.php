<?php

namespace Apotheca\Marketing\GDPR;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UnsubscribeHandler {

    public function add_rewrite_rules(): void {
        add_rewrite_rule(
            '^ams-unsubscribe/?$',
            'index.php?ams_unsubscribe=1',
            'top'
        );
    }

    public function query_vars( array $vars ): array {
        $vars[] = 'ams_unsubscribe';
        return $vars;
    }

    public function handle_request(): void {
        if ( ! get_query_var( 'ams_unsubscribe' ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

        if ( empty( $token ) ) {
            $this->render_page( 'error', __( 'Invalid unsubscribe link.', 'apotheca-marketing-suite' ) );
            return;
        }

        $manager    = new \Apotheca\Marketing\Subscriber\SubscriberManager();
        $subscriber = $manager->find_by_unsubscribe_token( $token );

        if ( ! $subscriber ) {
            $this->render_page( 'error', __( 'Invalid unsubscribe link.', 'apotheca-marketing-suite' ) );
            return;
        }

        if ( 'unsubscribed' === $subscriber->status ) {
            $this->render_page( 'already', __( 'You have already been unsubscribed.', 'apotheca-marketing-suite' ) );
            return;
        }

        $manager->unsubscribe_by_token( $token );

        // Log the event.
        $logger = new \Apotheca\Marketing\Subscriber\EventLogger();
        $logger->log( (int) $subscriber->id, 'unsubscribed', [ 'method' => 'link' ] );

        $this->render_page( 'success', __( 'You have been successfully unsubscribed.', 'apotheca-marketing-suite' ) );
    }

    private function render_page( string $type, string $message ): void {
        $title = __( 'Unsubscribe', 'apotheca-marketing-suite' );
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>" />
            <meta name="viewport" content="width=device-width, initial-scale=1" />
            <title><?php echo esc_html( $title ); ?></title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; background: #f0f0f1; }
                .ams-unsub { background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.1); max-width: 400px; text-align: center; }
                .ams-unsub.success { border-top: 4px solid #00a32a; }
                .ams-unsub.error { border-top: 4px solid #d63638; }
                .ams-unsub.already { border-top: 4px solid #dba617; }
            </style>
        </head>
        <body>
            <div class="ams-unsub <?php echo esc_attr( $type ); ?>">
                <h1><?php echo esc_html( $title ); ?></h1>
                <p><?php echo esc_html( $message ); ?></p>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}
