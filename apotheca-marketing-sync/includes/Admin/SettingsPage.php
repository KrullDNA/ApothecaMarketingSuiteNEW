<?php

namespace Apotheca\Marketing\Sync\Admin;

use Apotheca\Marketing\Sync\Dispatcher;
use Apotheca\Marketing\Sync\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Settings page: WooCommerce > Marketing Sync.
 */
class SettingsPage {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'wp_ajax_ams_sync_save_settings', [ $this, 'ajax_save_settings' ] );
        add_action( 'wp_ajax_ams_sync_test_connection', [ $this, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_ams_sync_retry_failed', [ $this, 'ajax_retry_failed' ] );
    }

    public function add_menu(): void {
        add_submenu_page(
            'woocommerce',
            'Marketing Sync',
            'Marketing Sync',
            'manage_woocommerce',
            'ams-marketing-sync',
            [ $this, 'render' ]
        );
    }

    public function render(): void {
        $settings = Plugin::get_settings();
        $events   = $settings['events'];

        // Sync health data.
        global $wpdb;
        $table = $wpdb->prefix . 'ams_sync_log';

        $last_success = $wpdb->get_var(
            "SELECT MAX(dispatched_at) FROM {$table} WHERE http_status BETWEEN 200 AND 299"
        );
        $queued_count = 0;
        if ( function_exists( 'as_get_scheduled_actions' ) ) {
            $queued_count = count( as_get_scheduled_actions( [
                'group'  => 'ams-sync',
                'status' => \ActionScheduler_Store::STATUS_PENDING,
            ] ) );
        }
        $today      = gmdate( 'Y-m-d' );
        $week_start = gmdate( 'Y-m-d', strtotime( 'monday this week' ) );
        $sent_today = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE http_status BETWEEN 200 AND 299
               AND dispatched_at >= %s",
            $today . ' 00:00:00'
        ) );
        $sent_week = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE http_status BETWEEN 200 AND 299
               AND dispatched_at >= %s",
            $week_start . ' 00:00:00'
        ) );

        $recent_errors = $wpdb->get_results(
            "SELECT event_type, http_status, response_body, attempt_number, dispatched_at
             FROM {$table}
             WHERE http_status NOT BETWEEN 200 AND 299
             ORDER BY id DESC
             LIMIT 10"
        );

        $event_labels = [
            'customer_registered'  => 'Customer Registered',
            'order_placed'         => 'Order Placed',
            'order_status_changed' => 'Order Status Changed',
            'cart_updated'         => 'Cart Updated',
            'product_viewed'       => 'Product Viewed',
            'checkout_started'     => 'Checkout Started',
            'abandoned_cart'       => 'Abandoned Cart',
        ];

        ?>
        <div class="wrap">
            <h1>Apotheca Marketing Sync</h1>
            <div id="ams-sync-settings" style="max-width:800px;">
                <form id="ams-sync-form">
                    <?php wp_nonce_field( 'ams_sync_settings', 'ams_sync_nonce' ); ?>

                    <h2>Connection</h2>
                    <table class="form-table">
                        <tr>
                            <th>Marketing Subdomain URL</th>
                            <td>
                                <input type="url" name="endpoint_url" class="regular-text"
                                       value="<?php echo esc_attr( $settings['endpoint_url'] ); ?>"
                                       placeholder="https://marketing.yoursite.com" />
                                <p class="description">The full URL of your marketing subdomain where Apotheca Marketing Suite is installed.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Shared Secret</th>
                            <td>
                                <input type="password" name="shared_secret" class="regular-text"
                                       value="" placeholder="<?php echo ! empty( $settings['shared_secret'] ) ? '••••••••' : ''; ?>" />
                                <p class="description">Leave blank to keep the existing secret. Must match the secret configured on the marketing subdomain.</p>
                            </td>
                        </tr>
                    </table>

                    <p>
                        <button type="button" class="button" id="ams-test-connection">Test Connection</button>
                        <span id="ams-test-result" style="margin-left:10px;"></span>
                    </p>

                    <h2>Event Toggles</h2>
                    <table class="form-table">
                        <?php foreach ( $event_labels as $key => $label ) : ?>
                        <tr>
                            <th><?php echo esc_html( $label ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="events[<?php echo esc_attr( $key ); ?>]"
                                           value="1" <?php checked( ! empty( $events[ $key ] ) ); ?> />
                                    Enable
                                </label>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary">Save Settings</button>
                        <span id="ams-save-result" style="margin-left:10px;"></span>
                    </p>
                </form>

                <hr />

                <h2>Sync Health</h2>
                <table class="widefat striped" style="max-width:500px;margin-bottom:20px;">
                    <tbody>
                        <tr>
                            <td><strong>Last Successful Sync</strong></td>
                            <td><?php echo $last_success ? esc_html( $last_success . ' UTC' ) : 'Never'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Events Queued</strong></td>
                            <td><?php echo esc_html( $queued_count ); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Sent Today</strong></td>
                            <td><?php echo esc_html( $sent_today ); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Sent This Week</strong></td>
                            <td><?php echo esc_html( $sent_week ); ?></td>
                        </tr>
                    </tbody>
                </table>

                <?php if ( ! empty( $recent_errors ) ) : ?>
                <h3>Last 10 Errors</h3>
                <table class="widefat striped" style="margin-bottom:20px;">
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th>HTTP</th>
                            <th>Attempt</th>
                            <th>Response</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $recent_errors as $err ) : ?>
                        <tr>
                            <td><?php echo esc_html( $err->event_type ); ?></td>
                            <td><?php echo esc_html( $err->http_status ); ?></td>
                            <td><?php echo esc_html( $err->attempt_number ); ?></td>
                            <td><code style="font-size:11px;word-break:break-all;"><?php echo esc_html( substr( $err->response_body, 0, 120 ) ); ?></code></td>
                            <td><?php echo esc_html( $err->dispatched_at ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <p>
                    <button type="button" class="button" id="ams-retry-failed">Retry Failed (Last 24h)</button>
                    <span id="ams-retry-result" style="margin-left:10px;"></span>
                </p>
            </div>

            <script>
            (function($){
                var nonce = $('#ams_sync_nonce').val();

                $('#ams-sync-form').on('submit', function(e) {
                    e.preventDefault();
                    var data = {
                        action: 'ams_sync_save_settings',
                        ams_sync_nonce: nonce,
                        endpoint_url: $('input[name="endpoint_url"]').val(),
                        shared_secret: $('input[name="shared_secret"]').val(),
                        events: {}
                    };
                    $('input[name^="events["]').each(function(){
                        var key = this.name.match(/events\[(.+)\]/)[1];
                        data.events[key] = this.checked ? 1 : 0;
                    });
                    $.post(ajaxurl, data, function(r) {
                        $('#ams-save-result').text(r.success ? 'Saved.' : 'Error: ' + (r.data||''));
                        setTimeout(function(){ $('#ams-save-result').text(''); }, 3000);
                    });
                });

                $('#ams-test-connection').on('click', function() {
                    var btn = $(this);
                    btn.prop('disabled', true).text('Testing...');
                    $.post(ajaxurl, {
                        action: 'ams_sync_test_connection',
                        ams_sync_nonce: nonce
                    }, function(r) {
                        btn.prop('disabled', false).text('Test Connection');
                        $('#ams-test-result').text(r.success ? 'Connected!' : 'Failed: ' + (r.data||''));
                        setTimeout(function(){ $('#ams-test-result').text(''); }, 5000);
                    });
                });

                $('#ams-retry-failed').on('click', function() {
                    var btn = $(this);
                    btn.prop('disabled', true).text('Retrying...');
                    $.post(ajaxurl, {
                        action: 'ams_sync_retry_failed',
                        ams_sync_nonce: nonce
                    }, function(r) {
                        btn.prop('disabled', false).text('Retry Failed (Last 24h)');
                        $('#ams-retry-result').text(r.success ? r.data + ' events re-queued.' : 'Error.');
                        setTimeout(function(){ $('#ams-retry-result').text(''); }, 5000);
                    });
                });
            })(jQuery);
            </script>
        </div>
        <?php
    }

    public function ajax_save_settings(): void {
        check_ajax_referer( 'ams_sync_settings', 'ams_sync_nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $settings = Plugin::get_settings();
        $settings['endpoint_url'] = esc_url_raw( wp_unslash( $_POST['endpoint_url'] ?? '' ) );

        // Only update secret if a new one is provided.
        $new_secret = sanitize_text_field( wp_unslash( $_POST['shared_secret'] ?? '' ) );
        if ( ! empty( $new_secret ) ) {
            $settings['shared_secret'] = Plugin::encrypt( $new_secret );
        }

        $event_keys = [
            'customer_registered', 'order_placed', 'order_status_changed',
            'cart_updated', 'product_viewed', 'checkout_started', 'abandoned_cart',
        ];
        $events_input = $_POST['events'] ?? [];
        foreach ( $event_keys as $key ) {
            $settings['events'][ $key ] = ! empty( $events_input[ $key ] );
        }

        update_option( 'ams_sync_settings', $settings );
        wp_send_json_success( 'Settings saved.' );
    }

    public function ajax_test_connection(): void {
        check_ajax_referer( 'ams_sync_settings', 'ams_sync_nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $settings = Plugin::get_settings();
        $secret   = Plugin::get_shared_secret();

        if ( empty( $settings['endpoint_url'] ) || empty( $secret ) ) {
            wp_send_json_error( 'Endpoint URL or shared secret not configured.' );
        }

        $endpoint  = trailingslashit( $settings['endpoint_url'] ) . 'wp-json/ams/v1/sync/ingest';
        $timestamp = time();
        $payload   = [ 'ping' => true ];
        $hmac      = hash_hmac( 'sha256', wp_json_encode( $payload ) . $timestamp, $secret );

        $response = wp_remote_post( $endpoint, [
            'timeout' => 15,
            'headers' => [
                'Content-Type'    => 'application/json',
                'X-AMS-Signature' => $hmac,
                'X-AMS-Timestamp' => (string) $timestamp,
            ],
            'body' => wp_json_encode( [
                'event_type' => 'ping',
                'payload'    => $payload,
                'timestamp'  => $timestamp,
                'site_url'   => home_url(),
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code >= 200 && $code < 300 ) {
            wp_send_json_success( 'Connection successful (HTTP ' . $code . ').' );
        }

        wp_send_json_error( 'HTTP ' . $code . ': ' . substr( wp_remote_retrieve_body( $response ), 0, 200 ) );
    }

    public function ajax_retry_failed(): void {
        check_ajax_referer( 'ams_sync_settings', 'ams_sync_nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $count = Dispatcher::retry_recent_failures();
        wp_send_json_success( $count );
    }
}
