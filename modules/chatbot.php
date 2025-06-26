<?php
// Allowed website
$allowed_websites = [
    'brand.utm.my' => [
        'url' => 'https://brand.utm.my',
        'chatbot_id' => 'brand-utm-my'
    ],
    'www.utm.my' => [
        'url' => 'https://www.utm.my',
        'chatbot_id' => 'brand-utm-my'
    ]
];

// Check if the current website is allowed
if (array_key_exists($_SERVER['HTTP_HOST'], $allowed_websites)) {
    $chatbotID = $allowed_websites[$_SERVER['HTTP_HOST']]['chatbot_id'];
    define('UTM_CHATBOT_ID', $chatbotID);
} else {
    // If not allowed, do not load the chatbot module
    return;
}

if (!isset($chatbotID) || empty($chatbotID)) {
    $chatbotID = get_option('utm_chatbot_id', ''); // Fallback to option if not set
    if (empty($chatbotID)) {
        // If still empty, generate a default ID based on site URL
        $chatbotID = str_replace(['https://', 'http://', '/', '.'], ['','', '-', '-'], site_url());
    }
    define('UTM_CHATBOT_ID', $chatbotID);
}

// Constants
define('UTM_CHATBOT_VERSION', '1.0.2');
define('UTM_CHATBOT_SERVER', 'https://chancellery.utm.my');
define('UTM_CHATBOT_SERVER_URL', UTM_CHATBOT_SERVER . '/chatbot');
define('UTM_CHATBOT_API', UTM_CHATBOT_SERVER_URL . '/api/v1');
define('UTM_CHATBOT_ID_GLOBAL', 'utm-my');

// Add the admin menu for UTM Chatbot
add_action('admin_menu', 'utm_chatbot_menu');

function utm_chatbot_menu() {
    add_menu_page(
        'UTM Chatbot Settings',
        'UTM Chatbot',
        'manage_options',
        'utm-chatbot',
        'utm_chatbot_settings_page',
        'dashicons-format-chat',
        100
    );
}

// Display the settings page
function utm_chatbot_settings_page() {
    // Get the log file path
    $upload_dir = wp_upload_dir();
    $log_file = trailingslashit($upload_dir['basedir']) . 'utm-chatbot.log';
    if (!file_exists($log_file)) {
        file_put_contents($log_file, '');
    }

    // On form submission
    if (isset($_POST['utm_chatbot_save_settings'])) {
        // Save the settings
        update_option('utm_chatbot_name', sanitize_text_field($_POST['utm_chatbot_name']));
        update_option('utm_chatbot_api_key', sanitize_text_field($_POST['utm_chatbot_api_key']));
        update_option('utm_chatbot_description', sanitize_text_field($_POST['utm_chatbot_description'])); // <-- Add this line

        // Save activate field
        update_option('utm_chatbot_active', isset($_POST['utm_chatbot_active']) ? '1' : '0');

        if (!empty($_FILES['utm_chatbot_avatar']['name'])) {
            $uploaded = media_handle_upload('utm_chatbot_avatar', 0);
            if (!is_wp_error($uploaded)) {
                update_option('utm_chatbot_avatar', $uploaded);
            }
        }

        // Send POST request to chatbot server
        $chatbot_id = str_replace(['https://', 'http://', '/', '.'], ['','', '-', '-'], site_url());
        $chatbot_name = get_option('utm_chatbot_name', '');
        $chatbot_description = get_option('utm_chatbot_description', 'An AI chatbot powered by UTM Chatbot');
        $chatbot_avatar_id = get_option('utm_chatbot_avatar', '');
        $chatbot_avatar_url = $chatbot_avatar_id ? wp_get_attachment_url($chatbot_avatar_id) : '';
        $chatbot_greeting = '';
        $chatbot_url = site_url();

        // Ensure all fields are strings and name is not empty
        if (!empty($chatbot_name)) {
            $post_data = array(
                'id' => (string)$chatbot_id,
                'name' => (string)$chatbot_name,
                'url' => (string)$chatbot_url,
                'description' => (string)$chatbot_description,
                'avatar' => (string)$chatbot_avatar_url,
                'greeting' => (string)$chatbot_greeting
            );

            $response = wp_remote_post(UTM_CHATBOT_SERVER_URL . '/create-chatbot', array(
                'method' => 'POST',
                'body' => $post_data,
                'timeout' => 15,
            ));

            // Debug: log the request and response
            utm_chatbot_log('Chatbot POST data: ' . print_r($post_data, true));
            if (is_wp_error($response)) {
                utm_chatbot_log('Chatbot server request failed: ' . $response->get_error_message());
            } else {
                utm_chatbot_log('Chatbot server HTTP code: ' . wp_remote_retrieve_response_code($response));
                utm_chatbot_log('Chatbot server response body: ' . print_r($response['body'], true));
            }
        } else {
            utm_chatbot_log('Chatbot name is empty, not sending to server.');
        }
    }

    // Get the saved settings
    $chatbot_id = str_replace(['https://', 'http://', '/', '.'], ['','', '-', '-'], site_url());
    $chatbot_name = get_option('utm_chatbot_name', '');
    $chatbot_description = get_option('utm_chatbot_description', 'An AI chatbot powered by UTM Chatbot');
    $chatbot_api_key = get_option('utm_chatbot_api_key', '');
    $chatbot_avatar_id = get_option('utm_chatbot_avatar', '');
    $chatbot_avatar_url = $chatbot_avatar_id ? wp_get_attachment_url($chatbot_avatar_id) : '';
    $chatbot_active = get_option('utm_chatbot_active', '0'); // New field

    // Enqueue media uploader
    wp_enqueue_media();
    ?>
    <div class="wrap">
        <h1>UTM Chatbot Settings</h1>
        <form method="post" enctype="multipart/form-data">
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="utm_chatbot_id">Chatbot ID</label></th>
                    <td><input type="text" name="utm_chatbot_id" id="utm_chatbot_id" value="<?php echo esc_attr($chatbot_id); ?>" class="regular-text" disabled></td>
                </tr>
                <tr>
                    <th scope="row"><label for="utm_chatbot_name">Chatbot Name</label></th>
                    <td><input type="text" name="utm_chatbot_name" id="utm_chatbot_name" value="<?php echo esc_attr($chatbot_name); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="utm_chatbot_description">Chatbot Description</label></th>
                    <td><input type="text" name="utm_chatbot_description" id="utm_chatbot_description" value="<?php echo esc_attr($chatbot_description); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="utm_chatbot_api_key">OpenAI API Key</label></th>
                    <td><input type="text" name="utm_chatbot_api_key" id="utm_chatbot_api_key" value="<?php echo esc_attr($chatbot_api_key); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="utm_chatbot_avatar">Chatbot Avatar</label></th>
                    <td>
                        <?php if ($chatbot_avatar_url): ?>
                            <img src="<?php echo esc_url($chatbot_avatar_url); ?>" alt="Chatbot Avatar" style="max-width: 100px; display: block; margin-bottom: 10px;">
                        <?php endif; ?>
                        <input type="file" name="utm_chatbot_avatar" id="utm_chatbot_avatar">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="utm_chatbot_active">Activate Chatbot</label></th>
                    <td>
                        <input type="checkbox" name="utm_chatbot_active" id="utm_chatbot_active" value="1" <?php checked($chatbot_active, '1'); ?>>
                        <label for="utm_chatbot_active">Enable chatbot on site</label>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Settings', 'primary', 'utm_chatbot_save_settings'); ?>
        </form>

        <h2>Chatbot Status</h2>
        <p>Number of webpages crawled: <strong><?php echo utm_chatbot_get_uncrawled_posts(true, -1); ?></strong></p>
        <form method="post">
            <?php wp_nonce_field('utm_chatbot_crawl_nonce', 'utm_chatbot_crawl_nonce'); ?>
            <input type="hidden" name="utm_chatbot_run_crawl" value="1">
            <?php submit_button('Start Manual Crawl', 'primary', 'utm_chatbot_crawl_submit', true, 
                  get_transient('utm_chatbot_crawling') ? array('disabled' => 'disabled') : array()); ?>
        </form>
        <p class="description">This will crawl your website content one page at a time every 5 seconds.</p>

        <h2>Chatbot Knowledge Base</h2>
        <p>Here you can add and manage the knowledge base articles for the chatbot.</p>
        <form method="post" action="">
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="utm_chatbot_knowledge_base">Knowledge Base</label></th>
                    <td>
                        <textarea name="utm_chatbot_knowledge_base" id="utm_chatbot_knowledge_base" rows="10" class="large-text"></textarea>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Knowledge Base', 'primary', 'utm_chatbot_save_knowledge_base'); ?>
        </form>

        <h2>All Published Content</h2>
        <table class="widefat fixed" style="max-width:1000px;">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Crawl Status</th>
                    <th>Refresh Crawl</th>
                </tr>
            </thead>
            <tbody>
            <?php
            // Handle refresh crawl action
            if (isset($_POST['utm_chatbot_refresh_crawl']) && isset($_POST['utm_chatbot_post_id']) && check_admin_referer('utm_chatbot_refresh_crawl_' . intval($_POST['utm_chatbot_post_id']))) {
                $post_id = intval($_POST['utm_chatbot_post_id']);
                delete_post_meta($post_id, '_utm_chatbot_crawled');
                utm_chatbot_log('Crawl status reset for post ID: ' . $post_id);
                // Immediately trigger crawl for this post
                $currentUrl = get_permalink($post_id);
                $backendUrl = UTM_CHATBOT_SERVER_URL;
                $chatbotId = str_replace(['https://', 'http://', '/', '.'], ['','', '-', '-'], site_url());
                $result = sendUrlForCrawling($backendUrl, $chatbotId, $currentUrl);
                if ($result) {
                    update_post_meta($post_id, '_utm_chatbot_crawled', '1');
                    utm_chatbot_log('Successfully crawled (refresh) URL: ' . $currentUrl);
                    echo '<div class="notice notice-success"><p>Crawl status refreshed and crawled for post ID: ' . $post_id . '.</p></div>';
                } else {
                    update_post_meta($post_id, '_utm_chatbot_crawled', '0');
                    utm_chatbot_log('Failed to crawl (refresh) URL: ' . $currentUrl);
                    echo '<div class="notice notice-error"><p>Crawl status refreshed but crawl failed for post ID: ' . $post_id . '.</p></div>';
                }
            }

            $args_all = array(
                'post_type' => array('page', 'post', 'project'),
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'fields' => 'ids'
            );
            $all_query = new WP_Query($args_all);
            if ($all_query->have_posts()) {
                foreach ($all_query->posts as $post_id) {
                    $title = get_the_title($post_id);
                    $url = get_permalink($post_id);
                    $crawl_status = get_post_meta($post_id, '_utm_chatbot_crawled', true);
                    if ($crawl_status === '1') {
                        $status_text = '<span style="color:green;">Crawled</span>';
                    } elseif ($crawl_status === '0') {
                        $status_text = '<span style="color:orange;">Failed</span>';
                    } else {
                        $status_text = '<span style="color:red;">Not Crawled</span>';
                    }
                    echo '<tr>';
                    echo '<td><a href="' . esc_url($url) . '" target="_blank">' . esc_html($title) . '</a></td>';
                    echo '<td>' . $status_text . '</td>';
                    echo '<td>';
                    echo '<form method="post" style="display:inline;">';
                    wp_nonce_field('utm_chatbot_refresh_crawl_' . $post_id);
                    echo '<input type="hidden" name="utm_chatbot_refresh_crawl" value="1">';
                    echo '<input type="hidden" name="utm_chatbot_post_id" value="' . esc_attr($post_id) . '">';
                    submit_button('Refresh Crawl', 'small', 'submit', false);
                    echo '</form>';
                    echo '</td>';
                    echo '</tr>';
                }
            } else {
                echo '<tr><td colspan="3">No published posts/pages/projects found.</td></tr>';
            }
            ?>
            </tbody>
        </table>

        <h2>Log</h2>
        <form method="post" style="margin-bottom:10px;">
            <?php wp_nonce_field('utm_chatbot_clear_log_nonce', 'utm_chatbot_clear_log_nonce'); ?>
            <input type="hidden" name="utm_chatbot_clear_log" value="1">
            <?php submit_button('Clear Log', 'secondary', 'utm_chatbot_clear_log_submit'); ?>
        </form>
        <pre id="utm_chatbot_log"><?php echo esc_html(file_get_contents($log_file)); ?></pre>

    </div>

    <?php
    // Handle manual crawling
    if (isset($_POST['utm_chatbot_run_crawl'])) {
        // Store the crawl status in a transient
        set_transient('utm_chatbot_crawling', true, 3600);
        
        // Log that crawling has started
        utm_chatbot_log('Manual crawl started');
    }    // Check if crawling is active
    if (get_transient('utm_chatbot_crawling')) {
        // Get fresh count of remaining posts before crawling one
        $args_uncrawled = array(
            'post_type' => array('page', 'post', 'project'),
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key' => '_utm_chatbot_crawled',
                    'compare' => 'NOT EXISTS'
                )
            ),
            'fields' => 'ids'
        );
        $uncrawled_query = new WP_Query($args_uncrawled);
        $remaining_before = $uncrawled_query->found_posts;
        
        // Process one post
        $processed = utm_chatbot_get_uncrawled_posts(false, 1);
        
        // Get fresh count again after crawling to show accurate remaining number
        $uncrawled_query = new WP_Query($args_uncrawled);
        $remaining_after = $uncrawled_query->found_posts;
        
        // If there are still uncrawled posts, set up a meta refresh to continue crawling
        if ($remaining_after > 0) {
            echo '<meta http-equiv="refresh" content="5">'; // Refresh the page every 5 seconds
            echo '<div class="notice notice-info"><p>Crawling in progress. Page will refresh automatically every 5 seconds. <strong>Uncrawled posts remaining: ' . $remaining_after . '</strong></p></div>';
            
            if ($remaining_before == $remaining_after) {
                // The count didn't change - log a warning
                utm_chatbot_log('Warning: Crawl attempt did not reduce the number of remaining posts. Check for errors.');
            } else {
                // Successfully processed a post
                utm_chatbot_log('Successfully processed a post. Remaining: ' . $remaining_after);
            }
        } else {
            // No more uncrawled posts, stop the process
            delete_transient('utm_chatbot_crawling');
            echo '<div class="notice notice-success"><p>Crawling completed! All posts have been processed.</p></div>';
            utm_chatbot_log('Crawling process completed. All posts processed.');
        }
    }

    // Handle clear log button
    if (isset($_POST['utm_chatbot_clear_log']) && check_admin_referer('utm_chatbot_clear_log_nonce', 'utm_chatbot_clear_log_nonce')) {
        file_put_contents($log_file, '');
        utm_chatbot_log('Log cleared by admin.');
        // Refresh to show empty log
        echo '<meta http-equiv="refresh" content="0">';
    }
}

// Query to get all pages/posts/projects that are not crawled
function utm_chatbot_get_uncrawled_posts($count = false, $post_per_page = 5) {
    // Get count of crawled posts (status = 1 only)
    $args_crawled = array(
        'post_type' => array('page', 'post', 'project'),
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'meta_query' => array(
            array(
                'key' => '_utm_chatbot_crawled',
                'value' => '1',
                'compare' => '='
            )
        ),
        'fields' => 'ids'
    );
    $crawled_query = new WP_Query($args_crawled);
    $crawled_count = $crawled_query->found_posts;

    // Get total count of all published posts/pages/projects (regardless of crawl status)
    $args_total = array(
        'post_type' => array('page', 'post', 'project'),
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'fields' => 'ids'
    );
    $total_query = new WP_Query($args_total);
    $total_count = $total_query->found_posts;

    // Get uncrawled posts (no _utm_chatbot_crawled meta)
    $args_uncrawled = array(
        'post_type' => array('page', 'post', 'project'),
        'posts_per_page' => $post_per_page,
        'post_status' => 'publish',
        'meta_query' => array(
            array(
                'key' => '_utm_chatbot_crawled',
                'compare' => 'NOT EXISTS'
            )
        )
    );
    $uncrawled_query = new WP_Query($args_uncrawled);
    $uncrawled_count = $uncrawled_query->found_posts;

    // If we only need counts
    if ($count) {
        return $crawled_count . '/' . $total_count;
    }

    // Process flag to track if we successfully processed a post
    $processed = false;
    
    // Check if there are uncrawled posts
    if ($uncrawled_count > 0) {
        // Crawl the first uncrawled post
        $post = $uncrawled_query->posts[0];
        $currentUrl = get_permalink($post->ID);
        $backendUrl = UTM_CHATBOT_SERVER_URL;
        $chatbotId = str_replace(['https://', 'http://', '/', '.'], ['','', '-', '-'], site_url());
        utm_chatbot_log('Attempting to crawl: ' . $currentUrl . ' (Post ID: ' . $post->ID . ')');
        
        // Send the URL for crawling
        $result = sendUrlForCrawling($backendUrl, $chatbotId, $currentUrl);
        if ($result) {
            // If the crawling was successful, add metadata to the post
            update_post_meta($post->ID, '_utm_chatbot_crawled', '1');
            utm_chatbot_log('Successfully crawled URL: ' . $currentUrl);
            $processed = true;
        } else {
            utm_chatbot_log('Failed to crawl URL: ' . $currentUrl);
            // Mark as crawled anyway to avoid getting stuck
            update_post_meta($post->ID, '_utm_chatbot_crawled', '0');
            utm_chatbot_log('Marked as processed to avoid getting stuck');
            $processed = true;
        }
    }
    return $processed;
}

// Add a metadata to pages/posts/projects after crawled
function sendUrlForCrawling($backendUrl, $chatbotId, $currentUrl) {
    $endpoint = rtrim($backendUrl, '/') . '/crawl-url/' . $chatbotId;
    
    utm_chatbot_log('Sending request to endpoint: ' . $endpoint);
    utm_chatbot_log('With URL: ' . $currentUrl);

    $response = wp_remote_post($endpoint, array(
        'method'      => 'POST',
        'body'        => array('url' => $currentUrl),
        'timeout'     => 15,
        'headers'     => array('Content-Type' => 'application/x-www-form-urlencoded'),
    ));

    if (is_wp_error($response)) {
        utm_chatbot_log('Failed to crawl URL: ' . $response->get_error_message());
        return false;
    }

    $httpCode = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    
    utm_chatbot_log('Response HTTP code: ' . $httpCode);
    utm_chatbot_log('Response body: ' . $body);

    if ($httpCode < 200 || $httpCode >= 300) {
        utm_chatbot_log('Failed to crawl URL: HTTP ' . $httpCode . ' - ' . $body);
        return false;
    }

    $data = json_decode($body, true);
    if (isset($data['message'])) {
        utm_chatbot_log('Crawling status: ' . $data['message']);
    }
    
    // Return true to indicate success even if data is empty,
    // as long as the HTTP response was successful
    return ($httpCode >= 200 && $httpCode < 300);
}

function utm_chatbot_log($message) {
    $upload_dir = wp_upload_dir();
    $log_file = trailingslashit($upload_dir['basedir']) . 'utm-chatbot.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
}

// FRONTEND: Only load if chatbot is activated
add_action('wp_enqueue_scripts', function() {
    if (get_option('utm_chatbot_active', '0') === '1') {
        wp_enqueue_script('utm-chatbot', UTM_CHATBOT_SERVER_URL . '/static/chatbot-widget.js?chatbotId=' . UTM_CHATBOT_ID, array('jquery'), UTM_CHATBOT_VERSION, true);
        wp_enqueue_style('utm-chatbot-style', UTM_CHATBOT_SERVER_URL . '/static/chatbot-widget.css', array(), UTM_CHATBOT_VERSION);
    }
});

// BACKEND: Only load on chatbot settings page
add_action('admin_enqueue_scripts', function($hook) {
    if ($hook === 'toplevel_page_utm-chatbot') {
        wp_enqueue_script('utm-chatbot', UTM_CHATBOT_SERVER_URL . '/static/chatbot-widget.js?chatbotId=' . UTM_CHATBOT_ID, array('jquery'), UTM_CHATBOT_VERSION, true);
        wp_enqueue_style('utm-chatbot-style', UTM_CHATBOT_SERVER_URL . '/static/chatbot-widget.css', array(), UTM_CHATBOT_VERSION);
    }
});