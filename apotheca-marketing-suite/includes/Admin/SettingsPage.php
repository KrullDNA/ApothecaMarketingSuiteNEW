<?php

namespace Apotheca\Marketing\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SettingsPage {

    public function __construct() {
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'wp_ajax_ams_test_connection', [ $this, 'ajax_test_connection' ] );
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

            <h2 class="nav-tab-wrapper">
                <a href="#sync" class="nav-tab nav-tab-active"><?php esc_html_e( 'Sync', 'apotheca-marketing-suite' ); ?></a>
            </h2>

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
        </div>

        <script>
        (function() {
            // Copy endpoint URL.
            var copyBtn = document.getElementById('ams-copy-endpoint');
            if (copyBtn) {
                copyBtn.addEventListener('click', function() {
                    var url = document.getElementById('ams-ingest-url').textContent;
                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(url).then(function() {
                            copyBtn.textContent = '<?php echo esc_js( __( 'Copied!', 'apotheca-marketing-suite' ) ); ?>';
                            setTimeout(function() { copyBtn.textContent = '<?php echo esc_js( __( 'Copy', 'apotheca-marketing-suite' ) ); ?>'; }, 2000);
                        });
                    }
                });
            }

            // Test connection.
            var testBtn = document.getElementById('ams-test-connection');
            if (testBtn) {
                testBtn.addEventListener('click', function() {
                    var result = document.getElementById('ams-test-result');
                    result.textContent = '<?php echo esc_js( __( 'Testing...', 'apotheca-marketing-suite' ) ); ?>';
                    testBtn.disabled = true;

                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>');
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function() {
                        testBtn.disabled = false;
                        try {
                            var data = JSON.parse(xhr.responseText);
                            if (data.success) {
                                result.textContent = '<?php echo esc_js( __( 'Connection successful!', 'apotheca-marketing-suite' ) ); ?>';
                                result.style.color = 'green';
                            } else {
                                result.textContent = data.data || '<?php echo esc_js( __( 'Connection failed.', 'apotheca-marketing-suite' ) ); ?>';
                                result.style.color = 'red';
                            }
                        } catch(e) {
                            result.textContent = '<?php echo esc_js( __( 'Unexpected response.', 'apotheca-marketing-suite' ) ); ?>';
                            result.style.color = 'red';
                        }
                    };
                    xhr.onerror = function() {
                        testBtn.disabled = false;
                        result.textContent = '<?php echo esc_js( __( 'Network error.', 'apotheca-marketing-suite' ) ); ?>';
                        result.style.color = 'red';
                    };
                    xhr.send('action=ams_test_connection&_wpnonce=<?php echo esc_js( wp_create_nonce( 'ams_test_connection' ) ); ?>');
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
