<?php

namespace Apotheca\Marketing\Forms;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FormsManager {

    /**
     * Create a form.
     */
    public function create( array $data ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_forms';

        $wpdb->insert( $table, [
            'name'             => sanitize_text_field( $data['name'] ?? '' ),
            'type'             => sanitize_text_field( $data['type'] ?? 'modal' ),
            'trigger_config'   => wp_json_encode( $data['trigger_config'] ?? [] ),
            'targeting_config' => wp_json_encode( $data['targeting_config'] ?? [] ),
            'fields'           => wp_json_encode( $data['fields'] ?? $this->default_fields() ),
            'design_config'    => wp_json_encode( $data['design_config'] ?? [] ),
            'status'           => 'inactive',
            'created_at'       => current_time( 'mysql', true ),
            'updated_at'       => current_time( 'mysql', true ),
        ] );

        return (int) $wpdb->insert_id;
    }

    /**
     * Update a form.
     */
    public function update( int $id, array $data ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_forms';

        $fields = [ 'updated_at' => current_time( 'mysql', true ) ];

        $allowed = [ 'name', 'type', 'status' ];
        foreach ( $allowed as $key ) {
            if ( isset( $data[ $key ] ) ) {
                $fields[ $key ] = sanitize_text_field( $data[ $key ] );
            }
        }

        $json_fields = [ 'trigger_config', 'targeting_config', 'fields', 'design_config' ];
        foreach ( $json_fields as $key ) {
            if ( isset( $data[ $key ] ) ) {
                $fields[ $key ] = wp_json_encode( $data[ $key ] );
            }
        }

        return false !== $wpdb->update( $table, $fields, [ 'id' => $id ] );
    }

    /**
     * Get a form by ID.
     */
    public function get( int $id ): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_forms';
        $form  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
        if ( $form ) {
            $form->trigger_config   = json_decode( $form->trigger_config, true ) ?: [];
            $form->targeting_config = json_decode( $form->targeting_config, true ) ?: [];
            $form->fields           = json_decode( $form->fields, true ) ?: [];
            $form->design_config    = json_decode( $form->design_config, true ) ?: [];
        }
        return $form;
    }

    /**
     * Delete a form.
     */
    public function delete( int $id ): bool {
        global $wpdb;
        return false !== $wpdb->delete( $wpdb->prefix . 'ams_forms', [ 'id' => $id ] );
    }

    /**
     * List all forms.
     */
    public function list_forms( array $args = [] ): array {
        global $wpdb;
        $table    = $wpdb->prefix . 'ams_forms';
        $per_page = absint( $args['per_page'] ?? 25 );
        $page     = max( 1, absint( $args['page'] ?? 1 ) );
        $offset   = ( $page - 1 ) * $per_page;

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $items = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ) );

        return [
            'items' => $items,
            'total' => $total,
            'page'  => $page,
            'pages' => (int) ceil( $total / $per_page ),
        ];
    }

    /**
     * Get active forms matching a page context.
     */
    public function get_active_for_page( int $page_id = 0 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_forms';

        $forms = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE status = 'active' ORDER BY id ASC"
        );

        $matched = [];
        foreach ( $forms as $form ) {
            $targeting = json_decode( $form->targeting_config, true ) ?: [];
            if ( $this->matches_targeting( $targeting, $page_id ) ) {
                $form->fields        = json_decode( $form->fields, true ) ?: [];
                $form->design_config = json_decode( $form->design_config, true ) ?: [];
                $form->trigger_config = json_decode( $form->trigger_config, true ) ?: [];
                $matched[] = $form;
            }
        }

        return $matched;
    }

    /**
     * Increment form view count.
     */
    public function increment_views( int $form_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_forms';
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET views = views + 1 WHERE id = %d",
            $form_id
        ) );
    }

    /**
     * Increment form submission count.
     */
    public function increment_submissions( int $form_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_forms';
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET submissions = submissions + 1 WHERE id = %d",
            $form_id
        ) );
    }

    /**
     * Check if targeting rules match a page.
     */
    private function matches_targeting( array $targeting, int $page_id ): bool {
        // No targeting rules = show everywhere.
        if ( empty( $targeting ) ) {
            return true;
        }

        // Page IDs targeting.
        if ( ! empty( $targeting['page_ids'] ) && is_array( $targeting['page_ids'] ) ) {
            if ( ! in_array( $page_id, array_map( 'absint', $targeting['page_ids'] ), true ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Default fields for a new form.
     */
    private function default_fields(): array {
        return [
            [
                'type'     => 'email',
                'label'    => 'Email',
                'required' => true,
                'name'     => 'email',
            ],
        ];
    }

    /**
     * Valid form types.
     */
    public static function valid_types(): array {
        return [ 'modal', 'flyout', 'embedded', 'sticky-bar', 'full-page', 'spin-to-win' ];
    }

    /**
     * Valid field types.
     */
    public static function valid_field_types(): array {
        return [ 'email', 'phone', 'first_name', 'last_name', 'birthday', 'radio', 'checkbox', 'dropdown', 'hidden' ];
    }
}
