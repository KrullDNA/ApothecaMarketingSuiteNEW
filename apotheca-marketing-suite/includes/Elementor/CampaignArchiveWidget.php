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
            'condition' => [ 'layout' => 'grid' ],
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
        ] );

        $this->end_controls_section();

        /* ── Style: Card ── */
        $this->start_controls_section( 'section_card_style', [
            'label' => esc_html__( 'Card', 'apotheca-marketing-suite' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->add_control( 'card_background', [
            'label'   => esc_html__( 'Background', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::COLOR,
            'default' => '#ffffff',
        ] );

        $this->add_control( 'card_border_radius', [
            'label'      => esc_html__( 'Border Radius', 'apotheca-marketing-suite' ),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'default'    => [ 'size' => 8, 'unit' => 'px' ],
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 0, 'max' => 50 ] ],
        ] );

        $this->add_control( 'card_padding', [
            'label'      => esc_html__( 'Padding', 'apotheca-marketing-suite' ),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'default'    => [ 'top' => '20', 'right' => '20', 'bottom' => '20', 'left' => '20', 'unit' => 'px' ],
            'size_units' => [ 'px', 'em' ],
        ] );

        $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), [
            'name'     => 'card_box_shadow',
            'selector' => '{{WRAPPER}} .ams-archive-card',
        ] );

        $this->add_control( 'card_hover_bg', [
            'label'   => esc_html__( 'Hover Background', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::COLOR,
            'default' => '#f9f9f9',
        ] );

        $this->add_control( 'card_hover_translatey', [
            'label'   => esc_html__( 'Hover Translate Y', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::SLIDER,
            'default' => [ 'size' => -4, 'unit' => 'px' ],
            'range'   => [ 'px' => [ 'min' => -20, 'max' => 20 ] ],
        ] );

        $this->add_control( 'card_transition', [
            'label'   => esc_html__( 'Transition Duration (ms)', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::NUMBER,
            'default' => 300,
            'min'     => 0,
            'max'     => 2000,
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
            'label'   => esc_html__( 'Color', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::COLOR,
            'default' => '#333333',
        ] );

        $this->add_control( 'title_hover_color', [
            'label'   => esc_html__( 'Hover Color', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::COLOR,
            'default' => '#2271b1',
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
            'label'   => esc_html__( 'Color', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::COLOR,
            'default' => '#666666',
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
            'label'   => esc_html__( 'Color', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::COLOR,
            'default' => '#999999',
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
            'label'   => esc_html__( 'Background', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::COLOR,
            'default' => '#2271b1',
        ] );
        $this->add_control( 'btn_color', [
            'label'   => esc_html__( 'Text Color', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::COLOR,
            'default' => '#ffffff',
        ] );
        $this->add_control( 'btn_border_color', [
            'label'   => esc_html__( 'Border Color', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::COLOR,
            'default' => '#2271b1',
        ] );
        $this->end_controls_tab();

        $this->start_controls_tab( 'btn_hover', [
            'label' => esc_html__( 'Hover', 'apotheca-marketing-suite' ),
        ] );
        $this->add_control( 'btn_hover_bg', [
            'label'   => esc_html__( 'Background', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::COLOR,
            'default' => '#135e96',
        ] );
        $this->add_control( 'btn_hover_color', [
            'label'   => esc_html__( 'Text Color', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::COLOR,
            'default' => '#ffffff',
        ] );
        $this->add_control( 'btn_hover_border', [
            'label'   => esc_html__( 'Border Color', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::COLOR,
            'default' => '#135e96',
        ] );
        $this->end_controls_tab();

        $this->end_controls_tabs();

        $this->add_control( 'btn_border_radius', [
            'label'      => esc_html__( 'Border Radius', 'apotheca-marketing-suite' ),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'default'    => [ 'size' => 4, 'unit' => 'px' ],
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 0, 'max' => 50 ] ],
            'separator'  => 'before',
        ] );

        $this->add_control( 'btn_padding', [
            'label'      => esc_html__( 'Padding', 'apotheca-marketing-suite' ),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'default'    => [ 'top' => '8', 'right' => '20', 'bottom' => '8', 'left' => '20', 'unit' => 'px' ],
            'size_units' => [ 'px', 'em' ],
        ] );

        $this->add_control( 'btn_icon', [
            'label'   => esc_html__( 'Button Icon', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::ICONS,
            'default' => [ 'value' => '', 'library' => '' ],
        ] );

        $this->add_control( 'btn_transition', [
            'label'   => esc_html__( 'Transition Duration (ms)', 'apotheca-marketing-suite' ),
            'type'    => \Elementor\Controls_Manager::NUMBER,
            'default' => 300,
            'min'     => 0,
            'max'     => 2000,
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
        $columns  = absint( $settings['columns'] ?? 3 );
        $gap      = ( $settings['gap']['size'] ?? 20 ) . 'px';
        $card_bg  = esc_attr( $settings['card_background'] ?? '#fff' );
        $card_br  = ( $settings['card_border_radius']['size'] ?? 8 ) . 'px';
        $pad      = $settings['card_padding'] ?? [ 'top' => '20', 'right' => '20', 'bottom' => '20', 'left' => '20', 'unit' => 'px' ];
        $pad_str  = $pad['top'] . $pad['unit'] . ' ' . $pad['right'] . $pad['unit'] . ' ' . $pad['bottom'] . $pad['unit'] . ' ' . $pad['left'] . $pad['unit'];
        $hover_bg = esc_attr( $settings['card_hover_bg'] ?? '#f9f9f9' );
        $hover_ty = ( $settings['card_hover_translatey']['size'] ?? -4 ) . 'px';
        $trans    = ( $settings['card_transition'] ?? 300 ) . 'ms';

        $title_color       = esc_attr( $settings['title_color'] ?? '#333' );
        $title_hover_color = esc_attr( $settings['title_hover_color'] ?? '#2271b1' );
        $excerpt_color     = esc_attr( $settings['excerpt_color'] ?? '#666' );
        $meta_color        = esc_attr( $settings['meta_color'] ?? '#999' );

        $btn_text   = esc_html( $settings['btn_text'] ?? 'Read More' );
        $btn_bg     = esc_attr( $settings['btn_bg'] ?? '#2271b1' );
        $btn_color  = esc_attr( $settings['btn_color'] ?? '#fff' );
        $btn_h_bg   = esc_attr( $settings['btn_hover_bg'] ?? '#135e96' );
        $btn_h_col  = esc_attr( $settings['btn_hover_color'] ?? '#fff' );
        $btn_br     = ( $settings['btn_border_radius']['size'] ?? 4 ) . 'px';
        $btn_pad    = $settings['btn_padding'] ?? [ 'top' => '8', 'right' => '20', 'bottom' => '8', 'left' => '20', 'unit' => 'px' ];
        $btn_pad_s  = $btn_pad['top'] . $btn_pad['unit'] . ' ' . $btn_pad['right'] . $btn_pad['unit'] . ' ' . $btn_pad['bottom'] . $btn_pad['unit'] . ' ' . $btn_pad['left'] . $btn_pad['unit'];
        $btn_trans  = ( $settings['btn_transition'] ?? 300 ) . 'ms';
        $btn_bdr_c  = esc_attr( $settings['btn_border_color'] ?? '#2271b1' );
        $btn_h_bdr  = esc_attr( $settings['btn_hover_border'] ?? '#135e96' );

        $uid = 'ams-' . $this->get_id();

        // Inline <style> scoped to widget.
        echo '<style>';
        echo "#{$uid} .ams-archive-card{background:{$card_bg};border-radius:{$card_br};padding:{$pad_str};transition:all {$trans} ease;}";
        echo "#{$uid} .ams-archive-card:hover{background:{$hover_bg};transform:translateY({$hover_ty});}";
        echo "#{$uid} .ams-archive-title{color:{$title_color};margin:0 0 8px;}";
        echo "#{$uid} .ams-archive-card:hover .ams-archive-title{color:{$title_hover_color};}";
        echo "#{$uid} .ams-archive-excerpt{color:{$excerpt_color};margin:0 0 12px;}";
        echo "#{$uid} .ams-archive-meta{color:{$meta_color};margin:0 0 12px;font-size:0.85em;}";
        echo "#{$uid} .ams-archive-btn{display:inline-block;background:{$btn_bg};color:{$btn_color};border:1px solid {$btn_bdr_c};border-radius:{$btn_br};padding:{$btn_pad_s};text-decoration:none;transition:all {$btn_trans} ease;font-weight:600;}";
        echo "#{$uid} .ams-archive-btn:hover{background:{$btn_h_bg};color:{$btn_h_col};border-color:{$btn_h_bdr};}";
        echo '</style>';

        $grid_style = 'grid' === $layout
            ? "display:grid;grid-template-columns:repeat({$columns},1fr);gap:{$gap};"
            : "display:flex;flex-direction:column;gap:{$gap};";

        echo '<div id="' . esc_attr( $uid ) . '" style="' . esc_attr( $grid_style ) . '">';

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
