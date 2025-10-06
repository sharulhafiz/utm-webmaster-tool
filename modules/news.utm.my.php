<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Function to disable post editing if current user is an author and current post status is 'pending review'
// print hello world with admin notice and show edit block notice if redirected
add_action('admin_notices', function() {
    // Show block notice if redirected
    if (isset($_GET['edit_blocked']) && $_GET['edit_blocked'] == '1') {
        echo '<div class="notice notice-error is-dismissible"><p>You are not allowed to edit a post while it is pending review.</p></div>';
    }
});

// Server-side block: Prevent authors from editing pending posts
add_action('admin_init', function() {
    global $pagenow;
    if ($pagenow === 'post.php' && isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['post'])) {
        $post_id = intval($_GET['post']);
        $post = get_post($post_id);
        if ($post && $post->post_status === 'pending') {
            $current_user = wp_get_current_user();
            $is_author_role = in_array('author', (array) $current_user->roles);
            $is_post_author = ($current_user->ID === (int) $post->post_author);
            if ($is_author_role || $is_post_author) {
                // Redirect to posts list with error
                $redirect_url = admin_url('edit.php?edit_blocked=1');
                wp_redirect($redirect_url);
                exit;
            }
        }
    }
});

// Hide Edit link in post list for authors on pending posts
add_filter('post_row_actions', function($actions, $post) {
    if ($post->post_status === 'pending') {
        $current_user = wp_get_current_user();
        $is_author_role = in_array('author', (array) $current_user->roles);
        $is_post_author = ($current_user->ID === (int) $post->post_author);
        if ($is_author_role || $is_post_author) {
            unset($actions['edit']);
        }
    }
    return $actions;
}, 10, 2);


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

// If domain is not "news.utm.my", return
if ($_SERVER['HTTP_HOST'] !== 'news.utm.my') {
    return;
}

// Only run in admin area
add_action('admin_init', function() {
    // Only target the specific user
    $current_user = wp_get_current_user();
    if ($current_user && $current_user->user_email === 'officevc@utm.my') {
        // Grant capabilities to the user to publish and edit posts
        $current_user->add_cap('edit_posts');
        $current_user->add_cap('publish_posts');
        // Optionally, ensure the user has the author role
        if (!in_array('author', $current_user->roles)) {
            $current_user->add_role('author');
        }
    }
});

// Append notice after posts by officevc@utm.my
add_filter('the_content', function($content) {
    global $post;
    if (is_admin() || !is_singular() || !isset($post->post_author)) {
        return $content;
    }
    $author = get_userdata($post->post_author);
    if ($author && $author->user_email === 'officevc@utm.my') {
        $notice = '<div style="margin-top:2em;font-style:italic;color:#555;">Berita ini dikendalikan sepenuhnya oleh Pejabat Naib Canselor</div>';
        return $content . $notice;
    }
    return $content;
});

/*
Shortcode to display analytics of posts by alumni category and department who posted it
It will have 2 list:
1. List of departments and their total posts (with category 'alumni')
2. List of news with category 'alumni' and link to the post

This only limited to current year posts
*/
add_shortcode('utm_news_alumni_analytics', function($atts) {
    // Get posts by alumni-networking category, limited to current year
    $atts = shortcode_atts(array(
        'department' => '', // Optional department filter
    ), $atts);
    $posts = get_posts(array(
        'category_name' => 'alumni-networking',
        'posts_per_page' => -1,
        'orderby' => 'date',
        'order' => 'DESC',
        'date_query' => array(
            array(
                'year' => date('Y'),
            ),
        ),
    ));

    if (empty($posts)) {
        return '<p>No alumni posts found.</p>';
    }

    // Build department post count
    $department_counts = array();
    foreach ($posts as $post) {
        $departments = wp_get_post_terms($post->ID, 'department');
        $department = !empty($departments) ? $departments[0]->name : 'Unknown';
        if ($atts['department'] && $atts['department'] !== $department) {
            continue;
        }
        if (!isset($department_counts[$department])) {
            $department_counts[$department] = 0;
        }
        $department_counts[$department]++;
    }

    // Output department summary
    $output = '<div class="utm-news-alumni-analytics" style="margin: 20px 0;">';
    $output .= '<h3>Total Alumni News: ' . count($posts) . '</h3>';
    $output .= '<h4>Department (Total Post)</h4>';
    $output .= '<ul>';
    foreach ($department_counts as $dept => $count) {
        $output .= sprintf('<li>%s (%d)</li>', esc_html($dept), intval($count));
    }
    $output .= '</ul>';

    // Output post list
    $output .= '<h4>Alumni News List</h4>';
    $output .= '<ul>';
    foreach ($posts as $post) {
        $date = date('F j, Y', strtotime($post->post_date));
        $departments = wp_get_post_terms($post->ID, 'department');
        $department = !empty($departments) ? $departments[0]->name : '';
        if ($atts['department'] && $atts['department'] !== $department) {
            continue;
        }
        $output .= sprintf(
            '<li>
                %s - <a href="%s" target="_blank">%s</a> - %s
            </li>',
            esc_html($date),
            esc_url(get_permalink($post->ID)),
            esc_html($post->post_title),
            esc_html($department)
        );
    }
    $output .= '</ul>';
    $output .= '</div>';

    return $output;
});
