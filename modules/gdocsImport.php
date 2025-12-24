<?php
/**
 * Plugin Name: Google Docs to WordPress Importer
 * Description: Sync Google Docs folder to WordPress pages automatically
 * 
 * URL Triggers (Admin only):
 * - ?the=createparent - Create SDG 1-17 parent pages
 * - ?the=createmenu - Regenerate THE Pages menu
 * - ?the=preview - Preview duplicate pages (safe, no deletion)
 * - ?the=cleanup - Remove duplicate SDG pages (destructive)
 */

// If site ID is not 10, do not load this module
if (get_current_blog_id() !== 10) {
    return;
}

// Register custom post type
add_action('init', function() {
    register_post_type('the', [
        'labels' => [
            'name' => 'THE Pages',
            'singular_name' => 'THE Page'
        ],
        'public' => true,
        'show_in_rest' => true,
        'has_archive' => false,
        'hierarchical' => true, // behave like pages
        'supports' => ['title', 'editor', 'excerpt', 'thumbnail', 'page-attributes'],
        'menu_position' => 5,
        'capability_type' => 'page',
        'rewrite' => ['slug' => 'the'],
        // no taxonomies (no categories or post tags)
    ]);

    // Register meta key to store Google Doc ID
    register_post_meta('the', '_google_doc_id', [
        'type' => 'string',
        'single' => true,
        'sanitize_callback' => 'sanitize_text_field',
        'show_in_rest' => true,
        'auth_callback' => function() {
            return current_user_can('edit_pages');
        }
    ]);

    // Register meta key to mark SDG parent pages
    register_post_meta('the', '_is_sdg_parent', [
        'type' => 'boolean',
        'single' => true,
        'default' => false,
        'show_in_rest' => true,
        'auth_callback' => function() {
            return current_user_can('edit_pages');
        }
    ]);
});


// Generate a dedicated menu for THE pages
add_action('wp_loaded', function() {
    if (!term_exists('THE Pages', 'nav_menu')) {
        wp_create_nav_menu('THE Pages');
    }
});

// Handle URL parameter triggers for manual actions
add_action('template_redirect', 'gdocs_handle_url_triggers');

function gdocs_handle_url_triggers() {
    // Only allow for administrators
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Trigger: ?the=createparent
    if (isset($_GET['the']) && $_GET['the'] === 'createparent') {
        gdocs_create_sdg_parent_pages();
        wp_die('SDG parent pages created successfully! <a href="' . esc_url(remove_query_arg('the')) . '">Go back</a>');
    }
    
    // Trigger: ?the=createmenu
    if (isset($_GET['the']) && $_GET['the'] === 'createmenu') {
        update_the_navigation_menu();
        wp_die('THE Pages menu updated successfully! <a href="' . esc_url(remove_query_arg('the')) . '">Go back</a>');
    }
}

// Small REST endpoint to look up a THE post by its Google Doc ID (_google_doc_id)
add_action('rest_api_init', function() {
    register_rest_route('utm/v1', '/post-by-google-id', array(
        'methods' => 'GET',
        'callback' => function(WP_REST_Request $request) {
            $gid = $request->get_param('google_id');
            if (! $gid ) {
                return new WP_Error('missing_google_id','Missing google_id', array('status'=>400));
            }
            global $wpdb;
            $post_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%s LIMIT 1",
                '_google_doc_id', $gid
            ));
            if (!$post_id) {
                return new WP_REST_Response(null, 404);
            }
            $post = get_post($post_id);
            if (! $post ) {
                return new WP_REST_Response(null, 404);
            }
            return rest_ensure_response(array(
                'id' => $post->ID,
                'slug' => $post->post_name,
                'title' => get_the_title($post),
            ));
        },
        'permission_callback' => function() { return current_user_can('edit_pages'); }
    ));
});

function gdocs_create_sdg_parent_pages() {
    $created = 0;
    $skipped = 0;
    
    // Create SDG 1 through SDG 17 parent pages
    for ($i = 1; $i <= 17; $i++) {
        $slug = 'sdg-' . $i;
        $title = 'SDG ' . $i;
        
        // Check if page already exists using post_name in query
        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
            WHERE post_type = 'the' 
            AND post_name = %s 
            AND post_status != 'trash'
            LIMIT 1",
            $slug
        ));
        
        if (!$existing) {
            // Create the parent page
            $post_id = wp_insert_post([
                'post_title' => $title,
                'post_name' => $slug,
                'post_type' => 'the',
                'post_status' => 'publish',
                'post_content' => '[sdg_children_list sdg="' . $i . '"]',
            ]);
            
            if ($post_id && !is_wp_error($post_id)) {
                // Mark as SDG parent page
                update_post_meta($post_id, '_is_sdg_parent', true);
                update_post_meta($post_id, '_sdg_number', $i);
                $created++;
            }
        } else {
            $skipped++;
        }
    }
    
    // Regenerate menu after creating pages
    update_the_navigation_menu();
    
    return ['created' => $created, 'skipped' => $skipped];
}

// Shortcode to display child pages for a specific SDG
add_shortcode('sdg_children_list', 'gdocs_sdg_children_shortcode');

function gdocs_sdg_children_shortcode($atts) {
    $atts = shortcode_atts([
        'sdg' => '1'
    ], $atts);
    
    $sdg_number = intval($atts['sdg']);
    
    // Get all THE posts that match this SDG pattern
    $all_posts = get_posts([
        'post_type' => 'the',
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
        'post_status' => 'publish'
    ]);
    
    $children = [];
    foreach ($all_posts as $post) {
        // Match pattern: starts with "SDG_NUMBER." (e.g., "1.3.1" for SDG 1)
        if (preg_match('/^' . $sdg_number . '\./', $post->post_title)) {
            $children[] = $post;
        }
    }
    
    if (empty($children)) {
        return '<div class="sdg-children-list"><p><em>No pages available yet for SDG ' . esc_html($sdg_number) . '.</em></p></div>';
    }
    
    // Build the HTML list
    $output = '<div class="sdg-children-list">';
    $output .= '<h3>SDG ' . esc_html($sdg_number) . ' Pages</h3>';
    $output .= '<ul class="sdg-page-list">';
    
    foreach ($children as $child) {
        $output .= '<li><a href="' . esc_url(get_permalink($child->ID)) . '">';
        $output .= esc_html($child->post_title);
        $output .= '</a></li>';
    }
    
    $output .= '</ul></div>';
    
    return $output;
}

// Filter content to add sibling navigation on child pages
add_filter('the_content', 'gdocs_add_sibling_navigation');
add_filter('the_content', 'gdocs_inject_inline_styles', 5); // Run early, before sibling nav

function gdocs_inject_inline_styles($content) {
    // Only apply to single THE posts
    if (!is_singular('the')) {
        return $content;
    }
    
    // Check if content has google-doc-content with data-gdoc-styles attribute
    if (preg_match('/<div class="google-doc-content" data-gdoc-styles="([^"]+)">/i', $content, $matches)) {
        $encodedStyles = $matches[1];
        // Decode HTML entities
        $styles = html_entity_decode($encodedStyles, ENT_QUOTES);
        
        // Wrap styles in style tag and inject at the beginning of content
        $styleTag = '<style type="text/css">' . $styles . '</style>';
        
        // Replace the div to remove data attribute and add styles before content
        $content = preg_replace(
            '/<div class="google-doc-content" data-gdoc-styles="[^"]+">/i',
            $styleTag . '<div class="google-doc-content">',
            $content,
            1
        );
    } 
    // FALLBACK: For old pages without data-gdoc-styles attribute
    // Extract inline CSS that appears as plain text right after opening div
    else if (preg_match('/<div class="google-doc-content">(.+?)(?:<p|<div|<h[1-6]|<ul|<ol|<table)/is', $content, $matches)) {
        $possibleCss = $matches[1];
        
        // Check if it looks like CSS (contains common CSS patterns)
        if (preg_match('/\{[^}]+\}/', $possibleCss) && strlen($possibleCss) > 100) {
            
            // Decode HTML entities in the CSS
            $cssText = html_entity_decode($possibleCss, ENT_QUOTES);
            
            // Clean up any whitespace
            $cssText = trim($cssText);
            
            // Wrap in style tag and inject right after the opening div
            $styleTag = '<style type="text/css">' . $cssText . '</style>';
            
            // Replace: remove the plain CSS text and inject as proper style tag
            $content = preg_replace(
                '/(<div class="google-doc-content">)' . preg_quote($possibleCss, '/') . '/s',
                '$1' . $styleTag,
                $content,
                1
            );
        }
    }
    
    return $content;
}

function gdocs_add_sibling_navigation($content) {
    // Only apply to single THE posts
    if (!is_singular('the')) {
        return $content;
    }
    
    global $post;
    
    // Skip if this is an SDG parent page
    if (get_post_meta($post->ID, '_is_sdg_parent', true)) {
        return $content;
    }
    
    // Extract SDG number from title (e.g., "1.3.1" → SDG 1)
    if (preg_match('/^(\d+)\./', $post->post_title, $matches)) {
        $sdg_number = intval($matches[1]);
        
        // Get all sibling pages
        $all_posts = get_posts([
            'post_type' => 'the',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'post_status' => 'publish'
        ]);
        
        $siblings = [];
        foreach ($all_posts as $sibling) {
            if (preg_match('/^' . $sdg_number . '\./', $sibling->post_title)) {
                $siblings[] = $sibling;
            }
        }
        
        if (!empty($siblings)) {
            $nav = '<div class="sdg-sibling-navigation">';
            $nav .= '<h3>SDG ' . esc_html($sdg_number) . ' Pages</h3>';
            $nav .= '<ul class="sdg-sibling-list">';
            
            foreach ($siblings as $sibling) {
                $is_current = ($sibling->ID === $post->ID);
                $class = $is_current ? ' class="current-page"' : '';
                
                $nav .= '<li' . $class . '><a href="' . esc_url(get_permalink($sibling->ID)) . '">';
                $nav .= esc_html($sibling->post_title);
                if ($is_current) {
                    $nav .= ' <span class="current-indicator">(Current)</span>';
                }
                $nav .= '</a></li>';
            }
            
            $nav .= '</ul></div>';
            
            // Prepend navigation to content
            $content = $nav . $content;
        }
    }
    
    return $content;
}

// Add basic CSS for the navigation
add_action('wp_head', 'gdocs_sdg_navigation_styles');

function gdocs_sdg_navigation_styles() {
    if (!is_singular('the')) {
        return;
    }
    ?>
    <style>
    /* SDG Navigation Styles */
    .sdg-children-list,
    .sdg-sibling-navigation {
        background: #f5f5f5;
        border-left: 4px solid #2d6a2d;
        padding: 20px;
        margin-bottom: 30px;
    }
    
    .sdg-children-list h3,
    .sdg-sibling-navigation h3 {
        margin-top: 0;
        color: #2d6a2d;
        font-size: 1.2em;
        margin-bottom: 15px;
    }
    
    .sdg-page-list,
    .sdg-sibling-list {
        list-style-type: none !important;
        list-style: none;
        padding: 0;
        margin: 0;
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }
    
    .sdg-page-list li,
    .sdg-sibling-list li {
        padding: 0;
        border: none;
        margin: 0;
    }
    
    .sdg-page-list a,
    .sdg-sibling-list a {
        display: inline-block;
        padding: 8px 16px;
        background: #e8f5e8;
        border: 2px solid #2d6a2d;
        border-radius: 5px;
        text-decoration: none;
        color: #2d6a2d;
        font-weight: 500;
        transition: all 0.2s ease;
    }
    
    .sdg-page-list a:hover,
    .sdg-sibling-list a:hover {
        background: #d4ecd4;
        border-color: #1f4a1f;
        color: #1f4a1f;
        transform: translateY(-1px);
        box-shadow: 0 2px 4px rgba(45, 106, 45, 0.2);
    }
    
    .sdg-sibling-list li.current-page a {
        background: #2d6a2d;
        color: white;
        font-weight: bold;
    }
    
    .sdg-sibling-list li.current-page a:hover {
        background: #1f4a1f;
        border-color: #1f4a1f;
        color: white;
    }
    
    .current-indicator {
        font-size: 0.85em;
        font-weight: normal;
        opacity: 0.9;
    }
    
    /* Google Docs Content Styles */
    .google-doc-content {
        max-width: 100%;
        overflow-wrap: break-word;
        word-wrap: break-word;
    }
    
    .google-doc-content img {
        max-width: 100%;
        height: auto;
        display: block;
        margin: 1em auto !important;
        /* Center images */
        text-align: center !important;
    }
    
    .google-doc-content table {
        border-collapse: collapse;
        width: 100%;
        margin: 1em 0;
    }
    
    .google-doc-content table td,
    .google-doc-content table th {
        border: 1px solid #ddd;
        padding: 8px;
    }
    
    /* Ensure Google Docs styles don't conflict with theme */
    .google-doc-content * {
        box-sizing: border-box;
    }
    </style>
    <?php
}


// Update menu with latest posts - hierarchical structure
function update_the_navigation_menu() {
    $menu_name = 'THE Pages';
    $menu = wp_get_nav_menu_object($menu_name);
    
    if (!$menu) return;
    
    // Get all THE posts
    $all_posts = get_posts([
        'post_type' => 'the',
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC'
    ]);
    
    // Clear existing menu items
    $menu_items = wp_get_nav_menu_items($menu->term_id);
    if ($menu_items) {
        foreach ($menu_items as $item) {
            wp_delete_post($item->ID, true);
        }
    }
    
    // Build hierarchical structure: SDG parents with children
    $sdg_structure = [];
    
    // First, organize posts by SDG
    foreach ($all_posts as $post) {
        // Check if this is an SDG parent
        $is_parent = get_post_meta($post->ID, '_is_sdg_parent', true);
        
        if ($is_parent) {
            $sdg_number = get_post_meta($post->ID, '_sdg_number', true);
            if ($sdg_number) {
                $sdg_structure[$sdg_number] = [
                    'parent' => $post,
                    'children' => []
                ];
            }
        } else {
            // Extract SDG number from title pattern (e.g., "1.3.1" → 1)
            if (preg_match('/^(\d+)\./', $post->post_title, $matches)) {
                $sdg_number = intval($matches[1]);
                
                if (!isset($sdg_structure[$sdg_number])) {
                    $sdg_structure[$sdg_number] = [
                        'parent' => null,
                        'children' => []
                    ];
                }
                
                $sdg_structure[$sdg_number]['children'][] = $post;
            }
        }
    }
    
    // Sort by SDG number
    ksort($sdg_structure);
    
    // Add to menu - only SDG parent pages (no children)
    foreach ($sdg_structure as $sdg_number => $data) {
        // Skip if parent doesn't exist
        if (!isset($data['parent'])) continue;
        
        $parent = $data['parent'];
        
        // Add parent menu item only (no children)
        wp_update_nav_menu_item($menu->term_id, 0, [
            'menu-item-title' => $parent->post_title,
            'menu-item-object-id' => $parent->ID,
            'menu-item-object' => 'the',
            'menu-item-type' => 'post_type',
            'menu-item-status' => 'publish'
        ]);
    }
}
