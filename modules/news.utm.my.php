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

/**
 * Detect language from content (Malay/English)
 * 
 * @param string $content The post content to analyze
 * @return string Language code: 'ms' for Malay, 'en' for English, 'unknown' if insufficient keywords
 */
function utm_news_detect_language($content) {
    // Remove HTML tags
    $content = wp_strip_all_tags($content);
    
    // Remove shortcodes
    $content = strip_shortcodes($content);
    
    // Convert to lowercase for case-insensitive matching
    $content = strtolower($content);
    
    // Malay keywords with higher specificity first
    $malay_keywords = array(
        'adalah', 'untuk', 'dengan', 'yang', 'ini', 'akan', 'dapat', 'kepada', 
        'telah', 'pada', 'dari', 'atau', 'juga', 'oleh', 'dalam', 'tidak', 
        'sebagai', 'antara', 'seperti', 'melalui'
    );
    
    // English keywords
    $english_keywords = array(
        'the', 'is', 'and', 'of', 'to', 'in', 'for', 'that', 'with', 'this',
        'from', 'or', 'also', 'by', 'not', 'as', 'between', 'like', 'through', 'has'
    );
    
    // Count Malay keywords
    $malay_count = 0;
    foreach ($malay_keywords as $keyword) {
        // Use word boundary matching to avoid substring matches
        $pattern = '\b' . preg_quote($keyword, '/') . '\b';
        $malay_count += preg_match_all('/' . $pattern . '/u', $content);
    }
    
    // Count English keywords
    $english_count = 0;
    foreach ($english_keywords as $keyword) {
        // Use word boundary matching to avoid substring matches
        $pattern = '\b' . preg_quote($keyword, '/') . '\b';
        $english_count += preg_match_all('/' . $pattern . '/u', $content);
    }
    
    // Minimum threshold to detect a language
    $min_threshold = 3;
    
    // If neither language meets threshold
    if ($malay_count < $min_threshold && $english_count < $min_threshold) {
        return 'unknown';
    }
    
    // Return language with higher keyword count
    if ($malay_count >= $english_count) {
        return ($malay_count >= $min_threshold) ? 'ms' : 'unknown';
    } else {
        return ($english_count >= $min_threshold) ? 'en' : 'unknown';
    }
}

/**
 * Get language for summary generation
 * 
 * Determines the language to use for AI summary based on:
 * 1. Admin preference setting ('auto' to detect, 'ms', or 'en')
 * 2. If 'auto': detects from post content
 * 3. Fallback to 'en' if detection results in 'unknown'
 * 
 * @param int $post_id The post ID to analyze
 * @return string Language code: 'ms' or 'en'
 */
function utm_news_get_summary_language($post_id) {
    // Get admin preference
    $language_pref = get_option('utm_news_language_pref', 'auto');
    
    // If preference is set to 'ms' or 'en', use it directly
    if ($language_pref === 'ms' || $language_pref === 'en') {
        return $language_pref;
    }
    
    // If preference is 'auto', detect from post content
    if ($language_pref === 'auto') {
        $post = get_post($post_id);
        
        if (!$post) {
            return 'en'; // Fallback if post not found
        }
        
        // Get the post content
        $content = $post->post_content;
        
        // Detect language from content
        $detected = utm_news_detect_language($content);
        
        // If detection is 'unknown', fallback to English
        if ($detected === 'unknown') {
            return 'en';
        }
        
        return $detected;
    }
    
    // Default fallback
    return 'en';
}

/**
 * Hook callback for post status transitions
 * 
 * Triggered when a post transitions to 'publish' status.
 * Automatically generates AI summary on first publish.
 * 
 * @param string  $new_status New post status
 * @param string  $old_status Old post status
 * @param WP_Post $post       Post object
 */
function utm_news_on_post_publish($new_status, $old_status, $post) {
    // Only process when transitioning TO 'publish' status
    if ($new_status !== 'publish') {
        return;
    }
    
    // Only process for 'post' post type (not pages, attachments, etc.)
    if ($post->post_type !== 'post') {
        return;
    }
    
    // Skip if this is an update (old_status was already 'publish')
    if ($old_status === 'publish') {
        return;
    }
    
    // Generate AI summary for this post
    utm_news_generate_ai_summary($post->ID);
}

/**
 * Generate AI summary for a post
 * 
 * Coordinator function that handles summary generation.
 * Respects existing summary unless force=true.
 * 
 * @param int  $post_id Post ID to generate summary for
 * @param bool $force   Force regeneration even if summary exists
 * @return bool True if summary generated/updated, false otherwise
 */
function utm_news_generate_ai_summary($post_id, $force = false) {
    // Check if summary already exists and force is false
    if (!$force) {
        $existing_summary = get_post_meta($post_id, '_ai_summary', true);
        if ($existing_summary) {
            return false; // Early return - summary already exists
        }
    }
    
    // Get the post
    $post = get_post($post_id);
    if (!$post) {
        utm_news_log_error("Post not found for ID: $post_id");
        return false;
    }
    
    // Get OpenAI API key from options
    $api_key = get_option('utm_news_openai_key');
    if (empty($api_key)) {
        utm_news_log_error("OpenAI API key is not configured. Cannot generate summary for post ID: $post_id");
        return false;
    }
    
    // Get post content and language
    $language = utm_news_get_summary_language($post_id);
    
    // Strip HTML tags and shortcodes from content
    $content = wp_strip_all_tags($post->post_content);
    
    // Build prompt from post title + content
    $prompt = "Title: " . $post->post_title . "\n\nContent: " . $content;
    
    // Call OpenAI API
    $summary = utm_news_call_openai_api($prompt, $language);
    
    if ($summary === false) {
        utm_news_log_error("Failed to generate summary for post ID: $post_id");
        return false;
    }
    
    // Save summary to post meta
    update_post_meta($post_id, '_ai_summary', $summary);
    
    // Generate TTS audio
    utm_news_generate_tts_audio($post_id);
    
    return true;
}

/**
 * Call OpenAI API to generate summary
 * 
 * Makes HTTP request to OpenAI's chat completions endpoint.
 * Uses gpt-4o-mini model with temperature 0.7 and max 150 tokens.
 * 
 * @param string $prompt   The prompt text (post title + content)
 * @param string $language Language code: 'ms' or 'en'
 * @return string|bool Summary text on success, false on error
 */
function utm_news_call_openai_api($prompt, $language) {
    // Get API key
    $api_key = get_option('utm_news_openai_key');
    if (empty($api_key)) {
        utm_news_log_error("OpenAI API key is empty in utm_news_call_openai_api");
        return false;
    }
    
    // Build system prompt based on language
    if ($language === 'ms') {
        $system_prompt = "You are a helpful assistant. Summarize the following news article in 2-3 sentences in Malay language.";
    } else {
        $system_prompt = "You are a helpful assistant. Summarize the following news article in 2-3 sentences in English.";
    }
    
    // Prepare API request body
    $body = array(
        'model' => 'gpt-4o-mini',
        'messages' => array(
            array(
                'role' => 'system',
                'content' => $system_prompt
            ),
            array(
                'role' => 'user',
                'content' => $prompt
            )
        ),
        'temperature' => 0.7,
        'max_tokens' => 150
    );
    
    // Prepare API request
    $args = array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key
        ),
        'body' => wp_json_encode($body),
        'timeout' => 30
    );
    
    // Make API call
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', $args);
    
    // Check for errors
    if (is_wp_error($response)) {
        utm_news_log_error("OpenAI API request failed: " . $response->get_error_message());
        return false;
    }
    
    // Check response code
    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        $body_text = wp_remote_retrieve_body($response);
        utm_news_log_error("OpenAI API returned status $response_code. Response: $body_text");
        return false;
    }
    
    // Parse JSON response
    $body_text = wp_remote_retrieve_body($response);
    $data = json_decode($body_text, true);
    
    if ($data === null) {
        utm_news_log_error("OpenAI API response is not valid JSON: $body_text");
        return false;
    }
    
    // Extract summary from response
    if (!isset($data['choices'][0]['message']['content'])) {
        utm_news_log_error("OpenAI API response missing summary content. Response: " . wp_json_encode($data));
        return false;
    }
    
    return $data['choices'][0]['message']['content'];
}

/**
 * Generate TTS audio from summary
 * 
 * Coordinator function for text-to-speech generation.
 * Creates audio from the saved AI summary and attaches to post.
 * Respects existing audio unless force=true.
 * 
 * @param int  $post_id Post ID to generate audio for
 * @param bool $force   Force regeneration even if audio exists
 * @return bool True if audio generated/updated, false otherwise
 */
function utm_news_generate_tts_audio($post_id, $force = false) {
    // Check if audio already exists and force is false
    if (!$force) {
        $existing_audio_id = get_post_meta($post_id, '_audio_attachment_id', true);
        if ($existing_audio_id) {
            return false; // Early return - audio already exists
        }
    }
    
    // Get ElevenLabs API key from options
    $api_key = get_option('utm_news_elevenlabs_key');
    if (empty($api_key)) {
        utm_news_log_error("ElevenLabs API key is not configured. Cannot generate audio for post ID: $post_id");
        return false;
    }
    
    // Get summary from post meta
    $summary = get_post_meta($post_id, '_ai_summary', true);
    if (empty($summary)) {
        utm_news_log_error("No AI summary found for post ID: $post_id. Cannot generate audio.");
        return false;
    }
    
    // Get language using existing function
    $language = utm_news_get_summary_language($post_id);
    
    // Call ElevenLabs API
    $audio_data = utm_news_call_elevenlabs_api($summary, $language, $api_key);
    
    if ($audio_data === false) {
        utm_news_log_error("Failed to generate audio from ElevenLabs API for post ID: $post_id");
        return false;
    }
    
    // Save audio and get attachment ID
    $attachment_id = utm_news_save_audio_to_media($audio_data, $post_id, "post-{$post_id}-audio.mp3");
    
    if ($attachment_id === false) {
        utm_news_log_error("Failed to save audio to media library for post ID: $post_id");
        return false;
    }
    
    // Save attachment ID to post meta
    update_post_meta($post_id, '_audio_attachment_id', $attachment_id);
    
    return true;
}

/**
 * Call ElevenLabs Text-to-Speech API
 * 
 * Makes HTTP request to ElevenLabs TTS endpoint.
 * Selects appropriate voice based on language.
 * 
 * @param string $text     Text to convert to speech
 * @param string $language Language code: 'ms' or 'en'
 * @param string $api_key  ElevenLabs API key
 * @return string|bool Binary audio data on success, false on error
 */
function utm_news_call_elevenlabs_api($text, $language, $api_key) {
    // Select voice ID based on language
    if ($language === 'ms') {
        // Malay voice
        $voice_id = 'pMsXgVXv3BLzUgSXRplE';
    } else {
        // English voice (Rachel - clear female voice)
        $voice_id = '21m00Tcm4TlvDq8ikWAM';
    }
    
    // Prepare API request body
    $body = array(
        'text' => $text,
        'model_id' => 'eleven_multilingual_v2',
        'voice_settings' => array(
            'stability' => 0.5,
            'similarity_boost' => 0.75
        )
    );
    
    // Prepare API request
    $args = array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'xi-api-key' => $api_key
        ),
        'body' => wp_json_encode($body),
        'timeout' => 60
    );
    
    // Make API call
    $response = wp_remote_post(
        "https://api.elevenlabs.io/v1/text-to-speech/{$voice_id}",
        $args
    );
    
    // Check for errors
    if (is_wp_error($response)) {
        utm_news_log_error("ElevenLabs API request failed: " . $response->get_error_message());
        return false;
    }
    
    // Check response code
    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        $body_text = wp_remote_retrieve_body($response);
        utm_news_log_error("ElevenLabs API returned status $response_code. Response: $body_text");
        return false;
    }
    
    // Get audio data
    $audio_data = wp_remote_retrieve_body($response);
    
    if (empty($audio_data)) {
        utm_news_log_error("ElevenLabs API returned empty audio data");
        return false;
    }
    
    return $audio_data;
}

/**
 * Save audio file to media library
 * 
 * Saves binary audio data as a temporary file and uses
 * media_handle_sideload to attach it to the post.
 * Cleans up temporary files after upload.
 * 
 * @param string $audio_data Binary audio data
 * @param int    $post_id    Post ID to attach audio to
 * @param string $filename   Filename for the audio (e.g., 'post-123-audio.mp3')
 * @return int|bool Attachment ID on success, false on error
 */
function utm_news_save_audio_to_media($audio_data, $post_id, $filename) {
    // Require WordPress file functions
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    
    // Get upload directory
    $upload_dir = wp_upload_dir();
    if (!isset($upload_dir['path'])) {
        utm_news_log_error("Could not determine upload directory");
        return false;
    }
    
    // Create temp file path
    $tmp_file = $upload_dir['path'] . '/' . $filename;
    
    // Write audio data to temp file
    $write_result = @file_put_contents($tmp_file, $audio_data);
    if ($write_result === false) {
        utm_news_log_error("Failed to write audio file to disk at $tmp_file");
        return false;
    }
    
    // Create file array for media_handle_sideload
    $file_array = array(
        'name' => $filename,
        'tmp_name' => $tmp_file,
        'type' => 'audio/mpeg'
    );
    
    // Use media_handle_sideload to attach file
    $attachment_id = media_handle_sideload($file_array, $post_id);
    
    // Check for errors from media_handle_sideload
    if (is_wp_error($attachment_id)) {
        utm_news_log_error("media_handle_sideload failed: " . $attachment_id->get_error_message());
        // Clean up temp file
        @unlink($tmp_file);
        return false;
    }
    
    // Clean up temp file
    @unlink($tmp_file);
    
    return $attachment_id;
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
function utm_news_prepend_summary_box($content) {
    // Only run on single post pages
    if (!is_singular('post')) {
        return $content;
    }
    
    global $post;
    if (!isset($post->ID)) {
        return $content;
    }
    
    // Get summary from post meta
    $summary = get_post_meta($post->ID, '_ai_summary', true);
    if (empty($summary)) {
        return $content;
    }
    
    // Build summary box HTML
    $box_html = '<div class="utm-news-ai-summary" style="background: #f0f7ff; border-left: 4px solid #0073aa; padding: 15px 20px; margin: 0 0 25px 0; border-radius: 4px;">';
    $box_html .= '<h4 style="margin: 0 0 10px 0; color: #0073aa; font-size: 16px;">📝 Ringkasan / Summary</h4>';
    $box_html .= '<p style="margin: 0; line-height: 1.6; color: #333;">' . wp_kses_post($summary) . '</p>';
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
function utm_news_prepend_audio_player($content) {
    // Only run on single post pages
    if (!is_singular('post')) {
        return $content;
    }
    
    global $post;
    if (!isset($post->ID)) {
        return $content;
    }
    
    // Get attachment ID from post meta
    $attachment_id = get_post_meta($post->ID, '_audio_attachment_id', true);
    if (empty($attachment_id)) {
        return $content;
    }
    
    // Get audio URL
    $audio_url = wp_get_attachment_url($attachment_id);
    if (empty($audio_url)) {
        return $content;
    }
    
    // Build audio player HTML
    $player_html = '<div class="utm-news-audio-player" style="background: #fff8e1; border-left: 4px solid #ff9800; padding: 15px 20px; margin: 0 0 20px 0; border-radius: 4px;">';
    $player_html .= '<h4 style="margin: 0 0 10px 0; color: #ff9800; font-size: 16px;">🎧 Audio Shortcast</h4>';
    $player_html .= '<audio controls style="width: 100%; max-width: 600px;">';
    // Detect actual MIME type from attachment, fallback to audio/mpeg
    $mime_type = get_post_mime_type($attachment_id) ?: 'audio/mpeg';
    $player_html .= '<source src="' . esc_url($audio_url) . '" type="' . esc_attr($mime_type) . '">';
    $player_html .= 'Your browser does not support the audio element.';
    $player_html .= '</audio>';
    $player_html .= '</div>';
    
    return $player_html . $content;
}

/**
 * Register meta box for manual regenerate button
 * 
 * Adds a meta box to the post editor sidebar for regenerating
 * summary and audio content.
 */
function utm_news_add_regenerate_metabox() {
    add_meta_box(
        'utm_news_regenerate',
        'AI Content Regeneration',
        'utm_news_render_regenerate_metabox',
        'post',
        'side',
        'default'
    );
}

/**
 * Render meta box HTML for manual regenerate
 * 
 * Displays current generation status and regenerate button.
 * 
 * @param WP_Post $post Post object
 */
function utm_news_render_regenerate_metabox($post) {
    if (!isset($post->ID)) {
        return;
    }
    
    // Get current status
    $has_summary = !empty(get_post_meta($post->ID, '_ai_summary', true));
    $has_audio = !empty(get_post_meta($post->ID, '_audio_attachment_id', true));
    
    // Build status indicators
    $summary_status = $has_summary ? '✅ Generated' : '❌ Not generated';
    $audio_status = $has_audio ? '✅ Generated' : '❌ Not generated';
    ?>
    <div>
        <p><strong>Current Status:</strong></p>
        <ul>
            <li>AI Summary: <?php echo esc_html($summary_status); ?></li>
            <li>Audio Shortcast: <?php echo esc_html($audio_status); ?></li>
        </ul>
        <p><strong>Regenerate Content:</strong></p>
        <p>Click the button below to regenerate the AI summary and audio shortcast. This will replace existing content.</p>
        <form method="post" action="">
            <?php wp_nonce_field('utm_news_regenerate_nonce', '_wpnonce'); ?>
            <input type="hidden" name="utm_news_regenerate" value="1">
            <button type="submit" class="button button-primary">Regenerate Summary & Audio</button>
        </form>
    </div>
    <?php
}

/**
 * Handle manual regenerate request
 * 
 * Processes the regenerate form submission, validates nonce,
 * and triggers generation of summary and audio with force=true.
 */
function utm_news_handle_manual_regenerate() {
    // Check if on admin edit page
    global $pagenow;
    if (!is_admin() || $pagenow !== 'post.php') {
        return;
    }
    
    // Check if regenerate form was submitted
    if (!isset($_POST['utm_news_regenerate']) || $_POST['utm_news_regenerate'] !== '1') {
        return;
    }
    
    // Verify nonce
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'utm_news_regenerate_nonce')) {
        return;
    }
    
    // Check user capability
    if (!current_user_can('edit_posts')) {
        return;
    }
    
    // Get post ID
    if (!isset($_GET['post'])) {
        return;
    }
    
    $post_id = intval($_GET['post']);
    if ($post_id <= 0) {
        return;
    }
    
    // Delete existing metas
    delete_post_meta($post_id, '_ai_summary');
    delete_post_meta($post_id, '_audio_attachment_id');
    
    // Store success message in transient and check generation results
    $summary_result = utm_news_generate_ai_summary($post_id, true);
    $audio_result = utm_news_generate_tts_audio($post_id, true);
    
    if ($summary_result && $audio_result) {
        set_transient('utm_news_regenerate_success_' . $post_id, 'both', 45);
    } elseif ($summary_result) {
        set_transient('utm_news_regenerate_success_' . $post_id, 'summary_only', 45);
    } elseif ($audio_result) {
        set_transient('utm_news_regenerate_success_' . $post_id, 'audio_only', 45);
    } else {
        set_transient('utm_news_regenerate_success_' . $post_id, 'failed', 45);
    }
    
    // Redirect to prevent resubmission
    wp_redirect(admin_url('post.php?post=' . $post_id . '&action=edit'));
    exit;
}

/**
 * Display transient-based admin notices for regeneration
 * 
 * Shows success/warning/error notices based on regeneration results
 * stored in transients. Deletes transient after displaying.
 */
function utm_news_show_regenerate_notice() {
    global $post;
    if (!$post || !isset($post->ID)) {
        return;
    }
    
    $status = get_transient('utm_news_regenerate_success_' . $post->ID);
    if ($status) {
        delete_transient('utm_news_regenerate_success_' . $post->ID);
        
        if ($status === 'both') {
            echo '<div class="notice notice-success is-dismissible"><p>Summary and audio regenerated successfully.</p></div>';
        } elseif ($status === 'summary_only') {
            echo '<div class="notice notice-warning is-dismissible"><p>Summary regenerated. Audio generation failed - check error log.</p></div>';
        } elseif ($status === 'audio_only') {
            echo '<div class="notice notice-warning is-dismissible"><p>Audio regenerated. Summary generation failed - check error log.</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>Regeneration failed. Check error log for details.</p></div>';
        }
    }
}
add_action('admin_notices', 'utm_news_show_regenerate_notice');

// Register admin menu
add_action('admin_menu', 'utm_news_register_settings_menu');

// Handle form submission (must happen before rendering)
add_action('admin_init', 'utm_news_save_settings');

// Phase 5: Display summary box and audio player
add_filter('the_content', 'utm_news_prepend_summary_box', 5);
add_filter('the_content', 'utm_news_prepend_audio_player', 5);

// Phase 5: Register meta box for manual regenerate
add_action('add_meta_boxes', 'utm_news_add_regenerate_metabox');

// Phase 5: Handle manual regenerate
add_action('admin_init', 'utm_news_handle_manual_regenerate');

// Hook for auto-generating AI summaries when posts are published
add_action('transition_post_status', 'utm_news_on_post_publish', 10, 3);

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
