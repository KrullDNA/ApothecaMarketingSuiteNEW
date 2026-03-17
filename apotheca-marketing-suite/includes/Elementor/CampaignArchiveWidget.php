<?php

namespace Apotheca\Marketing\Elementor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AMS Campaign Archive — Elementor Widget.
 *
 * Displays past email newsletters as a browsable grid/list.
 * Requires Elementor installed on Site B.
 */
class CampaignArchiveWidget extends \Elementor\Widget_Base {

    public function get_name(): string {
        return 'ams_campaign_archive';
    }

    public function get_title(): string {
        return esc_html__( 'AMS Campaign Archive', 'apotheca-marketing-suite' );
    }

    public function get_icon(): string {
        return 'eicon-archive';
    }

    public function get_categories(): array {
        return [ 'general' ];
    }

    public function get_keywords(): array {
        return [ 'campaign', 'archive', 'newsletter', 'email', 'marketing' ];
    }

    public function get_style_depends(): array {
        return [ 'ams-widgets' ];
    }

    protected function register_controls(): void {
        /* ── Content: Layout ── */
        $this->start_controls_section( 'section_layout', [
            'label' => esc_html__( 'Layout', 'apotheca-marketing-suite' ),
        ] );

        $this->add_control( 'layout', [
            'label'   => esc_html__( 'Layout', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'grid',
            'options' => [
                'grid' => esc_html__( 'Grid', 'apotheca-marketing-suite' ),
                'list' => esc_html__( 'List', 'apotheca-marketing-suite' ),
            ],
        ] );

        $this->add_control( 'columns', [
            'label'   => esc_html__( 'Columns', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => '3',
            'options' => [
                '1' => '1',
                '2' => '2',
                '3' => '3',
                '4' => '4',
            ],
            'condition'  => [ 'layout' => 'grid' ],
            'selectors'  => [ '{{WRAPPER}} .ams-archive-grid' => 'grid-template-columns: repeat({{VALUE}}, 1fr);' ],
        ] );

        $this->add_control( 'posts_per_page', [
            'label'   => esc_html__( 'Campaigns Per Page', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::NUMBER,
            'default' => 9,
            'min'     => 1,
            'max'     => 50,
        ] );

        $this->add_control( 'gap', [
            'label'      => esc_html__( 'Gap Between Cards', 'apotheca-marketing-suite' ),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'default'    => [ 'size' => 20, 'unit' => 'px' ],
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 0, 'max' => 60 ] ],
            'selectors'  => [
                '{{WRAPPER}} .ams-archive-grid' => 'gap: {{SIZE}}{{UNIT}};',
                '{{WRAPPER}} .ams-archive-list' => 'gap: {{SIZE}}{{UNIT}};',
            ],
        ] );

        $this->end_controls_section();

        /* ── Style: Card ── */
        $this->start_controls_section( 'section_card_style', [
            'label' => esc_html__( 'Card', 'apotheca-marketing-suite' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->add_control( 'card_background', [
            'label'     => esc_html__( 'Background', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#ffffff',
            'selectors' => [ '{{WRAPPER}} .ams-archive-card' => 'background-color: {{VALUE}};' ],
        ] );

        $this->add_control( 'card_border_radius', [
            'label'      => esc_html__( 'Border Radius', 'apotheca-marketing-suite' ),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'default'    => [ 'size' => 8, 'unit' => 'px' ],
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 0, 'max' => 50 ] ],
            'selectors'  => [ '{{WRAPPER}} .ams-archive-card' => 'border-radius: {{SIZE}}{{UNIT}};' ],
        ] );

        $this->add_control( 'card_padding', [
            'label'      => esc_html__( 'Padding', 'apotheca-marketing-suite' ),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'default'    => [ 'top' => '20', 'right' => '20', 'bottom' => '20', 'left' => '20', 'unit' => 'px' ],
            'size_units' => [ 'px', 'em' ],
            'selectors'  => [ '{{WRAPPER}} .ams-archive-card' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
        ] );

        $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), [
            'name'     => 'card_box_shadow',
            'selector' => '{{WRAPPER}} .ams-archive-card',
        ] );

        $this->add_control( 'card_hover_bg', [
            'label'     => esc_html__( 'Hover Background', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#f9f9f9',
            'selectors' => [ '{{WRAPPER}} .ams-archive-card:hover' => 'background-color: {{VALUE}};' ],
        ] );

        $this->add_control( 'card_hover_translatey', [
            'label'     => esc_html__( 'Hover Translate Y', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::SLIDER,
            'default'   => [ 'size' => -4, 'unit' => 'px' ],
            'range'     => [ 'px' => [ 'min' => -20, 'max' => 20 ] ],
            'selectors' => [ '{{WRAPPER}} .ams-archive-card:hover' => 'transform: translateY({{SIZE}}px);' ],
        ] );

        $this->add_control( 'card_transition', [
            'label'     => esc_html__( 'Transition Duration (ms)', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::NUMBER,
            'default'   => 300,
            'min'       => 0,
            'max'       => 2000,
            'selectors' => [ '{{WRAPPER}} .ams-archive-card' => 'transition: all {{VALUE}}ms ease;' ],
        ] );

        $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), [
            'name'     => 'card_hover_shadow',
            'label'    => esc_html__( 'Hover Box Shadow', 'apotheca-marketing-suite' ),
            'selector' => '{{WRAPPER}} .ams-archive-card:hover',
        ] );

        $this->end_controls_section();

        /* ── Style: Title ── */
        $this->start_controls_section( 'section_title_style', [
            'label' => esc_html__( 'Title', 'apotheca-marketing-suite' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
            'name'     => 'title_typography',
            'selector' => '{{WRAPPER}} .ams-archive-title',
        ] );

        $this->add_control( 'title_color', [
            'label'     => esc_html__( 'Color', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#333333',
            'selectors' => [ '{{WRAPPER}} .ams-archive-title' => 'color: {{VALUE}};' ],
        ] );

        $this->add_control( 'title_hover_color', [
            'label'     => esc_html__( 'Hover Color', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#2271b1',
            'selectors' => [ '{{WRAPPER}} .ams-archive-card:hover .ams-archive-title' => 'color: {{VALUE}};' ],
        ] );

        $this->end_controls_section();

        /* ── Style: Excerpt ── */
        $this->start_controls_section( 'section_excerpt_style', [
            'label' => esc_html__( 'Excerpt', 'apotheca-marketing-suite' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
            'name'     => 'excerpt_typography',
            'selector' => '{{WRAPPER}} .ams-archive-excerpt',
        ] );

        $this->add_control( 'excerpt_color', [
            'label'     => esc_html__( 'Color', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#666666',
            'selectors' => [ '{{WRAPPER}} .ams-archive-excerpt' => 'color: {{VALUE}};' ],
        ] );

        $this->end_controls_section();

        /* ── Style: Date / Meta ── */
        $this->start_controls_section( 'section_meta_style', [
            'label' => esc_html__( 'Date / Meta', 'apotheca-marketing-suite' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
            'name'     => 'meta_typography',
            'selector' => '{{WRAPPER}} .ams-archive-meta',
        ] );

        $this->add_control( 'meta_color', [
            'label'     => esc_html__( 'Color', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#999999',
            'selectors' => [ '{{WRAPPER}} .ams-archive-meta' => 'color: {{VALUE}};' ],
        ] );

        $this->end_controls_section();

        /* ── Style: Button ── */
        $this->start_controls_section( 'section_button_style', [
            'label' => esc_html__( 'Button', 'apotheca-marketing-suite' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->add_control( 'btn_text', [
            'label'   => esc_html__( 'Button Text', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Read More',
        ] );

        $this->start_controls_tabs( 'btn_tabs' );

        $this->start_controls_tab( 'btn_normal', [
            'label' => esc_html__( 'Normal', 'apotheca-marketing-suite' ),
        ] );
        $this->add_control( 'btn_bg', [
            'label'     => esc_html__( 'Background', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#2271b1',
            'selectors' => [ '{{WRAPPER}} .ams-archive-btn' => 'background-color: {{VALUE}};' ],
        ] );
        $this->add_control( 'btn_color', [
            'label'     => esc_html__( 'Text Color', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#ffffff',
            'selectors' => [ '{{WRAPPER}} .ams-archive-btn' => 'color: {{VALUE}};' ],
        ] );
        $this->add_control( 'btn_border_color', [
            'label'     => esc_html__( 'Border Color', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#2271b1',
            'selectors' => [ '{{WRAPPER}} .ams-archive-btn' => 'border-color: {{VALUE}};' ],
        ] );
        $this->end_controls_tab();

        $this->start_controls_tab( 'btn_hover', [
            'label' => esc_html__( 'Hover', 'apotheca-marketing-suite' ),
        ] );
        $this->add_control( 'btn_hover_bg', [
            'label'     => esc_html__( 'Background', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#135e96',
            'selectors' => [ '{{WRAPPER}} .ams-archive-btn:hover' => 'background-color: {{VALUE}};' ],
        ] );
        $this->add_control( 'btn_hover_color', [
            'label'     => esc_html__( 'Text Color', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#ffffff',
            'selectors' => [ '{{WRAPPER}} .ams-archive-btn:hover' => 'color: {{VALUE}};' ],
        ] );
        $this->add_control( 'btn_hover_border', [
            'label'     => esc_html__( 'Border Color', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#135e96',
            'selectors' => [ '{{WRAPPER}} .ams-archive-btn:hover' => 'border-color: {{VALUE}};' ],
        ] );
        $this->end_controls_tab();

        $this->end_controls_tabs();

        $this->add_control( 'btn_border_radius', [
            'label'      => esc_html__( 'Border Radius', 'apotheca-marketing-suite' ),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'default'    => [ 'size' => 4, 'unit' => 'px' ],
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 0, 'max' => 50 ] ],
            'selectors'  => [ '{{WRAPPER}} .ams-archive-btn' => 'border-radius: {{SIZE}}{{UNIT}};' ],
            'separator'  => 'before',
        ] );

        $this->add_control( 'btn_padding', [
            'label'      => esc_html__( 'Padding', 'apotheca-marketing-suite' ),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'default'    => [ 'top' => '8', 'right' => '20', 'bottom' => '8', 'left' => '20', 'unit' => 'px' ],
            'size_units' => [ 'px', 'em' ],
            'selectors'  => [ '{{WRAPPER}} .ams-archive-btn' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
        ] );

        $this->add_control( 'btn_icon', [
            'label'   => esc_html__( 'Button Icon', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::ICONS,
            'default' => [ 'value' => '', 'library' => '' ],
        ] );

        $this->add_control( 'btn_transition', [
            'label'     => esc_html__( 'Transition Duration (ms)', 'apotheca-marketing-suite' ),
            'type'      => \Elementor\Controls_Manager::NUMBER,
            'default'   => 300,
            'min'       => 0,
            'max'       => 2000,
            'selectors' => [ '{{WRAPPER}} .ams-archive-btn' => 'transition: all {{VALUE}}ms ease;' ],
        ] );

        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
            'name'     => 'btn_typography',
            'selector' => '{{WRAPPER}} .ams-archive-btn',
        ] );

        $this->end_controls_section();

        /* ── Style: Spacing ── */
        $this->start_controls_section( 'section_spacing', [
            'label' => esc_html__( 'Spacing', 'apotheca-marketing-suite' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->add_control( 'title_margin_bottom', [
            'label'      => esc_html__( 'Title Bottom Margin', 'apotheca-marketing-suite' ),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'default'    => [ 'size' => 8, 'unit' => 'px' ],
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 0, 'max' => 40 ] ],
            'selectors'  => [ '{{WRAPPER}} .ams-archive-title' => 'margin-bottom: {{SIZE}}{{UNIT}};' ],
        ] );

        $this->add_control( 'meta_margin_bottom', [
            'label'      => esc_html__( 'Meta Bottom Margin', 'apotheca-marketing-suite' ),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'default'    => [ 'size' => 12, 'unit' => 'px' ],
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 0, 'max' => 40 ] ],
            'selectors'  => [ '{{WRAPPER}} .ams-archive-meta' => 'margin-bottom: {{SIZE}}{{UNIT}};' ],
        ] );

        $this->add_control( 'excerpt_margin_bottom', [
            'label'      => esc_html__( 'Excerpt Bottom Margin', 'apotheca-marketing-suite' ),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'default'    => [ 'size' => 12, 'unit' => 'px' ],
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 0, 'max' => 40 ] ],
            'selectors'  => [ '{{WRAPPER}} .ams-archive-excerpt' => 'margin-bottom: {{SIZE}}{{UNIT}};' ],
        ] );

        $this->end_controls_section();
    }

    protected function render(): void {
        $settings = $this->get_settings_for_display();

        global $wpdb;
        $campaigns = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, subject, preview_text, type, sent_at, created_at
             FROM {$wpdb->prefix}ams_campaigns
             WHERE status = 'sent'
             ORDER BY sent_at DESC
             LIMIT %d",
            absint( $settings['posts_per_page'] ?? 9 )
        ) );

        if ( empty( $campaigns ) ) {
            echo '<p>' . esc_html__( 'No campaigns found.', 'apotheca-marketing-suite' ) . '</p>';
            return;
        }

        $layout   = $settings['layout'] ?? 'grid';
        $btn_text = esc_html( $settings['btn_text'] ?? 'Read More' );

        $wrapper_class = 'grid' === $layout ? 'ams-archive-grid' : 'ams-archive-list';

        echo '<div class="' . esc_attr( $wrapper_class ) . '">';

        foreach ( $campaigns as $c ) {
            $date    = $c->sent_at ? date_i18n( get_option( 'date_format' ), strtotime( $c->sent_at ) ) : '';
            $excerpt = wp_trim_words( wp_strip_all_tags( $c->preview_text ?: $c->subject ), 20 );
            $link    = add_query_arg( [ 'ams_campaign_view' => $c->id ], home_url( '/newsletter/' ) );

            echo '<div class="ams-archive-card">';
            echo '<h3 class="ams-archive-title">' . esc_html( $c->name ?: $c->subject ) . '</h3>';
            echo '<p class="ams-archive-meta">' . esc_html( $date ) . ' &middot; ' . esc_html( strtoupper( $c->type ) ) . '</p>';
            if ( $excerpt ) {
                echo '<p class="ams-archive-excerpt">' . esc_html( $excerpt ) . '</p>';
            }
            echo '<a class="ams-archive-btn" href="' . esc_url( $link ) . '">' . $btn_text . '</a>';
            echo '</div>';
        }

        echo '</div>';
    }
}
