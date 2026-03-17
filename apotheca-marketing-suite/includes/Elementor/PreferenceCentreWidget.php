<?php

namespace Apotheca\Marketing\Elementor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AMS Preference Centre — Elementor Widget.
 *
 * Allows subscribers to manage their email/SMS preferences.
 * Identifies the subscriber via cookie (ams_subscriber_token).
 */
class PreferenceCentreWidget extends \Elementor\Widget_Base {

    public function get_name(): string {
        return 'ams_preference_centre';
    }

    public function get_title(): string {
        return esc_html__( 'AMS Preference Centre', 'apotheca-marketing-suite' );
    }

    public function get_icon(): string {
        return 'eicon-preferences';
    }

    public function get_categories(): array {
        return [ 'general' ];
    }

    public function get_keywords(): array {
        return [ 'preference', 'unsubscribe', 'settings', 'marketing', 'gdpr' ];
    }

    public function get_style_depends(): array {
        return [ 'ams-widgets' ];
    }

    public function get_script_depends(): array {
        return [ 'ams-preference-centre' ];
    }

    protected function register_controls(): void {
        /* ── Content ── */
        $this->start_controls_section( 'section_content', [
            'label' => esc_html__( 'Content', 'apotheca-marketing-suite' ),
        ] );

        $this->add_control( 'heading', [
            'label'   => esc_html__( 'Heading', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Communication Preferences',
        ] );

        $this->add_control( 'description', [
            'label'   => esc_html__( 'Description', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::TEXTAREA,
            'default' => 'Choose which types of communication you\'d like to receive.',
        ] );

        $this->add_control( 'show_email_toggle', [
            'label'   => esc_html__( 'Show Email Toggle', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::SWITCHER,
            'default' => 'yes',
        ] );

        $this->add_control( 'email_label', [
            'label'     => esc_html__( 'Email Label', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => 'Email Marketing',
            'condition' => [ 'show_email_toggle' => 'yes' ],
        ] );

        $this->add_control( 'show_sms_toggle', [
            'label'   => esc_html__( 'Show SMS Toggle', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::SWITCHER,
            'default' => 'yes',
        ] );

        $this->add_control( 'sms_label', [
            'label'     => esc_html__( 'SMS Label', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => 'SMS Notifications',
            'condition' => [ 'show_sms_toggle' => 'yes' ],
        ] );

        $this->add_control( 'save_text', [
            'label'   => esc_html__( 'Save Button Text', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Save Preferences',
        ] );

        $this->add_control( 'show_unsubscribe_link', [
            'label'   => esc_html__( 'Show Unsubscribe All Link', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::SWITCHER,
            'default' => 'yes',
        ] );

        $this->add_control( 'unsubscribe_text', [
            'label'     => esc_html__( 'Unsubscribe Link Text', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => 'Unsubscribe from all communications',
            'condition' => [ 'show_unsubscribe_link' => 'yes' ],
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
            'selectors' => [ '{{WRAPPER}} .ams-prefcentre-container' => 'background-color: {{VALUE}};' ],
        ] );

        $this->add_control( 'container_border_radius', [
            'label'      => esc_html__( 'Border Radius', 'apotheca-marketing-suite' ),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'default'    => [ 'size' => 8, 'unit' => 'px' ],
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 0, 'max' => 50 ] ],
            'selectors'  => [ '{{WRAPPER}} .ams-prefcentre-container' => 'border-radius: {{SIZE}}{{UNIT}};' ],
        ] );

        $this->add_control( 'container_padding', [
            'label'      => esc_html__( 'Padding', 'apotheca-marketing-suite' ),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'default'    => [ 'top' => '24', 'right' => '24', 'bottom' => '24', 'left' => '24', 'unit' => 'px' ],
            'size_units' => [ 'px', 'em' ],
            'selectors'  => [ '{{WRAPPER}} .ams-prefcentre-container' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
        ] );

        $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), [
            'name'     => 'container_shadow',
            'selector' => '{{WRAPPER}} .ams-prefcentre-container',
        ] );

        $this->end_controls_section();

        /* ── Style: Heading ── */
        $this->start_controls_section( 'section_heading_style', [
            'label' => esc_html__( 'Heading', 'apotheca-marketing-suite' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
            'name'     => 'heading_typography',
            'selector' => '{{WRAPPER}} .ams-prefcentre-heading',
        ] );

        $this->add_control( 'heading_color', [
            'label'     => esc_html__( 'Color', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#333333',
            'selectors' => [ '{{WRAPPER}} .ams-prefcentre-heading' => 'color: {{VALUE}};' ],
        ] );

        $this->end_controls_section();

        /* ── Style: Description ── */
        $this->start_controls_section( 'section_desc_style', [
            'label' => esc_html__( 'Description', 'apotheca-marketing-suite' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
            'name'     => 'desc_typography',
            'selector' => '{{WRAPPER}} .ams-prefcentre-description',
        ] );

        $this->add_control( 'desc_color', [
            'label'     => esc_html__( 'Color', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#666666',
            'selectors' => [ '{{WRAPPER}} .ams-prefcentre-description' => 'color: {{VALUE}};' ],
        ] );

        $this->end_controls_section();

        /* ── Style: Toggle ── */
        $this->start_controls_section( 'section_toggle_style', [
            'label' => esc_html__( 'Toggle', 'apotheca-marketing-suite' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->add_control( 'toggle_active_color', [
            'label'     => esc_html__( 'Active Color', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#2271b1',
            'selectors' => [ '{{WRAPPER}} .ams-prefcentre-toggle input:checked + .ams-prefcentre-slider' => 'background-color: {{VALUE}};' ],
        ] );

        $this->add_control( 'toggle_inactive_color', [
            'label'     => esc_html__( 'Inactive Color', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#cccccc',
            'selectors' => [ '{{WRAPPER}} .ams-prefcentre-slider' => 'background-color: {{VALUE}};' ],
        ] );

        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
            'name'     => 'channel_label_typography',
            'label'    => esc_html__( 'Channel Label Typography', 'apotheca-marketing-suite' ),
            'selector' => '{{WRAPPER}} .ams-prefcentre-channel-label',
        ] );

        $this->add_control( 'channel_label_color', [
            'label'     => esc_html__( 'Channel Label Color', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#333333',
            'selectors' => [ '{{WRAPPER}} .ams-prefcentre-channel-label' => 'color: {{VALUE}};' ],
        ] );

        $this->add_control( 'row_border_color', [
            'label'     => esc_html__( 'Row Divider Color', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#e0e0e0',
            'selectors' => [ '{{WRAPPER}} .ams-prefcentre-row' => 'border-color: {{VALUE}};' ],
        ] );

        $this->end_controls_section();

        /* ── Style: Save Button ── */
        $this->start_controls_section( 'section_save_btn_style', [
            'label' => esc_html__( 'Save Button', 'apotheca-marketing-suite' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->start_controls_tabs( 'save_btn_tabs' );

        $this->start_controls_tab( 'save_btn_normal', [
            'label' => esc_html__( 'Normal', 'apotheca-marketing-suite' ),
        ] );
        $this->add_control( 'save_btn_bg', [
            'label'     => esc_html__( 'Background', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#2271b1',
            'selectors' => [ '{{WRAPPER}} .ams-prefcentre-save' => 'background-color: {{VALUE}};' ],
        ] );
        $this->add_control( 'save_btn_color', [
            'label'     => esc_html__( 'Text Color', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#ffffff',
            'selectors' => [ '{{WRAPPER}} .ams-prefcentre-save' => 'color: {{VALUE}};' ],
        ] );
        $this->end_controls_tab();

        $this->start_controls_tab( 'save_btn_hover_tab', [
            'label' => esc_html__( 'Hover', 'apotheca-marketing-suite' ),
        ] );
        $this->add_control( 'save_btn_hover_bg', [
            'label'     => esc_html__( 'Background', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#135e96',
            'selectors' => [ '{{WRAPPER}} .ams-prefcentre-save:hover' => 'background-color: {{VALUE}};' ],
        ] );
        $this->add_control( 'save_btn_hover_color', [
            'label'     => esc_html__( 'Text Color', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#ffffff',
            'selectors' => [ '{{WRAPPER}} .ams-prefcentre-save:hover' => 'color: {{VALUE}};' ],
        ] );
        $this->end_controls_tab();

        $this->end_controls_tabs();

        $this->add_control( 'save_btn_border_radius', [
            'label'      => esc_html__( 'Border Radius', 'apotheca-marketing-suite' ),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'default'    => [ 'size' => 4, 'unit' => 'px' ],
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 0, 'max' => 50 ] ],
            'selectors'  => [ '{{WRAPPER}} .ams-prefcentre-save' => 'border-radius: {{SIZE}}{{UNIT}};' ],
            'separator'  => 'before',
        ] );

        $this->add_control( 'save_btn_padding', [
            'label'      => esc_html__( 'Padding', 'apotheca-marketing-suite' ),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'default'    => [ 'top' => '12', 'right' => '24', 'bottom' => '12', 'left' => '24', 'unit' => 'px' ],
            'size_units' => [ 'px', 'em' ],
            'selectors'  => [ '{{WRAPPER}} .ams-prefcentre-save' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
        ] );

        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
            'name'     => 'save_btn_typography',
            'selector' => '{{WRAPPER}} .ams-prefcentre-save',
        ] );

        $this->end_controls_section();

        /* ── Style: Status / Feedback Text ── */
        $this->start_controls_section( 'section_status_style', [
            'label' => esc_html__( 'Status Message', 'apotheca-marketing-suite' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->add_control( 'status_color', [
            'label'     => esc_html__( 'Color', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#00a32a',
            'selectors' => [ '{{WRAPPER}} .ams-prefcentre-status' => 'color: {{VALUE}};' ],
        ] );

        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
            'name'     => 'status_typography',
            'selector' => '{{WRAPPER}} .ams-prefcentre-status',
        ] );

        $this->end_controls_section();
    }

    protected function render(): void {
        $settings = $this->get_settings_for_display();

        $heading     = esc_html( $settings['heading'] ?? 'Communication Preferences' );
        $description = esc_html( $settings['description'] ?? '' );
        $show_email  = 'yes' === ( $settings['show_email_toggle'] ?? 'yes' );
        $show_sms    = 'yes' === ( $settings['show_sms_toggle'] ?? 'yes' );
        $email_label = esc_html( $settings['email_label'] ?? 'Email Marketing' );
        $sms_label   = esc_html( $settings['sms_label'] ?? 'SMS Notifications' );
        $save_text   = esc_html( $settings['save_text'] ?? 'Save Preferences' );
        $show_unsub  = 'yes' === ( $settings['show_unsubscribe_link'] ?? 'yes' );
        $unsub_text  = esc_html( $settings['unsubscribe_text'] ?? 'Unsubscribe from all communications' );

        echo '<div class="ams-prefcentre-container" data-ams-prefcentre="1">';

        if ( $heading ) {
            echo '<h3 class="ams-prefcentre-heading">' . $heading . '</h3>';
        }
        if ( $description ) {
            echo '<p class="ams-prefcentre-description">' . $description . '</p>';
        }

        if ( $show_email ) {
            echo '<div class="ams-prefcentre-row">';
            echo '<span class="ams-prefcentre-channel-label">' . $email_label . '</span>';
            echo '<label class="ams-prefcentre-toggle">';
            echo '<input type="checkbox" name="email_opt_in" checked />';
            echo '<span class="ams-prefcentre-slider"></span>';
            echo '</label>';
            echo '</div>';
        }

        if ( $show_sms ) {
            echo '<div class="ams-prefcentre-row">';
            echo '<span class="ams-prefcentre-channel-label">' . $sms_label . '</span>';
            echo '<label class="ams-prefcentre-toggle">';
            echo '<input type="checkbox" name="sms_opt_in" checked />';
            echo '<span class="ams-prefcentre-slider"></span>';
            echo '</label>';
            echo '</div>';
        }

        echo '<button type="button" class="ams-prefcentre-save">' . $save_text . '</button>';

        echo '<p class="ams-prefcentre-status"></p>';

        if ( $show_unsub ) {
            echo '<div class="ams-prefcentre-unsubscribe">';
            echo '<a href="#" class="ams-prefcentre-unsub-link">' . $unsub_text . '</a>';
            echo '</div>';
        }

        echo '</div>';
    }
}
