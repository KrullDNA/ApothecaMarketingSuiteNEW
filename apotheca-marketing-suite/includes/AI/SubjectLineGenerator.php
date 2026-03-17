<?php

namespace Apotheca\Marketing\AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AI-powered subject line generator.
 *
 * Takes brand name, email body summary, and segment name.
 * Returns 5 subject line options with emoji variants.
 * Runs asynchronously via Action Scheduler.
 */
class SubjectLineGenerator {

    private const HOOK = 'ams_ai_generate_subject_lines';

    public function register(): void {
        add_action( self::HOOK, [ $this, 'process' ], 10, 2 );
    }

    /**
     * Schedule an async subject line generation.
     *
     * @return string Job key for polling results via transient.
     */
    public function schedule( string $body_summary, string $segment_name = '' ): string {
        $key = 'ams_ai_sl_' . wp_generate_password( 12, false );

        set_transient( $key, [ 'status' => 'processing' ], HOUR_IN_SECONDS );

        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action( time(), self::HOOK, [
                $key,
                [
                    'body_summary' => $body_summary,
                    'segment_name' => $segment_name,
                ],
            ], 'ams_ai' );
        } else {
            // Fallback: process synchronously.
            $this->process( $key, [
                'body_summary' => $body_summary,
                'segment_name' => $segment_name,
            ] );
        }

        return $key;
    }

    /**
     * Process the generation (called by Action Scheduler).
     */
    public function process( string $key, array $data ): void {
        $brand   = get_bloginfo( 'name' );
        $body    = $data['body_summary'] ?? '';
        $segment = $data['segment_name'] ?? '';

        $system = 'You are an email marketing expert. Generate exactly 5 email subject lines for the given context. '
            . 'For each subject line, provide a version with emoji and one without. '
            . 'Return JSON array: [{"plain":"...","emoji":"..."},...]';

        $user = "Brand: {$brand}\n"
            . "Email body summary: {$body}\n"
            . ( $segment ? "Target segment: {$segment}\n" : '' )
            . 'Generate 5 compelling subject lines.';

        $provider = new OpenAIProvider();
        $result   = $provider->chat( $system, $user, 'subject_line', 0, 0.8, 600 );

        if ( $result['success'] ) {
            $parsed = json_decode( $result['content'], true );
            if ( ! is_array( $parsed ) ) {
                // Try to extract JSON from markdown code block.
                if ( preg_match( '/\[.*\]/s', $result['content'], $m ) ) {
                    $parsed = json_decode( $m[0], true );
                }
            }
            set_transient( $key, [
                'status'  => 'complete',
                'options' => is_array( $parsed ) ? $parsed : [],
            ], HOUR_IN_SECONDS );
        } else {
            set_transient( $key, [
                'status' => 'error',
                'error'  => $result['error'],
            ], HOUR_IN_SECONDS );
        }
    }

    /**
     * Get the result of a scheduled generation.
     */
    public function get_result( string $key ): array {
        $data = get_transient( $key );
        if ( ! $data ) {
            return [ 'status' => 'not_found' ];
        }
        return $data;
    }
}
