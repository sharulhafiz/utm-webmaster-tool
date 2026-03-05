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

// [utm_news_department] is registered in modules/utm-news-import.php as the
// canonical implementation to avoid duplicate shortcode overrides.

/***************************************************************
    The code after this point will only run on news.utm.my
***************************************************************/
$utm_http_host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
if ($utm_http_host !== 'news.utm.my') {
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
                        <label for="utm_news_openai_key">OpenRouter API Key</label>
                    </th>
                    <td>
                        <input 
                            type="password" 
                            id="utm_news_openai_key" 
                            name="utm_news_openai_key" 
                            value="<?php echo esc_attr(get_option('utm_news_openai_key')); ?>" 
                            class="regular-text"
                        />
                        <p class="description">Enter your OpenRouter API key for text summaries (model: google-vertex).</p>
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
                
                <tr>
                    <th scope="row">
                        <label for="utm_news_ai_enabled">AI Features</label>
                    </th>
                    <td>
                        <label>
                            <input 
                                type="checkbox" 
                                id="utm_news_ai_enabled" 
                                name="utm_news_ai_enabled" 
                                value="1" 
                                <?php checked(get_option('utm_news_ai_enabled', '0'), '1'); ?>
                            />
                            Enable AI summary and audio generation
                        </label>
                        <p class="description">Uncheck to disable automatic AI summary and audio generation for new posts.</p>
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
    
    // Save AI enabled toggle
    $ai_enabled = isset($_POST['utm_news_ai_enabled']) ? '1' : '0';
    update_option('utm_news_ai_enabled', $ai_enabled);
    
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
    // Check if AI features are enabled
    if (get_option('utm_news_ai_enabled', '0') !== '1') {
        return;
    }
    
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
    // Get the post
    $post = get_post($post_id);
    if (!$post) {
        utm_news_log_error("Post not found for ID: $post_id");
        return false;
    }
    
    // Check if excerpt already exists and force is false
    if (!$force) {
        $existing_excerpt = trim($post->post_excerpt);
        if (!empty($existing_excerpt)) {
            return false; // Early return - excerpt already exists
        }
    }
    
    // Get OpenRouter API key from options (stored in utm_news_openai_key)
    $api_key = get_option('utm_news_openai_key');
    if (empty($api_key)) {
        utm_news_log_error("OpenRouter API key is not configured. Cannot generate summary for post ID: $post_id");
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
    
    // Save summary to post excerpt
    wp_update_post(array(
        'ID' => $post_id,
        'post_excerpt' => $summary
    ));
    
    // Generate TTS audio
    utm_news_generate_tts_audio($post_id);
    
    return true;
}

/**
 * Call LLM API to generate summary
 * 
 * Primary: Tries Ollama local endpoint (http://161.139.39.214/api/chat/completions)
 * Fallback: After 3 failed attempts, falls back to OpenRouter
 * 
 * @param string $prompt   The prompt text (post title + content)
 * @param string $language Language code: 'ms' or 'en'
 * @return string|bool Summary text on success, false on error
 */
function utm_news_call_openai_api($prompt, $language) {
    // Build system prompt based on language
    if ($language === 'ms') {
        $system_prompt = "Ringkaskan artikel berita berikut dalam 2-3 ayat dalam Bahasa Melayu. Berikan ringkasan sahaja tanpa sebarang pengenalan, penjelasan atau frasa seperti 'Berikut adalah ringkasan'. Terus berikan ringkasan. Fokus pada impak utama dan maklumat penting.";
    } else {
        $system_prompt = "Summarize the following news article in 2-3 sentences in English. Output only the summary without any preamble, explanation, or phrases like 'Here is the summary'. Provide the summary directly. Focus on the main impact and key information.";
    }

    // Try Ollama first (3 attempts)
    $ollama_result = utm_news_call_ollama_api($prompt, $system_prompt, 3);
    if ($ollama_result !== false) {
        utm_news_log_error("Ollama API success for language: $language");
        return $ollama_result;
    }

    // Fallback to OpenRouter after 3 failed Ollama attempts
    utm_news_log_error("Ollama API failed after 3 attempts. Falling back to OpenRouter.");
    return utm_news_call_openrouter_api($prompt, $system_prompt);
}

/**
 * Call Ollama local API to generate summary
 * 
 * Makes HTTP request to local Ollama endpoint with retry logic.
 * Uses llama3.1:8b model.
 * 
 * @param string $prompt        The prompt text (post title + content)
 * @param string $system_prompt The system prompt
 * @param int    $max_attempts  Maximum number of retry attempts
 * @return string|bool Summary text on success, false on error
 */
function utm_news_call_ollama_api($prompt, $system_prompt, $max_attempts = 3) {
    $ollama_url = 'http://161.139.39.214/api/chat/completions';
    $ollama_key = 'sk-d1d16d5366c74ae2acf01a0d08d080f3';
    
    // Prepare API request body for Ollama
    $body = array(
        'model' => 'llama3.1:8b',
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

    // Retry logic
    for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
        // Prepare API request
        $args = array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $ollama_key
            ),
            'body' => wp_json_encode($body),
            'timeout' => 60
        );

        // Make API call to Ollama
        $response = wp_remote_post($ollama_url, $args);

        // Check for errors
        if (is_wp_error($response)) {
            utm_news_log_error("Ollama API request failed (attempt $attempt/$max_attempts): " . $response->get_error_message());
            if ($attempt < $max_attempts) {
                sleep(2); // Wait 2 seconds before retry
                continue;
            }
            return false;
        }

        // Check response code
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code < 200 || $response_code >= 300) {
            $body_text = wp_remote_retrieve_body($response);
            utm_news_log_error("Ollama API returned status $response_code (attempt $attempt/$max_attempts). Response: $body_text");
            if ($attempt < $max_attempts) {
                sleep(2); // Wait 2 seconds before retry
                continue;
            }
            return false;
        }

        // Parse JSON response
        $body_text = wp_remote_retrieve_body($response);
        $data = json_decode($body_text, true);

        if ($data === null) {
            utm_news_log_error("Ollama API response is not valid JSON (attempt $attempt/$max_attempts): $body_text");
            if ($attempt < $max_attempts) {
                sleep(2); // Wait 2 seconds before retry
                continue;
            }
            return false;
        }

        // Extract summary from response
        if (isset($data['choices'][0]['message']['content'])) {
            $content = $data['choices'][0]['message']['content'];
            if (is_string($content) && !empty($content)) {
                return trim($content);
            }
        }

        utm_news_log_error("Ollama API response missing content (attempt $attempt/$max_attempts). Response: " . wp_json_encode($data));
        if ($attempt < $max_attempts) {
            sleep(2); // Wait 2 seconds before retry
            continue;
        }
        return false;
    }

    return false;
}

/**
 * Call OpenRouter API to generate summary (fallback)
 * 
 * Makes HTTP request to OpenRouter's chat completions endpoint.
 * Uses google-vertex model with temperature 0.7 and max 150 tokens.
 * 
 * @param string $prompt        The prompt text (post title + content)
 * @param string $system_prompt The system prompt
 * @return string|bool Summary text on success, false on error
 */
function utm_news_call_openrouter_api($prompt, $system_prompt) {
    // Get API key (OpenRouter)
    $api_key = get_option('utm_news_openai_key');
    if (empty($api_key)) {
        utm_news_log_error("OpenRouter API key is empty in utm_news_call_openrouter_api");
        return false;
    }

    // Prepare API request body for OpenRouter using Gemini message format
    $body = array(
        'model' => 'google/gemini-3-flash-preview',
        'messages' => array(
            array(
                'role' => 'system',
                'content' => array(
                    array('type' => 'text', 'text' => $system_prompt)
                )
            ),
            array(
                'role' => 'user',
                'content' => array(
                    array('type' => 'text', 'text' => $prompt)
                )
            )
        ),
        'temperature' => 0.7,
        'max_tokens' => 150 // for approximately 150 words
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

    // Make API call to OpenRouter
    $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', $args);

    // Check for errors
    if (is_wp_error($response)) {
        utm_news_log_error("OpenRouter API request failed: " . $response->get_error_message());
        return false;
    }

    // Check response code
    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code < 200 || $response_code >= 300) {
        $body_text = wp_remote_retrieve_body($response);
        utm_news_log_error("OpenRouter API returned status $response_code. Response: $body_text");
        return false;
    }

    // Parse JSON response
    $body_text = wp_remote_retrieve_body($response);
    $data = json_decode($body_text, true);

    if ($data === null) {
        utm_news_log_error("OpenRouter API response is not valid JSON: $body_text");
        return false;
    }

    // Extract summary from response. Support multiple possible schemas.
    // 1) choices[0].message.content => may be string or array of parts
    if (isset($data['choices'][0]['message']['content'])) {
        $content = $data['choices'][0]['message']['content'];
        if (is_string($content)) {
            return $content;
        }
        if (is_array($content)) {
            // If content is an array of {type,text} objects, concatenate text fields
            $collected = '';
            foreach ($content as $item) {
                if (is_array($item) && isset($item['text'])) {
                    $collected .= $item['text'];
                } elseif (is_string($item)) {
                    $collected .= $item;
                }
            }
            if (!empty($collected)) {
                return $collected;
            }
            // sometimes the content may be nested further
            return wp_json_encode($content);
        }
    }

    // 2) Some responses use choices[0].output or choices[0].text or output_text
    if (isset($data['choices'][0]['output'])) {
        return is_string($data['choices'][0]['output']) ? $data['choices'][0]['output'] : wp_json_encode($data['choices'][0]['output']);
    }
    if (isset($data['choices'][0]['text'])) {
        return $data['choices'][0]['text'];
    }
    if (isset($data['output_text'])) {
        return $data['output_text'];
    }

    utm_news_log_error("OpenRouter API response missing summary content. Response: " . wp_json_encode($data));
    return false;
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
    
    // Get excerpt from post
    $post = get_post($post_id);
    if (!$post) {
        utm_news_log_error("Post not found for ID: $post_id");
        return false;
    }
    
    $summary = trim($post->post_excerpt);
    if (empty($summary)) {
        utm_news_log_error("No excerpt found for post ID: $post_id. Cannot generate audio.");
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
    // Use single voice ID for both languages
    // Voice ID: UcqZLa941Kkt8ZhEEybf
    $voice_id = 'UcqZLa941Kkt8ZhEEybf';
    
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
    
    // Get summary from post excerpt
    $summary = trim($post->post_excerpt);
    if (empty($summary)) {
        return $content;
    }

    $summaryTitle = (utm_news_get_summary_language($post->ID) === 'ms') ? '📝 Ringkasan' : '📝 Summary';
    
    // Build summary box HTML
    $box_html = '<div class="utm-news-ai-summary" style="background: #f0f7ff; border-left: 4px solid #0073aa; padding: 15px 20px; margin: 0 0 25px 0; border-radius: 4px;">';
    $box_html .= '<h4 style="margin: 0 0 10px 0; color: #0073aa; font-size: 16px;">' . esc_html($summaryTitle) . '</h4>';
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
    $has_summary = !empty(trim($post->post_excerpt));
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
        
        <p><strong>Regenerate Summary:</strong></p>
        <form method="post" action="" style="margin-bottom: 15px;">
            <?php wp_nonce_field('utm_news_regenerate_summary_nonce', '_utm_news_regenerate_summary_nonce'); ?>
            <input type="hidden" name="utm_news_regenerate_summary" value="1">
            <button type="submit" class="button button-secondary" style="width: 100%;">Regenerate Summary Only</button>
        </form>
        
        <p><strong>Regenerate Audio:</strong></p>
        <form method="post" action="" style="margin-bottom: 15px;">
            <?php wp_nonce_field('utm_news_regenerate_audio_nonce', '_utm_news_regenerate_audio_nonce'); ?>
            <input type="hidden" name="utm_news_regenerate_audio" value="1">
            <button type="submit" class="button button-secondary" style="width: 100%;">Regenerate Audio Only</button>
        </form>
        
        <p><strong>Regenerate Both:</strong></p>
        <form method="post" action="">
            <?php wp_nonce_field('utm_news_regenerate_both_nonce', '_utm_news_regenerate_both_nonce'); ?>
            <input type="hidden" name="utm_news_regenerate_both" value="1">
            <button type="submit" class="button button-primary" style="width: 100%;">Regenerate Summary & Audio</button>
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
    
    // Only process if editing (not on other post.php actions)
    if (!isset($_GET['action']) || $_GET['action'] !== 'edit') {
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
    
    // Determine which action to take
    $regenerate_summary = isset($_POST['utm_news_regenerate_summary']) && $_POST['utm_news_regenerate_summary'] === '1';
    $regenerate_audio = isset($_POST['utm_news_regenerate_audio']) && $_POST['utm_news_regenerate_audio'] === '1';
    $regenerate_both = isset($_POST['utm_news_regenerate_both']) && $_POST['utm_news_regenerate_both'] === '1';
    
    // If none submitted, return early
    if (!$regenerate_summary && !$regenerate_audio && !$regenerate_both) {
        return;
    }
    
    // Verify appropriate nonce based on action
    if ($regenerate_summary) {
        if (!isset($_POST['_utm_news_regenerate_summary_nonce']) || !wp_verify_nonce($_POST['_utm_news_regenerate_summary_nonce'], 'utm_news_regenerate_summary_nonce')) {
            utm_news_log_error('Regenerate summary nonce verification failed');
            return;
        }
        
        // Clear existing excerpt
        wp_update_post(array(
            'ID' => $post_id,
            'post_excerpt' => ''
        ));
        
        // Regenerate summary only
        $summary_result = utm_news_generate_ai_summary($post_id, true);
        
        if ($summary_result) {
            set_transient('utm_news_regenerate_success_' . $post_id, 'summary_success', 45);
        } else {
            set_transient('utm_news_regenerate_success_' . $post_id, 'summary_failed', 45);
        }
        
    } elseif ($regenerate_audio) {
        if (!isset($_POST['_utm_news_regenerate_audio_nonce']) || !wp_verify_nonce($_POST['_utm_news_regenerate_audio_nonce'], 'utm_news_regenerate_audio_nonce')) {
            utm_news_log_error('Regenerate audio nonce verification failed');
            return;
        }
        
        // Delete existing audio
        delete_post_meta($post_id, '_audio_attachment_id');
        
        // Regenerate audio only
        $audio_result = utm_news_generate_tts_audio($post_id, true);
        
        if ($audio_result) {
            set_transient('utm_news_regenerate_success_' . $post_id, 'audio_success', 45);
        } else {
            set_transient('utm_news_regenerate_success_' . $post_id, 'audio_failed', 45);
        }
        
    } elseif ($regenerate_both) {
        if (!isset($_POST['_utm_news_regenerate_both_nonce']) || !wp_verify_nonce($_POST['_utm_news_regenerate_both_nonce'], 'utm_news_regenerate_both_nonce')) {
            utm_news_log_error('Regenerate both nonce verification failed');
            return;
        }
        
        // Clear existing excerpt and audio
        wp_update_post(array(
            'ID' => $post_id,
            'post_excerpt' => ''
        ));
        delete_post_meta($post_id, '_audio_attachment_id');
        
        // Regenerate both
        $summary_result = utm_news_generate_ai_summary($post_id, true);
        $audio_result = utm_news_generate_tts_audio($post_id, true);
        
        if ($summary_result && $audio_result) {
            set_transient('utm_news_regenerate_success_' . $post_id, 'both_success', 45);
        } elseif ($summary_result) {
            set_transient('utm_news_regenerate_success_' . $post_id, 'summary_only', 45);
        } elseif ($audio_result) {
            set_transient('utm_news_regenerate_success_' . $post_id, 'audio_only', 45);
        } else {
            set_transient('utm_news_regenerate_success_' . $post_id, 'both_failed', 45);
        }
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
        
        switch ($status) {
            case 'summary_success':
                echo '<div class="notice notice-success is-dismissible"><p>Summary regenerated successfully.</p></div>';
                break;
            case 'summary_failed':
                echo '<div class="notice notice-error is-dismissible"><p>Summary regeneration failed. Check error log for details.</p></div>';
                break;
            case 'audio_success':
                echo '<div class="notice notice-success is-dismissible"><p>Audio regenerated successfully.</p></div>';
                break;
            case 'audio_failed':
                echo '<div class="notice notice-error is-dismissible"><p>Audio regeneration failed. Check error log for details.</p></div>';
                break;
            case 'both_success':
                echo '<div class="notice notice-success is-dismissible"><p>Summary and audio regenerated successfully.</p></div>';
                break;
            case 'summary_only':
                echo '<div class="notice notice-warning is-dismissible"><p>Summary regenerated. Audio generation failed - check error log.</p></div>';
                break;
            case 'audio_only':
                echo '<div class="notice notice-warning is-dismissible"><p>Audio regenerated. Summary generation failed - check error log.</p></div>';
                break;
            case 'both_failed':
                echo '<div class="notice notice-error is-dismissible"><p>Both summary and audio regeneration failed. Check error log for details.</p></div>';
                break;
            default:
                echo '<div class="notice notice-error is-dismissible"><p>Regeneration failed. Check error log for details.</p></div>';
        }
    }
}
add_action('admin_notices', 'utm_news_show_regenerate_notice');

/**
 * Audio Playlist Widget
 * 
 * Displays a widget showing recent posts with audio shortcasts
 * Queries for posts with _audio_attachment_id meta key
 * Shows post titles as links, post dates, and audio players
 */
class UTM_News_Audio_Playlist_Widget extends WP_Widget {
    
    /**
     * Widget initialization
     */
    public function __construct() {
        parent::__construct(
            'utm_news_audio_playlist',
            'Audio Shortcasts Playlist',
            array(
                'description' => 'Display recent posts with audio shortcasts'
            )
        );
    }
    
    /**
     * Frontend widget display
     * 
     * @param array $args Widget arguments (before_widget, after_widget, before_title, after_title)
     * @param array $instance Widget settings (count)
     */
    public function widget( $args, $instance ) {
        // Get count from instance with default of 10
        $count = isset( $instance['count'] ) ? intval( $instance['count'] ) : 10;
        $count = $count > 0 ? $count : 10;
        
        // Query posts with audio attachment meta
        $query = new WP_Query( array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'meta_key'       => '_audio_attachment_id',
            'meta_compare'   => 'EXISTS',
            'posts_per_page' => $count,
            'orderby'        => 'date',
            'order'          => 'DESC'
        ) );
        
        // Output widget container
        echo $args['before_widget'];
        echo $args['before_title'] . 'Audio Shortcasts' . $args['after_title'];
        
        // Output inline CSS
        echo '<style>
.utm-audio-playlist { list-style: none; padding: 0; margin: 0; }
.utm-audio-playlist .playlist-item { 
    margin-bottom: 20px; 
    padding-bottom: 20px; 
    border-bottom: 1px solid #eee; 
}
.utm-audio-playlist .playlist-item:last-child { border-bottom: none; }
.utm-audio-playlist .playlist-item h4 { margin: 0 0 5px 0; font-size: 16px; }
.utm-audio-playlist .post-date { 
    display: block; 
    color: #666; 
    font-size: 12px; 
    margin-bottom: 10px; 
}
</style>';
        
        // Display posts or empty message
        if ( $query->have_posts() ) {
            echo '<ul class="utm-audio-playlist">';
            
            while ( $query->have_posts() ) {
                $query->the_post();
                $post_id = get_the_ID();
                $audio_id = get_post_meta( $post_id, '_audio_attachment_id', true );
                
                // Get audio URL and MIME type
                $audio_url = wp_get_attachment_url( $audio_id );
                $mime_type = get_post_mime_type( $audio_id );
                
                // Fallback MIME type
                if ( empty( $mime_type ) ) {
                    $mime_type = 'audio/mpeg';
                }
                
                // Get post date
                $post_date = get_the_date( 'F j, Y' );
                $post_url = get_permalink();
                $post_title = get_the_title();
                
                // Render list item
                echo '<li class="playlist-item">';
                echo '<h4><a href="' . esc_url( $post_url ) . '">' . esc_html( $post_title ) . '</a></h4>';
                echo '<span class="post-date">' . esc_html( $post_date ) . '</span>';
                
                if ( $audio_url ) {
                    echo '<audio controls style="width: 100%; margin: 10px 0;">';
                    echo '<source src="' . esc_url( $audio_url ) . '" type="' . esc_attr( $mime_type ) . '">';
                    echo 'Your browser does not support the audio element.';
                    echo '</audio>';
                }
                
                echo '</li>';
            }
            
            echo '</ul>';
        } else {
            echo '<p>No audio shortcasts found.</p>';
        }
        
        // Restore original post data
        wp_reset_postdata();
        
        echo $args['after_widget'];
    }
    
    /**
     * Widget admin form
     * 
     * @param array $instance Current widget settings
     */
    public function form( $instance ) {
        $count = isset( $instance['count'] ) ? intval( $instance['count'] ) : 10;
        $field_id = $this->get_field_id( 'count' );
        $field_name = $this->get_field_name( 'count' );
        
        echo '<p>';
        echo '<label for="' . esc_attr( $field_id ) . '">Number of posts:</label><br />';
        echo '<input type="number" id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $field_name ) . '" value="' . esc_attr( $count ) . '" min="1" max="50" />';
        echo '</p>';
    }
    
    /**
     * Save widget settings
     * 
     * @param array $new_instance New settings
     * @param array $old_instance Old settings
     * @return array Updated settings
     */
    public function update( $new_instance, $old_instance ) {
        $instance = array();
        
        // Sanitize count value
        $instance['count'] = ( ! empty( $new_instance['count'] ) ) ? absint( $new_instance['count'] ) : 10;
        
        return $instance;
    }
}

/**
 * Register the widget
 */
add_action( 'widgets_init', function() {
    register_widget( 'UTM_News_Audio_Playlist_Widget' );
} );

/**
 * Register REST endpoint for debugging error log
 */
add_action('rest_api_init', function() {
    register_rest_route('utm-news/v1', '/debug-log', array(
        'methods' => 'GET',
        'callback' => 'utm_news_get_debug_log',
        'permission_callback' => function() {
            // Allow if debug parameter matches secret or user is admin
            if (isset($_GET['debug_key']) && $_GET['debug_key'] === 'utm2025debug') {
                return true;
            }
            return current_user_can('manage_options');
        }
    ));
});

/**
 * Get error log contents via REST API
 * 
 * Endpoint: /wp-json/utm-news/v1/debug-log
 * Usage: curl -H "Authorization: Bearer YOUR_TOKEN" https://news.utm.my/wp-json/utm-news/v1/debug-log
 * 
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function utm_news_get_debug_log($request) {
    $log_file = dirname(__FILE__) . '/news.utm.my-errors.log';
    
    // Get number of lines (default last 100)
    $lines = isset($request['lines']) ? intval($request['lines']) : 100;
    $lines = max(1, min(1000, $lines)); // Limit between 1-1000
    
    if (!file_exists($log_file)) {
        return new WP_REST_Response(array(
            'success' => true,
            'log_file' => $log_file,
            'exists' => false,
            'message' => 'No error log file found. No errors logged yet.'
        ), 200);
    }
    
    // Read last N lines efficiently
    $file = new SplFileObject($log_file, 'r');
    $file->seek(PHP_INT_MAX);
    $total_lines = $file->key() + 1;
    
    $start_line = max(0, $total_lines - $lines);
    $log_content = array();
    
    $file->seek($start_line);
    while (!$file->eof()) {
        $line = $file->current();
        if (!empty(trim($line))) {
            $log_content[] = rtrim($line);
        }
        $file->next();
    }
    
    return new WP_REST_Response(array(
        'success' => true,
        'log_file' => $log_file,
        'exists' => true,
        'total_lines' => $total_lines,
        'returned_lines' => count($log_content),
        'log_entries' => $log_content,
        'ai_enabled' => get_option('utm_news_ai_enabled', '0'),
        'has_openai_key' => !empty(get_option('utm_news_openai_key')),
        'has_elevenlabs_key' => !empty(get_option('utm_news_elevenlabs_key'))
    ), 200);
}

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
