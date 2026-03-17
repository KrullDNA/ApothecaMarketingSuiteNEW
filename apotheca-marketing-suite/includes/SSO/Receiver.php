<?php

namespace Apotheca\Marketing\SSO;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Receiver {

    public function add_rewrite_rules(): void {
        add_rewrite_rule(
            '^ams-sso/?$',
            'index.php?ams_sso=1',
            'top'
        );
    }

    public function query_vars( array $vars ): array {
        $vars[] = 'ams_sso';
        return $vars;
    }

    public function handle_request(): void {
        if ( ! get_query_var( 'ams_sso' ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $token_param = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $sig_param   = isset( $_GET['sig'] ) ? sanitize_text_field( wp_unslash( $_GET['sig'] ) ) : '';

        if ( empty( $token_param ) || empty( $sig_param ) ) {
            $this->fail( 'missing_params' );
            return;
        }

        // Validate HMAC signature.
        $settings      = get_option( 'ams_settings', [] );
        $shared_secret = $this->decrypt_secret( $settings['sync_shared_secret'] ?? '' );

        if ( empty( $shared_secret ) ) {
            $this->fail( 'not_configured' );
            return;
        }

        $expected_sig = hash_hmac( 'sha256', $token_param, $shared_secret );
        if ( ! hash_equals( $expected_sig, $sig_param ) ) {
            $this->fail( 'invalid_signature' );
            return;
        }

        // Decode token.
        $decoded = base64_decode( $token_param, true );
        if ( false === $decoded ) {
            $this->fail( 'invalid_token' );
            return;
        }

        $token_data = json_decode( $decoded, true );
        if ( ! is_array( $token_data ) ) {
            $this->fail( 'invalid_token' );
            return;
        }

        $user_id = (int) ( $token_data['user_id'] ?? 0 );
        $email   = sanitize_email( $token_data['email'] ?? '' );
        $expires = (int) ( $token_data['expires'] ?? 0 );
        $nonce   = sanitize_text_field( $token_data['nonce'] ?? '' );

        // Validate expiry.
        if ( time() > $expires ) {
            $this->fail( 'expired' );
            return;
        }

        // Validate single-use nonce (transient, 120s).
        $nonce_key = 'ams_sso_nonce_' . $nonce;
        if ( false !== get_transient( $nonce_key ) ) {
            $this->fail( 'nonce_reused' );
            return;
        }
        set_transient( $nonce_key, 1, 120 );

        // Validate email.
        if ( empty( $email ) || ! is_email( $email ) ) {
            $this->fail( 'invalid_email' );
            return;
        }

        // Find or create WP admin user.
        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            $username = sanitize_user( strtok( $email, '@' ), true );
            if ( username_exists( $username ) ) {
                $username .= '_' . wp_rand( 100, 999 );
            }
            $user_id_new = wp_insert_user( [
                'user_login' => $username,
                'user_email' => $email,
                'user_pass'  => wp_generate_password( 24 ),
                'role'       => 'administrator',
            ] );

            if ( is_wp_error( $user_id_new ) ) {
                $this->fail( 'user_creation_failed' );
                return;
            }
            $user = get_user_by( 'ID', $user_id_new );
        }

        // Log the user in.
        wp_set_auth_cookie( $user->ID, true );

        // Redirect to AMS dashboard.
        wp_safe_redirect( admin_url( 'admin.php?page=ams-dashboard' ) );
        exit;
    }

    private function fail( string $reason ): void {
        wp_safe_redirect( wp_login_url() . '?ams_sso_error=' . urlencode( $reason ) );
        exit;
    }

    /**
     * Decrypt the shared secret stored in settings.
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
