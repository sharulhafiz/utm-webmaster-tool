<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Extract and sort SDG tags from a post.
 *
 * @param int $post_id Post ID.
 * @return array Array of SDG tag labels (e.g. SDG1, SDG4).
 */
function utm_news_get_ordered_sdg_tags( $post_id ) {
    $terms = wp_get_post_terms( $post_id, 'post_tag' );
    if ( is_wp_error( $terms ) || empty( $terms ) ) {
        return array();
    }

    $goal_map = array();

    foreach ( $terms as $term ) {
        if ( ! isset( $term->name ) ) {
            continue;
        }

        $name = strtoupper( trim( (string) $term->name ) );
        if ( preg_match( '/^SDG(1[0-7]|[1-9])$/', $name, $matches ) ) {
            $goal_number = (int) $matches[1];
            $goal_map[ $goal_number ] = 'SDG' . $goal_number;
        }
    }

    if ( empty( $goal_map ) ) {
        return array();
    }

    ksort( $goal_map, SORT_NUMERIC );

    return array_values( $goal_map );
}

/**
 * Render the SDG icon strip for a post.
 *
 * Used by the [sdg] shortcode for Elementor and other shortcode-aware
 * editors. The strip shows the SDG wheel once, followed by matching goal
 * icons.
 *
 * @param int $post_id Post ID.
 * @return string Rendered HTML, or an empty string if no icons are available.
 */
function utm_news_render_sdg_icons( $post_id ) {
    if ( '1' !== get_option( 'utm_news_show_sdg_icons', '1' ) ) {
        return '';
    }

    $sdg_tags = utm_news_get_ordered_sdg_tags( $post_id );
    if ( empty( $sdg_tags ) ) {
        return '';
    }

    $icon_base_url  = trailingslashit( UTM_WEBMASTER_PLUGIN_URL . 'assets/sdg-icons' );
    $icon_base_path = trailingslashit( UTM_WEBMASTER_PLUGIN_PATH . 'assets/sdg-icons' );

    $wheel_filename = 'sdg-wheel.png';
    $wheel_path     = $icon_base_path . $wheel_filename;
    $wheel_url      = file_exists( $wheel_path ) ? ( $icon_base_url . $wheel_filename ) : '';

    $items_html = '';

    foreach ( $sdg_tags as $sdg_tag ) {
        $goal_number = (int) preg_replace( '/[^0-9]/', '', $sdg_tag );
        if ( $goal_number < 1 || $goal_number > 17 ) {
            continue;
        }

        $icon_filename = 'sdg' . $goal_number . '.png';
        $icon_path     = $icon_base_path . $icon_filename;

        if ( ! file_exists( $icon_path ) ) {
            continue;
        }

        $icon_url = $icon_base_url . $icon_filename;

        $goal_url = 'https://sdgs.un.org/goals/goal' . $goal_number;

        $items_html .= '<span class="utm-news-sdg-item">';
        $items_html .= '<a class="utm-news-sdg-link" href="' . esc_url( $goal_url ) . '" target="_blank" rel="noopener noreferrer" aria-label="Open details for ' . esc_attr( $sdg_tag ) . '">';
        $items_html .= '<img class="utm-news-sdg-icon" src="' . esc_url( $icon_url ) . '" alt="' . esc_attr( $sdg_tag ) . '" title="' . esc_attr( $sdg_tag ) . '" width="50" height="50" loading="lazy" decoding="async" />';
        $items_html .= '</a>';
        $items_html .= '</span>';
    }

    if ( '' === $items_html ) {
        return '';
    }

    $html  = '<div class="utm-news-sdg-strip" aria-label="UN Sustainable Development Goal tags">';
    $html .= '<style>';
    $html .= '.utm-news-sdg-strip{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin:0 0 18px 0;padding:10px 0;border-top:1px solid #eee;border-bottom:1px solid #eee;}';
    $html .= '.utm-news-sdg-item{display:inline-flex;align-items:center;gap:8px;}';
    $html .= '.utm-news-sdg-link{display:inline-flex;border-radius:6px;line-height:0;transition:transform .18s ease,box-shadow .18s ease;}';
    $html .= '.utm-news-sdg-link:hover{transform:translateY(-1px) scale(1.04);box-shadow:0 6px 18px rgba(0,0,0,.18);}';
    $html .= '.utm-news-sdg-link:focus-visible{outline:2px solid #005fcc;outline-offset:2px;}';
    $html .= '.utm-news-sdg-wheel{width:50px;height:50px;object-fit:cover;flex-shrink:0;border-radius:4px;}';
    $html .= '.utm-news-sdg-icon{width:50px;height:50px;object-fit:cover;flex-shrink:0;border-radius:4px;}';
    $html .= '</style>';

    if ( '' !== $wheel_url ) {
        $html .= '<span class="utm-news-sdg-item utm-news-sdg-item-wheel">';
        $html .= '<a class="utm-news-sdg-link" href="https://sdgs.un.org/goals" target="_blank" rel="noopener noreferrer" aria-label="Open Sustainable Development Goals overview">';
        $html .= '<img class="utm-news-sdg-wheel" src="' . esc_url( $wheel_url ) . '" alt="SDG" title="Sustainable Development Goals" width="50" height="50" loading="lazy" decoding="async" />';
        $html .= '</a>';
        $html .= '</span>';
    }

    $html .= $items_html;
    $html .= '</div>';

    return $html;
}

/**
 * Shortcode callback for SDG icons.
 *
 * Usage: [sdg] or [sdg post_id="123"]
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function utm_news_sdg_shortcode( $atts ) {
    $atts = shortcode_atts(
        array(
            'post_id' => 0,
        ),
        $atts,
        'sdg'
    );

    $post_id = absint( $atts['post_id'] );
    if ( ! $post_id ) {
        $post_id = get_the_ID();
    }

    if ( ! $post_id ) {
        global $post;
        if ( isset( $post->ID ) ) {
            $post_id = (int) $post->ID;
        }
    }

    if ( ! $post_id ) {
        return '';
    }

    return utm_news_render_sdg_icons( $post_id );
}

/**
 * Prepend AI summary box to post content
 * 
 * Displays a styled summary box at the top of post content
 * if _ai_summary post meta exists. Only runs on single post pages.
 * 
 * @param string $content Post content
 * @return string Modified content with summary box prepended
 */
function utm_news_prepend_summary_box( $content ) {
    // Only run on single post pages
    if ( ! is_singular( 'post' ) ) {
        return $content;
    }
    
    global $post;
    if ( ! isset( $post->ID ) ) {
        return $content;
    }
    
    // Get summary from post excerpt
    $summary = trim( $post->post_excerpt );
    if ( empty( $summary ) ) {
        return $content;
    }

    $summaryTitle = ( utm_news_get_summary_language( $post->ID ) === 'ms' ) ? '📝 Ringkasan' : '📝 Summary';
    
    // Build summary box HTML
    $box_html = '<div class="utm-news-ai-summary" style="background: #f0f7ff; border-left: 4px solid #0073aa; padding: 15px 20px; margin: 0 0 25px 0; border-radius: 4px;">';
    $box_html .= '<h4 style="margin: 0 0 10px 0; color: #0073aa; font-size: 16px;">' . esc_html( $summaryTitle ) . '</h4>';
    $box_html .= '<p style="margin: 0; line-height: 1.6; color: #333;">' . wp_kses_post( $summary ) . '</p>';
    $box_html .= '</div>';
    
    return $box_html . $content;
}

/**
 * Prepend audio player to post content
 * 
 * Displays an audio player at the top of post content
 * if _audio_attachment_id post meta exists. Only runs on single post pages.
 * 
 * @param string $content Post content
 * @return string Modified content with audio player prepended
 */
function utm_news_prepend_audio_player( $content ) {
    // Only run on single post pages
    if ( ! is_singular( 'post' ) ) {
        return $content;
    }
    
    global $post;
    if ( ! isset( $post->ID ) ) {
        return $content;
    }
    
    // Get attachment ID from post meta
    $attachment_id = get_post_meta( $post->ID, '_audio_attachment_id', true );
    if ( empty( $attachment_id ) ) {
        return $content;
    }
    
    // Get audio URL
    $audio_url = wp_get_attachment_url( $attachment_id );
    if ( empty( $audio_url ) ) {
        return $content;
    }
    
    // Build audio player HTML
    $player_html = '<div class="utm-news-audio-player" style="background: #fff8e1; border-left: 4px solid #ff9800; padding: 15px 20px; margin: 0 0 20px 0; border-radius: 4px;">';
    $player_html .= '<h4 style="margin: 0 0 10px 0; color: #ff9800; font-size: 16px;">🎧 Audio Shortcast</h4>';
    $player_html .= '<audio controls style="width: 100%; max-width: 600px;">';
    // Detect actual MIME type from attachment, fallback to audio/mpeg
    $mime_type = get_post_mime_type( $attachment_id ) ?: 'audio/mpeg';
    $player_html .= '<source src="' . esc_url( $audio_url ) . '" type="' . esc_attr( $mime_type ) . '">';
    $player_html .= 'Your browser does not support the audio element.';
    $player_html .= '</audio>';
    $player_html .= '</div>';
    
    return $player_html . $content;
}

// Phase 5: Display summary box and audio player
add_shortcode( 'sdg', 'utm_news_sdg_shortcode' );
add_filter( 'the_content', 'utm_news_prepend_summary_box', 5 );
add_filter( 'the_content', 'utm_news_prepend_audio_player', 5 );
