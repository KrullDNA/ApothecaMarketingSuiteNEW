<?php

namespace Apotheca\Marketing\Elementor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AMS Subscriber Count Badge — Elementor Widget.
 *
 * Displays the current active subscriber count with a label.
 */
class SubscriberCountBadgeWidget extends \Elementor\Widget_Base {

    public function get_name(): string {
        return 'ams_subscriber_count_badge';
    }

    public function get_title(): string {
        return esc_html__( 'AMS Subscriber Count Badge', 'apotheca-marketing-suite' );
    }

    public function get_icon(): string {
        return 'eicon-counter';
    }

    public function get_categories(): array {
        return [ 'general' ];
    }

    public function get_keywords(): array {
        return [ 'subscriber', 'count', 'badge', 'number', 'marketing' ];
    }

    public function get_style_depends(): array {
        return [ 'ams-widgets' ];
    }

    protected function register_controls(): void {
        /* ── Content: Label ── */
        $this->start_controls_section( 'section_content', [
            'label' => esc_html__( 'Content', 'apotheca-marketing-suite' ),
        ] );

        $this->add_control( 'label_text', [
            'label'   => esc_html__( 'Label', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'happy subscribers',
        ] );

        $this->add_control( 'label_position', [
            'label'   => esc_html__( 'Label Position', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'after',
            'options' => [
                'before' => esc_html__( 'Before Number', 'apotheca-marketing-suite' ),
                'after'  => esc_html__( 'After Number', 'apotheca-marketing-suite' ),
            ],
        ] );

        $this->add_control( 'number_format', [
            'label'   => esc_html__( 'Format Number', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::SWITCHER,
            'default' => 'yes',
            'description' => esc_html__( 'Adds thousand separators.', 'apotheca-marketing-suite' ),
        ] );

        $this->add_control( 'alignment', [
            'label'   => esc_html__( 'Alignment', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::CHOOSE,
            'options' => [
                'flex-start' => [ 'title' => esc_html__( 'Left', 'apotheca-marketing-suite' ), 'icon' => 'eicon-text-align-left' ],
                'center'     => [ 'title' => esc_html__( 'Center', 'apotheca-marketing-suite' ), 'icon' => 'eicon-text-align-center' ],
                'flex-end'   => [ 'title' => esc_html__( 'Right', 'apotheca-marketing-suite' ), 'icon' => 'eicon-text-align-right' ],
            ],
            'default'   => 'center',
            'selectors' => [ '{{WRAPPER}} .ams-badge-container' => 'justify-content: {{VALUE}};' ],
        ] );

        $this->end_controls_section();

        /* ── Style: Number ── */
        $this->start_controls_section( 'section_number_style', [
            'label' => esc_html__( 'Number', 'apotheca-marketing-suite' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
            'name'     => 'number_typography',
            'selector' => '{{WRAPPER}} .ams-badge-number',
        ] );

        $this->add_control( 'number_color', [
            'label'     => esc_html__( 'Color', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#2271b1',
            'selectors' => [ '{{WRAPPER}} .ams-badge-number' => 'color: {{VALUE}};' ],
        ] );

        $this->end_controls_section();

        /* ── Style: Label ── */
        $this->start_controls_section( 'section_label_style', [
            'label' => esc_html__( 'Label', 'apotheca-marketing-suite' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
            'name'     => 'label_typography',
            'selector' => '{{WRAPPER}} .ams-badge-label',
        ] );

        $this->add_control( 'label_color', [
            'label'     => esc_html__( 'Color', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#666666',
            'selectors' => [ '{{WRAPPER}} .ams-badge-label' => 'color: {{VALUE}};' ],
        ] );

        $this->add_control( 'badge_gap', [
            'label'      => esc_html__( 'Gap', 'apotheca-marketing-suite' ),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'default'    => [ 'size' => 6, 'unit' => 'px' ],
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 0, 'max' => 30 ] ],
            'selectors'  => [ '{{WRAPPER}} .ams-badge-container' => 'gap: {{SIZE}}{{UNIT}};' ],
        ] );

        $this->end_controls_section();
    }

    protected function render(): void {
        $settings = $this->get_settings_for_display();

        $cache_key = 'ams_active_subscriber_count';
        $count     = wp_cache_get( $cache_key, 'ams' );
        if ( false === $count ) {
            global $wpdb;
            $count = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ams_subscribers WHERE status = 'active'"
            );
            wp_cache_set( $cache_key, $count, 'ams', HOUR_IN_SECONDS );
        }

        $format = 'yes' === ( $settings['number_format'] ?? 'yes' );
        $number = $format ? number_format_i18n( $count ) : $count;
        $label  = esc_html( $settings['label_text'] ?? 'happy subscribers' );
        $pos    = $settings['label_position'] ?? 'after';

        echo '<div class="ams-badge-container">';

        if ( 'before' === $pos && $label ) {
            echo '<span class="ams-badge-label">' . $label . '</span>';
        }

        echo '<span class="ams-badge-number">' . esc_html( $number ) . '</span>';

        if ( 'after' === $pos && $label ) {
            echo '<span class="ams-badge-label">' . $label . '</span>';
        }

        echo '</div>';
    }
}
