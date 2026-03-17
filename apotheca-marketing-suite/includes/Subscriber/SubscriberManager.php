<?php

namespace Apotheca\Marketing\Subscriber;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SubscriberManager {

    /**
     * Create or update a subscriber by email.
     *
     * @return int Subscriber ID.
     */
    public function create_or_update( string $email, array $data = [] ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_subscribers';

        $email = sanitize_email( strtolower( trim( $email ) ) );
        if ( ! is_email( $email ) ) {
            return 0;
        }

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE email = %s",
            $email
        ) );

        $fields = [
            'first_name'  => sanitize_text_field( $data['first_name'] ?? '' ),
            'last_name'   => sanitize_text_field( $data['last_name'] ?? '' ),
            'phone'       => sanitize_text_field( $data['phone'] ?? '' ),
            'source'      => sanitize_text_field( $data['source'] ?? 'sync_order' ),
            'status'      => sanitize_text_field( $data['status'] ?? 'active' ),
            'updated_at'  => current_time( 'mysql', true ),
        ];

        if ( ! empty( $data['gdpr_consent'] ) ) {
            $fields['gdpr_consent']   = 1;
            $fields['gdpr_timestamp'] = current_time( 'mysql', true );
        }
        if ( isset( $data['sms_opt_in'] ) ) {
            $fields['sms_opt_in'] = (int) $data['sms_opt_in'];
        }
        if ( isset( $data['tags'] ) ) {
            $fields['tags'] = wp_json_encode( $data['tags'] );
        }
        if ( isset( $data['custom_fields'] ) ) {
            $fields['custom_fields'] = wp_json_encode( $data['custom_fields'] );
        }

        if ( $existing ) {
            // Update — don't overwrite source or empty fields.
            $update = array_filter( $fields, fn( $v ) => '' !== $v && null !== $v );
            unset( $update['source'] ); // Preserve original source.
            $wpdb->update( $table, $update, [ 'id' => $existing->id ] );
            return (int) $existing->id;
        }

        // Insert new subscriber.
        $fields['email']             = $email;
        $fields['unsubscribe_token'] = $this->generate_token();
        $fields['subscriber_token']  = $this->generate_token();
        $fields['created_at']        = current_time( 'mysql', true );

        $wpdb->insert( $table, $fields );
        return (int) $wpdb->insert_id;
    }

    /**
     * Find subscriber by email.
     */
    public function find_by_email( string $email ): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_subscribers';
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE email = %s",
            sanitize_email( strtolower( trim( $email ) ) )
        ) );
    }

    /**
     * Find subscriber by subscriber_token.
     */
    public function find_by_token( string $token ): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_subscribers';
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE subscriber_token = %s",
            sanitize_text_field( $token )
        ) );
    }

    /**
     * Find subscriber by unsubscribe_token.
     */
    public function find_by_unsubscribe_token( string $token ): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_subscribers';
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE unsubscribe_token = %s",
            sanitize_text_field( $token )
        ) );
    }

    /**
     * Get a subscriber by ID.
     */
    public function get( int $id ): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_subscribers';
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $id
        ) );
    }

    /**
     * Increment order stats after an order is placed.
     */
    public function increment_order_stats( int $subscriber_id, float $order_total, string $order_date ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_subscribers';
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET
                total_orders = total_orders + 1,
                total_spent = total_spent + %f,
                last_order_date = %s,
                updated_at = %s
            WHERE id = %d",
            $order_total,
            $order_date,
            current_time( 'mysql', true ),
            $subscriber_id
        ) );
    }

    /**
     * Unsubscribe a subscriber by token.
     */
    public function unsubscribe_by_token( string $token ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_subscribers';
        $updated = $wpdb->update(
            $table,
            [ 'status' => 'unsubscribed', 'updated_at' => current_time( 'mysql', true ) ],
            [ 'unsubscribe_token' => sanitize_text_field( $token ) ]
        );
        return false !== $updated && $updated > 0;
    }

    /**
     * List subscribers with pagination and filtering.
     */
    public function list_subscribers( array $args = [] ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_subscribers';

        $per_page = absint( $args['per_page'] ?? 25 );
        $page     = max( 1, absint( $args['page'] ?? 1 ) );
        $offset   = ( $page - 1 ) * $per_page;
        $search   = sanitize_text_field( $args['search'] ?? '' );
        $status   = sanitize_text_field( $args['status'] ?? '' );
        $orderby  = in_array( ( $args['orderby'] ?? '' ), [ 'email', 'created_at', 'total_spent', 'total_orders', 'last_order_date', 'rfm_segment' ], true )
            ? $args['orderby'] : 'created_at';
        $order    = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';

        $where = '1=1';
        $params = [];

        if ( $search ) {
            $like   = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= $wpdb->prepare( ' AND (email LIKE %s OR first_name LIKE %s OR last_name LIKE %s)', $like, $like, $like );
        }
        if ( $status ) {
            $where .= $wpdb->prepare( ' AND status = %s', $status );
        }

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );

        $rows = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order} LIMIT {$per_page} OFFSET {$offset}"
        );

        return [
            'items'    => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => (int) ceil( $total / $per_page ),
        ];
    }

    /**
     * Import subscribers from CSV data.
     *
     * @param array $rows     Array of associative arrays with field mappings.
     * @param array $mapping  Column name => subscriber field mapping.
     * @return array{ imported: int, updated: int, skipped: int }
     */
    public function import_csv( array $rows, array $mapping ): array {
        $stats = [ 'imported' => 0, 'updated' => 0, 'skipped' => 0 ];

        foreach ( $rows as $row ) {
            $data  = [];
            $email = '';

            foreach ( $mapping as $csv_col => $field ) {
                $value = sanitize_text_field( $row[ $csv_col ] ?? '' );
                if ( 'email' === $field ) {
                    $email = $value;
                } else {
                    $data[ $field ] = $value;
                }
            }

            if ( ! is_email( $email ) ) {
                $stats['skipped']++;
                continue;
            }

            $data['source'] = $data['source'] ?? 'import';
            $existing       = $this->find_by_email( $email );
            $this->create_or_update( $email, $data );

            if ( $existing ) {
                $stats['updated']++;
            } else {
                $stats['imported']++;
            }
        }

        return $stats;
    }

    /**
     * Export subscribers as CSV-compatible array.
     */
    public function export_csv( array $args = [] ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_subscribers';

        $where = '1=1';
        $status = sanitize_text_field( $args['status'] ?? '' );
        if ( $status ) {
            $where .= $wpdb->prepare( ' AND status = %s', $status );
        }

        return $wpdb->get_results(
            "SELECT email, phone, first_name, last_name, status, source, gdpr_consent,
                    sms_opt_in, total_orders, total_spent, last_order_date,
                    rfm_score, rfm_segment, predicted_clv, churn_risk_score,
                    created_at
             FROM {$table} WHERE {$where} ORDER BY created_at DESC",
            ARRAY_A
        );
    }

    /**
     * Generate a unique random token.
     */
    private function generate_token(): string {
        return bin2hex( random_bytes( 32 ) );
    }
}
