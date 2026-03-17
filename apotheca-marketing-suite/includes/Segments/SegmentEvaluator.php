<?php

namespace Apotheca\Marketing\Segments;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SegmentEvaluator {

    /**
     * Evaluate a conditions tree against a subscriber.
     *
     * The conditions tree structure:
     * {
     *   "match": "all" | "any",  // AND or OR
     *   "rules": [
     *     { "field": "...", "operator": "...", "value": "..." },
     *     { "match": "all", "rules": [...] }  // Nested group (up to 3 levels)
     *   ]
     * }
     *
     * @return bool Whether the subscriber matches all conditions.
     */
    public function evaluate( object $subscriber, array $conditions, int $depth = 0 ): bool {
        if ( empty( $conditions ) || empty( $conditions['rules'] ) ) {
            return true;
        }

        // Max 3 levels of nesting.
        if ( $depth > 2 ) {
            return true;
        }

        $match = $conditions['match'] ?? 'all';
        $rules = $conditions['rules'] ?? [];

        foreach ( $rules as $rule ) {
            // Nested group.
            if ( isset( $rule['match'] ) && isset( $rule['rules'] ) ) {
                $result = $this->evaluate( $subscriber, $rule, $depth + 1 );
            } else {
                $result = $this->evaluate_rule( $subscriber, $rule );
            }

            if ( 'any' === $match && $result ) {
                return true;
            }
            if ( 'all' === $match && ! $result ) {
                return false;
            }
        }

        return 'all' === $match;
    }

    /**
     * Evaluate a single rule against a subscriber.
     */
    private function evaluate_rule( object $subscriber, array $rule ): bool {
        $field    = $rule['field'] ?? '';
        $operator = $rule['operator'] ?? '';
        $value    = $rule['value'] ?? '';

        // Route to the appropriate evaluator category.
        if ( str_starts_with( $field, 'subscriber.' ) ) {
            return $this->evaluate_subscriber_field( $subscriber, substr( $field, 11 ), $operator, $value );
        }
        if ( str_starts_with( $field, 'woo.' ) ) {
            return $this->evaluate_woo_field( $subscriber, substr( $field, 4 ), $operator, $value );
        }
        if ( str_starts_with( $field, 'engagement.' ) ) {
            return $this->evaluate_engagement_field( $subscriber, substr( $field, 11 ), $operator, $value );
        }
        if ( str_starts_with( $field, 'rfm.' ) ) {
            return $this->evaluate_rfm_field( $subscriber, substr( $field, 4 ), $operator, $value );
        }
        if ( str_starts_with( $field, 'tag.' ) ) {
            return $this->evaluate_tag_field( $subscriber, $operator, $value );
        }
        if ( str_starts_with( $field, 'custom.' ) ) {
            return $this->evaluate_custom_field( $subscriber, substr( $field, 7 ), $operator, $value );
        }

        return false;
    }

    /**
     * Subscriber field conditions.
     * Fields: email, first_name, last_name, phone, status, source, created_at, gdpr_consent, sms_opt_in
     */
    private function evaluate_subscriber_field( object $subscriber, string $field, string $operator, mixed $value ): bool {
        $allowed = [ 'email', 'first_name', 'last_name', 'phone', 'status', 'source', 'created_at', 'gdpr_consent', 'sms_opt_in' ];
        if ( ! in_array( $field, $allowed, true ) ) {
            return false;
        }

        $actual = $subscriber->$field ?? '';

        // Date fields.
        if ( 'created_at' === $field ) {
            return $this->compare_date( $actual, $operator, $value );
        }

        // Boolean fields.
        if ( in_array( $field, [ 'gdpr_consent', 'sms_opt_in' ], true ) ) {
            return $this->compare_boolean( (int) $actual, $operator, $value );
        }

        return $this->compare_string( (string) $actual, $operator, $value );
    }

    /**
     * WooCommerce / order conditions.
     * Fields: total_orders, total_spent, last_order_date, avg_order_value, days_since_order
     */
    private function evaluate_woo_field( object $subscriber, string $field, string $operator, mixed $value ): bool {
        switch ( $field ) {
            case 'total_orders':
                return $this->compare_numeric( (int) ( $subscriber->total_orders ?? 0 ), $operator, $value );

            case 'total_spent':
                return $this->compare_numeric( (float) ( $subscriber->total_spent ?? 0 ), $operator, $value );

            case 'last_order_date':
                return $this->compare_date( $subscriber->last_order_date ?? '', $operator, $value );

            case 'avg_order_value':
                $orders = (int) ( $subscriber->total_orders ?? 0 );
                $spent  = (float) ( $subscriber->total_spent ?? 0 );
                $aov    = $orders > 0 ? $spent / $orders : 0;
                return $this->compare_numeric( $aov, $operator, $value );

            case 'days_since_order':
                $last = $subscriber->last_order_date ?? '';
                if ( ! $last ) {
                    return $this->compare_numeric( 9999, $operator, $value );
                }
                $days = max( 0, (int) floor( ( time() - strtotime( $last ) ) / DAY_IN_SECONDS ) );
                return $this->compare_numeric( $days, $operator, $value );

            case 'has_ordered':
                $has = (int) ( $subscriber->total_orders ?? 0 ) > 0;
                return $this->compare_boolean( $has, $operator, $value );
        }

        return false;
    }

    /**
     * Engagement conditions (queries ams_sends and ams_events).
     * Fields: email_opens, email_clicks, email_sent, last_open_days, last_click_days, last_email_days,
     *         event_count, has_event_type
     */
    private function evaluate_engagement_field( object $subscriber, string $field, string $operator, mixed $value ): bool {
        global $wpdb;
        $sub_id = (int) $subscriber->id;

        switch ( $field ) {
            case 'email_opens':
                $count = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}ams_sends WHERE subscriber_id = %d AND opened_at IS NOT NULL",
                    $sub_id
                ) );
                return $this->compare_numeric( $count, $operator, $value );

            case 'email_clicks':
                $count = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}ams_sends WHERE subscriber_id = %d AND clicked_at IS NOT NULL",
                    $sub_id
                ) );
                return $this->compare_numeric( $count, $operator, $value );

            case 'email_sent':
                $count = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}ams_sends WHERE subscriber_id = %d AND channel = 'email' AND sent_at IS NOT NULL",
                    $sub_id
                ) );
                return $this->compare_numeric( $count, $operator, $value );

            case 'last_open_days':
                $last = $wpdb->get_var( $wpdb->prepare(
                    "SELECT MAX(opened_at) FROM {$wpdb->prefix}ams_sends WHERE subscriber_id = %d AND opened_at IS NOT NULL",
                    $sub_id
                ) );
                $days = $last ? max( 0, (int) floor( ( time() - strtotime( $last ) ) / DAY_IN_SECONDS ) ) : 9999;
                return $this->compare_numeric( $days, $operator, $value );

            case 'last_click_days':
                $last = $wpdb->get_var( $wpdb->prepare(
                    "SELECT MAX(clicked_at) FROM {$wpdb->prefix}ams_sends WHERE subscriber_id = %d AND clicked_at IS NOT NULL",
                    $sub_id
                ) );
                $days = $last ? max( 0, (int) floor( ( time() - strtotime( $last ) ) / DAY_IN_SECONDS ) ) : 9999;
                return $this->compare_numeric( $days, $operator, $value );

            case 'last_email_days':
                $last = $wpdb->get_var( $wpdb->prepare(
                    "SELECT MAX(sent_at) FROM {$wpdb->prefix}ams_sends WHERE subscriber_id = %d AND channel = 'email' AND sent_at IS NOT NULL",
                    $sub_id
                ) );
                $days = $last ? max( 0, (int) floor( ( time() - strtotime( $last ) ) / DAY_IN_SECONDS ) ) : 9999;
                return $this->compare_numeric( $days, $operator, $value );

            case 'event_count':
                $count = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}ams_events WHERE subscriber_id = %d",
                    $sub_id
                ) );
                return $this->compare_numeric( $count, $operator, $value );

            case 'has_event_type':
                $count = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}ams_events WHERE subscriber_id = %d AND event_type = %s",
                    $sub_id,
                    sanitize_text_field( $value )
                ) );
                return 'equals' === $operator ? $count > 0 : 0 === $count;
        }

        return false;
    }

    /**
     * RFM conditions.
     * Fields: segment, score, recency, frequency, monetary, churn_risk, predicted_clv
     */
    private function evaluate_rfm_field( object $subscriber, string $field, string $operator, mixed $value ): bool {
        switch ( $field ) {
            case 'segment':
                return $this->compare_string( (string) ( $subscriber->rfm_segment ?? '' ), $operator, $value );

            case 'score':
                return $this->compare_string( (string) ( $subscriber->rfm_score ?? '' ), $operator, $value );

            case 'recency':
                $score = (string) ( $subscriber->rfm_score ?? '111' );
                return $this->compare_numeric( (int) ( $score[0] ?? 1 ), $operator, $value );

            case 'frequency':
                $score = (string) ( $subscriber->rfm_score ?? '111' );
                return $this->compare_numeric( (int) ( $score[1] ?? 1 ), $operator, $value );

            case 'monetary':
                $score = (string) ( $subscriber->rfm_score ?? '111' );
                return $this->compare_numeric( (int) ( $score[2] ?? 1 ), $operator, $value );

            case 'churn_risk':
                return $this->compare_numeric( (int) ( $subscriber->churn_risk_score ?? 0 ), $operator, $value );

            case 'predicted_clv':
                return $this->compare_numeric( (float) ( $subscriber->predicted_clv ?? 0 ), $operator, $value );
        }

        return false;
    }

    /**
     * Tag conditions.
     */
    private function evaluate_tag_field( object $subscriber, string $operator, mixed $value ): bool {
        $tags = json_decode( $subscriber->tags ?? '[]', true ) ?: [];

        return match ( $operator ) {
            'contains'     => in_array( $value, $tags, true ),
            'not_contains' => ! in_array( $value, $tags, true ),
            'is_empty'     => empty( $tags ),
            'is_not_empty' => ! empty( $tags ),
            default        => false,
        };
    }

    /**
     * Custom field conditions.
     */
    private function evaluate_custom_field( object $subscriber, string $field, string $operator, mixed $value ): bool {
        $custom = json_decode( $subscriber->custom_fields ?? '{}', true ) ?: [];
        $actual = $custom[ $field ] ?? '';

        return $this->compare_string( (string) $actual, $operator, $value );
    }

    /**
     * String comparison operators.
     */
    private function compare_string( string $actual, string $operator, mixed $value ): bool {
        $value = (string) $value;

        return match ( $operator ) {
            'equals'          => $actual === $value,
            'not_equals'      => $actual !== $value,
            'contains'        => str_contains( strtolower( $actual ), strtolower( $value ) ),
            'not_contains'    => ! str_contains( strtolower( $actual ), strtolower( $value ) ),
            'starts_with'     => str_starts_with( strtolower( $actual ), strtolower( $value ) ),
            'ends_with'       => str_ends_with( strtolower( $actual ), strtolower( $value ) ),
            'is_empty'        => '' === $actual,
            'is_not_empty'    => '' !== $actual,
            'in'              => in_array( $actual, array_map( 'trim', explode( ',', $value ) ), true ),
            'not_in'          => ! in_array( $actual, array_map( 'trim', explode( ',', $value ) ), true ),
            default           => false,
        };
    }

    /**
     * Numeric comparison operators.
     */
    private function compare_numeric( float|int $actual, string $operator, mixed $value ): bool {
        $value = (float) $value;

        return match ( $operator ) {
            'equals', 'equal'        => abs( $actual - $value ) < 0.001,
            'not_equals', 'not_equal' => abs( $actual - $value ) >= 0.001,
            'greater_than'           => $actual > $value,
            'less_than'              => $actual < $value,
            'greater_or_equal'       => $actual >= $value,
            'less_or_equal'          => $actual <= $value,
            'between'                => $this->between( $actual, $value, $value ),
            default                  => false,
        };
    }

    /**
     * Date comparison operators.
     */
    private function compare_date( string $actual, string $operator, mixed $value ): bool {
        if ( ! $actual && in_array( $operator, [ 'is_empty', 'is_not_set' ], true ) ) {
            return true;
        }
        if ( ! $actual ) {
            return 'is_not_empty' === $operator ? false : false;
        }

        $actual_ts = strtotime( $actual );
        $now       = time();

        return match ( $operator ) {
            'before'        => $actual_ts < strtotime( $value ),
            'after'         => $actual_ts > strtotime( $value ),
            'on'            => date( 'Y-m-d', $actual_ts ) === date( 'Y-m-d', strtotime( $value ) ),
            'in_last_days'  => $actual_ts >= ( $now - ( (int) $value * DAY_IN_SECONDS ) ),
            'not_in_last_days' => $actual_ts < ( $now - ( (int) $value * DAY_IN_SECONDS ) ),
            'is_empty'      => false,
            'is_not_empty'  => true,
            default         => false,
        };
    }

    /**
     * Boolean comparison.
     */
    private function compare_boolean( int $actual, string $operator, mixed $value ): bool {
        $expected = filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ? 1 : 0;

        return match ( $operator ) {
            'equals', 'is' => $actual === $expected,
            'not_equals', 'is_not' => $actual !== $expected,
            default => false,
        };
    }

    /**
     * Check if value is between two bounds (value is "min,max").
     */
    private function between( float|int $actual, mixed $value_unused, mixed $original_value ): bool {
        // Not used inline; between checks need raw rule value.
        return false;
    }

    /**
     * Count matching subscribers for a conditions tree (for live preview).
     */
    public function count_matching( array $conditions ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_subscribers';

        // For performance, try to build a SQL WHERE clause for simple conditions.
        // Fall back to per-subscriber evaluation for complex engagement/event conditions.
        $sql_where = $this->try_build_sql( $conditions );

        if ( false !== $sql_where ) {
            return (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table} WHERE status = 'active' AND ({$sql_where})"
            );
        }

        // Fallback: evaluate each subscriber (batched for memory).
        return $this->count_by_evaluation( $conditions );
    }

    /**
     * Get matching subscriber IDs for a conditions tree.
     */
    public function get_matching_ids( array $conditions ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_subscribers';

        $sql_where = $this->try_build_sql( $conditions );

        if ( false !== $sql_where ) {
            return array_map( 'intval', $wpdb->get_col(
                "SELECT id FROM {$table} WHERE status = 'active' AND ({$sql_where})"
            ) );
        }

        return $this->get_ids_by_evaluation( $conditions );
    }

    /**
     * Try to build a pure SQL WHERE clause for simple conditions.
     * Returns false if conditions require per-row evaluation (engagement, events).
     */
    private function try_build_sql( array $conditions, int $depth = 0 ): string|false {
        if ( empty( $conditions ) || empty( $conditions['rules'] ) ) {
            return '1=1';
        }
        if ( $depth > 2 ) {
            return '1=1';
        }

        global $wpdb;
        $match    = $conditions['match'] ?? 'all';
        $joiner   = 'all' === $match ? ' AND ' : ' OR ';
        $clauses  = [];

        foreach ( $conditions['rules'] as $rule ) {
            if ( isset( $rule['match'] ) && isset( $rule['rules'] ) ) {
                $nested = $this->try_build_sql( $rule, $depth + 1 );
                if ( false === $nested ) {
                    return false;
                }
                $clauses[] = "({$nested})";
                continue;
            }

            $field = $rule['field'] ?? '';

            // Engagement and event conditions require per-subscriber evaluation.
            if ( str_starts_with( $field, 'engagement.' ) ) {
                return false;
            }

            $sql_clause = $this->rule_to_sql( $rule );
            if ( false === $sql_clause ) {
                return false;
            }
            $clauses[] = $sql_clause;
        }

        return empty( $clauses ) ? '1=1' : implode( $joiner, $clauses );
    }

    /**
     * Convert a single rule to a SQL clause.
     */
    private function rule_to_sql( array $rule ): string|false {
        global $wpdb;

        $field    = $rule['field'] ?? '';
        $operator = $rule['operator'] ?? '';
        $value    = $rule['value'] ?? '';

        // Subscriber fields.
        if ( str_starts_with( $field, 'subscriber.' ) ) {
            $col = substr( $field, 11 );
            $allowed = [ 'email', 'first_name', 'last_name', 'phone', 'status', 'source', 'created_at', 'gdpr_consent', 'sms_opt_in' ];
            if ( ! in_array( $col, $allowed, true ) ) {
                return false;
            }

            if ( 'created_at' === $col ) {
                return $this->date_to_sql( $col, $operator, $value );
            }
            if ( in_array( $col, [ 'gdpr_consent', 'sms_opt_in' ], true ) ) {
                $expected = filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ? 1 : 0;
                return match ( $operator ) {
                    'equals', 'is'         => $wpdb->prepare( "{$col} = %d", $expected ),
                    'not_equals', 'is_not'  => $wpdb->prepare( "{$col} != %d", $expected ),
                    default                 => false,
                };
            }

            return $this->string_to_sql( $col, $operator, $value );
        }

        // WooCommerce fields.
        if ( str_starts_with( $field, 'woo.' ) ) {
            $sub_field = substr( $field, 4 );

            return match ( $sub_field ) {
                'total_orders'    => $this->numeric_to_sql( 'total_orders', $operator, $value ),
                'total_spent'     => $this->numeric_to_sql( 'total_spent', $operator, $value ),
                'last_order_date' => $this->date_to_sql( 'last_order_date', $operator, $value ),
                'avg_order_value' => $this->numeric_to_sql( '(CASE WHEN total_orders > 0 THEN total_spent / total_orders ELSE 0 END)', $operator, $value ),
                'days_since_order' => $this->numeric_to_sql( 'DATEDIFF(NOW(), COALESCE(last_order_date, created_at))', $operator, $value ),
                'has_ordered'      => match ( $operator ) {
                    'equals', 'is' => filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ? 'total_orders > 0' : 'total_orders = 0',
                    default        => false,
                },
                default => false,
            };
        }

        // RFM fields.
        if ( str_starts_with( $field, 'rfm.' ) ) {
            $sub_field = substr( $field, 4 );

            return match ( $sub_field ) {
                'segment'       => $this->string_to_sql( 'rfm_segment', $operator, $value ),
                'score'         => $this->string_to_sql( 'rfm_score', $operator, $value ),
                'recency'       => $this->numeric_to_sql( 'CAST(SUBSTRING(COALESCE(rfm_score, \'111\'), 1, 1) AS UNSIGNED)', $operator, $value ),
                'frequency'     => $this->numeric_to_sql( 'CAST(SUBSTRING(COALESCE(rfm_score, \'111\'), 2, 1) AS UNSIGNED)', $operator, $value ),
                'monetary'      => $this->numeric_to_sql( 'CAST(SUBSTRING(COALESCE(rfm_score, \'111\'), 3, 1) AS UNSIGNED)', $operator, $value ),
                'churn_risk'    => $this->numeric_to_sql( 'churn_risk_score', $operator, $value ),
                'predicted_clv' => $this->numeric_to_sql( 'COALESCE(predicted_clv, 0)', $operator, $value ),
                default         => false,
            };
        }

        // Tag fields.
        if ( str_starts_with( $field, 'tag.' ) ) {
            $escaped = $wpdb->prepare( '%s', $value );
            return match ( $operator ) {
                'contains'     => "JSON_CONTAINS(COALESCE(tags, '[]'), {$escaped})",
                'not_contains' => "NOT JSON_CONTAINS(COALESCE(tags, '[]'), {$escaped})",
                'is_empty'     => "(tags IS NULL OR tags = '[]' OR tags = '')",
                'is_not_empty' => "(tags IS NOT NULL AND tags != '[]' AND tags != '')",
                default        => false,
            };
        }

        // Custom fields.
        if ( str_starts_with( $field, 'custom.' ) ) {
            $key = substr( $field, 7 );
            $json_path = $wpdb->prepare( '%s', '$.' . $key );
            $col_expr  = "JSON_UNQUOTE(JSON_EXTRACT(COALESCE(custom_fields, '{}'), {$json_path}))";
            return $this->string_to_sql( $col_expr, $operator, $value );
        }

        return false;
    }

    /**
     * Build SQL for string comparison.
     */
    private function string_to_sql( string $col, string $operator, string $value ): string|false {
        global $wpdb;

        return match ( $operator ) {
            'equals'       => $wpdb->prepare( "{$col} = %s", $value ),
            'not_equals'   => $wpdb->prepare( "{$col} != %s", $value ),
            'contains'     => $wpdb->prepare( "{$col} LIKE %s", '%' . $wpdb->esc_like( $value ) . '%' ),
            'not_contains' => $wpdb->prepare( "{$col} NOT LIKE %s", '%' . $wpdb->esc_like( $value ) . '%' ),
            'starts_with'  => $wpdb->prepare( "{$col} LIKE %s", $wpdb->esc_like( $value ) . '%' ),
            'ends_with'    => $wpdb->prepare( "{$col} LIKE %s", '%' . $wpdb->esc_like( $value ) ),
            'is_empty'     => "({$col} IS NULL OR {$col} = '')",
            'is_not_empty' => "({$col} IS NOT NULL AND {$col} != '')",
            'in'           => $this->in_clause( $col, $value ),
            'not_in'       => $this->not_in_clause( $col, $value ),
            default        => false,
        };
    }

    /**
     * Build SQL for numeric comparison.
     */
    private function numeric_to_sql( string $col, string $operator, mixed $value ): string|false {
        global $wpdb;
        $num = (float) $value;

        return match ( $operator ) {
            'equals', 'equal'             => $wpdb->prepare( "{$col} = %f", $num ),
            'not_equals', 'not_equal'     => $wpdb->prepare( "{$col} != %f", $num ),
            'greater_than'                => $wpdb->prepare( "{$col} > %f", $num ),
            'less_than'                   => $wpdb->prepare( "{$col} < %f", $num ),
            'greater_or_equal'            => $wpdb->prepare( "{$col} >= %f", $num ),
            'less_or_equal'               => $wpdb->prepare( "{$col} <= %f", $num ),
            default                       => false,
        };
    }

    /**
     * Build SQL for date comparison.
     */
    private function date_to_sql( string $col, string $operator, mixed $value ): string|false {
        global $wpdb;

        return match ( $operator ) {
            'before'           => $wpdb->prepare( "{$col} < %s", $value ),
            'after'            => $wpdb->prepare( "{$col} > %s", $value ),
            'on'               => $wpdb->prepare( "DATE({$col}) = %s", $value ),
            'in_last_days'     => $wpdb->prepare( "{$col} >= DATE_SUB(NOW(), INTERVAL %d DAY)", (int) $value ),
            'not_in_last_days' => $wpdb->prepare( "{$col} < DATE_SUB(NOW(), INTERVAL %d DAY)", (int) $value ),
            'is_empty'         => "({$col} IS NULL)",
            'is_not_empty'     => "({$col} IS NOT NULL)",
            default            => false,
        };
    }

    /**
     * Build IN clause from comma-separated values.
     */
    private function in_clause( string $col, string $value ): string {
        global $wpdb;
        $items   = array_map( 'trim', explode( ',', $value ) );
        $escaped = array_map( fn( $v ) => $wpdb->prepare( '%s', $v ), $items );
        return "{$col} IN (" . implode( ',', $escaped ) . ')';
    }

    /**
     * Build NOT IN clause from comma-separated values.
     */
    private function not_in_clause( string $col, string $value ): string {
        global $wpdb;
        $items   = array_map( 'trim', explode( ',', $value ) );
        $escaped = array_map( fn( $v ) => $wpdb->prepare( '%s', $v ), $items );
        return "{$col} NOT IN (" . implode( ',', $escaped ) . ')';
    }

    /**
     * Count matching subscribers by per-row evaluation (fallback for complex conditions).
     */
    private function count_by_evaluation( array $conditions ): int {
        global $wpdb;
        $table  = $wpdb->prefix . 'ams_subscribers';
        $count  = 0;
        $offset = 0;
        $batch  = 500;

        do {
            $subscribers = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = 'active' ORDER BY id ASC LIMIT %d OFFSET %d",
                $batch,
                $offset
            ) );

            foreach ( $subscribers as $sub ) {
                if ( $this->evaluate( $sub, $conditions ) ) {
                    $count++;
                }
            }

            $offset += $batch;
        } while ( count( $subscribers ) === $batch );

        return $count;
    }

    /**
     * Get matching subscriber IDs by per-row evaluation.
     */
    private function get_ids_by_evaluation( array $conditions ): array {
        global $wpdb;
        $table  = $wpdb->prefix . 'ams_subscribers';
        $ids    = [];
        $offset = 0;
        $batch  = 500;

        do {
            $subscribers = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = 'active' ORDER BY id ASC LIMIT %d OFFSET %d",
                $batch,
                $offset
            ) );

            foreach ( $subscribers as $sub ) {
                if ( $this->evaluate( $sub, $conditions ) ) {
                    $ids[] = (int) $sub->id;
                }
            }

            $offset += $batch;
        } while ( count( $subscribers ) === $batch );

        return $ids;
    }

    /**
     * Get available condition types for the segment builder UI.
     */
    public static function get_condition_types(): array {
        return [
            // Subscriber fields (9).
            [ 'field' => 'subscriber.email',        'label' => 'Email',           'type' => 'string',  'category' => 'Subscriber' ],
            [ 'field' => 'subscriber.first_name',   'label' => 'First Name',      'type' => 'string',  'category' => 'Subscriber' ],
            [ 'field' => 'subscriber.last_name',    'label' => 'Last Name',       'type' => 'string',  'category' => 'Subscriber' ],
            [ 'field' => 'subscriber.phone',        'label' => 'Phone',           'type' => 'string',  'category' => 'Subscriber' ],
            [ 'field' => 'subscriber.status',       'label' => 'Status',          'type' => 'select',  'category' => 'Subscriber', 'options' => [ 'active', 'unsubscribed', 'pending', 'bounced' ] ],
            [ 'field' => 'subscriber.source',       'label' => 'Source',          'type' => 'select',  'category' => 'Subscriber', 'options' => [ 'sync_order', 'form', 'import', 'manual', 'api' ] ],
            [ 'field' => 'subscriber.created_at',   'label' => 'Date Added',      'type' => 'date',    'category' => 'Subscriber' ],
            [ 'field' => 'subscriber.gdpr_consent', 'label' => 'GDPR Consent',    'type' => 'boolean', 'category' => 'Subscriber' ],
            [ 'field' => 'subscriber.sms_opt_in',   'label' => 'SMS Opt-In',      'type' => 'boolean', 'category' => 'Subscriber' ],

            // WooCommerce fields (6).
            [ 'field' => 'woo.total_orders',    'label' => 'Total Orders',       'type' => 'number',  'category' => 'WooCommerce' ],
            [ 'field' => 'woo.total_spent',     'label' => 'Total Spent',        'type' => 'number',  'category' => 'WooCommerce' ],
            [ 'field' => 'woo.last_order_date', 'label' => 'Last Order Date',    'type' => 'date',    'category' => 'WooCommerce' ],
            [ 'field' => 'woo.avg_order_value', 'label' => 'Avg Order Value',    'type' => 'number',  'category' => 'WooCommerce' ],
            [ 'field' => 'woo.days_since_order','label' => 'Days Since Order',   'type' => 'number',  'category' => 'WooCommerce' ],
            [ 'field' => 'woo.has_ordered',     'label' => 'Has Placed Order',   'type' => 'boolean', 'category' => 'WooCommerce' ],

            // Engagement fields (8).
            [ 'field' => 'engagement.email_opens',    'label' => 'Email Opens',        'type' => 'number', 'category' => 'Engagement' ],
            [ 'field' => 'engagement.email_clicks',   'label' => 'Email Clicks',       'type' => 'number', 'category' => 'Engagement' ],
            [ 'field' => 'engagement.email_sent',     'label' => 'Emails Sent',        'type' => 'number', 'category' => 'Engagement' ],
            [ 'field' => 'engagement.last_open_days', 'label' => 'Days Since Open',    'type' => 'number', 'category' => 'Engagement' ],
            [ 'field' => 'engagement.last_click_days','label' => 'Days Since Click',   'type' => 'number', 'category' => 'Engagement' ],
            [ 'field' => 'engagement.last_email_days','label' => 'Days Since Email',   'type' => 'number', 'category' => 'Engagement' ],
            [ 'field' => 'engagement.event_count',    'label' => 'Total Events',       'type' => 'number', 'category' => 'Engagement' ],
            [ 'field' => 'engagement.has_event_type', 'label' => 'Has Event Type',     'type' => 'event',  'category' => 'Engagement' ],

            // RFM fields (7).
            [ 'field' => 'rfm.segment',       'label' => 'RFM Segment',        'type' => 'select',  'category' => 'RFM', 'options' => self::rfm_segments() ],
            [ 'field' => 'rfm.score',         'label' => 'RFM Score',          'type' => 'string',  'category' => 'RFM' ],
            [ 'field' => 'rfm.recency',       'label' => 'Recency Score',      'type' => 'number',  'category' => 'RFM' ],
            [ 'field' => 'rfm.frequency',     'label' => 'Frequency Score',    'type' => 'number',  'category' => 'RFM' ],
            [ 'field' => 'rfm.monetary',      'label' => 'Monetary Score',     'type' => 'number',  'category' => 'RFM' ],
            [ 'field' => 'rfm.churn_risk',    'label' => 'Churn Risk Score',   'type' => 'number',  'category' => 'RFM' ],
            [ 'field' => 'rfm.predicted_clv', 'label' => 'Predicted CLV',      'type' => 'number',  'category' => 'RFM' ],

            // Tag (1).
            [ 'field' => 'tag.has',     'label' => 'Has Tag',     'type' => 'tag',  'category' => 'Tags' ],
        ];
    }

    /**
     * Named RFM segment mappings.
     */
    public static function rfm_segments(): array {
        return [
            'champions',
            'loyal_customers',
            'potential_loyalists',
            'new_customers',
            'promising',
            'at_risk',
            'cant_lose_them',
            'lost',
            'need_attention',
        ];
    }

    /**
     * Named RFM segment labels for display.
     */
    public static function rfm_segment_labels(): array {
        return [
            'champions'          => 'Champions',
            'loyal_customers'    => 'Loyal Customers',
            'potential_loyalists'=> 'Potential Loyalists',
            'new_customers'      => 'New Customers',
            'promising'          => 'Promising',
            'at_risk'            => 'At Risk',
            'cant_lose_them'     => "Can't Lose Them",
            'lost'               => 'Lost',
            'need_attention'     => 'Need Attention',
        ];
    }
}
