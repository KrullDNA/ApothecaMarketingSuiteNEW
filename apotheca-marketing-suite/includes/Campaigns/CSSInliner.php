<?php

namespace Apotheca\Marketing\Campaigns;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Pure PHP CSS Inliner for email HTML.
 *
 * Extracts CSS from <style> blocks, parses rules, and applies them
 * as inline style attributes. Preserves @import and media query blocks
 * in the <head> (not inlined).
 */
class CSSInliner {

    /**
     * Inline CSS from <style> blocks into HTML elements.
     *
     * @param string $html Full HTML document.
     * @return string HTML with inlined styles.
     */
    public function inline( string $html ): string {
        if ( '' === $html ) {
            return $html;
        }

        // Extract <style> blocks.
        $styles    = [];
        $preserved = []; // Media queries, @import — stay in <head>.

        $html = preg_replace_callback( '/<style[^>]*>(.*?)<\/style>/si', function ( $matches ) use ( &$styles, &$preserved ) {
            $css = $matches[1];

            // Separate @import lines (preserve in <head>).
            $css = preg_replace_callback( '/@import\s+[^;]+;/i', function ( $m ) use ( &$preserved ) {
                $preserved[] = $m[0];
                return '';
            }, $css );

            // Separate @media blocks (preserve in <head>).
            $css = preg_replace_callback( '/@media\s+[^{]+\{(?:[^{}]*\{[^}]*\})*[^}]*\}/si', function ( $m ) use ( &$preserved ) {
                $preserved[] = $m[0];
                return '';
            }, $css );

            // Separate MSO conditional blocks (preserve).
            $css = preg_replace_callback( '/\/\*\[if\s+mso\].*?\[endif\]\*\//si', function ( $m ) use ( &$preserved ) {
                $preserved[] = $m[0];
                return '';
            }, $css );

            $styles[] = trim( $css );
            return ''; // Remove original <style> block.
        }, $html );

        // Parse CSS rules.
        $rules = $this->parse_rules( implode( "\n", $styles ) );

        // Apply rules to matching elements using simple selectors.
        foreach ( $rules as $rule ) {
            $html = $this->apply_rule( $html, $rule['selector'], $rule['properties'] );
        }

        // Re-insert preserved CSS (media queries, @import) in <head>.
        if ( ! empty( $preserved ) ) {
            $preserved_css = '<style>' . "\n" . implode( "\n", $preserved ) . "\n" . '</style>';

            if ( stripos( $html, '</head>' ) !== false ) {
                $html = str_ireplace( '</head>', $preserved_css . "\n" . '</head>', $html );
            } elseif ( stripos( $html, '<body' ) !== false ) {
                $html = preg_replace( '/(<body)/i', $preserved_css . "\n$1", $html );
            } else {
                $html = $preserved_css . "\n" . $html;
            }
        }

        return $html;
    }

    /**
     * Parse CSS text into selector→properties pairs.
     */
    private function parse_rules( string $css ): array {
        $rules = [];

        // Remove comments.
        $css = preg_replace( '/\/\*.*?\*\//s', '', $css );

        // Match selector { properties }.
        preg_match_all( '/([^{]+)\{([^}]+)\}/s', $css, $matches, PREG_SET_ORDER );

        foreach ( $matches as $match ) {
            $selectors  = array_map( 'trim', explode( ',', trim( $match[1] ) ) );
            $properties = trim( $match[2] );

            foreach ( $selectors as $selector ) {
                if ( '' === $selector ) {
                    continue;
                }
                $rules[] = [
                    'selector'   => $selector,
                    'properties' => $properties,
                ];
            }
        }

        return $rules;
    }

    /**
     * Apply a CSS rule to matching HTML elements.
     * Supports: tag, .class, #id, tag.class selectors.
     */
    private function apply_rule( string $html, string $selector, string $properties ): string {
        $pattern = $this->selector_to_regex( $selector );
        if ( ! $pattern ) {
            return $html;
        }

        return preg_replace_callback( $pattern, function ( $matches ) use ( $properties ) {
            $tag = $matches[0];

            // Check if element already has a style attribute.
            if ( preg_match( '/style\s*=\s*["\']([^"\']*)["\']/', $tag, $style_match ) ) {
                $existing = rtrim( $style_match[1], '; ' );
                $new_style = $existing . '; ' . trim( $properties, '; ' );
                $tag = str_replace( $style_match[0], 'style="' . $new_style . '"', $tag );
            } else {
                // Add style before closing >.
                $tag = preg_replace( '/\s*\/?\s*>$/', ' style="' . trim( $properties, '; ' ) . '">', $tag );
            }

            return $tag;
        }, $html );
    }

    /**
     * Convert a simple CSS selector to a regex pattern matching the opening HTML tag.
     */
    private function selector_to_regex( string $selector ): ?string {
        $selector = trim( $selector );

        // ID selector: #myid or tag#myid.
        if ( preg_match( '/^([a-zA-Z0-9]*)?#([a-zA-Z0-9_-]+)$/', $selector, $m ) ) {
            $tag = $m[1] ?: '[a-zA-Z][a-zA-Z0-9]*';
            $id  = preg_quote( $m[2], '/' );
            return '/<' . $tag . '(?=[^>]*\bid\s*=\s*["\']' . $id . '["\'])[^>]*>/i';
        }

        // Class selector: .myclass or tag.myclass.
        if ( preg_match( '/^([a-zA-Z0-9]*)?\.([a-zA-Z0-9_-]+)$/', $selector, $m ) ) {
            $tag   = $m[1] ?: '[a-zA-Z][a-zA-Z0-9]*';
            $class = preg_quote( $m[2], '/' );
            return '/<' . $tag . '(?=[^>]*\bclass\s*=\s*["\'][^"\']*\b' . $class . '\b[^"\']*["\'])[^>]*>/i';
        }

        // Simple tag selector.
        if ( preg_match( '/^[a-zA-Z][a-zA-Z0-9]*$/', $selector ) ) {
            $tag = preg_quote( $selector, '/' );
            return '/<' . $tag . '(?:\s[^>]*)?\s*>/i';
        }

        // Unsupported selector — skip.
        return null;
    }
}
