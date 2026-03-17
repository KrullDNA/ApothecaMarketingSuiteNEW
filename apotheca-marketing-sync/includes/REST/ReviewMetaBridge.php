<?php

namespace Apotheca\Marketing\Sync\REST;

use Apotheca\Marketing\Sync\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Bridge endpoint for KDNA review meta data.
 *
 * GET /wp-json/ams-bridge/v1/review-meta?ids=1,2,3
 * Requires X-AMS-Signature header (same shared secret).
 */
class ReviewMetaBridge {

    private const NAMESPACE = 'ams-bridge/v1';

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/review-meta', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_review_meta' ],
            'permission_callback' => [ $this, 'verify_signature' ],
            'args'                => [
                'ids' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );
    }

    /**
     * Verify the request HMAC signature.
     */
    public function verify_signature( \WP_REST_Request $request ): bool {
        $signature = $request->get_header( 'X-AMS-Signature' );
        $timestamp = $request->get_header( 'X-AMS-Timestamp' );
        $secret    = Plugin::get_shared_secret();

        if ( empty( $signature ) || empty( $timestamp ) || empty( $secret ) ) {
            return false;
        }

        // Reject if timestamp is older than 5 minutes.
        if ( abs( time() - (int) $timestamp ) > 300 ) {
            return false;
        }

        $ids_param = $request->get_param( 'ids' );
        $expected  = hash_hmac( 'sha256', $ids_param . $timestamp, $secret );

        return hash_equals( $expected, $signature );
    }

    /**
     * Return KDNA review meta for the requested comment IDs.
     */
    public function get_review_meta( \WP_REST_Request $request ): \WP_REST_Response {
        $ids_raw = $request->get_param( 'ids' );
        $ids     = array_filter( array_map( 'absint', explode( ',', $ids_raw ) ) );

        if ( empty( $ids ) ) {
            return new \WP_REST_Response( [], 200 );
        }

        $results = [];
        foreach ( $ids as $comment_id ) {
            $comment = get_comment( $comment_id );
            if ( ! $comment ) {
                continue;
            }

            $results[] = [
                'comment_id'           => $comment_id,
                '_kdna_review_title'   => get_comment_meta( $comment_id, '_kdna_review_title', true ) ?: '',
                '_kdna_attachment_ids' => get_comment_meta( $comment_id, '_kdna_attachment_ids', true ) ?: '',
                '_kdna_video_url'      => get_comment_meta( $comment_id, '_kdna_video_url', true ) ?: '',
                '_kdna_positive_votes' => (int) get_comment_meta( $comment_id, '_kdna_positive_votes', true ),
                '_kdna_negative_votes' => (int) get_comment_meta( $comment_id, '_kdna_negative_votes', true ),
                'rating'               => (int) get_comment_meta( $comment_id, 'rating', true ),
            ];
        }

        return new \WP_REST_Response( $results, 200 );
    }
}
