<?php

namespace Apotheca\Marketing\AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * OpenAI API provider.
 *
 * All calls go through wp_remote_post to the OpenAI chat completions endpoint.
 * API key is encrypted in ams_settings.openai_api_key.
 * Every call is logged to ams_ai_log with token usage and cost.
 */
class OpenAIProvider {

    private const API_URL = 'https://api.openai.com/v1/chat/completions';
    private const MODEL   = 'gpt-4o';

    // Approximate cost per 1K tokens (input/output blended).
    private const COST_PER_1K = 0.005;

    /**
     * Send a chat completion request to OpenAI.
     *
     * @param string $system_prompt System message.
     * @param string $user_prompt   User message.
     * @param string $feature       Feature name for logging.
     * @param int    $subscriber_id Optional subscriber context.
     * @param float  $temperature   Temperature (0-2).
     * @param int    $max_tokens    Max response tokens.
     * @return array{success: bool, content: string, tokens: int, error: string}
     */
    public function chat(
        string $system_prompt,
        string $user_prompt,
        string $feature = 'general',
        int $subscriber_id = 0,
        float $temperature = 0.7,
        int $max_tokens = 1000
    ): array {
        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            return [ 'success' => false, 'content' => '', 'tokens' => 0, 'error' => 'OpenAI API key not configured.' ];
        }

        // Check if feature is enabled.
        if ( ! $this->is_feature_enabled( $feature ) ) {
            return [ 'success' => false, 'content' => '', 'tokens' => 0, 'error' => "Feature '{$feature}' is disabled." ];
        }

        // Check budget.
        if ( $this->is_budget_exceeded() ) {
            return [ 'success' => false, 'content' => '', 'tokens' => 0, 'error' => 'Monthly AI token budget exceeded.' ];
        }

        $response = wp_remote_post( self::API_URL, [
            'timeout' => 60,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body'    => wp_json_encode( [
                'model'       => self::MODEL,
                'messages'    => [
                    [ 'role' => 'system', 'content' => $system_prompt ],
                    [ 'role' => 'user', 'content' => $user_prompt ],
                ],
                'temperature' => $temperature,
                'max_tokens'  => $max_tokens,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'content' => '', 'tokens' => 0, 'error' => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            $err = $body['error']['message'] ?? 'API error (HTTP ' . $code . ')';
            return [ 'success' => false, 'content' => '', 'tokens' => 0, 'error' => $err ];
        }

        $content = $body['choices'][0]['message']['content'] ?? '';
        $tokens  = (int) ( $body['usage']['total_tokens'] ?? 0 );
        $cost    = round( $tokens / 1000 * self::COST_PER_1K, 4 );

        // Log the call.
        $this->log( $feature, $user_prompt, $content, $tokens, $cost, $subscriber_id );

        return [ 'success' => true, 'content' => $content, 'tokens' => $tokens, 'error' => '' ];
    }

    /**
     * Get the decrypted OpenAI API key.
     */
    private function get_api_key(): string {
        $settings  = get_option( 'ams_settings', [] );
        $encrypted = $settings['openai_api_key'] ?? '';
        if ( empty( $encrypted ) ) {
            return '';
        }
        return $this->decrypt( $encrypted );
    }

    /**
     * Check if a feature is enabled.
     */
    private function is_feature_enabled( string $feature ): bool {
        $ai_settings = get_option( 'ams_ai_settings', [] );
        $enabled     = $ai_settings['enabled_features'] ?? [];

        // If no features array is set, all are enabled by default.
        if ( empty( $enabled ) ) {
            return true;
        }

        return in_array( $feature, $enabled, true );
    }

    /**
     * Check if monthly token budget is exceeded.
     */
    private function is_budget_exceeded(): bool {
        $ai_settings = get_option( 'ams_ai_settings', [] );
        $budget      = (int) ( $ai_settings['monthly_token_budget'] ?? 0 );

        if ( $budget <= 0 ) {
            return false; // No budget limit.
        }

        $used = $this->get_monthly_usage();
        return $used >= $budget;
    }

    /**
     * Get tokens used in the current month.
     */
    public function get_monthly_usage(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_ai_log';

        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( ! $table_exists ) {
            return 0;
        }

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(tokens_used), 0) FROM {$table}
             WHERE created_at >= %s",
            gmdate( 'Y-m-01 00:00:00' )
        ) );
    }

    /**
     * Get monthly cost.
     */
    public function get_monthly_cost(): float {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_ai_log';

        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( ! $table_exists ) {
            return 0.0;
        }

        return (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(cost_usd), 0) FROM {$table}
             WHERE created_at >= %s",
            gmdate( 'Y-m-01 00:00:00' )
        ) );
    }

    /**
     * Log an AI call to ams_ai_log.
     */
    private function log( string $feature, string $input, string $output, int $tokens, float $cost, int $subscriber_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_ai_log';

        $wpdb->insert( $table, [
            'feature'        => sanitize_text_field( $feature ),
            'input_summary'  => mb_substr( $input, 0, 500 ),
            'output_summary' => mb_substr( $output, 0, 1000 ),
            'tokens_used'    => $tokens,
            'cost_usd'       => $cost,
            'subscriber_id'  => $subscriber_id ?: null,
            'created_at'     => current_time( 'mysql', true ),
        ] );
    }

    /**
     * Decrypt using AES-256-CBC (same key derivation as SettingsPage).
     */
    private function decrypt( string $encrypted ): string {
        if ( empty( $encrypted ) ) {
            return '';
        }
        $key     = substr( hash( 'sha256', AUTH_KEY ), 0, 32 );
        $decoded = base64_decode( $encrypted, true );
        if ( false === $decoded ) {
            return '';
        }
        $iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
        if ( strlen( $decoded ) < $iv_length ) {
            return '';
        }
        $iv  = substr( $decoded, 0, $iv_length );
        $raw = substr( $decoded, $iv_length );
        $dec = openssl_decrypt( $raw, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
        return false === $dec ? '' : $dec;
    }

    /**
     * Encrypt using AES-256-CBC.
     */
    public static function encrypt( string $value ): string {
        if ( empty( $value ) ) {
            return '';
        }
        $key = substr( hash( 'sha256', AUTH_KEY ), 0, 32 );
        $iv  = openssl_random_pseudo_bytes( openssl_cipher_iv_length( 'aes-256-cbc' ) );
        $enc = openssl_encrypt( $value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
        return base64_encode( $iv . $enc );
    }
}
