<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * UTM News Import Module
 * 
 * This module can be used by any UTM subdomain to import news from news.utm.my
 * No domain restrictions - flexible for all sites in the UTM network
 */

/**
 * Import and display posts from news.utm.my using WordPress REST API
 * 
 * FEATURES:
 * ✅ Uses standard WordPress REST API (no custom code needed on news.utm.my)
 * ✅ Imports featured images automatically
 * ✅ Supports department ID or slug
 * ✅ Supports category-based imports
 * ✅ Rate limiting with transient cache (1 hour default)
 * ✅ Silent import mode for background syncing
 * 
 * USAGE EXAMPLES:
 * By department ID: [utm_news_department department_id="3058"]
 * By department slug: [utm_news_department department_id="school-of-professional-continuing-education"]
 * By category: [utm_news_department source_category="alumni-networking"]
 * Silent import: [utm_news_department department_id="3058" display="import"]
 * 
 * PARAMETERS:
 * - department_id: Department ID or slug from news.utm.my (optional if source_category is used)
 * - source_category: Category ID or slug from news.utm.my (optional if department_id is used)
 * - category_name: Local category name to assign imported posts (default: "MJIIT News")
 * - posts_per_page: Number of posts to display (default: 3)
 * - cache_duration: Cache duration in seconds (default: 3600 = 1 hour)
 * - display: Display mode - "inline" (show posts) or "import" (import only) (default: "inline")
 * - show_date: Show post date - "yes" or "no" (default: "yes")
 * - show_category: Show category badge - "yes" or "no" (default: "yes")
 * - target: Link target - "_blank" or "_self" (default: "_blank")
 */

// Function to resolve department slug to ID from news.utm.my
function resolve_department_slug_to_id($slug_or_id) {
    // If it's numeric, assume it's an ID
    if (is_numeric($slug_or_id)) {
        return $slug_or_id;
    }
    
    // Fetch department by slug from news.utm.my API
    $dept_url = 'https://news.utm.my/wp-json/wp/v2/department?slug=' . urlencode($slug_or_id);
    $dept_response = wp_remote_get($dept_url, array('timeout' => 10));
    
    if (is_wp_error($dept_response)) {
        error_log('UTM News Department Resolve Error: ' . $dept_response->get_error_message());
        return $slug_or_id; // Return original value if fetch fails
    }
    
    $departments = json_decode(wp_remote_retrieve_body($dept_response), true);
    
    if (!empty($departments) && is_array($departments) && isset($departments[0]['id'])) {
        return $departments[0]['id'];
    }
    
    return $slug_or_id; // Return original if not found
}

// Function to import featured image from news.utm.my
function import_featured_image_from_url($post_id, $featured_media_id, $original_post_id) {
    // Get featured image URL from news.utm.my API
    $media_url = 'https://news.utm.my/wp-json/wp/v2/media/' . $featured_media_id;
    $media_response = wp_remote_get($media_url, array('timeout' => 15));
    
    if (is_wp_error($media_response)) {
        return false;
    }
    
    $media_data = json_decode(wp_remote_retrieve_body($media_response), true);
    if (empty($media_data['source_url'])) {
        return false;
    }
    
    $image_url = $media_data['source_url'];
    
    // Check if image already imported
    $existing_attachment = get_posts(array(
        'post_type' => 'attachment',
        'meta_key' => 'utm_news_original_media_id',
        'meta_value' => $featured_media_id,
        'posts_per_page' => 1
    ));
    
    if (!empty($existing_attachment)) {
        // Use existing attachment
        set_post_thumbnail($post_id, $existing_attachment[0]->ID);
        return $existing_attachment[0]->ID;
    }
    
    // Download and import image
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    
    $tmp = download_url($image_url);
    if (is_wp_error($tmp)) {
        return false;
    }
    
    $file_array = array(
        'name' => basename($image_url),
        'tmp_name' => $tmp
    );
    
    $attachment_id = media_handle_sideload($file_array, $post_id, $media_data['title']['rendered']);
    
    if (is_wp_error($attachment_id)) {
        @unlink($file_array['tmp_name']);
        return false;
    }
    
    // Store original media ID
    update_post_meta($attachment_id, 'utm_news_original_media_id', $featured_media_id);
    update_post_meta($attachment_id, 'utm_news_original_post_id', $original_post_id);
    
    // Set as featured image
    set_post_thumbnail($post_id, $attachment_id);
    
    return $attachment_id;
}

// Function to import posts with flexible parameters
function import_utm_news_posts_flexible($department_id = '', $category_name = 'MJIIT News', $cache_duration = 3600, $source_category = '') {
    // Resolve department slug to ID if needed
    if (!empty($department_id) && !is_numeric($department_id)) {
        $resolved_id = resolve_department_slug_to_id($department_id);
        error_log("UTM News: Resolved department slug '$department_id' to ID '$resolved_id'");
        $department_id = $resolved_id;
    }
    
    // Build cache key based on import type
    $cache_key_suffix = '';
    if (!empty($department_id)) {
        $cache_key_suffix = 'dept_' . sanitize_key($department_id);
    } elseif (!empty($source_category)) {
        $cache_key_suffix = 'cat_' . sanitize_key($source_category);
    } else {
        error_log('UTM News Import Error: No department_id or source_category provided');
        return false;
    }
    
    $transient_key = 'utm_news_' . $cache_key_suffix;
    $cached_data = get_transient($transient_key);
    
    if ($cached_data !== false) {
        return true; // Data is fresh, no need to import
    }
    
    // Build API URL based on import type
    $remote_api_url = 'https://news.utm.my/wp-json/wp/v2/posts?per_page=25';
    if (!empty($department_id)) {
        // Department is a custom taxonomy in news.utm.my (accepts ID or slug)
        $remote_api_url .= '&department=' . urlencode($department_id);
    }
    if (!empty($source_category)) {
        // Categories use category ID or slug
        $remote_api_url .= '&categories=' . urlencode($source_category);
    }
    $response = wp_remote_get($remote_api_url, array(
        'timeout' => 15,
        'sslverify' => true
    ));

    if (is_wp_error($response)) {
        error_log('UTM News Import Error: ' . $response->get_error_message());
        return false;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        error_log('UTM News Import Error: HTTP ' . $response_code);
        return false;
    }

    $posts = json_decode(wp_remote_retrieve_body($response), true);
    
    if (empty($posts) || !is_array($posts)) {
        error_log('UTM News Import Error: No posts returned or invalid format');
        return false;
    }
    
    // Create or get category with dynamic name
    $cat_slug = sanitize_title($category_name);
    $category = get_term_by('slug', $cat_slug, 'category');
    
    if (!$category) {
        $term_data = wp_insert_term($category_name, 'category', array('slug' => $cat_slug));
        if (!is_wp_error($term_data)) {
            $category = get_term_by('id', $term_data['term_id'], 'category');
        }
    }
    
    $imported_count = 0;

    foreach ($posts as $post) {
        // Check if post already exists
        $existing_post = get_posts(array(
            'meta_key' => 'utm_news_original_id',
            'meta_value' => $post['id'],
            'post_type' => 'post',
            'post_status' => 'any',
            'posts_per_page' => 1
        ));

        if (empty($existing_post)) {
            // Prepare post data
            $post_data = array(
                'post_title' => wp_strip_all_tags($post['title']['rendered']),
                'post_content' => $post['content']['rendered'] . '<p><a href="' . esc_url($post['link']) . '" target="_blank">Read more on UTM Newshub</a></p>',
                'post_excerpt' => wp_strip_all_tags($post['excerpt']['rendered']),
                'post_status' => 'publish',
                'post_date' => $post['date'],
                'post_author' => 1, // Default to admin user
                'post_category' => $category ? array($category->term_id) : array()
            );

            // Insert post
            $post_id = wp_insert_post($post_data);
            
            // Import featured image if available
            if ($post_id && !is_wp_error($post_id) && !empty($post['featured_media'])) {
                import_featured_image_from_url($post_id, $post['featured_media'], $post['id']);
            }

            if ($post_id && !is_wp_error($post_id)) {
                // Add source metadata
                update_post_meta($post_id, 'utm_news_original_id', $post['id']);
                update_post_meta($post_id, 'utm_news_original_url', $post['link']);
                update_post_meta($post_id, 'utm_news_imported_date', current_time('mysql'));
                
                // Store import source metadata
                if (!empty($department_id)) {
                    update_post_meta($post_id, 'utm_news_department_id', $department_id);
                }
                if (!empty($source_category)) {
                    update_post_meta($post_id, 'utm_news_source_category', $source_category);
                }
                
                $imported_count++;
            }
        }
    }
    
    // Set transient to prevent excessive API calls
    set_transient($transient_key, array(
        'last_import' => current_time('mysql'),
        'imported_count' => $imported_count
    ), $cache_duration);
    
    $import_source = !empty($department_id) ? "department $department_id" : "category $source_category";
    error_log("UTM News Import Success: Imported $imported_count new posts from $import_source");

    return true;
}

// Add shortcode with flexible parameters
add_shortcode('utm_news_department', function($atts) {
    // Parse attributes with defaults
    $atts = shortcode_atts(array(
        'department_id' => '', // Department ID (optional if source_category is used)
        'source_category' => '', // Source category slug from news.utm.my (optional if department_id is used)
        'category_name' => 'MJIIT News',
        'posts_per_page' => 3,
        'cache_duration' => 3600, // 1 hour in seconds
        'show_date' => 'yes',
        'show_category' => 'yes',
        'target' => '_blank', // Link target
        'display' => 'inline' // Display mode: 'inline' or 'import'
    ), $atts);

    // Validate: at least one source must be specified
    if (empty($atts['department_id']) && empty($atts['source_category'])) {
        return '<p class="utm-news-error">Error: Please specify either department_id or source_category.</p>';
    }

    // Import latest posts (with rate limiting via transient)
    $import_result = import_utm_news_posts_flexible(
        $atts['department_id'], 
        $atts['category_name'],
        intval($atts['cache_duration']),
        $atts['source_category']
    );

    // If display mode is "import", just import and return empty string
    if ($atts['display'] === 'import') {
        return ''; // Silent import, no output
    }

    // Get all posts from the specified category
    $cat_slug = sanitize_title($atts['category_name']);
    
    // Build meta query based on what was used for import
    $meta_query = array('relation' => 'OR');
    if (!empty($atts['department_id'])) {
        $meta_query[] = array(
            'key' => 'utm_news_department_id',
            'value' => $atts['department_id'],
            'compare' => '='
        );
    }
    if (!empty($atts['source_category'])) {
        $meta_query[] = array(
            'key' => 'utm_news_source_category',
            'value' => $atts['source_category'],
            'compare' => '='
        );
    }
    
    $posts = get_posts(array(
        'category_name' => $cat_slug,
        'posts_per_page' => intval($atts['posts_per_page']),
        'orderby' => 'date',
        'order' => 'DESC',
        'meta_query' => $meta_query
    ));

    if (empty($posts)) {
        return '<p class="utm-news-no-posts">No posts found from UTM Newshub.</p>';
    }

    // Build output
    $output = '<div class="utm-news-department" data-department="' . esc_attr($atts['department_id']) . '">';
    
    foreach ($posts as $post) {
        $date = date('F j, Y', strtotime($post->post_date));
        $original_url = get_post_meta($post->ID, 'utm_news_original_url', true);
        $link = $original_url ? $original_url : get_permalink($post->ID);
        $categories = get_the_category($post->ID);
        $category = !empty($categories) ? $categories[0]->name : 'Uncategorized';

        $output .= '<div class="news-item">';
        $output .= sprintf('<h3><a href="%s" target="%s">%s</a></h3>',
            esc_url($link),
            esc_attr($atts['target']),
            esc_html($post->post_title)
        );
        
        if ($atts['show_date'] === 'yes' || $atts['show_category'] === 'yes') {
            $output .= '<div class="news-meta">';
            if ($atts['show_date'] === 'yes') {
                $output .= '<span class="date">' . esc_html($date) . '</span>';
            }
            if ($atts['show_category'] === 'yes') {
                $output .= '<span class="category">' . esc_html($category) . '</span>';
            }
            $output .= '</div>';
        }
        
        if (!empty($post->post_excerpt)) {
            $output .= '<div class="news-excerpt">' . wp_trim_words($post->post_excerpt, 30) . '</div>';
        }
        
        $output .= '</div>';
    }
    
    $output .= '</div>';

    // Add responsive CSS
    $output .= '
    <style>
        .utm-news-department {
            margin: 20px 0;
        }
        .utm-news-department .news-item {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }
        .utm-news-department .news-item:last-child {
            border-bottom: none;
        }
        .utm-news-department .news-item h3 {
            margin: 0 0 10px 0;
            font-size: 1.2em;
            line-height: 1.4;
        }
        .utm-news-department .news-item h3 a {
            color: #009154;
            text-decoration: none;
            transition: color 0.3s ease;
        }
        .utm-news-department .news-item h3 a:hover {
            color: #007a45;
            text-decoration: underline;
        }
        .utm-news-department .news-meta {
            color: #666;
            font-size: 0.9em;
            margin: 5px 0 10px 0;
        }
        .utm-news-department .news-meta .date {
            margin-right: 10px;
        }
        .utm-news-department .category {
            display: inline-block;
            font-size: 0.8em;
            color: #fff;
            background-color: #009154;
            padding: 2px 8px;
            border-radius: 12px;
        }
        .utm-news-department .news-excerpt {
            color: #555;
            font-size: 0.95em;
            line-height: 1.6;
            margin-top: 8px;
        }
        .utm-news-no-posts {
            color: #666;
            font-style: italic;
            padding: 20px;
            text-align: center;
            background: #f9f9f9;
            border-radius: 4px;
        }
        @media (max-width: 768px) {
            .utm-news-department .news-item h3 {
                font-size: 1.1em;
            }
        }
    </style>';

    return $output;
});

// Optional: Manual import trigger via admin action
// Usage: Add button in admin or use WP-CLI
// URL: /wp-admin/admin.php?action=utm_news_manual_import&department_id=3058
// URL: /wp-admin/admin.php?action=utm_news_manual_import&source_category=alumni-networking
add_action('admin_action_utm_news_manual_import', function() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    $department_id = isset($_GET['department_id']) ? sanitize_text_field($_GET['department_id']) : '';
    $source_category = isset($_GET['source_category']) ? sanitize_text_field($_GET['source_category']) : '';
    $category_name = isset($_GET['category_name']) ? sanitize_text_field($_GET['category_name']) : 'MJIIT News';
    
    // Validate input
    if (empty($department_id) && empty($source_category)) {
        wp_die('Error: Please specify either department_id or source_category parameter.');
    }
    
    // Force import by clearing transient
    if (!empty($department_id)) {
        delete_transient('utm_news_dept_' . $department_id);
    }
    if (!empty($source_category)) {
        delete_transient('utm_news_cat_' . $source_category);
    }
    
    $result = import_utm_news_posts_flexible($department_id, $category_name, 0, $source_category);
    
    if ($result) {
        wp_redirect(admin_url('edit.php?utm_news_import=success'));
    } else {
        wp_redirect(admin_url('edit.php?utm_news_import=error'));
    }
    exit;
});


