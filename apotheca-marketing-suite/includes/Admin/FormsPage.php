<?php

namespace Apotheca\Marketing\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FormsPage {

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $manager = new \Apotheca\Marketing\Forms\FormsManager();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $action = sanitize_text_field( $_GET['action'] ?? '' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $form_id = absint( $_GET['form_id'] ?? 0 );

        if ( 'edit' === $action && $form_id ) {
            $this->render_edit( $form_id );
            return;
        }

        if ( 'new' === $action ) {
            $this->render_new();
            return;
        }

        $this->render_list();
    }

    private function render_list(): void {
        $manager = new \Apotheca\Marketing\Forms\FormsManager();
        $result  = $manager->list_forms();

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Opt-In Forms', 'apotheca-marketing-suite' ); ?></h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ams-forms&action=new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'apotheca-marketing-suite' ); ?></a>

            <table class="widefat fixed striped" style="margin-top:12px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Name', 'apotheca-marketing-suite' ); ?></th>
                        <th><?php esc_html_e( 'Type', 'apotheca-marketing-suite' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'apotheca-marketing-suite' ); ?></th>
                        <th><?php esc_html_e( 'Views', 'apotheca-marketing-suite' ); ?></th>
                        <th><?php esc_html_e( 'Submissions', 'apotheca-marketing-suite' ); ?></th>
                        <th><?php esc_html_e( 'Conversion', 'apotheca-marketing-suite' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'apotheca-marketing-suite' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $result['items'] ) ) : ?>
                        <tr><td colspan="7"><?php esc_html_e( 'No forms created yet.', 'apotheca-marketing-suite' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $result['items'] as $form ) : ?>
                            <?php $conv_rate = $form->views > 0 ? round( ( $form->submissions / $form->views ) * 100, 1 ) : 0; ?>
                            <tr>
                                <td><a href="<?php echo esc_url( admin_url( 'admin.php?page=ams-forms&action=edit&form_id=' . $form->id ) ); ?>"><?php echo esc_html( $form->name ); ?></a></td>
                                <td><?php echo esc_html( ucfirst( str_replace( '-', ' ', $form->type ) ) ); ?></td>
                                <td><?php echo esc_html( ucfirst( $form->status ) ); ?></td>
                                <td><?php echo esc_html( number_format_i18n( $form->views ) ); ?></td>
                                <td><?php echo esc_html( number_format_i18n( $form->submissions ) ); ?></td>
                                <td><?php echo esc_html( $conv_rate . '%' ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=ams-forms&action=edit&form_id=' . $form->id ) ); ?>"><?php esc_html_e( 'Edit', 'apotheca-marketing-suite' ); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_new(): void {
        $this->render_form_editor( null );
    }

    private function render_edit( int $form_id ): void {
        $manager = new \Apotheca\Marketing\Forms\FormsManager();
        $form    = $manager->get( $form_id );
        if ( ! $form ) {
            echo '<div class="wrap"><p>' . esc_html__( 'Form not found.', 'apotheca-marketing-suite' ) . '</p></div>';
            return;
        }
        $this->render_form_editor( $form );
    }

    private function render_form_editor( ?object $form ): void {
        $is_new = null === $form;
        $nonce  = wp_create_nonce( 'ams_form_editor' );
        ?>
        <div class="wrap">
            <h1>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ams-forms' ) ); ?>">&larr;</a>
                <?php echo $is_new ? esc_html__( 'New Form', 'apotheca-marketing-suite' ) : esc_html__( 'Edit Form', 'apotheca-marketing-suite' ); ?>
            </h1>

            <form method="post" id="ams-form-editor">
                <input type="hidden" name="ams_form_nonce" value="<?php echo esc_attr( $nonce ); ?>" />
                <input type="hidden" name="form_id" value="<?php echo esc_attr( $form->id ?? 0 ); ?>" />

                <table class="form-table">
                    <tr>
                        <th><label for="form_name"><?php esc_html_e( 'Name', 'apotheca-marketing-suite' ); ?></label></th>
                        <td><input type="text" id="form_name" name="name" value="<?php echo esc_attr( $form->name ?? '' ); ?>" class="regular-text" required /></td>
                    </tr>
                    <tr>
                        <th><label for="form_type"><?php esc_html_e( 'Type', 'apotheca-marketing-suite' ); ?></label></th>
                        <td>
                            <select id="form_type" name="type">
                                <?php foreach ( \Apotheca\Marketing\Forms\FormsManager::valid_types() as $type ) : ?>
                                    <option value="<?php echo esc_attr( $type ); ?>" <?php selected( $form->type ?? 'modal', $type ); ?>><?php echo esc_html( ucfirst( str_replace( '-', ' ', $type ) ) ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="form_status"><?php esc_html_e( 'Status', 'apotheca-marketing-suite' ); ?></label></th>
                        <td>
                            <select id="form_status" name="status">
                                <option value="inactive" <?php selected( $form->status ?? 'inactive', 'inactive' ); ?>><?php esc_html_e( 'Inactive', 'apotheca-marketing-suite' ); ?></option>
                                <option value="active" <?php selected( $form->status ?? '', 'active' ); ?>><?php esc_html_e( 'Active', 'apotheca-marketing-suite' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Fields', 'apotheca-marketing-suite' ); ?></th>
                        <td>
                            <div id="ams-fields-config">
                                <textarea name="fields_json" rows="6" class="large-text code"><?php echo esc_textarea( wp_json_encode( $form->fields ?? [ [ 'type' => 'email', 'label' => 'Email', 'required' => true, 'name' => 'email' ] ], JSON_PRETTY_PRINT ) ); ?></textarea>
                            </div>
                            <p class="description"><?php esc_html_e( 'JSON array of field objects. Types: email, phone, first_name, last_name, birthday, radio, checkbox, dropdown, hidden.', 'apotheca-marketing-suite' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Targeting Rules', 'apotheca-marketing-suite' ); ?></th>
                        <td>
                            <textarea name="targeting_json" rows="6" class="large-text code"><?php echo esc_textarea( wp_json_encode( $form->targeting_config ?? [], JSON_PRETTY_PRINT ) ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'JSON object. Keys: page_ids, device, scroll_depth, exit_intent, time_on_page, cart_value_min, utm_match, frequency_cap_days.', 'apotheca-marketing-suite' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Trigger Config', 'apotheca-marketing-suite' ); ?></th>
                        <td>
                            <textarea name="trigger_json" rows="4" class="large-text code"><?php echo esc_textarea( wp_json_encode( $form->trigger_config ?? [], JSON_PRETTY_PRINT ) ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'JSON object. Keys: delay_seconds, scroll_percent, exit_intent (bool).', 'apotheca-marketing-suite' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Design Config', 'apotheca-marketing-suite' ); ?></th>
                        <td>
                            <textarea name="design_json" rows="6" class="large-text code"><?php echo esc_textarea( wp_json_encode( $form->design_config ?? [], JSON_PRETTY_PRINT ) ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'JSON object for styling. Keys: headline, body_text, button_text, consent_text, success_message, double_optin (bool), colors, etc.', 'apotheca-marketing-suite' ); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( $is_new ? __( 'Create Form', 'apotheca-marketing-suite' ) : __( 'Update Form', 'apotheca-marketing-suite' ) ); ?>
            </form>
        </div>

        <script>
        (function(){
            var form = document.getElementById('ams-form-editor');
            form.addEventListener('submit', function(e){
                e.preventDefault();
                var fd = new FormData(form);
                var data = {
                    name: fd.get('name'),
                    type: fd.get('type'),
                    status: fd.get('status')
                };
                try { data.fields = JSON.parse(fd.get('fields_json')); } catch(e) { alert('Invalid fields JSON'); return; }
                try { data.targeting_config = JSON.parse(fd.get('targeting_json')); } catch(e) { alert('Invalid targeting JSON'); return; }
                try { data.trigger_config = JSON.parse(fd.get('trigger_json')); } catch(e) { alert('Invalid trigger JSON'); return; }
                try { data.design_config = JSON.parse(fd.get('design_json')); } catch(e) { alert('Invalid design JSON'); return; }

                var formId = fd.get('form_id');
                var method = formId && formId !== '0' ? 'PUT' : 'POST';
                var url = '<?php echo esc_js( rest_url( 'ams/v1/admin/forms' ) ); ?>' + (method === 'PUT' ? '/' + formId : '');

                var xhr = new XMLHttpRequest();
                xhr.open(method, url);
                xhr.setRequestHeader('Content-Type', 'application/json');
                xhr.setRequestHeader('X-WP-Nonce', '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>');
                xhr.onload = function(){
                    try {
                        var res = JSON.parse(xhr.responseText);
                        if(res.id) {
                            location.href = '<?php echo esc_js( admin_url( 'admin.php?page=ams-forms&action=edit&form_id=' ) ); ?>' + res.id;
                        }
                    } catch(err) { alert('Error saving form.'); }
                };
                xhr.send(JSON.stringify(data));
            });
        })();
        </script>
        <?php
    }
}
