<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Detect language from content (Malay/English)
 * 
 * @param string $content The post content to analyze
 * @return string Language code: 'ms' for Malay, 'en' for English, 'unknown' if insufficient keywords
 */
function utm_news_detect_language( $content ) {
    // Remove HTML tags
    $content = wp_strip_all_tags( $content );
    
    // Remove shortcodes
    $content = strip_shortcodes( $content );
    
    // Convert to lowercase for case-insensitive matching
    $content = strtolower( $content );
    
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
    foreach ( $malay_keywords as $keyword ) {
        // Use word boundary matching to avoid substring matches
        $pattern = '\b' . preg_quote( $keyword, '/' ) . '\b';
        $malay_count += preg_match_all( '/' . $pattern . '/u', $content );
    }
    
    // Count English keywords
    $english_count = 0;
    foreach ( $english_keywords as $keyword ) {
        // Use word boundary matching to avoid substring matches
        $pattern = '\b' . preg_quote( $keyword, '/' ) . '\b';
        $english_count += preg_match_all( '/' . $pattern . '/u', $content );
    }
    
    // Minimum threshold to detect a language
    $min_threshold = 3;
    
    // If neither language meets threshold
    if ( $malay_count < $min_threshold && $english_count < $min_threshold ) {
        return 'unknown';
    }
    
    // Return language with higher keyword count
    if ( $malay_count >= $english_count ) {
        return ( $malay_count >= $min_threshold ) ? 'ms' : 'unknown';
    } else {
        return ( $english_count >= $min_threshold ) ? 'en' : 'unknown';
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
function utm_news_get_summary_language( $post_id ) {
    // Get admin preference
    $language_pref = get_option( 'utm_news_language_pref', 'auto' );
    
    // If preference is set to 'ms' or 'en', use it directly
    if ( 'ms' === $language_pref || 'en' === $language_pref ) {
        return $language_pref;
    }
    
    // If preference is 'auto', detect from post content
    if ( 'auto' === $language_pref ) {
        $post = get_post( $post_id );
        
        if ( ! $post ) {
            return 'en'; // Fallback if post not found
        }
        
        // Get the post content
        $content = $post->post_content;
        
        // Detect language from content
        $detected = utm_news_detect_language( $content );
        
        // If detection is 'unknown', fallback to English
        if ( 'unknown' === $detected ) {
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
function utm_news_on_post_publish( $new_status, $old_status, $post ) {
    // Check if AI features are enabled
    if ( get_option( 'utm_news_ai_enabled', '0' ) !== '1' ) {
        return;
    }
    
    // Only process when transitioning TO 'publish' status
    if ( 'publish' !== $new_status ) {
        return;
    }
    
    // Only process for 'post' post type (not pages, attachments, etc.)
    if ( 'post' !== $post->post_type ) {
        return;
    }
    
    // Skip if this is an update (old_status was already 'publish')
    if ( 'publish' === $old_status ) {
        return;
    }
    
    // Generate AI summary for this post
    utm_news_generate_ai_summary( $post->ID );

    // Generate SDG tags for this post using the same AI pipeline.
    utm_news_generate_sdg_tags( $post->ID );
}

/**
 * Call the existing AI providers with a custom system prompt.
 *
 * This keeps summary, classification, and future AI tasks on the same
 * endpoint pipeline.
 *
 * @param string $prompt        User prompt.
 * @param string $system_prompt System prompt.
 * @return string|bool Generated text on success, false on error.
 */
function utm_news_call_ai_pipeline( $prompt, $system_prompt ) {
    // Try Ollama first (3 attempts).
    $ollama_result = utm_news_call_ollama_api( $prompt, $system_prompt, 3 );
    if ( false !== $ollama_result ) {
        return $ollama_result;
    }

    // Fallback to OpenRouter after 3 failed Ollama attempts.
    return utm_news_call_openrouter_api( $prompt, $system_prompt );
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
function utm_news_generate_ai_summary( $post_id, $force = false ) {
    // Get the post
    $post = get_post( $post_id );
    if ( ! $post ) {
        utm_news_log_error( "Post not found for ID: $post_id" );
        return false;
    }
    
    // Check if excerpt already exists and force is false
    if ( ! $force ) {
        $existing_excerpt = trim( $post->post_excerpt );
        if ( ! empty( $existing_excerpt ) ) {
            return false; // Early return - excerpt already exists
        }
    }
    
    // Get OpenRouter API key from options (stored in utm_news_openai_key)
    $api_key = get_option( 'utm_news_openai_key' );
    if ( empty( $api_key ) ) {
        utm_news_log_error( "OpenRouter API key is not configured. Cannot generate summary for post ID: $post_id" );
        return false;
    }
    
    // Get post content and language
    $language = utm_news_get_summary_language( $post_id );
    
    // Strip HTML tags and shortcodes from content
    $content = wp_strip_all_tags( $post->post_content );
    
    // Build prompt from post title + content
    $prompt = "Title: " . $post->post_title . "\n\nContent: " . $content;
    
    // Call OpenAI API
    $summary = utm_news_call_openai_api( $prompt, $language );
    
    if ( false === $summary ) {
        utm_news_log_error( "Failed to generate summary for post ID: $post_id" );
        return false;
    }
    
    // Save summary to post excerpt
    wp_update_post( array(
        'ID' => $post_id,
        'post_excerpt' => $summary
    ) );
    
    // Generate TTS audio
    utm_news_generate_tts_audio( $post_id );
    
    return true;
}

/**
 * Generate SDG tags for a post using AI classification.
 *
 * Applies native WordPress tags in the `post_tag` taxonomy.
 *
 * @param int  $post_id Post ID to tag.
 * @param bool $force   Force regeneration even if tags already exist.
 * @return array|false  List of applied SDG tags on success, false on error.
 */
function utm_news_generate_sdg_tags( $post_id, $force = false ) {
    $post = get_post( $post_id );
    if ( ! $post ) {
        utm_news_log_error( "Post not found for SDG tagging ID: $post_id" );
        return false;
    }

    $existing_tags = wp_get_post_terms(
        $post_id,
        'post_tag',
        array(
            'fields' => 'names',
        )
    );

    if ( ! $force && is_array( $existing_tags ) ) {
        foreach ( $existing_tags as $existing_tag ) {
            if ( is_string( $existing_tag ) && preg_match( '/^SDG(1[0-7]|[1-9])$/', trim( $existing_tag ) ) ) {
                return $existing_tags;
            }
        }
    }

    $content = wp_strip_all_tags( $post->post_content );
    $prompt  = "Title: " . $post->post_title . "\n\nContent: " . $content . "\n\nClassify this article into the most relevant UN Sustainable Development Goal tags. Return only the SDG tag names, using the exact format SDG1 through SDG17. You may return one or more tags, separated by commas. Return no other text. If no SDG applies, return an empty response.";

    $system_prompt = "You classify news articles into UN Sustainable Development Goal tags. Only output valid tags from SDG1 to SDG17. Return a comma-separated list such as SDG3, SDG4, SDG13. Do not explain your answer. Do not output any text outside the tag list.";

    $response = utm_news_call_ai_pipeline( $prompt, $system_prompt );
    if ( false === $response ) {
        utm_news_log_error( "Failed to classify SDG tags for post ID: $post_id" );
        return false;
    }

    $sdg_tags = utm_news_extract_sdg_tags( $response );
    if ( empty( $sdg_tags ) ) {
        utm_news_log_error( "AI returned no valid SDG tags for post ID: $post_id. Response: $response" );
        return false;
    }

    $set_result = wp_set_post_terms( $post_id, $sdg_tags, 'post_tag', true );
    if ( is_wp_error( $set_result ) ) {
        utm_news_log_error( "Failed to apply SDG tags for post ID: $post_id: " . $set_result->get_error_message() );
        return false;
    }

    update_post_meta( $post_id, '_utm_news_sdg_tags', wp_json_encode( $sdg_tags ) );

    return $sdg_tags;
}

/**
 * Extract valid SDG tag names from AI output.
 *
 * @param string $response AI response text.
 * @return array
 */
function utm_news_extract_sdg_tags( $response ) {
    $response = strtoupper( (string) $response );

    preg_match_all( '/\bSDG\s*(1[0-7]|[1-9])\b/', $response, $matches );

    if ( empty( $matches[1] ) || ! is_array( $matches[1] ) ) {
        return array();
    }

    $tags = array();
    foreach ( $matches[1] as $goal_number ) {
        $tag = 'SDG' . (int) $goal_number;
        if ( ! in_array( $tag, $tags, true ) ) {
            $tags[] = $tag;
        }
    }

    return $tags;
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
function utm_news_call_openai_api( $prompt, $language ) {
    // Build system prompt based on language
    if ( 'ms' === $language ) {
        $system_prompt = "Ringkaskan artikel berita berikut dalam 2-3 ayat dalam Bahasa Melayu. Berikan ringkasan sahaja tanpa sebarang pengenalan, penjelasan atau frasa seperti 'Berikut adalah ringkasan'. Terus berikan ringkasan. Fokus pada impak utama dan maklumat penting.";
    } else {
        $system_prompt = "Summarize the following news article in 2-3 sentences in English. Output only the summary without any preamble, explanation, or phrases like 'Here is the summary'. Provide the summary directly. Focus on the main impact and key information.";
    }

    $result = utm_news_call_ai_pipeline( $prompt, $system_prompt );

    if ( false !== $result ) {
        utm_news_log_error( "AI pipeline success for language: $language" );
        return $result;
    }

    utm_news_log_error( "AI pipeline failed after fallback attempts." );
    return false;
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
function utm_news_call_ollama_api( $prompt, $system_prompt, $max_attempts = 3 ) {
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
    for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
        // Prepare API request
        $args = array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $ollama_key
            ),
            'body' => wp_json_encode( $body ),
            'timeout' => 60
        );

        // Make API call to Ollama
        $response = wp_remote_post( $ollama_url, $args );

        // Check for errors
        if ( is_wp_error( $response ) ) {
            utm_news_log_error( "Ollama API request failed (attempt $attempt/$max_attempts): " . $response->get_error_message() );
            if ( $attempt < $max_attempts ) {
                sleep( 2 ); // Wait 2 seconds before retry
                continue;
            }
            return false;
        }

        // Check response code
        $response_code = wp_remote_retrieve_response_code( $response );
        if ( $response_code < 200 || $response_code >= 300 ) {
            $body_text = wp_remote_retrieve_body( $response );
            utm_news_log_error( "Ollama API returned status $response_code (attempt $attempt/$max_attempts). Response: $body_text" );
            if ( $attempt < $max_attempts ) {
                sleep( 2 ); // Wait 2 seconds before retry
                continue;
            }
            return false;
        }

        // Parse JSON response
        $body_text = wp_remote_retrieve_body( $response );
        $data = json_decode( $body_text, true );

        if ( null === $data ) {
            utm_news_log_error( "Ollama API response is not valid JSON (attempt $attempt/$max_attempts): $body_text" );
            if ( $attempt < $max_attempts ) {
                sleep( 2 ); // Wait 2 seconds before retry
                continue;
            }
            return false;
        }

        // Extract summary from response
        if ( isset( $data['choices'][0]['message']['content'] ) ) {
            $content = $data['choices'][0]['message']['content'];
            if ( is_string( $content ) && ! empty( $content ) ) {
                return trim( $content );
            }
        }

        utm_news_log_error( "Ollama API response missing content (attempt $attempt/$max_attempts). Response: " . wp_json_encode( $data ) );
        if ( $attempt < $max_attempts ) {
            sleep( 2 ); // Wait 2 seconds before retry
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
function utm_news_call_openrouter_api( $prompt, $system_prompt ) {
    // Get API key (OpenRouter)
    $api_key = get_option( 'utm_news_openai_key' );
    if ( empty( $api_key ) ) {
        utm_news_log_error( "OpenRouter API key is empty in utm_news_call_openrouter_api" );
        return false;
    }

    // Prepare API request body for OpenRouter using Gemini message format
    $body = array(
        'model' => 'google/gemini-3-flash-preview',
        'messages' => array(
            array(
                'role' => 'system',
                'content' => array(
                    array( 'type' => 'text', 'text' => $system_prompt )
                )
            ),
            array(
                'role' => 'user',
                'content' => array(
                    array( 'type' => 'text', 'text' => $prompt )
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
        'body' => wp_json_encode( $body ),
        'timeout' => 30
    );

    // Make API call to OpenRouter
    $response = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', $args );

    // Check for errors
    if ( is_wp_error( $response ) ) {
        utm_news_log_error( "OpenRouter API request failed: " . $response->get_error_message() );
        return false;
    }

    // Check response code
    $response_code = wp_remote_retrieve_response_code( $response );
    if ( $response_code < 200 || $response_code >= 300 ) {
        $body_text = wp_remote_retrieve_body( $response );
        utm_news_log_error( "OpenRouter API returned status $response_code. Response: $body_text" );
        return false;
    }

    // Parse JSON response
    $body_text = wp_remote_retrieve_body( $response );
    $data = json_decode( $body_text, true );

    if ( null === $data ) {
        utm_news_log_error( "OpenRouter API response is not valid JSON: $body_text" );
        return false;
    }

    // Extract summary from response. Support multiple possible schemas.
    // 1) choices[0].message.content => may be string or array of parts
    if ( isset( $data['choices'][0]['message']['content'] ) ) {
        $content = $data['choices'][0]['message']['content'];
        if ( is_string( $content ) ) {
            return $content;
        }
        if ( is_array( $content ) ) {
            // If content is an array of {type,text} objects, concatenate text fields
            $collected = '';
            foreach ( $content as $item ) {
                if ( is_array( $item ) && isset( $item['text'] ) ) {
                    $collected .= $item['text'];
                } elseif ( is_string( $item ) ) {
                    $collected .= $item;
                }
            }
            if ( ! empty( $collected ) ) {
                return $collected;
            }
            // sometimes the content may be nested further
            return wp_json_encode( $content );
        }
    }

    // 2) Some responses use choices[0].output or choices[0].text or output_text
    if ( isset( $data['choices'][0]['output'] ) ) {
        return is_string( $data['choices'][0]['output'] ) ? $data['choices'][0]['output'] : wp_json_encode( $data['choices'][0]['output'] );
    }
    if ( isset( $data['choices'][0]['text'] ) ) {
        return $data['choices'][0]['text'];
    }
    if ( isset( $data['output_text'] ) ) {
        return $data['output_text'];
    }

    utm_news_log_error( "OpenRouter API response missing summary content. Response: " . wp_json_encode( $data ) );
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
function utm_news_generate_tts_audio( $post_id, $force = false ) {
    // Check if audio already exists and force is false
    if ( ! $force ) {
        $existing_audio_id = get_post_meta( $post_id, '_audio_attachment_id', true );
        if ( $existing_audio_id ) {
            return false; // Early return - audio already exists
        }
    }
    
    // Get ElevenLabs API key from options
    $api_key = get_option( 'utm_news_elevenlabs_key' );
    if ( empty( $api_key ) ) {
        utm_news_log_error( "ElevenLabs API key is not configured. Cannot generate audio for post ID: $post_id" );
        return false;
    }
    
    // Get excerpt from post
    $post = get_post( $post_id );
    if ( ! $post ) {
        utm_news_log_error( "Post not found for ID: $post_id" );
        return false;
    }
    
    $summary = trim( $post->post_excerpt );
    if ( empty( $summary ) ) {
        utm_news_log_error( "No excerpt found for post ID: $post_id. Cannot generate audio." );
        return false;
    }
    
    // Get language using existing function
    $language = utm_news_get_summary_language( $post_id );
    
    // Call ElevenLabs API
    $audio_data = utm_news_call_elevenlabs_api( $summary, $language, $api_key );
    
    if ( false === $audio_data ) {
        utm_news_log_error( "Failed to generate audio from ElevenLabs API for post ID: $post_id" );
        return false;
    }
    
    // Save audio and get attachment ID
    $attachment_id = utm_news_save_audio_to_media( $audio_data, $post_id, "post-{$post_id}-audio.mp3" );
    
    if ( false === $attachment_id ) {
        utm_news_log_error( "Failed to save audio to media library for post ID: $post_id" );
        return false;
    }
    
    // Save attachment ID to post meta
    update_post_meta( $post_id, '_audio_attachment_id', $attachment_id );
    
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
function utm_news_call_elevenlabs_api( $text, $language, $api_key ) {
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
        'body' => wp_json_encode( $body ),
        'timeout' => 60
    );
    
    // Make API call
    $response = wp_remote_post(
        "https://api.elevenlabs.io/v1/text-to-speech/{$voice_id}",
        $args
    );
    
    // Check for errors
    if ( is_wp_error( $response ) ) {
        utm_news_log_error( "ElevenLabs API request failed: " . $response->get_error_message() );
        return false;
    }
    
    // Check response code
    $response_code = wp_remote_retrieve_response_code( $response );
    if ( 200 !== $response_code ) {
        $body_text = wp_remote_retrieve_body( $response );
        utm_news_log_error( "ElevenLabs API returned status $response_code. Response: $body_text" );
        return false;
    }
    
    // Get audio data
    $audio_data = wp_remote_retrieve_body( $response );
    
    if ( empty( $audio_data ) ) {
        utm_news_log_error( "ElevenLabs API returned empty audio data" );
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
function utm_news_save_audio_to_media( $audio_data, $post_id, $filename ) {
    // Require WordPress file functions
    require_once( ABSPATH . 'wp-admin/includes/file.php' );
    require_once( ABSPATH . 'wp-admin/includes/media.php' );
    require_once( ABSPATH . 'wp-admin/includes/image.php' );
    
    // Get upload directory
    $upload_dir = wp_upload_dir();
    if ( ! isset( $upload_dir['path'] ) ) {
        utm_news_log_error( "Could not determine upload directory" );
        return false;
    }
    
    // Create temp file path
    $tmp_file = $upload_dir['path'] . '/' . $filename;
    
    // Write audio data to temp file
    $write_result = @file_put_contents( $tmp_file, $audio_data );
    if ( false === $write_result ) {
        utm_news_log_error( "Failed to write audio file to disk at $tmp_file" );
        return false;
    }
    
    // Create file array for media_handle_sideload
    $file_array = array(
        'name' => $filename,
        'tmp_name' => $tmp_file,
        'type' => 'audio/mpeg'
    );
    
    // Use media_handle_sideload to attach file
    $attachment_id = media_handle_sideload( $file_array, $post_id );
    
    // Check for errors from media_handle_sideload
    if ( is_wp_error( $attachment_id ) ) {
        utm_news_log_error( "media_handle_sideload failed: " . $attachment_id->get_error_message() );
        // Clean up temp file
        @unlink( $tmp_file );
        return false;
    }
    
    // Clean up temp file
    @unlink( $tmp_file );
    
    return $attachment_id;
}

// Hook for auto-generating AI summaries when posts are published
add_action( 'transition_post_status', 'utm_news_on_post_publish', 10, 3 );
