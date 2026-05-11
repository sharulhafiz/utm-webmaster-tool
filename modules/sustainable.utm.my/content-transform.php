<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Render Google Drive preview iframe from shortcode.
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function utm_sustainable_drive_preview_shortcode( $atts ) {
    $atts = shortcode_atts(
        array(
            'file_id' => '',
            'width'   => '100%',
            'height'  => '800',
        ),
        (array) $atts,
        'utm_drive_preview'
    );

    $file_id = sanitize_text_field( (string) $atts['file_id'] );

    if ( '' === $file_id ) {
        return '';
    }

    $src = 'https://drive.google.com/file/d/' . rawurlencode( $file_id ) . '/preview';

    return '<div class="utm-gdoc-pdf-embed"><iframe src="' . esc_url( $src ) . '" width="' . esc_attr( (string) $atts['width'] ) . '" height="' . esc_attr( (string) $atts['height'] ) . '" loading="lazy" allow="autoplay"></iframe></div>';
}
add_shortcode( 'utm_drive_preview', 'utm_sustainable_drive_preview_shortcode' );

/**
 * Build a Google Drive preview embed wrapper for a file ID.
 *
 * @param string $file_id Google Drive file ID.
 * @return string
 */
function utm_sustainable_build_drive_preview_embed_html( $file_id ) {
    $file_id = sanitize_text_field( (string) $file_id );

    if ( '' === $file_id ) {
        return '';
    }

    return '[utm_drive_preview file_id="' . esc_attr( $file_id ) . '"]';
}

/**
 * Transform Google Drive file links to embeddable iframe HTML.
 *
 * Example input: [https://drive.google.com/file/d/<id>/view?usp=sharing]
 * Also supports standalone block-level Drive URLs that appear as their own
 * paragraph/list item/etc. inside synced page content.
 *
 * @param string $content Raw content.
 * @return string
 */
function utm_sustainable_transform_bracketed_pdf_links( $content ) {
    $content = (string) $content;

    if ( '' === $content || false === stripos( $content, 'drive.google.com/file/d/' ) ) {
        return $content;
    }

    $content = preg_replace_callback(
        '/\[\[\s*(https:\/\/drive\.google\.com\/file\/d\/[a-zA-Z0-9_-]+\/(?:view|edit|preview)(?:\?[^\]\s<\"]*)?)\s*\]\]/i',
        function ( $matches ) {
            return utm_sustainable_drive_url_to_preview_embed( $matches[1] );
        },
        $content
    );

    $content = preg_replace_callback(
        '/\[\[\s*drive-preview\s*:\s*([^\]\s]+)\s*\]\]/i',
        function ( $matches ) {
            return utm_sustainable_drive_url_to_preview_embed( $matches[1] );
        },
        $content
    );

    $content = utm_sustainable_replace_drive_block_elements_with_embeds( $content );

    $content = preg_replace_callback(
        '/\[(https:\/\/drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)\/(?:view|edit|preview)(?:\?[^\]\s<\"]*)?)\]/i',
        function ( $matches ) {
            return utm_sustainable_build_drive_preview_embed_html( $matches[2] );
        },
        $content
    );

    $content = preg_replace_callback(
        '/<a\b([^>]*?)href=(["\'])(?:https:\/\/www\.google\.com\/url\?[^"\']*?q=)?(https:\/\/drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)\/(?:view|edit|preview)(?:\?[^"\']*)?)\2([^>]*)>(.*?)<\/a>/i',
        function ( $matches ) {
            return utm_sustainable_build_drive_preview_embed_html( $matches[4] );
        },
        $content
    );

    $content = preg_replace_callback(
        '/<(p|div|li|td|th|blockquote)\b([^>]*)>\s*(https:\/\/drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)\/(?:view|edit|preview)(?:\?[^<\"]*)?)\s*<\/\1>/i',
        function ( $matches ) {
            return utm_sustainable_build_drive_preview_embed_html( $matches[4] );
        },
        $content
    );

    return $content;
}

/**
 * Convert a Google Drive URL to preview iframe embed HTML.
 *
 * Supports both direct Drive file URLs and Google redirect URLs.
 *
 * @param string $url Google Drive URL.
 * @return string
 */
function utm_sustainable_drive_url_to_preview_embed( $url ) {
    $url = trim( (string) $url );

    if ( '' === $url ) {
        return '';
    }

    $file_id = utm_sustainable_extract_drive_file_id_from_url( $url );

    return utm_sustainable_build_drive_preview_embed_html( $file_id );
}

/**
 * Extract a Google Drive file ID from supported URL forms.
 *
 * Supports direct Drive file URLs, raw file IDs, and Google redirect links
 * such as https://www.google.com/url?q=https%3A%2F%2Fdrive.google.com%2Ffile%2Fd%2F...
 *
 * @param string $url Candidate URL or file ID.
 * @return string
 */
function utm_sustainable_extract_drive_file_id_from_url( $url ) {
    $url = trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );

    if ( '' === $url ) {
        return '';
    }

    if ( preg_match( '/^[a-zA-Z0-9_-]{20,}$/', $url ) ) {
        return $url;
    }

    if ( preg_match( '/drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)/i', $url, $matches ) ) {
        return sanitize_text_field( (string) $matches[1] );
    }

    $parsed = wp_parse_url( $url );

    if ( is_array( $parsed ) && ! empty( $parsed['host'] ) && false !== stripos( (string) $parsed['host'], 'google.com' ) && ! empty( $parsed['path'] ) && false !== stripos( (string) $parsed['path'], '/url' ) ) {
        $query = array();
        parse_str( (string) ( $parsed['query'] ?? '' ), $query );

        if ( ! empty( $query['q'] ) ) {
            $decoded_target = rawurldecode( (string) $query['q'] );
            $file_id        = utm_sustainable_extract_drive_file_id_from_url( $decoded_target );

            if ( '' !== $file_id ) {
                return $file_id;
            }
        }
    }

    if ( false !== stripos( $url, 'drive.google.com%2Ffile%2Fd%2F' ) ) {
        $decoded_url = rawurldecode( $url );

        if ( preg_match( '/drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)/i', $decoded_url, $matches ) ) {
            return sanitize_text_field( (string) $matches[1] );
        }
    }

    return '';
}

/**
 * Replace block elements whose visible text is a Google Drive file URL with preview embeds.
 *
 * This catches Google Docs export markup such as bracketed links wrapped in spans
 * and redirect anchors, for example:
 * <p><span>[</span><a href="https://www.google.com/url?...q=https://drive.google.com/file/d/...">https://drive.google.com/file/d/...</a><span>]</span></p>
 *
 * @param string $content Raw HTML content.
 * @return string
 */
function utm_sustainable_replace_drive_block_elements_with_embeds( $content ) {
    $content = (string) $content;

    if ( '' === $content || false === stripos( $content, 'drive.google.com/file/d/' ) ) {
        return $content;
    }

    if ( ! class_exists( 'DOMDocument' ) ) {
        return $content;
    }

    $previous_internal_errors = libxml_use_internal_errors( true );
    $dom = new DOMDocument( '1.0', 'UTF-8' );

    $loaded = $dom->loadHTML(
        '<?xml encoding="utf-8" ?><div id="utm-sustainable-drive-root">' . $content . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );

    if ( ! $loaded ) {
        libxml_clear_errors();
        libxml_use_internal_errors( $previous_internal_errors );

        return $content;
    }

    $xpath = new DOMXPath( $dom );
    $nodes = $xpath->query( '//*[@id="utm-sustainable-drive-root"]//*[self::p or self::div or self::li or self::td or self::th or self::blockquote]' );

    if ( false === $nodes || 0 === $nodes->length ) {
        libxml_clear_errors();
        libxml_use_internal_errors( $previous_internal_errors );

        return $content;
    }

    $block_tags = array( 'p', 'div', 'li', 'td', 'th', 'blockquote' );

    foreach ( $nodes as $node ) {
        if ( ! $node instanceof DOMElement || ! in_array( strtolower( $node->tagName ), $block_tags, true ) ) {
            continue;
        }

        $text_content = trim( preg_replace( '/\s+/u', ' ', $node->textContent ) );

        $anchors = $node->getElementsByTagName( 'a' );

        if ( $anchors instanceof DOMNodeList && 1 === $anchors->length ) {
            $anchor      = $anchors->item( 0 );
            $anchor_href = $anchor instanceof DOMElement ? (string) $anchor->getAttribute( 'href' ) : '';
            $file_id     = utm_sustainable_extract_drive_file_id_from_url( $anchor_href );

            if ( '' !== $file_id ) {
                $anchor_text = '';

                if ( $anchor instanceof DOMElement ) {
                    $anchor_text = trim( preg_replace( '/\s+/u', ' ', $anchor->textContent ) );
                }

                $remaining_text = trim( str_replace( $anchor_text, '', $text_content ) );

                if ( '' === $remaining_text || preg_match( '/^\[{1,2}\s*\]{1,2}$/', $remaining_text ) ) {
                    $embed_html = utm_sustainable_build_drive_preview_embed_html( $file_id );

                    if ( '' !== $embed_html ) {
                        $replacement = $dom->createDocumentFragment();
                        $replacement->appendXML( $embed_html );

                        if ( $node->parentNode ) {
                            $node->parentNode->replaceChild( $replacement, $node );
                        }

                        continue;
                    }
                }
            }
        }

        if ( preg_match( '/^\[{1,2}\s*drive-preview\s*:\s*([^\]\s]+)\s*\]{1,2}$/i', $text_content, $marker_matches ) ) {
            $embed_html = utm_sustainable_drive_url_to_preview_embed( $marker_matches[1] );

            if ( '' !== $embed_html ) {
                $replacement = $dom->createDocumentFragment();
                $replacement->appendXML( $embed_html );

                if ( $node->parentNode ) {
                    $node->parentNode->replaceChild( $replacement, $node );
                }
            }

            continue;
        }

        if ( ! preg_match( '/^\[{1,2}?\s*(https:\/\/(?:www\.)?(?:google\.com\/url\?[^\s\]]*?q=)?drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)\/(?:view|edit|preview)(?:\?[^\s\]]*)?)\s*\]{1,2}?$/i', $text_content, $matches ) ) {
            continue;
        }

        $embed_html = utm_sustainable_build_drive_preview_embed_html( $matches[2] );

        if ( '' === $embed_html ) {
            continue;
        }

        $replacement = $dom->createDocumentFragment();
        $replacement->appendXML( $embed_html );

        if ( $node->parentNode ) {
            $node->parentNode->replaceChild( $replacement, $node );
        }
    }

    $root = $dom->getElementById( 'utm-sustainable-drive-root' );

    if ( ! $root ) {
        libxml_clear_errors();
        libxml_use_internal_errors( $previous_internal_errors );

        return $content;
    }

    $output = '';

    foreach ( $root->childNodes as $child ) {
        $output .= $dom->saveHTML( $child );
    }

    libxml_clear_errors();
    libxml_use_internal_errors( $previous_internal_errors );

    return (string) $output;
}

/**
 * Normalize Google Docs HTML content before storing in WordPress.
 *
 * @param string $content Raw content.
 * @return string
 */
function utm_sustainable_cleanup_google_doc_content( $content ) {
    $content = (string) $content;

    $content = preg_replace( '/<script\b[^>]*>(.*?)<\/script>/is', '', $content );

    return (string) $content;
}

/**
 * Normalize malformed inline base64 image sources to data URIs.
 *
 * Some incoming HTML contains src="image/png;base64,..." which is invalid and
 * should be src="data:image/png;base64,...".
 *
 * @param string $content Raw content.
 * @return string
 */
function utm_sustainable_normalize_inline_base64_image_sources( $content ) {
    return (string) preg_replace(
        '/src=(["\"])\s*(image\/[a-z0-9.+-]+;base64,[^"\'\s>]+)\1/i',
        'src=$1data:$2$1',
        (string) $content
    );
}

/**
 * Convert inline base64 image sources to uploaded WordPress media URLs.
 *
 * @param string $content Raw HTML content.
 * @return string
 */
function utm_sustainable_convert_inline_base64_images_to_uploads( $content ) {
    $content = (string) $content;

    if ( '' === $content || false === stripos( $content, 'base64,' ) ) {
        return $content;
    }

    return (string) preg_replace_callback(
        '/<img\b([^>]*?)\bsrc=(["\"])\s*(data:)?(image\/[a-z0-9.+-]+;base64,)([^"\'\s>]+)\2([^>]*)>/i',
        'utm_sustainable_replace_inline_base64_img_tag',
        $content
    );
}

/**
 * Inline Google Docs class styles from <style> blocks and remove those blocks.
 *
 * This prevents WordPress capability-based KSES from stripping style tags and
 * leaving raw CSS text visible at the top of synced pages.
 *
 * @param string $content Raw HTML content.
 * @return string
 */
function utm_sustainable_inline_google_doc_class_styles( $content ) {
    $content = (string) $content;

    if ( '' === $content || false === stripos( $content, '<style' ) ) {
        return $content;
    }

    $class_style_map = array();

    preg_match_all( '/<style\b[^>]*>(.*?)<\/style>/is', $content, $style_matches );

    if ( ! empty( $style_matches[1] ) ) {
        foreach ( $style_matches[1] as $css_block ) {
            if ( ! is_string( $css_block ) || '' === trim( $css_block ) ) {
                continue;
            }

            preg_match_all( '/\.([a-z0-9_-]+)\s*\{([^}]*)\}/i', $css_block, $rule_matches, PREG_SET_ORDER );

            if ( empty( $rule_matches ) ) {
                continue;
            }

            foreach ( $rule_matches as $rule ) {
                $class_name = isset( $rule[1] ) ? strtolower( trim( (string) $rule[1] ) ) : '';
                $style_decl = isset( $rule[2] ) ? trim( (string) $rule[2] ) : '';

                if ( '' === $class_name || '' === $style_decl ) {
                    continue;
                }

                if ( ! isset( $class_style_map[ $class_name ] ) ) {
                    $class_style_map[ $class_name ] = $style_decl;
                } else {
                    $class_style_map[ $class_name ] .= ';' . $style_decl;
                }
            }
        }
    }

    $content = preg_replace( '/<style\b[^>]*>.*?<\/style>/is', '', $content );

    if ( empty( $class_style_map ) ) {
        return (string) $content;
    }

    $content = preg_replace_callback(
        '/<([a-z0-9]+)([^>]*)\bclass=(["\"])([^"\']+)\3([^>]*)>/i',
        function ( $matches ) use ( $class_style_map ) {
            $tag_name       = isset( $matches[1] ) ? $matches[1] : '';
            $before_class   = isset( $matches[2] ) ? $matches[2] : '';
            $class_quote    = isset( $matches[3] ) ? $matches[3] : '"';
            $class_value    = isset( $matches[4] ) ? $matches[4] : '';
            $after_class    = isset( $matches[5] ) ? $matches[5] : '';
            $class_names    = preg_split( '/\s+/', trim( (string) $class_value ) );
            $inline_styles  = array();

            if ( is_array( $class_names ) ) {
                foreach ( $class_names as $class_name ) {
                    $class_name = strtolower( trim( (string) $class_name ) );

                    if ( '' === $class_name || ! isset( $class_style_map[ $class_name ] ) ) {
                        continue;
                    }

                    $inline_styles[] = trim( (string) $class_style_map[ $class_name ] );
                }
            }

            $existing_style = '';

            if ( preg_match( '/\bstyle=(["\"])(.*?)\1/i', $before_class . ' ' . $after_class, $style_match ) ) {
                $existing_style = trim( (string) $style_match[2] );
            }

            $combined_style = trim( implode( ';', array_filter( array_merge( array( $existing_style ), $inline_styles ) ) ) );

            $before_class = preg_replace( '/\s*\bstyle=(["\"]).*?\1/i', '', $before_class );
            $after_class  = preg_replace( '/\s*\bstyle=(["\"]).*?\1/i', '', $after_class );

            $replacement = '<' . $tag_name . $before_class . ' class=' . $class_quote . $class_value . $class_quote;

            if ( '' !== $combined_style ) {
                $replacement .= ' style="' . esc_attr( $combined_style ) . '"';
            }

            $replacement .= $after_class . '>';

            return $replacement;
        },
        $content
    );

    return (string) $content;
}

/**
 * Replace a single inline base64 IMG tag with an uploaded media URL.
 *
 * @param array $matches Regex matches.
 * @return string
 */
function utm_sustainable_replace_inline_base64_img_tag( $matches ) {
    $before_attrs = isset( $matches[1] ) ? $matches[1] : '';
    $quote        = isset( $matches[2] ) ? $matches[2] : '"';
    $mime_base    = isset( $matches[4] ) ? strtolower( (string) $matches[4] ) : '';
    $base64_data  = isset( $matches[5] ) ? (string) $matches[5] : '';
    $after_attrs  = isset( $matches[6] ) ? $matches[6] : '';

    $uploaded_url = utm_sustainable_store_inline_base64_image_and_get_url( $mime_base, $base64_data );

    if ( '' === $uploaded_url ) {
        return '<img' . $before_attrs . ' src=' . $quote . 'data:' . $mime_base . $base64_data . $quote . $after_attrs . '>';
    }

    return '<img' . $before_attrs . ' src=' . $quote . esc_url( $uploaded_url ) . $quote . $after_attrs . '>';
}

/**
 * Persist a base64 image payload into uploads and return the URL.
 *
 * @param string $mime_base Mime+base64 prefix, e.g. image/png;base64,
 * @param string $base64_data Base64 payload.
 * @return string
 */
function utm_sustainable_store_inline_base64_image_and_get_url( $mime_base, $base64_data ) {
    $mime_base   = strtolower( trim( (string) $mime_base ) );
    $base64_data = preg_replace( '/\s+/', '', (string) $base64_data );

    if ( '' === $mime_base || '' === $base64_data ) {
        return '';
    }

    if ( ! preg_match( '/^image\/(png|jpeg|jpg|gif|webp|svg\+xml);base64,$/i', $mime_base, $mime_match ) ) {
        return '';
    }

    $extension = strtolower( $mime_match[1] );
    if ( 'jpeg' === $extension ) {
        $extension = 'jpg';
    }

    $binary = base64_decode( $base64_data, true );
    if ( false === $binary || '' === $binary ) {
        return '';
    }

    if ( ! function_exists( 'wp_upload_bits' ) ) {
        return '';
    }

    $hash      = md5( $binary );
    $meta_key  = '_utm_sustainable_inline_img_hash';
    $existing  = get_posts(
        array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'meta_key'       => $meta_key,
            'meta_value'     => $hash,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        )
    );

    if ( ! empty( $existing ) ) {
        $existing_url = wp_get_attachment_url( (int) $existing[0] );
        if ( is_string( $existing_url ) && '' !== $existing_url ) {
            return $existing_url;
        }
    }

    $filename = 'utm-gdoc-inline-' . $hash . '.' . $extension;
    $upload   = wp_upload_bits( $filename, null, $binary );

    if ( ! is_array( $upload ) || ! empty( $upload['error'] ) || empty( $upload['file'] ) || empty( $upload['url'] ) ) {
        return '';
    }

    $filetype = wp_check_filetype( $upload['file'], null );

    $attachment_id = wp_insert_attachment(
        array(
            'post_mime_type' => ! empty( $filetype['type'] ) ? $filetype['type'] : 'application/octet-stream',
            'post_title'     => sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ),
            'post_status'    => 'inherit',
        ),
        $upload['file']
    );

    if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
        return (string) $upload['url'];
    }

    update_post_meta( (int) $attachment_id, $meta_key, $hash );

    if ( function_exists( 'wp_generate_attachment_metadata' ) ) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment_data = wp_generate_attachment_metadata( (int) $attachment_id, $upload['file'] );
        if ( is_array( $attachment_data ) ) {
            wp_update_attachment_metadata( (int) $attachment_id, $attachment_data );
        }
    }

    return (string) $upload['url'];
}
