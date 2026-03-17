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
            /* Hide SMS tab by default */
            var smsTab = document.getElementById('ams-tab-sms');
            if (smsTab) smsTab.style.display = 'none';

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
