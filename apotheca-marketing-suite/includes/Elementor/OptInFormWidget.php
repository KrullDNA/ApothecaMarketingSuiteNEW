<?php

namespace Apotheca\Marketing\Elementor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AMS Opt-In Form — Elementor Widget.
 *
 * Embeds an AMS signup form with full style controls.
 */
class OptInFormWidget extends \Elementor\Widget_Base {

    public function get_name(): string {
        return 'ams_optin_form';
    }

    public function get_title(): string {
        return esc_html__( 'AMS Opt-In Form', 'apotheca-marketing-suite' );
    }

    public function get_icon(): string {
        return 'eicon-form-horizontal';
    }

    public function get_categories(): array {
        return [ 'general' ];
    }

    public function get_keywords(): array {
        return [ 'form', 'optin', 'subscribe', 'newsletter', 'signup', 'marketing' ];
    }

    public function get_style_depends(): array {
        return [ 'ams-widgets' ];
    }

    protected function register_controls(): void {
        /* ── Content: Form ── */
        $this->start_controls_section( 'section_form', [
            'label' => esc_html__( 'Form', 'apotheca-marketing-suite' ),
        ] );

        $this->add_control( 'form_id', [
            'label'       => esc_html__( 'Form', 'apotheca-marketing-suite' ),
            'type'        => \Elementor\Controls_Manager::SELECT,
            'default'     => '',
            'options'     => $this->get_form_options(),
            'description' => esc_html__( 'Select an AMS form to embed.', 'apotheca-marketing-suite' ),
        ] );

        $this->add_control( 'headline', [
            'label'   => esc_html__( 'Headline Override', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '',
            'description' => esc_html__( 'Leave blank to use the form\'s own headline.', 'apotheca-marketing-suite' ),
        ] );

        $this->add_control( 'subheadline', [
            'label'   => esc_html__( 'Sub-headline Override', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::TEXTAREA,
            'default' => '',
        ] );

        $this->add_control( 'show_name', [
            'label'   => esc_html__( 'Show Name Field', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::SWITCHER,
            'default' => 'yes',
        ] );

        $this->add_control( 'show_phone', [
            'label'   => esc_html__( 'Show Phone Field', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::SWITCHER,
            'default' => '',
        ] );

        $this->add_control( 'button_text', [
            'label'   => esc_html__( 'Button Text', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Subscribe',
        ] );

        $this->add_control( 'consent_text', [
            'label'   => esc_html__( 'Consent Text', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::TEXTAREA,
            'default' => 'By subscribing you agree to receive marketing emails. You can unsubscribe at any time.',
        ] );

        $this->add_control( 'success_message', [
            'label'   => esc_html__( 'Success Message', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Thank you for subscribing!',
        ] );

        $this->end_controls_section();

        /* ── Style: Container ── */
        $this->start_controls_section( 'section_container_style', [
            'label' => esc_html__( 'Container', 'apotheca-marketing-suite' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->add_control( 'container_bg', [
            'label'     => esc_html__( 'Background', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#ffffff',
            'selectors' => [ '{{WRAPPER}} .ams-optin-container' => 'background-color: {{VALUE}};' ],
        ] );

        $this->add_control( 'container_border_radius', [
            'label'      => esc_html__( 'Border Radius', 'apotheca-marketing-suite' ),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'default'    => [ 'size' => 8, 'unit' => 'px' ],
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 0, 'max' => 50 ] ],
            'selectors'  => [ '{{WRAPPER}} .ams-optin-container' => 'border-radius: {{SIZE}}{{UNIT}};' ],
        ] );

        $this->add_control( 'container_padding', [
            'label'      => esc_html__( 'Padding', 'apotheca-marketing-suite' ),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'default'    => [ 'top' => '24', 'right' => '24', 'bottom' => '24', 'left' => '24', 'unit' => 'px' ],
            'size_units' => [ 'px', 'em' ],
            'selectors'  => [ '{{WRAPPER}} .ams-optin-container' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
        ] );

        $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), [
            'name'     => 'container_shadow',
            'selector' => '{{WRAPPER}} .ams-optin-container',
        ] );

        $this->end_controls_section();

        /* ── Style: Headline ── */
        $this->start_controls_section( 'section_headline_style', [
            'label' => esc_html__( 'Headline', 'apotheca-marketing-suite' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
            'name'     => 'headline_typography',
            'selector' => '{{WRAPPER}} .ams-optin-headline',
        ] );

        $this->add_control( 'headline_color', [
            'label'     => esc_html__( 'Color', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#333333',
            'selectors' => [ '{{WRAPPER}} .ams-optin-headline' => 'color: {{VALUE}};' ],
        ] );

        $this->add_control( 'headline_align', [
            'label'     => esc_html__( 'Alignment', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::CHOOSE,
            'options'   => [
                'left'   => [ 'title' => esc_html__( 'Left', 'apotheca-marketing-suite' ), 'icon' => 'eicon-text-align-left' ],
                'center' => [ 'title' => esc_html__( 'Center', 'apotheca-marketing-suite' ), 'icon' => 'eicon-text-align-center' ],
                'right'  => [ 'title' => esc_html__( 'Right', 'apotheca-marketing-suite' ), 'icon' => 'eicon-text-align-right' ],
            ],
            'default'   => 'left',
            'selectors' => [ '{{WRAPPER}} .ams-optin-headline' => 'text-align: {{VALUE}};' ],
        ] );

        $this->end_controls_section();

        /* ── Style: Sub-headline / Body Text ── */
        $this->start_controls_section( 'section_body_style', [
            'label' => esc_html__( 'Body Text', 'apotheca-marketing-suite' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
            'name'     => 'body_typography',
            'selector' => '{{WRAPPER}} .ams-optin-subheadline',
        ] );

        $this->add_control( 'body_color', [
            'label'     => esc_html__( 'Color', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#666666',
            'selectors' => [ '{{WRAPPER}} .ams-optin-subheadline' => 'color: {{VALUE}};' ],
        ] );

        $this->end_controls_section();

        /* ── Style: Input Fields ── */
        $this->start_controls_section( 'section_input_style', [
            'label' => esc_html__( 'Input Fields', 'apotheca-marketing-suite' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->add_control( 'input_bg', [
            'label'     => esc_html__( 'Background', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#f7f7f7',
            'selectors' => [ '{{WRAPPER}} .ams-optin-field' => 'background-color: {{VALUE}};' ],
        ] );

        $this->add_control( 'input_color', [
            'label'     => esc_html__( 'Text Color', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#333333',
            'selectors' => [ '{{WRAPPER}} .ams-optin-field' => 'color: {{VALUE}};' ],
        ] );

        $this->add_control( 'input_border_color', [
            'label'     => esc_html__( 'Border Color', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#dddddd',
            'selectors' => [ '{{WRAPPER}} .ams-optin-field' => 'border: 1px solid {{VALUE}};' ],
        ] );

        $this->add_control( 'input_focus_border', [
            'label'     => esc_html__( 'Focus Border Color', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#2271b1',
            'selectors' => [ '{{WRAPPER}} .ams-optin-field:focus' => 'border-color: {{VALUE}};' ],
        ] );

        $this->add_control( 'input_border_radius', [
            'label'      => esc_html__( 'Border Radius', 'apotheca-marketing-suite' ),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'default'    => [ 'size' => 4, 'unit' => 'px' ],
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 0, 'max' => 30 ] ],
            'selectors'  => [ '{{WRAPPER}} .ams-optin-field' => 'border-radius: {{SIZE}}{{UNIT}};' ],
        ] );

        $this->add_control( 'input_padding', [
            'label'      => esc_html__( 'Padding', 'apotheca-marketing-suite' ),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'default'    => [ 'top' => '10', 'right' => '14', 'bottom' => '10', 'left' => '14', 'unit' => 'px' ],
            'size_units' => [ 'px' ],
            'selectors'  => [ '{{WRAPPER}} .ams-optin-field' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
        ] );

        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
            'name'     => 'input_typography',
            'selector' => '{{WRAPPER}} .ams-optin-field',
        ] );

        $this->end_controls_section();

        /* ── Style: Button ── */
        $this->start_controls_section( 'section_button_style', [
            'label' => esc_html__( 'Button', 'apotheca-marketing-suite' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->start_controls_tabs( 'btn_tabs' );

        $this->start_controls_tab( 'btn_normal', [
            'label' => esc_html__( 'Normal', 'apotheca-marketing-suite' ),
        ] );
        $this->add_control( 'btn_bg', [
            'label'     => esc_html__( 'Background', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#2271b1',
            'selectors' => [ '{{WRAPPER}} .ams-optin-submit' => 'background-color: {{VALUE}};' ],
        ] );
        $this->add_control( 'btn_color', [
            'label'     => esc_html__( 'Text Color', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#ffffff',
            'selectors' => [ '{{WRAPPER}} .ams-optin-submit' => 'color: {{VALUE}};' ],
        ] );
        $this->end_controls_tab();

        $this->start_controls_tab( 'btn_hover_tab', [
            'label' => esc_html__( 'Hover', 'apotheca-marketing-suite' ),
        ] );
        $this->add_control( 'btn_hover_bg', [
            'label'     => esc_html__( 'Background', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#135e96',
            'selectors' => [ '{{WRAPPER}} .ams-optin-submit:hover' => 'background-color: {{VALUE}};' ],
        ] );
        $this->add_control( 'btn_hover_color', [
            'label'     => esc_html__( 'Text Color', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#ffffff',
            'selectors' => [ '{{WRAPPER}} .ams-optin-submit:hover' => 'color: {{VALUE}};' ],
        ] );
        $this->end_controls_tab();

        $this->end_controls_tabs();

        $this->add_control( 'btn_border_radius', [
            'label'      => esc_html__( 'Border Radius', 'apotheca-marketing-suite' ),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'default'    => [ 'size' => 4, 'unit' => 'px' ],
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 0, 'max' => 50 ] ],
            'selectors'  => [ '{{WRAPPER}} .ams-optin-submit' => 'border-radius: {{SIZE}}{{UNIT}};' ],
            'separator'  => 'before',
        ] );

        $this->add_control( 'btn_padding', [
            'label'      => esc_html__( 'Padding', 'apotheca-marketing-suite' ),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'default'    => [ 'top' => '12', 'right' => '24', 'bottom' => '12', 'left' => '24', 'unit' => 'px' ],
            'size_units' => [ 'px', 'em' ],
            'selectors'  => [ '{{WRAPPER}} .ams-optin-submit' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
        ] );

        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
            'name'     => 'btn_typography',
            'selector' => '{{WRAPPER}} .ams-optin-submit',
        ] );

        $this->end_controls_section();

        /* ── Style: Success Message ── */
        $this->start_controls_section( 'section_success_style', [
            'label' => esc_html__( 'Success Message', 'apotheca-marketing-suite' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->add_control( 'success_color', [
            'label'     => esc_html__( 'Text Color', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#00a32a',
            'selectors' => [ '{{WRAPPER}} .ams-optin-success' => 'color: {{VALUE}};' ],
        ] );

        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
            'name'     => 'success_typography',
            'selector' => '{{WRAPPER}} .ams-optin-success',
        ] );

        $this->end_controls_section();

        /* ── Style: Consent Text ── */
        $this->start_controls_section( 'section_consent_style', [
            'label' => esc_html__( 'Consent Text', 'apotheca-marketing-suite' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->add_control( 'consent_color', [
            'label'     => esc_html__( 'Color', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#999999',
            'selectors' => [ '{{WRAPPER}} .ams-optin-consent' => 'color: {{VALUE}};' ],
        ] );

        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
            'name'     => 'consent_typography',
            'selector' => '{{WRAPPER}} .ams-optin-consent',
        ] );

        $this->end_controls_section();

        /* ── Style: Spacing ── */
        $this->start_controls_section( 'section_spacing', [
            'label' => esc_html__( 'Spacing', 'apotheca-marketing-suite' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->add_control( 'field_gap', [
            'label'      => esc_html__( 'Field Gap', 'apotheca-marketing-suite' ),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'default'    => [ 'size' => 12, 'unit' => 'px' ],
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 0, 'max' => 40 ] ],
            'selectors'  => [ '{{WRAPPER}} .ams-optin-form' => 'gap: {{SIZE}}{{UNIT}};' ],
        ] );

        $this->end_controls_section();
    }

    protected function render(): void {
        $settings = $this->get_settings_for_display();

        $form_id = absint( $settings['form_id'] ?? 0 );
        $form    = null;

        if ( $form_id ) {
            global $wpdb;
            $form = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ams_forms WHERE id = %d AND status = 'active'",
                $form_id
            ) );
        }

        $headline    = ! empty( $settings['headline'] ) ? $settings['headline'] : ( $form->name ?? '' );
        $subheadline = ! empty( $settings['subheadline'] ) ? $settings['subheadline'] : '';
        $btn_text    = esc_html( $settings['button_text'] ?? 'Subscribe' );
        $consent     = $settings['consent_text'] ?? '';
        $success     = esc_html( $settings['success_message'] ?? 'Thank you for subscribing!' );
        $show_name   = 'yes' === ( $settings['show_name'] ?? 'yes' );
        $show_phone  = 'yes' === ( $settings['show_phone'] ?? '' );

        echo '<div class="ams-optin-container">';

        if ( $headline ) {
            echo '<h3 class="ams-optin-headline">' . esc_html( $headline ) . '</h3>';
        }
        if ( $subheadline ) {
            echo '<p class="ams-optin-subheadline">' . esc_html( $subheadline ) . '</p>';
        }

        echo '<form class="ams-optin-form" data-ams-form-id="' . esc_attr( $form_id ) . '">';

        if ( $show_name ) {
            echo '<input type="text" class="ams-optin-field" name="first_name" placeholder="' . esc_attr__( 'First Name', 'apotheca-marketing-suite' ) . '" />';
        }

        echo '<input type="email" class="ams-optin-field" name="email" placeholder="' . esc_attr__( 'Email Address', 'apotheca-marketing-suite' ) . '" required />';

        if ( $show_phone ) {
            echo '<input type="tel" class="ams-optin-field" name="phone" placeholder="' . esc_attr__( 'Phone Number', 'apotheca-marketing-suite' ) . '" />';
        }

        echo '<button type="submit" class="ams-optin-submit">' . $btn_text . '</button>';

        if ( $consent ) {
            echo '<p class="ams-optin-consent">' . esc_html( $consent ) . '</p>';
        }

        echo '<p class="ams-optin-error"></p>';
        echo '</form>';

        echo '<div class="ams-optin-success">' . $success . '</div>';

        echo '</div>';
    }

    private function get_form_options(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'ams_forms';

        // Check table exists to prevent errors in Elementor editor before activation.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $exists = $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
            DB_NAME,
            $table
        ) );

        if ( ! $exists ) {
            return [ '' => esc_html__( '— No forms found —', 'apotheca-marketing-suite' ) ];
        }

        $forms = $wpdb->get_results( "SELECT id, name FROM {$table} WHERE status = 'active' ORDER BY name ASC" );

        $options = [ '' => esc_html__( '— Select a form —', 'apotheca-marketing-suite' ) ];
        foreach ( $forms as $f ) {
            $options[ $f->id ] = esc_html( $f->name );
        }
        return $options;
    }
}
