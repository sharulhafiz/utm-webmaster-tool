<?php
/*
 * Extract and display Google Docs content shortcode
 * Fetches published Google Docs as HTML with text and media (photos, videos)
 */
add_shortcode('embed_google_doc', function($atts) {
    $atts = shortcode_atts(array(
        'url' => '',
        'cache' => '3600', // Cache duration in seconds (default 1 hour)
        'style' => 'default', // CSS class for styling
    ), $atts);

    if (empty($atts['url'])) {
        return '<div class="google-doc-error">' . __('No Google Docs URL provided.', 'utm') . '</div>';
    }

    // Extract document ID from various Google Docs URL formats
    $doc_id = extract_google_doc_id($atts['url']);
    
    if (!$doc_id) {
        return '<div class="google-doc-error">' . __('Invalid Google Docs URL format.', 'utm') . '</div>';
    }

    // Check cache first
    $cache_key = 'google_doc_' . md5($doc_id);
    $cached_content = get_transient($cache_key);
    
    if ($cached_content !== false && $atts['cache'] > 0) {
        return $cached_content;
    }

    // Fetch Google Docs content as HTML
    $content = fetch_google_doc_content($doc_id);
    
    if (is_wp_error($content)) {
        return '<div class="google-doc-error">' . 
               sprintf(__('Error fetching document: %s', 'utm'), $content->get_error_message()) . 
               '</div>';
    }

    // Wrap content in container with custom styling
    $output = sprintf(
        '<div class="google-doc-content %s">%s</div>',
        esc_attr($atts['style']),
        $content
    );

    // Cache the result
    if ($atts['cache'] > 0) {
        set_transient($cache_key, $output, intval($atts['cache']));
    }

    return $output;
});

/**
 * Extract Google Doc ID from various URL formats
 */
function extract_google_doc_id($url) {
    // Handle different URL patterns:
    // https://docs.google.com/document/d/DOC_ID/edit
    // https://docs.google.com/document/d/DOC_ID/pub
    // https://docs.google.com/document/d/DOC_ID
    
    if (preg_match('/\/document\/d\/([a-zA-Z0-9-_]+)/', $url, $matches)) {
        return $matches[1];
    }
    
    return false;
}

/**
 * Fetch and parse Google Docs content as HTML
 */
function fetch_google_doc_content($doc_id) {
    // Use Google Docs export URL to get HTML content
    // This works for documents that are published to the web or publicly accessible
    $export_url = 'https://docs.google.com/document/d/' . $doc_id . '/export?format=html';
    
    // Attempt to fetch the content
    $response = wp_remote_get($export_url, array(
        'timeout' => 30,
        'sslverify' => true,
    ));

    if (is_wp_error($response)) {
        return $response;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    
    if ($status_code !== 200) {
        return new WP_Error(
            'fetch_failed',
            sprintf(__('Failed to fetch document. Status code: %d. Make sure the document is published to the web.', 'utm'), $status_code)
        );
    }

    $html = wp_remote_retrieve_body($response);
    
    if (empty($html)) {
        return new WP_Error('empty_content', __('Document content is empty.', 'utm'));
    }

    // Parse and clean the HTML content
    $cleaned_html = parse_google_doc_html($html);
    
    return $cleaned_html;
}

/**
 * Parse and clean Google Docs HTML content
 * Preserves text, images, videos, and formatting
 */
function parse_google_doc_html($html) {
    // Remove the head section (contains styles we don't need)
    $html = preg_replace('/<head>.*?<\/head>/is', '', $html);
    
    // Remove html and body tags to get just the content
    $html = preg_replace('/<\/?html[^>]*>/i', '', $html);
    $html = preg_replace('/<\/?body[^>]*>/i', '', $html);
    
    // Clean up Google's inline styles but preserve some important ones
    // Keep styles for images, tables, and text alignment
    $html = preg_replace('/style="[^"]*?((?:width|height|max-width|text-align|float|margin)[^"]*?)"/i', 'style="$1"', $html);
    
    // Remove Google's tracking and unnecessary attributes
    $html = preg_replace('/ (id|class)="c\d+"/i', '', $html);
    
    // Ensure images are responsive
    $html = preg_replace('/<img([^>]*?)>/i', '<img$1 loading="lazy" class="google-doc-image">', $html);
    
    // Convert Google's image URLs to use direct access
    // Google Docs images are usually base64 encoded or use googleusercontent.com
    // They should work as-is in most cases
    
    // Clean up excessive spans
    $html = preg_replace('/<span[^>]*>\s*<\/span>/i', '', $html);
    
    // Add WordPress-friendly classes to common elements
    $html = str_replace('<table', '<table class="google-doc-table"', $html);
    $html = str_replace('<ul', '<ul class="google-doc-list"', $html);
    $html = str_replace('<ol', '<ol class="google-doc-list"', $html);
    
    // Sanitize the HTML to prevent XSS attacks
    // Allow common HTML tags and attributes
    $allowed_tags = array(
        'p' => array('style' => true, 'class' => true),
        'br' => array(),
        'strong' => array(),
        'b' => array(),
        'em' => array(),
        'i' => array(),
        'u' => array(),
        'span' => array('style' => true, 'class' => true),
        'div' => array('style' => true, 'class' => true),
        'h1' => array('style' => true, 'class' => true),
        'h2' => array('style' => true, 'class' => true),
        'h3' => array('style' => true, 'class' => true),
        'h4' => array('style' => true, 'class' => true),
        'h5' => array('style' => true, 'class' => true),
        'h6' => array('style' => true, 'class' => true),
        'img' => array('src' => true, 'alt' => true, 'width' => true, 'height' => true, 'style' => true, 'class' => true, 'loading' => true),
        'a' => array('href' => true, 'title' => true, 'target' => true, 'rel' => true),
        'ul' => array('style' => true, 'class' => true),
        'ol' => array('style' => true, 'class' => true, 'start' => true),
        'li' => array('style' => true, 'class' => true),
        'table' => array('style' => true, 'class' => true, 'border' => true, 'cellpadding' => true, 'cellspacing' => true),
        'thead' => array('style' => true, 'class' => true),
        'tbody' => array('style' => true, 'class' => true),
        'tr' => array('style' => true, 'class' => true),
        'th' => array('style' => true, 'class' => true, 'colspan' => true, 'rowspan' => true),
        'td' => array('style' => true, 'class' => true, 'colspan' => true, 'rowspan' => true),
        'blockquote' => array('style' => true, 'class' => true),
        'code' => array('class' => true),
        'pre' => array('class' => true),
        'iframe' => array('src' => true, 'width' => true, 'height' => true, 'frameborder' => true, 'allowfullscreen' => true, 'class' => true),
        'video' => array('src' => true, 'width' => true, 'height' => true, 'controls' => true, 'class' => true),
        'hr' => array(),
    );
    
    $html = wp_kses($html, $allowed_tags);
    
    return $html;
}

/**
 * Add basic styling for Google Docs content
 */
add_action('wp_head', function() {
    echo '<style>
        .google-doc-content {
            max-width: 100%;
            overflow-wrap: break-word;
            word-wrap: break-word;
        }
        .google-doc-content img {
            max-width: 100%;
            height: auto;
            display: block;
            margin: 1em auto;
        }
        .google-doc-content table {
            width: 100%;
            border-collapse: collapse;
            margin: 1em 0;
        }
        .google-doc-content table td,
        .google-doc-content table th {
            padding: 8px;
            border: 1px solid #ddd;
        }
        .google-doc-error {
            padding: 15px;
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            border-radius: 4px;
            margin: 10px 0;
        }
        .google-doc-list {
            margin: 1em 0;
            padding-left: 2em;
        }
    </style>';
});
