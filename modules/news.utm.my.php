<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Import and display posts from news.utm.my
 * Usage: [utm_news_department id="3058"]
 */

// Schedule the import every 4 hours
if (!wp_next_scheduled('import_utm_news_posts_event')) {
    wp_schedule_event(time(), 'four_hours', 'import_utm_news_posts_event');
}

// Add custom interval for 4 hours
add_filter('cron_schedules', function($schedules) {
    $schedules['four_hours'] = array(
        'interval' => 14400, // 4 hours in seconds
        'display' => __('Every 4 Hours')
    );
    return $schedules;
});

// Hook the import function to the scheduled event
add_action('import_utm_news_posts_event', function() {
    import_utm_news_posts('3058'); // Default department ID
});

// Function to import posts
function import_utm_news_posts($department_id) {
    $remote_api_url = 'https://news.utm.my/wp-json/wp/v2/posts?department=' . $department_id . '&per_page=25';
    $response = wp_remote_get($remote_api_url);

    if (is_wp_error($response)) {
        return false;
    }

    $posts = json_decode(wp_remote_retrieve_body($response), true);
    
    // Create category if it doesn't exist
    $cat_name = 'MJIIT News';
    $cat_slug = 'mjiit-news';
    $category = get_term_by('slug', $cat_slug, 'category');
    
    if (!$category) {
        wp_insert_term($cat_name, 'category', array('slug' => $cat_slug));
        $category = get_term_by('slug', $cat_slug, 'category');
    }

    foreach ($posts as $post) {
        // Check if post already exists
        $existing_post = get_posts(array(
            'meta_key' => 'utm_news_original_id',
            'meta_value' => $post['id'],
            'post_type' => 'post',
            'post_status' => 'any'
        ));

        if (empty($existing_post)) {
            // Prepare post data
            $post_data = array(
                'post_title' => wp_strip_all_tags($post['title']['rendered']),
                'post_content' => $post['content']['rendered'] . '<p><a href="' . esc_url($post['link']) . '" target="_blank">Read more on UTM Newshub</a></p>',
                'post_excerpt' => wp_strip_all_tags($post['excerpt']['rendered']),
                'post_status' => 'publish',
                'post_date' => $post['date'],
                'post_category' => array($category->term_id)
            );

            // Insert post
            $post_id = wp_insert_post($post_data);

            if ($post_id) {
                // Add source metadata
                update_post_meta($post_id, 'utm_news_original_id', $post['id']);
                update_post_meta($post_id, 'utm_news_original_url', $post['link']);
            }
        }
    }

    return true;
}

// Add shortcode
add_shortcode('utm_news_department', function($atts) {
    // Parse attributes
    $atts = shortcode_atts(array(
        'id' => '3058', // Default department ID
    ), $atts);

    // Import latest posts
    import_utm_news_posts($atts['id']);

    // Get all posts from local WordPress
    $posts = get_posts(array(
        'category_name' => 'mjiit-news',
        'posts_per_page' => 3,
        'orderby' => 'date',
        'order' => 'DESC'
    ));

    if (empty($posts)) {
        return '<p>No posts found.</p>';
    }

    // Build output
    $output = '<div class="utm-news-department">';
    
    foreach ($posts as $post) {
        $date = date('F j, Y', strtotime($post->post_date));
        $original_url = get_post_meta($post->ID, 'utm_news_original_url', true);
        $link = $original_url ? $original_url : get_permalink($post->ID);
        $category = get_the_category($post->ID) ? get_the_category($post->ID)[0]->name : 'Uncategorized';

        $output .= sprintf(
            '<div class="news-item">
                <h3><a href="%s" target="_blank">%s</a></h3>
                <div class="news-meta">%s <span class="category">%s</span></div>
            </div>',
            esc_url($link),
            esc_html($post->post_title),
            esc_html($date),
            esc_html($category)
        );
    }
    
    $output .= '</div>';

    // Add basic CSS
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
        .utm-news-department .news-meta {
            color: #666;
            font-size: 0.9em;
            margin: 5px 0;
        }
        .utm-news-department .category {
            display: inline-block;
            font-size: 0.8em;
            color: #fff;
            background-color:#009154;
            padding: 0px 8px;
            border-radius: 12px;
            margin-top: 5px;
            margin-left: 10px;
        }
    </style>';

    return $output;
});
