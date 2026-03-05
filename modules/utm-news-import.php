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
 * ✅ Auto-updates existing posts when remote content changes
 * ✅ All links point directly to news.utm.my (301 redirect)
 * ✅ Auto-cleanup of old posts (keeps recent + buffer to avoid redundancy)
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
 * - category_name: Local category name to assign imported posts (default: "News")
 * - posts_per_page: Number of posts to display (default: 3)
 * - import_count: Number of posts to import/fetch from news.utm.my (default: 3)
 * - Note: By default the shortcode will sync `posts_per_page` to `import_count` if
 *   you do not explicitly provide `posts_per_page`. This ensures display matches
 *   what was fetched.
 * - cache_duration: Cache duration in seconds (default: 3600 = 1 hour)
 * - display: Display mode - "inline" (show posts) or "import" (import only) (default: "inline")
 * - show_date: Show post date - "yes" or "no" (default: "yes")
 * - show_category: Show category badge - "yes" or "no" (default: "yes")
 * - target: Link target - "_blank" or "_self" (default: "_blank")
 * - Fallback behaviour: If neither `department_id` nor `source_category` is provided,
 *   the importer will fall back to importing the latest 3 posts site-wide from
 *   news.utm.my and assign them to the local category specified by `category_name`.
 * - Auto-cleanup: After each import, old posts beyond the import_count + 2 buffer
 *   are automatically deleted to prevent redundancy with news.utm.my. This keeps
 *   your local database lean while ensuring display widgets always have fresh content.
 *
 * EXAMPLES:
 * - Import by department ID (function):
 *     import_utm_news_posts_flexible('3058', 'News');
 *
 * - Import by department slug (function):
 *     import_utm_news_posts_flexible('school-of-professional-continuing-education', 'SPCE News');
 *
 * - Import by category slug from news.utm.my (function):
 *     import_utm_news_posts_flexible('', 'Alumni News', 3600, 'alumni-networking');
 *
 * - Shortcode to display/import (department):
 *     [utm_news_department department_id="3058" posts_per_page="5"]
 *
 * - Shortcode to display/import (category):
 *     [utm_news_department source_category="alumni-networking" category_name="Alumni News"]
 *
 * - Shortcode with no source (fallback to latest 3 posts):
 *     [utm_news_department]
 *
 * - Shortcode with custom import count (fetch 5 posts):
 *     [utm_news_department import_count="5"]
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

// Function to cleanup old imported posts
// Keeps only the most recent N posts per source to avoid redundancy
function cleanup_old_imported_posts($category, $department_id = '', $source_category = '', $keep_count = 3) {
    // Add buffer - keep a few extra posts in case some are updated
    $keep_with_buffer = intval($keep_count) + 2;
    
    // Build query to find all imported posts from this source
    $query_args = array(
        'post_type' => 'post',
        'post_status' => 'any',
        'posts_per_page' => -1, // Get all posts
        'orderby' => 'date',
        'order' => 'DESC',
        'fields' => 'ids', // Only get IDs for performance
    );
    
    // Filter by category if available
    if ($category && isset($category->term_id)) {
        $query_args['category'] = $category->term_id;
    }
    
    // Build meta query to identify source
    $meta_query = array('relation' => 'AND');
    
    // Must have original_id or original_url (identifies imported posts)
    $meta_query[] = array(
        'relation' => 'OR',
        array(
            'key' => 'utm_news_original_id',
            'compare' => 'EXISTS'
        ),
        array(
            'key' => 'utm_news_original_url',
            'compare' => 'EXISTS'
        )
    );
    
    // Filter by specific source if provided
    if (!empty($department_id)) {
        $meta_query[] = array(
            'key' => 'utm_news_department_id',
            'value' => $department_id,
            'compare' => '='
        );
    } elseif (!empty($source_category)) {
        $meta_query[] = array(
            'key' => 'utm_news_source_category',
            'value' => $source_category,
            'compare' => '='
        );
    }
    
    $query_args['meta_query'] = $meta_query;
    
    // Get all matching posts
    $all_post_ids = get_posts($query_args);
    
    // If we have more posts than we want to keep
    if (count($all_post_ids) > $keep_with_buffer) {
        // Get the IDs to delete (older posts beyond the keep limit)
        $posts_to_delete = array_slice($all_post_ids, $keep_with_buffer);
        
        foreach ($posts_to_delete as $post_id) {
            // Delete post and its attachments
            wp_delete_post($post_id, true); // true = force delete (skip trash)
            
            // Clean up orphaned attachments
            $attachments = get_posts(array(
                'post_type' => 'attachment',
                'post_parent' => $post_id,
                'posts_per_page' => -1,
                'fields' => 'ids'
            ));
            
            foreach ($attachments as $attachment_id) {
                wp_delete_attachment($attachment_id, true);
            }
        }
        
        $deleted_count = count($posts_to_delete);
        if (!empty($department_id)) {
            $source = "department $department_id";
        } elseif (!empty($source_category)) {
            $source = "category $source_category";
        } else {
            $source = 'site-wide';
        }
        error_log("UTM News Cleanup: Deleted $deleted_count old posts from $source (keeping $keep_with_buffer most recent)");
    }
}

// Function to import posts with flexible parameters
// How to use:
// By department ID: import_utm_news_posts_flexible('3058', 'MJIIT News');
// By department slug: import_utm_news_posts_flexible('school-of-professional-continuing-education', 'SPCE News');
// By category: import_utm_news_posts_flexible('', 'Alumni News', 3600, 'alumni-networking');
function import_utm_news_posts_flexible($department_id = '', $category_name = 'News', $cache_duration = 3600, $source_category = '', $import_count = 3) {
    // Resolve department slug to ID if needed
    if (!empty($department_id) && !is_numeric($department_id)) {
        $resolved_id = resolve_department_slug_to_id($department_id);
        error_log("UTM News: Resolved department slug '$department_id' to ID '$resolved_id'");
        $department_id = $resolved_id;
    }
    
    // Build cache key based on import type. Import count controls how many
    // posts we request from the remote API. Cache key includes the count so
    // different counts are cached separately.
    $remote_per_page = max(1, intval($import_count));
    $cache_key_suffix = '';
    if (!empty($department_id)) {
        $cache_key_suffix = 'dept_' . sanitize_key($department_id);
    } elseif (!empty($source_category)) {
        $cache_key_suffix = 'cat_' . sanitize_key($source_category);
    } else {
        // Fallback: import latest posts site-wide
        $cache_key_suffix = 'all';
    }

    // Include count in transient key to separate caches for different counts
    $transient_key = 'utm_news_' . $cache_key_suffix . '_count_' . intval($remote_per_page);
    $cached_data = get_transient($transient_key);

    if ($cached_data !== false) {
        return true; // Data is fresh, no need to import
    }

    // Build API URL based on import type
    $remote_api_url = 'https://news.utm.my/wp-json/wp/v2/posts?per_page=' . intval($remote_per_page);
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
        // Prepare remote identifiers
        $remote_id = isset($post['id']) ? $post['id'] : '';
        $remote_url = isset($post['link']) ? $post['link'] : '';
        $remote_title = isset($post['title']['rendered']) ? wp_strip_all_tags($post['title']['rendered']) : '';

        // Check if post already exists by original ID or original URL
        $existing_post = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => 'utm_news_original_id',
                    'value' => $remote_id,
                    'compare' => '='
                ),
                array(
                    'key' => 'utm_news_original_url',
                    'value' => $remote_url,
                    'compare' => '='
                )
            )
        ));

        if (!empty($existing_post)) {
            // Post exists - check if remote version is newer and update if needed
            $local = $existing_post[0];
            $remote_modified = isset($post['modified_gmt']) ? $post['modified_gmt'] : $post['modified'];
            $local_modified = $local->post_modified_gmt;
            
            // Compare timestamps and update if remote is newer
            if (strtotime($remote_modified) > strtotime($local_modified)) {
                wp_update_post(array(
                    'ID' => $local->ID,
                    'post_title' => wp_strip_all_tags($post['title']['rendered']),
                    'post_content' => $post['content']['rendered'],
                    'post_excerpt' => wp_strip_all_tags($post['excerpt']['rendered']),
                    'post_date' => $post['date'],
                ));
                
                // Update featured image if changed
                if (!empty($post['featured_media'])) {
                    $stored_media_id = get_post_meta($local->ID, 'utm_news_original_media_id', true);
                    if ($stored_media_id != $post['featured_media']) {
                        import_featured_image_from_url($local->ID, $post['featured_media'], $post['id']);
                    }
                }
                
                // Update metadata
                update_post_meta($local->ID, 'utm_news_remote_modified', $remote_modified);
                update_post_meta($local->ID, 'utm_news_original_url', $post['link']);
                $imported_count++;
            }
            continue; // Skip to next post
        }
        
        // As a last resort, check for an existing local post with the same
        // title. If found and it doesn't already have the original id meta,
        // attach the metadata instead of inserting a duplicate.
        $by_title = null;
        if (!empty($remote_title)) {
            $by_title = get_page_by_title($remote_title, OBJECT, 'post');
        }
        if ($by_title && !get_post_meta($by_title->ID, 'utm_news_original_id', true)) {
            // Attach source metadata to existing post and skip inserting a new one
            if (!empty($remote_id)) {
                update_post_meta($by_title->ID, 'utm_news_original_id', $remote_id);
            }
            if (!empty($remote_url)) {
                update_post_meta($by_title->ID, 'utm_news_original_url', $remote_url);
            }
            update_post_meta($by_title->ID, 'utm_news_imported_date', current_time('mysql'));
            if (!empty($department_id)) {
                update_post_meta($by_title->ID, 'utm_news_department_id', $department_id);
            }
            if (!empty($source_category)) {
                update_post_meta($by_title->ID, 'utm_news_source_category', $source_category);
            }
            $imported_count++;
            // skip insertion
            continue;
        }
        
        // Prepare post data for new post
        $post_data = array(
            'post_title' => wp_strip_all_tags($post['title']['rendered']),
            'post_content' => $post['content']['rendered'],
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
    
    // Set transient to prevent excessive API calls
    set_transient($transient_key, array(
        'last_import' => current_time('mysql'),
        'imported_count' => $imported_count,
        'import_count' => intval($import_count)
    ), $cache_duration);

    if (!empty($department_id)) {
        $import_source = "department $department_id";
    } elseif (!empty($source_category)) {
        $import_source = "category $source_category";
    } else {
        $import_source = 'site-wide (fallback)';
    }
    error_log("UTM News Import Success: Imported $imported_count new posts from $import_source (requested $remote_per_page)");

    // Cleanup old imported posts - keep only the most recent ones
    cleanup_old_imported_posts($category, $department_id, $source_category, $remote_per_page);

    return true;
}

// Add shortcode with flexible parameters
add_shortcode('utm_news_department', function($atts) {
    $user_atts = $atts; // keep original user-provided attributes to detect explicit values
    // Parse attributes with defaults
    $atts = shortcode_atts(array(
        'id' => '', // Legacy alias (mapped to department_id)
        'department_id' => '', // Department ID (optional if source_category is used)
        'source_category' => '', // Source category slug from news.utm.my (optional if department_id is used)
        'category_name' => 'News',
        'posts_per_page' => 3,
        'import_count' => 3,
        'cache_duration' => 3600, // 1 hour in seconds
        'show_date' => 'yes',
        'show_category' => 'yes',
        'target' => '_blank', // Link target
        'display' => 'inline' // Display mode: 'inline' or 'import'
    ), $atts);

    // Backward compatibility for legacy usage:
    // [utm_news_department id="3058"]
    if (empty($atts['department_id']) && !empty($atts['id'])) {
        $atts['department_id'] = $atts['id'];
    }

    // If posts_per_page wasn't explicitly provided by the user, sync it to
    // the import_count so display matches what we fetch by default.
    if (!isset($user_atts['posts_per_page'])) {
        $atts['posts_per_page'] = intval($atts['import_count']);
    }

    // If no source specified, shortcode will use fallback behaviour (import latest posts)

    // Import latest posts (with rate limiting via transient)
    $import_result = import_utm_news_posts_flexible(
        $atts['department_id'], 
        $atts['category_name'],
        intval($atts['cache_duration']),
        $atts['source_category'],
        intval($atts['import_count'])
    );

    // If display mode is "import", just import and return empty string
    if ($atts['display'] === 'import') {
        return ''; // Silent import, no output
    }

    // Get all posts from the specified category
    $cat_slug = sanitize_title($atts['category_name']);
    
    // Build meta query only if a source was provided. When using the fallback
    // (no source provided), we simply query the local category set by
    // `category_name` and avoid a meta_query filter.
    $meta_query = array();
    if (!empty($atts['department_id']) || !empty($atts['source_category'])) {
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
    }

    $query_args = array(
        'category_name' => $cat_slug,
        'posts_per_page' => intval($atts['posts_per_page']),
        'orderby' => 'date',
        'order' => 'DESC',
    );
    if (!empty($meta_query)) {
        $query_args['meta_query'] = $meta_query;
    }

    $posts = get_posts($query_args);

    if (empty($posts)) {
        return '<p class="utm-news-no-posts">No posts found from UTM Newshub.</p>';
    }

    // Build output
    $output = '<div class="utm-news-department" data-department="' . esc_attr($atts['department_id']) . '">';
    
    foreach ($posts as $post) {
        $date = date('F j, Y', strtotime($post->post_date));
    // Prefer linking to the original source URL when available. Fall back
    // to the local permalink if original URL metadata isn't present.
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
    $category_name = isset($_GET['category_name']) ? sanitize_text_field($_GET['category_name']) : 'News';
    $import_count = isset($_GET['import_count']) ? intval($_GET['import_count']) : 3;

    // Force import by clearing transient. If no source provided, clear the
    // 'all' fallback transient so manual import will import latest site-wide posts.
    // We clear both legacy keys (without count) and new keys (with count) for
    // backward compatibility.
    if (!empty($department_id)) {
        delete_transient('utm_news_dept_' . $department_id);
        delete_transient('utm_news_dept_' . $department_id . '_count_' . $import_count);
    }
    if (!empty($source_category)) {
        delete_transient('utm_news_cat_' . $source_category);
        delete_transient('utm_news_cat_' . $source_category . '_count_' . $import_count);
    }
    if (empty($department_id) && empty($source_category)) {
        delete_transient('utm_news_all');
        delete_transient('utm_news_all_count_' . $import_count);
    }

    $result = import_utm_news_posts_flexible($department_id, $category_name, 0, $source_category, $import_count);
    
    if ($result) {
        wp_redirect(admin_url('edit.php?utm_news_import=success'));
    } else {
        wp_redirect(admin_url('edit.php?utm_news_import=error'));
    }
    exit;
});

// Redirect imported posts to their original URL on news.utm.my
// This ensures users always see content on news.utm.my, not the local copy
add_action('template_redirect', function() {
    // Only redirect on single post pages
    if (!is_single()) {
        return;
    }
    
    // Check if this is an imported post
    $post_id = get_the_ID();
    $original_url = get_post_meta($post_id, 'utm_news_original_url', true);
    
    // If original URL exists, redirect to news.utm.my
    if (!empty($original_url)) {
        wp_redirect($original_url, 301); // 301 = permanent redirect
        exit;
    }
});


