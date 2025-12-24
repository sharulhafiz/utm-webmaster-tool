<?php
// Last updated: 22 Dec 2025

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}


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

// Add shortcode [utm_news_department] to display latest 3 posts from a specific department
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

/***************************************************************
    The code after this point will only run on news.utm.my
***************************************************************/
if ($_SERVER['HTTP_HOST'] !== 'news.utm.my') {
    return;
}

/**
 * Phase 1: API Configuration & Settings
 * Functions for managing OpenAI and ElevenLabs API keys
 */

/**
 * Write errors to modules/news.utm.my-errors.log
 * 
 * @param string $message Error message to log
 */
function utm_news_log_error($message) {
    $log_file = dirname(__FILE__) . '/news.utm.my-errors.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] ERROR: {$message}\n";
    
    $result = @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    if ($result === false) {
        error_log('UTM News: Failed to write to error log');
    }
}

/**
 * Register admin menu for settings
 */
function utm_news_register_settings_menu() {
    add_submenu_page(
        'options-general.php',
        'News Settings',
        'News Settings',
        'manage_options',
        'utm_news_settings',
        'utm_news_settings_page'
    );
}

/**
 * Render settings form page
 */
function utm_news_settings_page() {
    // Check capability
    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to access this page.');
    }
    ?>
    <div class="wrap">
        <h1>News Settings</h1>
        <p>Configure API keys and language preferences for the news module.</p>
        
        <?php settings_errors('utm_news_settings'); ?>
        
        <form method="post" action="">
            <?php wp_nonce_field('utm_news_settings_nonce', '_nonce_utm_news_settings'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="utm_news_openai_key">OpenAI API Key</label>
                    </th>
                    <td>
                        <input 
                            type="password" 
                            id="utm_news_openai_key" 
                            name="utm_news_openai_key" 
                            value="<?php echo esc_attr(get_option('utm_news_openai_key')); ?>" 
                            class="regular-text"
                        />
                        <p class="description">Enter your OpenAI API key for text processing.</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="utm_news_elevenlabs_key">ElevenLabs API Key</label>
                    </th>
                    <td>
                        <input 
                            type="password" 
                            id="utm_news_elevenlabs_key" 
                            name="utm_news_elevenlabs_key" 
                            value="<?php echo esc_attr(get_option('utm_news_elevenlabs_key')); ?>" 
                            class="regular-text"
                        />
                        <p class="description">Enter your ElevenLabs API key for text-to-speech synthesis.</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="utm_news_language_pref">Language Preference</label>
                    </th>
                    <td>
                        <select id="utm_news_language_pref" name="utm_news_language_pref" class="regular-text">
                            <option value="auto" <?php selected(get_option('utm_news_language_pref'), 'auto'); ?>>Auto-detect</option>
                            <option value="ms" <?php selected(get_option('utm_news_language_pref'), 'ms'); ?>>Malay</option>
                            <option value="en" <?php selected(get_option('utm_news_language_pref'), 'en'); ?>>English</option>
                        </select>
                        <p class="description">Choose language preference or auto-detect to determine automatically.</p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button('Save Settings', 'primary', 'utm_news_save_settings_btn'); ?>
        </form>
    </div>
    <?php
}

/**
 * Handle form submission and save settings
 */
function utm_news_save_settings() {
    // Only process if button was clicked
    if (!isset($_POST['utm_news_save_settings_btn'])) {
        return;
    }
    
    // Verify nonce
    if (!isset($_POST['_nonce_utm_news_settings']) || 
        !wp_verify_nonce($_POST['_nonce_utm_news_settings'], 'utm_news_settings_nonce')) {
        utm_news_log_error('Nonce verification failed for settings save');
        return;
    }
    
    // Check capability
    if (!current_user_can('manage_options')) {
        utm_news_log_error('Non-admin user attempted to save settings');
        return;
    }
    
    // Sanitize and save OpenAI key
    if (isset($_POST['utm_news_openai_key'])) {
        $openai_key = sanitize_text_field($_POST['utm_news_openai_key']);
        update_option('utm_news_openai_key', $openai_key);
    }
    
    // Sanitize and save ElevenLabs key
    if (isset($_POST['utm_news_elevenlabs_key'])) {
        $elevenlabs_key = sanitize_text_field($_POST['utm_news_elevenlabs_key']);
        update_option('utm_news_elevenlabs_key', $elevenlabs_key);
    }
    
    // Sanitize and save language preference
    if (isset($_POST['utm_news_language_pref'])) {
        $language_pref = sanitize_text_field($_POST['utm_news_language_pref']);
        // Validate language preference
        if (in_array($language_pref, array('auto', 'ms', 'en'))) {
            update_option('utm_news_language_pref', $language_pref);
        }
    }
    
    add_settings_error('utm_news_settings', 'settings_updated', 'Settings saved successfully.', 'success');
}

// Register admin menu
add_action('admin_menu', 'utm_news_register_settings_menu');

// Handle form submission (must happen before rendering)
add_action('admin_init', 'utm_news_save_settings');

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

// Notice to login users that the news submission will be disabled from 15 Dec 2025 to 31 Dec 2025
// All access to wp-admin/post-new.php will be blocked for all authors during this period
// This notice cant be dismissed until the period is over
// This notice will appear starting from now until 31 Dec 2025
add_action('admin_notices', function() {
    global $pagenow;
    $start_date = strtotime('2025-12-22 00:00:00');
    $end_date = strtotime('2025-12-31 23:59:59');
    $current_date = current_time('timestamp');
    $show_notice = false;
    $block_access = false;
    $notice_msg = '<div class="notice notice-warning"><p><strong>Notice:</strong> News submission is disabled from 22 Dec 2025 to 31 Dec 2025 for system maintenance. You will not be able to create new posts during this period.</p></div>';

    if ($current_date <= $end_date) {
        $show_notice = true;
    }

    if ($current_date >= $start_date && $current_date <= $end_date) {
        $show_notice = true;
        $block_access = true;
    }
    if ($show_notice) {
        echo $notice_msg;
    }
    // Block access to post-new.php or post.php for authors during the block period
    if ($block_access && ($pagenow === 'post-new.php' || $pagenow === 'post.php')) {
        $current_user = wp_get_current_user();
        $is_author_role = in_array('author', (array) $current_user->roles);
        if ($is_author_role) {
            wp_die('<h1>Access Denied</h1><p>News submission is disabled from 22 Dec 2025 to 31 Dec 2025 for system maintenance. You cannot submit news during this period.</p>', 'Access Denied', array('back_link' => true));
        }
    }
});
