<?php

namespace Apotheca\Marketing\Admin;

use Apotheca\Marketing\SMS\TwilioProvider;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SettingsPage {

    public function __construct() {
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'wp_ajax_ams_test_connection', [ $this, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_ams_save_sms_settings', [ $this, 'ajax_save_sms_settings' ] );
        add_action( 'wp_ajax_ams_test_sms', [ $this, 'ajax_test_sms' ] );
        add_action( 'wp_ajax_ams_save_reviews_settings', [ $this, 'ajax_save_reviews_settings' ] );
        add_action( 'wp_ajax_ams_refresh_reviews', [ $this, 'ajax_refresh_reviews' ] );
        add_action( 'wp_ajax_ams_save_ai_settings', [ $this, 'ajax_save_ai_settings' ] );
    }

    public function register_settings(): void {
        register_setting( 'ams_settings_group', 'ams_settings', [
            'sanitize_callback' => [ $this, 'sanitize_settings' ],
        ] );
    }

    public function sanitize_settings( array $input ): array {
        $current  = get_option( 'ams_settings', [] );
        $sanitized = [];

        $sanitized['store_url'] = esc_url_raw( trim( $input['store_url'] ?? '' ) );

        // Encrypt shared secret if changed.
        $new_secret = $input['sync_shared_secret'] ?? '';
        if ( ! empty( $new_secret ) && $new_secret !== '••••••••' ) {
            $sanitized['sync_shared_secret'] = $this->encrypt_secret( sanitize_text_field( $new_secret ) );
        } else {
            $sanitized['sync_shared_secret'] = $current['sync_shared_secret'] ?? '';
        }

        return $sanitized;
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings     = get_option( 'ams_settings', [] );
        $ingest_url   = rest_url( 'ams/v1/sync/ingest' );
        $has_secret   = ! empty( $settings['sync_shared_secret'] );
        $sync_log     = $this->get_recent_sync_log( 50 );

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Apotheca Marketing Settings', 'apotheca-marketing-suite' ); ?></h1>

            <h2 class="nav-tab-wrapper" id="ams-settings-tabs">
                <a href="#sync" class="nav-tab nav-tab-active" data-tab="sync"><?php esc_html_e( 'Sync', 'apotheca-marketing-suite' ); ?></a>
                <a href="#sms" class="nav-tab" data-tab="sms"><?php esc_html_e( 'SMS', 'apotheca-marketing-suite' ); ?></a>
                <a href="#reviews" class="nav-tab" data-tab="reviews"><?php esc_html_e( 'Reviews', 'apotheca-marketing-suite' ); ?></a>
                <a href="#ai" class="nav-tab" data-tab="ai"><?php esc_html_e( 'AI', 'apotheca-marketing-suite' ); ?></a>
            </h2>

            <div class="ams-tab-content" id="ams-tab-sync">
            <form method="post" action="options.php" id="ams-settings-form">
                <?php settings_fields( 'ams_settings_group' ); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="ams_store_url"><?php esc_html_e( 'Store URL', 'apotheca-marketing-suite' ); ?></label>
                        </th>
                        <td>
                            <input type="url" id="ams_store_url" name="ams_settings[store_url]"
                                   value="<?php echo esc_attr( $settings['store_url'] ?? '' ); ?>"
                                   class="regular-text" placeholder="https://yoursite.com" />
                            <p class="description"><?php esc_html_e( 'The URL of your main WooCommerce store (Site A).', 'apotheca-marketing-suite' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ams_shared_secret"><?php esc_html_e( 'Shared Secret', 'apotheca-marketing-suite' ); ?></label>
                        </th>
                        <td>
                            <input type="password" id="ams_shared_secret" name="ams_settings[sync_shared_secret]"
                                   value="<?php echo $has_secret ? '••••••••' : ''; ?>"
                                   class="regular-text" autocomplete="new-password" />
                            <p class="description"><?php esc_html_e( 'The shared secret key used to authenticate sync payloads. Must match the key configured on Site A.', 'apotheca-marketing-suite' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Ingest Endpoint', 'apotheca-marketing-suite' ); ?></th>
                        <td>
                            <code id="ams-ingest-url"><?php echo esc_html( $ingest_url ); ?></code>
                            <button type="button" class="button button-small" id="ams-copy-endpoint"><?php esc_html_e( 'Copy', 'apotheca-marketing-suite' ); ?></button>
                            <p class="description"><?php esc_html_e( 'Configure this URL in the sync plugin on Site A.', 'apotheca-marketing-suite' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Test Connection', 'apotheca-marketing-suite' ); ?></th>
                        <td>
                            <button type="button" class="button" id="ams-test-connection"><?php esc_html_e( 'Test Connection', 'apotheca-marketing-suite' ); ?></button>
                            <span id="ams-test-result"></span>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <h2><?php esc_html_e( 'Sync Log (Last 50)', 'apotheca-marketing-suite' ); ?></h2>

            <?php if ( empty( $sync_log ) ) : ?>
                <p><?php esc_html_e( 'No sync events recorded yet.', 'apotheca-marketing-suite' ); ?></p>
            <?php else : ?>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Event', 'apotheca-marketing-suite' ); ?></th>
                            <th><?php esc_html_e( 'Source', 'apotheca-marketing-suite' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'apotheca-marketing-suite' ); ?></th>
                            <th><?php esc_html_e( 'HTTP', 'apotheca-marketing-suite' ); ?></th>
                            <th><?php esc_html_e( 'Received', 'apotheca-marketing-suite' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $sync_log as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html( $row->event_type ); ?></td>
                                <td><?php echo esc_html( $row->source_site_url ); ?></td>
                                <td><?php echo esc_html( $row->status ); ?></td>
                                <td><?php echo esc_html( $row->http_response_sent ); ?></td>
                                <td><?php echo esc_html( $row->received_at ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            </div><!-- #ams-tab-sync -->

            <?php $this->render_sms_tab(); ?>

            <?php $this->render_reviews_tab(); ?>

            <?php $this->render_ai_tab(); ?>

        </div><!-- .wrap -->

        <script>
        (function() {
            /* Tab switching */
            var tabs = document.querySelectorAll('#ams-settings-tabs .nav-tab');
            tabs.forEach(function(tab) {
                tab.addEventListener('click', function(e) {
                    e.preventDefault();
                    tabs.forEach(function(t) { t.classList.remove('nav-tab-active'); });
                    tab.classList.add('nav-tab-active');
                    document.querySelectorAll('.ams-tab-content').forEach(function(c) { c.style.display = 'none'; });
                    var target = document.getElementById('ams-tab-' + tab.getAttribute('data-tab'));
                    if (target) target.style.display = 'block';
                });
            });
            /* Hide SMS and Reviews tabs by default */
            var smsTab = document.getElementById('ams-tab-sms');
            if (smsTab) smsTab.style.display = 'none';
            var reviewsTab = document.getElementById('ams-tab-reviews');
            if (reviewsTab) reviewsTab.style.display = 'none';
            var aiTab = document.getElementById('ams-tab-ai');
            if (aiTab) aiTab.style.display = 'none';

            var ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

            /* Copy endpoint URL */
            var copyBtn = document.getElementById('ams-copy-endpoint');
            if (copyBtn) {
                copyBtn.addEventListener('click', function() {
                    var url = document.getElementById('ams-ingest-url').textContent;
                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(url).then(function() {
                            copyBtn.textContent = 'Copied!';
                            setTimeout(function() { copyBtn.textContent = 'Copy'; }, 2000);
                        });
                    }
                });
            }

            /* Test sync connection */
            var testBtn = document.getElementById('ams-test-connection');
            if (testBtn) {
                testBtn.addEventListener('click', function() {
                    var result = document.getElementById('ams-test-result');
                    result.textContent = 'Testing...';
                    testBtn.disabled = true;
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', ajaxUrl);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function() {
                        testBtn.disabled = false;
                        try {
                            var data = JSON.parse(xhr.responseText);
                            result.textContent = data.success ? 'Connection successful!' : (data.data || 'Connection failed.');
                            result.style.color = data.success ? 'green' : 'red';
                        } catch(e) { result.textContent = 'Unexpected response.'; result.style.color = 'red'; }
                    };
                    xhr.onerror = function() { testBtn.disabled = false; result.textContent = 'Network error.'; result.style.color = 'red'; };
                    xhr.send('action=ams_test_connection&_wpnonce=<?php echo esc_js( wp_create_nonce( 'ams_test_connection' ) ); ?>');
                });
            }

            /* Save SMS settings */
            var smsForm = document.getElementById('ams-sms-settings-form');
            if (smsForm) {
                smsForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    var result = document.getElementById('ams-sms-save-result');
                    result.textContent = 'Saving...';
                    var fd = new FormData(smsForm);
                    fd.append('action', 'ams_save_sms_settings');
                    fd.append('_wpnonce', '<?php echo esc_js( wp_create_nonce( 'ams_sms_settings' ) ); ?>');
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', ajaxUrl);
                    xhr.onload = function() {
                        try {
                            var data = JSON.parse(xhr.responseText);
                            result.textContent = data.success ? 'SMS settings saved!' : (data.data || 'Save failed.');
                            result.style.color = data.success ? 'green' : 'red';
                        } catch(e) { result.textContent = 'Error.'; result.style.color = 'red'; }
                    };
                    xhr.send(fd);
                });
            }

            /* Test SMS send */
            var testSmsBtn = document.getElementById('ams-test-sms-btn');
            if (testSmsBtn) {
                testSmsBtn.addEventListener('click', function() {
                    var phone = document.getElementById('ams_test_sms_phone').value;
                    if (!phone) { alert('Enter a phone number.'); return; }
                    var result = document.getElementById('ams-test-sms-result');
                    result.textContent = 'Sending...';
                    testSmsBtn.disabled = true;
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', ajaxUrl);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function() {
                        testSmsBtn.disabled = false;
                        try {
                            var data = JSON.parse(xhr.responseText);
                            result.textContent = data.success ? 'Test SMS sent! SID: ' + (data.data.sid || '') : (data.data || 'Send failed.');
                            result.style.color = data.success ? 'green' : 'red';
                        } catch(e) { result.textContent = 'Error.'; result.style.color = 'red'; }
                    };
                    xhr.onerror = function() { testSmsBtn.disabled = false; result.textContent = 'Network error.'; result.style.color = 'red'; };
                    xhr.send('action=ams_test_sms&phone=' + encodeURIComponent(phone) + '&_wpnonce=<?php echo esc_js( wp_create_nonce( 'ams_test_sms' ) ); ?>');
                });
            }
            /* Save Reviews settings */
            var reviewsForm = document.getElementById('ams-reviews-settings-form');
            if (reviewsForm) {
                reviewsForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    var result = document.getElementById('ams-reviews-save-result');
                    result.textContent = 'Saving...';
                    var fd = new FormData(reviewsForm);
                    fd.append('action', 'ams_save_reviews_settings');
                    fd.append('_wpnonce', '<?php echo esc_js( wp_create_nonce( 'ams_reviews_settings' ) ); ?>');
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', ajaxUrl);
                    xhr.onload = function() {
                        try {
                            var data = JSON.parse(xhr.responseText);
                            result.textContent = data.success ? 'Reviews settings saved!' : (data.data || 'Save failed.');
                            result.style.color = data.success ? 'green' : 'red';
                        } catch(e) { result.textContent = 'Error.'; result.style.color = 'red'; }
                    };
                    xhr.send(fd);
                });
            }

            /* Refresh Reviews Cache */
            var refreshBtn = document.getElementById('ams-refresh-reviews-btn');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', function() {
                    var result = document.getElementById('ams-refresh-reviews-result');
                    result.textContent = 'Refreshing...';
                    refreshBtn.disabled = true;
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', ajaxUrl);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function() {
                        refreshBtn.disabled = false;
                        try {
                            var data = JSON.parse(xhr.responseText);
                            if (data.success) {
                                result.textContent = 'Cache refreshed! ' + data.data.count + ' reviews cached.';
                                result.style.color = 'green';
                            } else {
                                result.textContent = data.data || 'Refresh failed.';
                                result.style.color = 'red';
                            }
                        } catch(e) { result.textContent = 'Error.'; result.style.color = 'red'; }
                    };
                    xhr.onerror = function() { refreshBtn.disabled = false; result.textContent = 'Network error.'; result.style.color = 'red'; };
                    xhr.send('action=ams_refresh_reviews&_wpnonce=<?php echo esc_js( wp_create_nonce( 'ams_refresh_reviews' ) ); ?>');
                });
            }

            /* Save AI settings */
            var aiForm = document.getElementById('ams-ai-settings-form');
            if (aiForm) {
                aiForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    var result = document.getElementById('ams-ai-save-result');
                    result.textContent = 'Saving...';
                    var fd = new FormData(aiForm);
                    fd.append('action', 'ams_save_ai_settings');
                    fd.append('_wpnonce', '<?php echo esc_js( wp_create_nonce( 'ams_ai_settings' ) ); ?>');
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', ajaxUrl);
                    xhr.onload = function() {
                        try {
                            var data = JSON.parse(xhr.responseText);
                            result.textContent = data.success ? 'AI settings saved!' : (data.data || 'Save failed.');
                            result.style.color = data.success ? 'green' : 'red';
                        } catch(e) { result.textContent = 'Error.'; result.style.color = 'red'; }
                    };
                    xhr.send(fd);
                });
            }
        })();
        </script>
        <?php
    }

    /**
     * AJAX handler for test connection (loopback signed ping).
     */
    public function ajax_test_connection(): void {
        check_ajax_referer( 'ams_test_connection' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'apotheca-marketing-suite' ) );
        }

        $settings      = get_option( 'ams_settings', [] );
        $shared_secret = $this->decrypt_secret( $settings['sync_shared_secret'] ?? '' );

        if ( empty( $shared_secret ) ) {
            wp_send_json_error( __( 'Shared secret not configured.', 'apotheca-marketing-suite' ) );
        }

        // Build a signed ping payload.
        $payload   = wp_json_encode( [
            'event_type' => 'ping',
            'payload'    => [],
            'timestamp'  => time(),
            'site_url'   => home_url(),
        ] );
        $timestamp = (string) time();
        $signature = hash_hmac( 'sha256', $payload, $shared_secret );

        $response = wp_remote_post( rest_url( 'ams/v1/sync/ingest' ), [
            'body'    => $payload,
            'headers' => [
                'Content-Type'    => 'application/json',
                'X-AMS-Signature' => $signature,
                'X-AMS-Timestamp' => $timestamp,
            ],
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        // A 400 with "Unknown event type" is expected for a ping — it means auth passed.
        if ( 200 === $code || 400 === $code ) {
            wp_send_json_success( __( 'Connection verified. HMAC authentication is working.', 'apotheca-marketing-suite' ) );
        }

        wp_send_json_error( sprintf(
            /* translators: %d: HTTP response code */
            __( 'Unexpected response code: %d', 'apotheca-marketing-suite' ),
            $code
        ) );
    }

    /**
     * Get recent sync log entries.
     */
    private function get_recent_sync_log( int $limit ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_sync_log';

        // Check if table exists before querying.
        $table_exists = $wpdb->get_var( $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $table
        ) );

        if ( ! $table_exists ) {
            return [];
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT event_type, source_site_url, status, http_response_sent, received_at
             FROM {$table} ORDER BY received_at DESC LIMIT %d",
            $limit
        ) );
    }

    /**
     * Render the SMS settings tab.
     */
    private function render_sms_tab(): void {
        $sms_settings = get_option( 'ams_sms_settings', [] );
        $has_sid      = ! empty( $sms_settings['account_sid'] );
        $has_token    = ! empty( $sms_settings['auth_token'] );
        $has_from     = ! empty( $sms_settings['from_number'] );
        $has_msgsid   = ! empty( $sms_settings['messaging_service_sid'] );
        $help_text    = $sms_settings['help_text'] ?? 'Reply STOP to unsubscribe or HELP for help.';
        $webhook_url  = rest_url( 'ams/v1/sms/webhook' );
        $status_url   = rest_url( 'ams/v1/sms/status' );
        ?>
        <div class="ams-tab-content" id="ams-tab-sms">
            <form id="ams-sms-settings-form">
                <h2><?php esc_html_e( 'Twilio Credentials', 'apotheca-marketing-suite' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="ams_twilio_account_sid"><?php esc_html_e( 'Account SID', 'apotheca-marketing-suite' ); ?></label>
                        </th>
                        <td>
                            <input type="password" id="ams_twilio_account_sid" name="account_sid"
                                   value="<?php echo $has_sid ? '••••••••' : ''; ?>"
                                   class="regular-text" autocomplete="new-password" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ams_twilio_auth_token"><?php esc_html_e( 'Auth Token', 'apotheca-marketing-suite' ); ?></label>
                        </th>
                        <td>
                            <input type="password" id="ams_twilio_auth_token" name="auth_token"
                                   value="<?php echo $has_token ? '••••••••' : ''; ?>"
                                   class="regular-text" autocomplete="new-password" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ams_twilio_from_number"><?php esc_html_e( 'From Number', 'apotheca-marketing-suite' ); ?></label>
                        </th>
                        <td>
                            <input type="password" id="ams_twilio_from_number" name="from_number"
                                   value="<?php echo $has_from ? '••••••••' : ''; ?>"
                                   class="regular-text" autocomplete="new-password" />
                            <p class="description"><?php esc_html_e( 'Your Twilio phone number in E.164 format (e.g. +15551234567).', 'apotheca-marketing-suite' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ams_twilio_messaging_service_sid"><?php esc_html_e( 'Messaging Service SID', 'apotheca-marketing-suite' ); ?></label>
                        </th>
                        <td>
                            <input type="password" id="ams_twilio_messaging_service_sid" name="messaging_service_sid"
                                   value="<?php echo $has_msgsid ? '••••••••' : ''; ?>"
                                   class="regular-text" autocomplete="new-password" />
                            <p class="description"><?php esc_html_e( 'Optional. If set, this takes priority over the From Number.', 'apotheca-marketing-suite' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ams_twilio_help_text"><?php esc_html_e( 'HELP Response Text', 'apotheca-marketing-suite' ); ?></label>
                        </th>
                        <td>
                            <textarea id="ams_twilio_help_text" name="help_text" rows="3" class="large-text"><?php echo esc_textarea( $help_text ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Sent when a subscriber replies HELP.', 'apotheca-marketing-suite' ); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Save SMS Settings', 'apotheca-marketing-suite' ); ?></button>
                    <span id="ams-sms-save-result" style="margin-left:10px;"></span>
                </p>
            </form>

            <hr />

            <h2><?php esc_html_e( 'Webhook URLs', 'apotheca-marketing-suite' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Configure these URLs in your Twilio console.', 'apotheca-marketing-suite' ); ?></p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Inbound SMS Webhook', 'apotheca-marketing-suite' ); ?></th>
                    <td><code><?php echo esc_html( $webhook_url ); ?></code></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Status Callback URL', 'apotheca-marketing-suite' ); ?></th>
                    <td><code><?php echo esc_html( $status_url ); ?></code></td>
                </tr>
            </table>

            <hr />

            <h2><?php esc_html_e( 'Opt-Out Rate Card', 'apotheca-marketing-suite' ); ?></h2>
            <table class="widefat fixed" style="max-width:500px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Keyword', 'apotheca-marketing-suite' ); ?></th>
                        <th><?php esc_html_e( 'Action', 'apotheca-marketing-suite' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td>STOP, STOPALL, UNSUBSCRIBE, CANCEL, END, QUIT</td><td><?php esc_html_e( 'Opt out subscriber', 'apotheca-marketing-suite' ); ?></td></tr>
                    <tr><td>UNSTOP, START, YES</td><td><?php esc_html_e( 'Opt in subscriber', 'apotheca-marketing-suite' ); ?></td></tr>
                    <tr><td>HELP</td><td><?php esc_html_e( 'Send HELP response text', 'apotheca-marketing-suite' ); ?></td></tr>
                </tbody>
            </table>

            <hr />

            <h2><?php esc_html_e( 'Test SMS', 'apotheca-marketing-suite' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="ams_test_sms_phone"><?php esc_html_e( 'Phone Number', 'apotheca-marketing-suite' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="ams_test_sms_phone" class="regular-text" placeholder="+15551234567" />
                        <button type="button" class="button" id="ams-test-sms-btn"><?php esc_html_e( 'Send Test SMS', 'apotheca-marketing-suite' ); ?></button>
                        <span id="ams-test-sms-result" style="margin-left:10px;"></span>
                        <p class="description"><?php esc_html_e( 'Sends a test message: "This is a test SMS from Apotheca Marketing Suite."', 'apotheca-marketing-suite' ); ?></p>
                    </td>
                </tr>
            </table>
        </div><!-- #ams-tab-sms -->
        <?php
    }

    /**
     * AJAX handler: save SMS settings (encrypted).
     */
    public function ajax_save_sms_settings(): void {
        check_ajax_referer( 'ams_sms_settings' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'apotheca-marketing-suite' ) );
        }

        $current  = get_option( 'ams_sms_settings', [] );
        $encrypt  = [ TwilioProvider::class, 'encrypt' ];
        $fields   = [ 'account_sid', 'auth_token', 'from_number', 'messaging_service_sid' ];
        $settings = [];

        foreach ( $fields as $field ) {
            $value = sanitize_text_field( wp_unslash( $_POST[ $field ] ?? '' ) );
            if ( ! empty( $value ) && '••••••••' !== $value ) {
                $settings[ $field ] = call_user_func( $encrypt, $value );
            } else {
                $settings[ $field ] = $current[ $field ] ?? '';
            }
        }

        $settings['help_text'] = sanitize_textarea_field( wp_unslash( $_POST['help_text'] ?? '' ) );

        update_option( 'ams_sms_settings', $settings );
        wp_send_json_success( __( 'SMS settings saved.', 'apotheca-marketing-suite' ) );
    }

    /**
     * AJAX handler: send a test SMS.
     */
    public function ajax_test_sms(): void {
        check_ajax_referer( 'ams_test_sms' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'apotheca-marketing-suite' ) );
        }

        $phone = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
        if ( empty( $phone ) ) {
            wp_send_json_error( __( 'Phone number is required.', 'apotheca-marketing-suite' ) );
        }

        $provider = new TwilioProvider();
        $result   = $provider->send_test( $phone, 'This is a test SMS from Apotheca Marketing Suite.' );

        if ( $result['success'] ) {
            wp_send_json_success( [ 'sid' => $result['sid'] ] );
        } else {
            wp_send_json_error( $result['error'] );
        }
    }

    /**
     * Render the Reviews settings tab.
     */
    private function render_reviews_tab(): void {
        $reviews_settings = get_option( 'ams_reviews_settings', [] );
        $settings         = get_option( 'ams_settings', [] );
        $store_url        = $settings['store_url'] ?? '';
        $min_rating       = (int) ( $reviews_settings['min_rating'] ?? 3 );
        $feedback_page_id = (int) ( $reviews_settings['feedback_page_id'] ?? 0 );
        $gate_expiry      = (int) ( $reviews_settings['gate_expiry_hours'] ?? 72 );

        $cache_job = new \Apotheca\Marketing\Reviews\ReviewsCacheJob();
        $stats     = $cache_job->get_stats();

        $pages = get_pages( [ 'post_status' => 'publish' ] );
        ?>
        <div class="ams-tab-content" id="ams-tab-reviews">
            <form id="ams-reviews-settings-form">
                <h2><?php esc_html_e( 'Reviews Settings', 'apotheca-marketing-suite' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Store URL', 'apotheca-marketing-suite' ); ?></th>
                        <td>
                            <code><?php echo esc_html( $store_url ?: __( 'Not configured', 'apotheca-marketing-suite' ) ); ?></code>
                            <p class="description"><?php esc_html_e( 'Configured in the Sync tab. Reviews are fetched from this store.', 'apotheca-marketing-suite' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ams_reviews_min_rating"><?php esc_html_e( 'Min Rating to Cache', 'apotheca-marketing-suite' ); ?></label>
                        </th>
                        <td>
                            <select id="ams_reviews_min_rating" name="min_rating">
                                <option value="3" <?php selected( $min_rating, 3 ); ?>>3+ Stars</option>
                                <option value="4" <?php selected( $min_rating, 4 ); ?>>4+ Stars</option>
                                <option value="5" <?php selected( $min_rating, 5 ); ?>>5 Stars Only</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ams_reviews_feedback_page"><?php esc_html_e( 'Private Feedback Page', 'apotheca-marketing-suite' ); ?></label>
                        </th>
                        <td>
                            <select id="ams_reviews_feedback_page" name="feedback_page_id">
                                <option value="0"><?php esc_html_e( '— Select Page —', 'apotheca-marketing-suite' ); ?></option>
                                <?php foreach ( $pages as $page ) : ?>
                                    <option value="<?php echo (int) $page->ID; ?>" <?php selected( $feedback_page_id, $page->ID ); ?>>
                                        <?php echo esc_html( $page->post_title ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Low-rating review gate clicks (1-3 stars) redirect here instead of the public review page.', 'apotheca-marketing-suite' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ams_reviews_gate_expiry"><?php esc_html_e( 'Review Gate Link Expiry', 'apotheca-marketing-suite' ); ?></label>
                        </th>
                        <td>
                            <input type="number" id="ams_reviews_gate_expiry" name="gate_expiry_hours"
                                   value="<?php echo esc_attr( $gate_expiry ); ?>"
                                   min="1" max="720" class="small-text" /> <?php esc_html_e( 'hours', 'apotheca-marketing-suite' ); ?>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Reviews Settings', 'apotheca-marketing-suite' ); ?></button>
                    <span id="ams-reviews-save-result" style="margin-left:10px;"></span>
                </p>
            </form>

            <hr />

            <h2><?php esc_html_e( 'Cache Stats', 'apotheca-marketing-suite' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Total Cached Reviews', 'apotheca-marketing-suite' ); ?></th>
                    <td><strong><?php echo (int) $stats['total']; ?></strong></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'KDNA Source', 'apotheca-marketing-suite' ); ?></th>
                    <td><?php echo (int) $stats['kdna_count']; ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'WooCommerce Source', 'apotheca-marketing-suite' ); ?></th>
                    <td><?php echo (int) $stats['woo_count']; ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Last Refreshed', 'apotheca-marketing-suite' ); ?></th>
                    <td><?php echo $stats['last_refreshed'] ? esc_html( $stats['last_refreshed'] ) : esc_html__( 'Never', 'apotheca-marketing-suite' ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Manual Refresh', 'apotheca-marketing-suite' ); ?></th>
                    <td>
                        <button type="button" class="button" id="ams-refresh-reviews-btn"><?php esc_html_e( 'Refresh Now', 'apotheca-marketing-suite' ); ?></button>
                        <span id="ams-refresh-reviews-result" style="margin-left:10px;"></span>
                    </td>
                </tr>
            </table>
        </div><!-- #ams-tab-reviews -->
        <?php
    }

    /**
     * AJAX handler: save reviews settings.
     */
    public function ajax_save_reviews_settings(): void {
        check_ajax_referer( 'ams_reviews_settings' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'apotheca-marketing-suite' ) );
        }

        $settings = [
            'min_rating'       => max( 3, min( 5, (int) ( $_POST['min_rating'] ?? 3 ) ) ),
            'feedback_page_id' => absint( $_POST['feedback_page_id'] ?? 0 ),
            'gate_expiry_hours'=> max( 1, min( 720, (int) ( $_POST['gate_expiry_hours'] ?? 72 ) ) ),
        ];

        update_option( 'ams_reviews_settings', $settings );
        wp_send_json_success( __( 'Reviews settings saved.', 'apotheca-marketing-suite' ) );
    }

    /**
     * AJAX handler: manually refresh the reviews cache.
     */
    public function ajax_refresh_reviews(): void {
        check_ajax_referer( 'ams_refresh_reviews' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'apotheca-marketing-suite' ) );
        }

        $job    = new \Apotheca\Marketing\Reviews\ReviewsCacheJob();
        $result = $job->manual_refresh();

        wp_send_json_success( $result );
    }

    /**
     * Render the AI settings tab.
     */
    private function render_ai_tab(): void {
        $ai_settings = get_option( 'ams_ai_settings', [] );
        $settings    = get_option( 'ams_settings', [] );
        $has_key     = ! empty( $settings['openai_api_key'] );

        $enabled = $ai_settings['enabled_features'] ?? [ 'subject_line', 'email_body', 'send_time', 'product_recs', 'segment_suggestions' ];
        $budget  = (int) ( $ai_settings['monthly_token_budget'] ?? 500000 );

        $provider    = new \Apotheca\Marketing\AI\OpenAIProvider();
        $used_tokens = $provider->get_monthly_usage();
        $used_cost   = $provider->get_monthly_cost();
        $budget_pct  = $budget > 0 ? round( $used_tokens / $budget * 100, 1 ) : 0;

        $all_features = [
            'subject_line'        => 'Subject Line Generator',
            'email_body'          => 'Email Body Generator',
            'send_time'           => 'Send-Time Optimisation',
            'product_recs'        => 'Product Recommendations',
            'segment_suggestions' => 'Segment Suggestions',
        ];
        ?>
        <div class="ams-tab-content" id="ams-tab-ai">
            <form id="ams-ai-settings-form">
                <h2><?php esc_html_e( 'AI Settings', 'apotheca-marketing-suite' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="ams_openai_api_key"><?php esc_html_e( 'OpenAI API Key', 'apotheca-marketing-suite' ); ?></label>
                        </th>
                        <td>
                            <input type="password" id="ams_openai_api_key" name="openai_api_key"
                                   value="<?php echo $has_key ? '••••••••' : ''; ?>"
                                   class="regular-text" autocomplete="new-password" />
                            <p class="description"><?php esc_html_e( 'Your OpenAI API key. Stored encrypted.', 'apotheca-marketing-suite' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Enabled Features', 'apotheca-marketing-suite' ); ?></th>
                        <td>
                            <?php foreach ( $all_features as $key => $label ) : ?>
                                <label style="display:block;margin-bottom:6px;">
                                    <input type="checkbox" name="enabled_features[]" value="<?php echo esc_attr( $key ); ?>"
                                        <?php checked( in_array( $key, $enabled, true ) ); ?> />
                                    <?php echo esc_html( $label ); ?>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ams_ai_budget"><?php esc_html_e( 'Monthly Token Budget', 'apotheca-marketing-suite' ); ?></label>
                        </th>
                        <td>
                            <input type="number" id="ams_ai_budget" name="monthly_token_budget"
                                   value="<?php echo esc_attr( $budget ); ?>"
                                   min="0" step="10000" class="regular-text" />
                            <p class="description"><?php esc_html_e( 'Set to 0 for unlimited. Warning at 80%, pause at 100%.', 'apotheca-marketing-suite' ); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Save AI Settings', 'apotheca-marketing-suite' ); ?></button>
                    <span id="ams-ai-save-result" style="margin-left:10px;"></span>
                </p>
            </form>

            <hr />

            <h2><?php esc_html_e( 'Usage This Month', 'apotheca-marketing-suite' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Tokens Used', 'apotheca-marketing-suite' ); ?></th>
                    <td>
                        <strong><?php echo number_format( $used_tokens ); ?></strong>
                        <?php if ( $budget > 0 ) : ?>
                            / <?php echo number_format( $budget ); ?> (<?php echo esc_html( $budget_pct ); ?>%)
                            <?php if ( $budget_pct >= 80 ) : ?>
                                <span style="color:#dba617;font-weight:600;margin-left:8px;"><?php esc_html_e( 'Approaching limit', 'apotheca-marketing-suite' ); ?></span>
                            <?php endif; ?>
                            <?php if ( $budget_pct >= 100 ) : ?>
                                <span style="color:#d63638;font-weight:600;margin-left:8px;"><?php esc_html_e( 'Budget exceeded — AI paused', 'apotheca-marketing-suite' ); ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Estimated Cost', 'apotheca-marketing-suite' ); ?></th>
                    <td>$<?php echo number_format( $used_cost, 4 ); ?></td>
                </tr>
            </table>
        </div><!-- #ams-tab-ai -->
        <?php
    }

    /**
     * AJAX handler: save AI settings.
     */
    public function ajax_save_ai_settings(): void {
        check_ajax_referer( 'ams_ai_settings' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'apotheca-marketing-suite' ) );
        }

        // Save API key to ams_settings (encrypted).
        $api_key = sanitize_text_field( wp_unslash( $_POST['openai_api_key'] ?? '' ) );
        $settings = get_option( 'ams_settings', [] );
        if ( ! empty( $api_key ) && '••••••••' !== $api_key ) {
            $settings['openai_api_key'] = \Apotheca\Marketing\AI\OpenAIProvider::encrypt( $api_key );
        }
        update_option( 'ams_settings', $settings );

        // Save AI-specific settings.
        $enabled_features = [];
        if ( isset( $_POST['enabled_features'] ) && is_array( $_POST['enabled_features'] ) ) {
            $valid = [ 'subject_line', 'email_body', 'send_time', 'product_recs', 'segment_suggestions' ];
            foreach ( $_POST['enabled_features'] as $f ) {
                $f = sanitize_text_field( $f );
                if ( in_array( $f, $valid, true ) ) {
                    $enabled_features[] = $f;
                }
            }
        }

        $ai_settings = [
            'enabled_features'     => $enabled_features,
            'monthly_token_budget' => max( 0, (int) ( $_POST['monthly_token_budget'] ?? 500000 ) ),
        ];
        update_option( 'ams_ai_settings', $ai_settings );

        wp_send_json_success( __( 'AI settings saved.', 'apotheca-marketing-suite' ) );
    }

    /**
     * Encrypt a value using AES-256-CBC.
     */
    private function encrypt_secret( string $value ): string {
        if ( empty( $value ) ) {
            return '';
        }

        $key       = substr( hash( 'sha256', AUTH_KEY ), 0, 32 );
        $iv        = openssl_random_pseudo_bytes( openssl_cipher_iv_length( 'aes-256-cbc' ) );
        $encrypted = openssl_encrypt( $value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

        return base64_encode( $iv . $encrypted );
    }

    /**
     * Decrypt a value using AES-256-CBC.
     */
    private function decrypt_secret( string $encrypted ): string {
        if ( empty( $encrypted ) ) {
            return '';
        }

        $key = substr( hash( 'sha256', AUTH_KEY ), 0, 32 );

        $decoded = base64_decode( $encrypted, true );
        if ( false === $decoded ) {
            return '';
        }

        $iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
        if ( strlen( $decoded ) < $iv_length ) {
            return '';
        }

        $iv            = substr( $decoded, 0, $iv_length );
        $encrypted_raw = substr( $decoded, $iv_length );

        $decrypted = openssl_decrypt( $encrypted_raw, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

        return false === $decrypted ? '' : $decrypted;
    }
}
