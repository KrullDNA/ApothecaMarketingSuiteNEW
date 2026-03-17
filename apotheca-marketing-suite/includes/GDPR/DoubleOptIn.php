<?php

namespace Apotheca\Marketing\GDPR;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DoubleOptIn {

    public function __construct() {
        add_action( 'ams_send_double_optin', [ $this, 'send_confirmation' ] );
        add_action( 'init', [ $this, 'add_rewrite_rules' ] );
        add_filter( 'query_vars', [ $this, 'query_vars' ] );
        add_action( 'template_redirect', [ $this, 'handle_confirmation' ] );
    }

    public function add_rewrite_rules(): void {
        add_rewrite_rule(
            '^ams-confirm/?$',
            'index.php?ams_confirm=1',
            'top'
        );
    }

    public function query_vars( array $vars ): array {
        $vars[] = 'ams_confirm';
        return $vars;
    }

    /**
     * Send double opt-in confirmation email.
     */
    public function send_confirmation( object $subscriber ): void {
        $token        = $subscriber->subscriber_token;
        $confirm_url  = home_url( '/ams-confirm/' ) . '?token=' . urlencode( $token );

        $subject = __( 'Please confirm your subscription', 'apotheca-marketing-suite' );
        $message = sprintf(
            /* translators: %s: confirmation URL */
            __( "Thank you for subscribing!\n\nPlease confirm your email address by clicking the link below:\n\n%s\n\nIf you did not subscribe, you can safely ignore this email.", 'apotheca-marketing-suite' ),
            $confirm_url
        );

        wp_mail( $subscriber->email, $subject, $message );
    }

    /**
     * Handle confirmation link click.
     */
    public function handle_confirmation(): void {
        if ( ! get_query_var( 'ams_confirm' ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

        if ( empty( $token ) ) {
            $this->render( 'error', __( 'Invalid confirmation link.', 'apotheca-marketing-suite' ) );
            return;
        }

        $manager    = new \Apotheca\Marketing\Subscriber\SubscriberManager();
        $subscriber = $manager->find_by_token( $token );

        if ( ! $subscriber ) {
            $this->render( 'error', __( 'Invalid confirmation link.', 'apotheca-marketing-suite' ) );
            return;
        }

        if ( 'active' === $subscriber->status ) {
            $this->render( 'already', __( 'Your subscription is already confirmed.', 'apotheca-marketing-suite' ) );
            return;
        }

        // Activate subscriber.
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'ams_subscribers',
            [
                'status'         => 'active',
                'gdpr_consent'   => 1,
                'gdpr_timestamp' => current_time( 'mysql', true ),
                'updated_at'     => current_time( 'mysql', true ),
            ],
            [ 'id' => $subscriber->id ]
        );

        // Log event.
        $logger = new \Apotheca\Marketing\Subscriber\EventLogger();
        $logger->log( (int) $subscriber->id, 'double_optin_confirmed', [] );

        // Fire welcome flow.
        do_action( 'ams_flow_trigger', 'welcome', (int) $subscriber->id, [ 'source' => 'double_optin' ] );

        $this->render( 'success', __( 'Your subscription has been confirmed! Thank you.', 'apotheca-marketing-suite' ) );
    }

    private function render( string $type, string $message ): void {
        $title = __( 'Subscription Confirmation', 'apotheca-marketing-suite' );
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>" />
            <meta name="viewport" content="width=device-width, initial-scale=1" />
            <title><?php echo esc_html( $title ); ?></title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; background: #f0f0f1; }
                .ams-confirm { background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.1); max-width: 400px; text-align: center; }
                .ams-confirm.success { border-top: 4px solid #00a32a; }
                .ams-confirm.error { border-top: 4px solid #d63638; }
                .ams-confirm.already { border-top: 4px solid #dba617; }
            </style>
        </head>
        <body>
            <div class="ams-confirm <?php echo esc_attr( $type ); ?>">
                <h1><?php echo esc_html( $title ); ?></h1>
                <p><?php echo esc_html( $message ); ?></p>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}
