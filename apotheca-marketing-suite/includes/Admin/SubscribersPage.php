<?php

namespace Apotheca\Marketing\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SubscribersPage {

    public function __construct() {
        add_action( 'wp_ajax_ams_subscribers_list', [ $this, 'ajax_list' ] );
        add_action( 'wp_ajax_ams_subscriber_profile', [ $this, 'ajax_profile' ] );
        add_action( 'wp_ajax_ams_subscribers_export', [ $this, 'ajax_export' ] );
        add_action( 'wp_ajax_ams_subscribers_import', [ $this, 'ajax_import' ] );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $view = sanitize_text_field( $_GET['view'] ?? 'list' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $sub_id = absint( $_GET['subscriber_id'] ?? 0 );

        if ( 'profile' === $view && $sub_id ) {
            $this->render_profile( $sub_id );
            return;
        }

        $this->render_list();
    }

    private function render_list(): void {
        $manager = new \Apotheca\Marketing\Subscriber\SubscriberManager();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $args = [
            'page'     => absint( $_GET['paged'] ?? 1 ),
            'per_page' => 25,
            'search'   => sanitize_text_field( $_GET['s'] ?? '' ),
            'status'   => sanitize_text_field( $_GET['status'] ?? '' ),
            'orderby'  => sanitize_text_field( $_GET['orderby'] ?? 'created_at' ),
            'order'    => sanitize_text_field( $_GET['order'] ?? 'DESC' ),
        ];

        $result = $manager->list_subscribers( $args );
        $nonce  = wp_create_nonce( 'ams_subscribers' );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Subscribers', 'apotheca-marketing-suite' ); ?></h1>

            <div style="float:right;margin-top:6px;">
                <button type="button" class="button" id="ams-import-btn"><?php esc_html_e( 'Import CSV', 'apotheca-marketing-suite' ); ?></button>
                <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=ams_subscribers_export&_wpnonce=' . $nonce ) ); ?>" class="button"><?php esc_html_e( 'Export CSV', 'apotheca-marketing-suite' ); ?></a>
            </div>

            <form method="get" style="margin: 12px 0;">
                <input type="hidden" name="page" value="ams-subscribers" />
                <input type="search" name="s" value="<?php echo esc_attr( $args['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search subscribers...', 'apotheca-marketing-suite' ); ?>" />
                <select name="status">
                    <option value=""><?php esc_html_e( 'All statuses', 'apotheca-marketing-suite' ); ?></option>
                    <?php foreach ( [ 'active', 'unsubscribed', 'bounced', 'pending' ] as $st ) : ?>
                        <option value="<?php echo esc_attr( $st ); ?>" <?php selected( $args['status'], $st ); ?>><?php echo esc_html( ucfirst( $st ) ); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php submit_button( __( 'Filter', 'apotheca-marketing-suite' ), 'secondary', 'filter', false ); ?>
            </form>

            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Email', 'apotheca-marketing-suite' ); ?></th>
                        <th><?php esc_html_e( 'Name', 'apotheca-marketing-suite' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'apotheca-marketing-suite' ); ?></th>
                        <th><?php esc_html_e( 'Source', 'apotheca-marketing-suite' ); ?></th>
                        <th><?php esc_html_e( 'RFM Segment', 'apotheca-marketing-suite' ); ?></th>
                        <th><?php esc_html_e( 'CLV', 'apotheca-marketing-suite' ); ?></th>
                        <th><?php esc_html_e( 'Created', 'apotheca-marketing-suite' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $result['items'] ) ) : ?>
                        <tr><td colspan="7"><?php esc_html_e( 'No subscribers found.', 'apotheca-marketing-suite' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $result['items'] as $sub ) : ?>
                            <tr style="cursor:pointer" onclick="location.href='<?php echo esc_js( admin_url( 'admin.php?page=ams-subscribers&view=profile&subscriber_id=' . $sub->id ) ); ?>'">
                                <td><?php echo esc_html( $sub->email ); ?></td>
                                <td><?php echo esc_html( trim( $sub->first_name . ' ' . $sub->last_name ) ); ?></td>
                                <td><span class="ams-status ams-status-<?php echo esc_attr( $sub->status ); ?>"><?php echo esc_html( ucfirst( $sub->status ) ); ?></span></td>
                                <td><?php echo esc_html( $sub->source ); ?></td>
                                <td><?php echo esc_html( $sub->rfm_segment ?: '—' ); ?></td>
                                <td><?php echo esc_html( $sub->predicted_clv ? '$' . number_format( (float) $sub->predicted_clv, 2 ) : '—' ); ?></td>
                                <td><?php echo esc_html( $sub->created_at ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php $this->render_pagination( $result ); ?>

            <!-- Import modal -->
            <div id="ams-import-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:100000;">
                <div style="background:#fff;max-width:500px;margin:10% auto;padding:20px;border-radius:8px;">
                    <h2><?php esc_html_e( 'Import Subscribers from CSV', 'apotheca-marketing-suite' ); ?></h2>
                    <form id="ams-import-form" enctype="multipart/form-data">
                        <?php wp_nonce_field( 'ams_subscribers', '_wpnonce' ); ?>
                        <p><input type="file" name="csv_file" accept=".csv" required /></p>
                        <p class="description"><?php esc_html_e( 'CSV must have a header row. Column "email" is required.', 'apotheca-marketing-suite' ); ?></p>
                        <p>
                            <button type="submit" class="button button-primary"><?php esc_html_e( 'Upload & Import', 'apotheca-marketing-suite' ); ?></button>
                            <button type="button" class="button" id="ams-import-cancel"><?php esc_html_e( 'Cancel', 'apotheca-marketing-suite' ); ?></button>
                        </p>
                        <div id="ams-import-result"></div>
                    </form>
                </div>
            </div>
        </div>

        <style>
            .ams-status { padding: 2px 8px; border-radius: 3px; font-size: 12px; }
            .ams-status-active { background: #d4edda; color: #155724; }
            .ams-status-unsubscribed { background: #f8d7da; color: #721c24; }
            .ams-status-bounced { background: #fff3cd; color: #856404; }
            .ams-status-pending { background: #d1ecf1; color: #0c5460; }
        </style>

        <script>
        (function(){
            var importBtn = document.getElementById('ams-import-btn');
            var modal = document.getElementById('ams-import-modal');
            var cancelBtn = document.getElementById('ams-import-cancel');
            if(importBtn) importBtn.addEventListener('click', function(){ modal.style.display='block'; });
            if(cancelBtn) cancelBtn.addEventListener('click', function(){ modal.style.display='none'; });

            var form = document.getElementById('ams-import-form');
            if(form) form.addEventListener('submit', function(e){
                e.preventDefault();
                var fd = new FormData(form);
                fd.append('action', 'ams_subscribers_import');
                var xhr = new XMLHttpRequest();
                xhr.open('POST', '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>');
                xhr.onload = function(){
                    var res = document.getElementById('ams-import-result');
                    try {
                        var d = JSON.parse(xhr.responseText);
                        if(d.success) {
                            res.innerHTML = '<p style="color:green">'+d.data+'</p>';
                            setTimeout(function(){ location.reload(); }, 1500);
                        } else {
                            res.innerHTML = '<p style="color:red">'+(d.data||'Import failed.')+'</p>';
                        }
                    } catch(err) { res.innerHTML = '<p style="color:red">Unexpected error.</p>'; }
                };
                xhr.send(fd);
            });
        })();
        </script>
        <?php
    }

    private function render_profile( int $sub_id ): void {
        $manager    = new \Apotheca\Marketing\Subscriber\SubscriberManager();
        $subscriber = $manager->get( $sub_id );
        if ( ! $subscriber ) {
            echo '<div class="wrap"><p>' . esc_html__( 'Subscriber not found.', 'apotheca-marketing-suite' ) . '</p></div>';
            return;
        }

        $logger = new \Apotheca\Marketing\Subscriber\EventLogger();
        $events = $logger->get_subscriber_events( $sub_id, 100 );

        // Get send history.
        global $wpdb;
        $sends_table = $wpdb->prefix . 'ams_sends';
        $sends = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$sends_table} WHERE subscriber_id = %d ORDER BY created_at DESC LIMIT 50",
            $sub_id
        ) );

        ?>
        <div class="wrap">
            <h1>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ams-subscribers' ) ); ?>">&larr;</a>
                <?php echo esc_html( $subscriber->email ); ?>
                <span class="ams-status ams-status-<?php echo esc_attr( $subscriber->status ); ?>"><?php echo esc_html( ucfirst( $subscriber->status ) ); ?></span>
            </h1>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:20px;">
                <div>
                    <h2><?php esc_html_e( 'Subscriber Details', 'apotheca-marketing-suite' ); ?></h2>
                    <table class="widefat">
                        <?php
                        $fields = [
                            __( 'Name', 'apotheca-marketing-suite' )        => trim( $subscriber->first_name . ' ' . $subscriber->last_name ),
                            __( 'Phone', 'apotheca-marketing-suite' )       => $subscriber->phone,
                            __( 'Source', 'apotheca-marketing-suite' )      => $subscriber->source,
                            __( 'GDPR Consent', 'apotheca-marketing-suite' ) => $subscriber->gdpr_consent ? __( 'Yes', 'apotheca-marketing-suite' ) . ' (' . $subscriber->gdpr_timestamp . ')' : __( 'No', 'apotheca-marketing-suite' ),
                            __( 'SMS Opt-in', 'apotheca-marketing-suite' )  => $subscriber->sms_opt_in ? __( 'Yes', 'apotheca-marketing-suite' ) : __( 'No', 'apotheca-marketing-suite' ),
                            __( 'Total Orders', 'apotheca-marketing-suite' ) => $subscriber->total_orders,
                            __( 'Total Spent', 'apotheca-marketing-suite' ) => '$' . number_format( (float) $subscriber->total_spent, 2 ),
                            __( 'Last Order', 'apotheca-marketing-suite' )  => $subscriber->last_order_date ?: '—',
                            __( 'RFM Score', 'apotheca-marketing-suite' )   => $subscriber->rfm_score ?: '—',
                            __( 'RFM Segment', 'apotheca-marketing-suite' ) => $subscriber->rfm_segment ?: '—',
                            __( 'Predicted CLV', 'apotheca-marketing-suite' ) => $subscriber->predicted_clv ? '$' . number_format( (float) $subscriber->predicted_clv, 2 ) : '—',
                            __( 'Next Order', 'apotheca-marketing-suite' )  => $subscriber->predicted_next_order ?: '—',
                            __( 'Churn Risk', 'apotheca-marketing-suite' )  => $subscriber->churn_risk_score . '%',
                            __( 'Best Send Hour', 'apotheca-marketing-suite' ) => $subscriber->best_send_hour . ':00 UTC',
                            __( 'Created', 'apotheca-marketing-suite' )     => $subscriber->created_at,
                        ];
                        foreach ( $fields as $label => $value ) : ?>
                            <tr><th style="width:140px"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( $value ); ?></td></tr>
                        <?php endforeach; ?>
                    </table>

                    <?php if ( $subscriber->tags ) : ?>
                        <h3><?php esc_html_e( 'Tags', 'apotheca-marketing-suite' ); ?></h3>
                        <p><?php
                            $tags = json_decode( $subscriber->tags, true ) ?: [];
                            echo esc_html( implode( ', ', $tags ) ?: '—' );
                        ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <h2><?php esc_html_e( 'Event Timeline', 'apotheca-marketing-suite' ); ?></h2>
                    <?php if ( empty( $events ) ) : ?>
                        <p><?php esc_html_e( 'No events recorded.', 'apotheca-marketing-suite' ); ?></p>
                    <?php else : ?>
                        <table class="widefat fixed striped">
                            <thead><tr><th><?php esc_html_e( 'Event', 'apotheca-marketing-suite' ); ?></th><th><?php esc_html_e( 'Date', 'apotheca-marketing-suite' ); ?></th></tr></thead>
                            <tbody>
                                <?php foreach ( $events as $event ) : ?>
                                    <tr>
                                        <td><strong><?php echo esc_html( $event->event_type ); ?></strong></td>
                                        <td><?php echo esc_html( $event->created_at ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <h2 style="margin-top:20px;"><?php esc_html_e( 'Send History', 'apotheca-marketing-suite' ); ?></h2>
                    <?php if ( empty( $sends ) ) : ?>
                        <p><?php esc_html_e( 'No sends recorded.', 'apotheca-marketing-suite' ); ?></p>
                    <?php else : ?>
                        <table class="widefat fixed striped">
                            <thead><tr>
                                <th><?php esc_html_e( 'Channel', 'apotheca-marketing-suite' ); ?></th>
                                <th><?php esc_html_e( 'Status', 'apotheca-marketing-suite' ); ?></th>
                                <th><?php esc_html_e( 'Sent', 'apotheca-marketing-suite' ); ?></th>
                                <th><?php esc_html_e( 'Opened', 'apotheca-marketing-suite' ); ?></th>
                                <th><?php esc_html_e( 'Clicked', 'apotheca-marketing-suite' ); ?></th>
                            </tr></thead>
                            <tbody>
                                <?php foreach ( $sends as $send ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( $send->channel ); ?></td>
                                        <td><?php echo esc_html( $send->status ); ?></td>
                                        <td><?php echo esc_html( $send->sent_at ?: '—' ); ?></td>
                                        <td><?php echo esc_html( $send->opened_at ?: '—' ); ?></td>
                                        <td><?php echo esc_html( $send->clicked_at ?: '—' ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <style>
            .ams-status { padding: 2px 8px; border-radius: 3px; font-size: 12px; vertical-align: middle; margin-left: 8px; }
            .ams-status-active { background: #d4edda; color: #155724; }
            .ams-status-unsubscribed { background: #f8d7da; color: #721c24; }
            .ams-status-bounced { background: #fff3cd; color: #856404; }
            .ams-status-pending { background: #d1ecf1; color: #0c5460; }
        </style>
        <?php
    }

    private function render_pagination( array $result ): void {
        if ( $result['pages'] <= 1 ) {
            return;
        }

        $base_url = admin_url( 'admin.php?page=ams-subscribers' );
        echo '<div class="tablenav"><div class="tablenav-pages">';
        echo '<span class="displaying-num">' . esc_html( sprintf(
            /* translators: %s: number of subscribers */
            __( '%s items', 'apotheca-marketing-suite' ),
            number_format_i18n( $result['total'] )
        ) ) . '</span>';

        for ( $i = 1; $i <= $result['pages']; $i++ ) {
            $url = add_query_arg( 'paged', $i, $base_url );
            if ( $i === $result['page'] ) {
                echo '<span class="tablenav-pages-navspan button disabled">' . esc_html( $i ) . '</span> ';
            } else {
                echo '<a class="button" href="' . esc_url( $url ) . '">' . esc_html( $i ) . '</a> ';
            }
        }
        echo '</div></div>';
    }

    /**
     * AJAX: Export subscribers as CSV.
     */
    public function ajax_export(): void {
        check_ajax_referer( 'ams_subscribers' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'apotheca-marketing-suite' ), 403 );
        }

        $manager = new \Apotheca\Marketing\Subscriber\SubscriberManager();
        $rows    = $manager->export_csv();

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=ams-subscribers-' . gmdate( 'Y-m-d' ) . '.csv' );

        $output = fopen( 'php://output', 'w' );
        if ( ! empty( $rows ) ) {
            fputcsv( $output, array_keys( $rows[0] ) );
            foreach ( $rows as $row ) {
                fputcsv( $output, $row );
            }
        }
        fclose( $output );
        exit;
    }

    /**
     * AJAX: Import subscribers from CSV.
     */
    public function ajax_import(): void {
        check_ajax_referer( 'ams_subscribers' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Unauthorized.', 'apotheca-marketing-suite' ) );
        }

        if ( empty( $_FILES['csv_file']['tmp_name'] ) ) {
            wp_send_json_error( __( 'No file uploaded.', 'apotheca-marketing-suite' ) );
        }

        $file = sanitize_text_field( $_FILES['csv_file']['tmp_name'] );
        if ( ! is_uploaded_file( $file ) ) {
            wp_send_json_error( __( 'Invalid file.', 'apotheca-marketing-suite' ) );
        }

        $handle = fopen( $file, 'r' );
        if ( ! $handle ) {
            wp_send_json_error( __( 'Could not read file.', 'apotheca-marketing-suite' ) );
        }

        $header = fgetcsv( $handle );
        if ( ! $header || ! in_array( 'email', $header, true ) ) {
            fclose( $handle );
            wp_send_json_error( __( 'CSV must have an "email" column header.', 'apotheca-marketing-suite' ) );
        }

        $rows = [];
        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            if ( count( $row ) === count( $header ) ) {
                $rows[] = array_combine( $header, $row );
            }
        }
        fclose( $handle );

        // Auto-map matching column names.
        $valid_fields = [ 'email', 'phone', 'first_name', 'last_name', 'source', 'status' ];
        $mapping      = [];
        foreach ( $header as $col ) {
            $col_clean = strtolower( trim( $col ) );
            if ( in_array( $col_clean, $valid_fields, true ) ) {
                $mapping[ $col ] = $col_clean;
            }
        }

        $manager = new \Apotheca\Marketing\Subscriber\SubscriberManager();
        $stats   = $manager->import_csv( $rows, $mapping );

        wp_send_json_success( sprintf(
            /* translators: %1$d: imported count, %2$d: updated count, %3$d: skipped count */
            __( 'Imported: %1$d, Updated: %2$d, Skipped: %3$d', 'apotheca-marketing-suite' ),
            $stats['imported'],
            $stats['updated'],
            $stats['skipped']
        ) );
    }
}
