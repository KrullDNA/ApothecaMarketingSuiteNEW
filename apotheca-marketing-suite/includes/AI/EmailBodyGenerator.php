<?php

namespace Apotheca\Marketing\AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AI-powered email body generator.
 *
 * Inputs: goal, tone, key message, product IDs to feature.
 * Returns: preheader, headline, body paragraphs, CTA text.
 * Runs asynchronously via Action Scheduler.
 */
class EmailBodyGenerator {

    private const HOOK = 'ams_ai_generate_email_body';

    public function register(): void {
        add_action( self::HOOK, [ $this, 'process' ], 10, 2 );
    }

    /**
     * Schedule an async email body generation.
     */
    public function schedule( array $params ): string {
        $key = 'ams_ai_eb_' . wp_generate_password( 12, false );

        set_transient( $key, [ 'status' => 'processing' ], HOUR_IN_SECONDS );

        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action( time(), self::HOOK, [ $key, $params ], 'ams_ai' );
        } else {
            $this->process( $key, $params );
        }

        return $key;
    }

    /**
     * Process the generation (called by Action Scheduler).
     */
    public function process( string $key, array $params ): void {
        $brand = get_bloginfo( 'name' );
        $goal  = sanitize_text_field( $params['goal'] ?? 'welcome' );
        $tone  = sanitize_text_field( $params['tone'] ?? 'friendly' );
        $msg   = sanitize_text_field( $params['key_message'] ?? '' );
        $prods = $params['product_names'] ?? [];

        $valid_goals = [ 'welcome', 'promo', 'winback', 'launch', 'newsletter' ];
        if ( ! in_array( $goal, $valid_goals, true ) ) {
            $goal = 'welcome';
        }

        $valid_tones = [ 'friendly', 'professional', 'urgent', 'playful' ];
        if ( ! in_array( $tone, $valid_tones, true ) ) {
            $tone = 'friendly';
        }

        $system = 'You are an email copywriter for e-commerce brands. '
            . 'Generate email content as a JSON object with these keys: '
            . '"preheader" (max 100 chars), "headline", "body" (array of paragraph strings), "cta_text" (call to action button text). '
            . 'Return only valid JSON, no markdown.';

        $user = "Brand: {$brand}\n"
            . "Goal: {$goal}\n"
            . "Tone: {$tone}\n"
            . ( $msg ? "Key message: {$msg}\n" : '' )
            . ( ! empty( $prods ) ? 'Featured products: ' . implode( ', ', array_map( 'sanitize_text_field', $prods ) ) . "\n" : '' )
            . 'Generate the email content.';

        $provider = new OpenAIProvider();
        $result   = $provider->chat( $system, $user, 'email_body', 0, 0.7, 1000 );

        if ( $result['success'] ) {
            $parsed = json_decode( $result['content'], true );
            if ( ! is_array( $parsed ) ) {
                if ( preg_match( '/\{.*\}/s', $result['content'], $m ) ) {
                    $parsed = json_decode( $m[0], true );
                }
            }
            set_transient( $key, [
                'status'  => 'complete',
                'content' => is_array( $parsed ) ? $parsed : [ 'headline' => '', 'preheader' => '', 'body' => [], 'cta_text' => '' ],
            ], HOUR_IN_SECONDS );
        } else {
            set_transient( $key, [
                'status' => 'error',
                'error'  => $result['error'],
            ], HOUR_IN_SECONDS );
        }
    }

    /**
     * Get the result.
     */
    public function get_result( string $key ): array {
        $data = get_transient( $key );
        if ( ! $data ) {
            return [ 'status' => 'not_found' ];
        }
        return $data;
    }
}
