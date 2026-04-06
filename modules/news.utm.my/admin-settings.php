<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
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
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You do not have permission to access this page.' );
    }
    ?>
    <div class="wrap">
        <h1>News Settings</h1>
        <p>Configure API keys and language preferences for the news module.</p>
        
        <?php settings_errors( 'utm_news_settings' ); ?>
        
        <form method="post" action="">
            <?php wp_nonce_field( 'utm_news_settings_nonce', '_nonce_utm_news_settings' ); ?>
            
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
                            value="<?php echo esc_attr( get_option( 'utm_news_openai_key' ) ); ?>" 
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
                            value="<?php echo esc_attr( get_option( 'utm_news_elevenlabs_key' ) ); ?>" 
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
                            <option value="auto" <?php selected( get_option( 'utm_news_language_pref' ), 'auto' ); ?>>Auto-detect</option>
                            <option value="ms" <?php selected( get_option( 'utm_news_language_pref' ), 'ms' ); ?>>Malay</option>
                            <option value="en" <?php selected( get_option( 'utm_news_language_pref' ), 'en' ); ?>>English</option>
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
                                <?php checked( get_option( 'utm_news_ai_enabled', '0' ), '1' ); ?>
                            />
                            Enable AI summary and audio generation
                        </label>
                        <p class="description">Uncheck to disable automatic AI summary and audio generation for new posts.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="utm_news_show_sdg_icons">SDG Icons on Posts</label>
                    </th>
                    <td>
                        <label>
                            <input
                                type="checkbox"
                                id="utm_news_show_sdg_icons"
                                name="utm_news_show_sdg_icons"
                                value="1"
                                <?php checked( get_option( 'utm_news_show_sdg_icons', '1' ), '1' ); ?>
                            />
                            Show SDG icon strip at the top of single post content
                        </label>
                        <p class="description">Displays SDG wheel + SDG goal icons (75x75) based on SDG tags.</p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button( 'Save Settings', 'primary', 'utm_news_save_settings_btn' ); ?>
        </form>
    </div>
    <?php
}

/**
 * Handle form submission and save settings
 */
function utm_news_save_settings() {
    // Only process if button was clicked
    if ( ! isset( $_POST['utm_news_save_settings_btn'] ) ) {
        return;
    }
    
    // Verify nonce
    if ( ! isset( $_POST['_nonce_utm_news_settings'] ) || 
        ! wp_verify_nonce( $_POST['_nonce_utm_news_settings'], 'utm_news_settings_nonce' ) ) {
        utm_news_log_error( 'Nonce verification failed for settings save' );
        return;
    }
    
    // Check capability
    if ( ! current_user_can( 'manage_options' ) ) {
        utm_news_log_error( 'Non-admin user attempted to save settings' );
        return;
    }
    
    // Sanitize and save OpenAI key
    if ( isset( $_POST['utm_news_openai_key'] ) ) {
        $openai_key = sanitize_text_field( $_POST['utm_news_openai_key'] );
        update_option( 'utm_news_openai_key', $openai_key );
    }
    
    // Sanitize and save ElevenLabs key
    if ( isset( $_POST['utm_news_elevenlabs_key'] ) ) {
        $elevenlabs_key = sanitize_text_field( $_POST['utm_news_elevenlabs_key'] );
        update_option( 'utm_news_elevenlabs_key', $elevenlabs_key );
    }
    
    // Sanitize and save language preference
    if ( isset( $_POST['utm_news_language_pref'] ) ) {
        $language_pref = sanitize_text_field( $_POST['utm_news_language_pref'] );
        // Validate language preference
        if ( in_array( $language_pref, array( 'auto', 'ms', 'en' ) ) ) {
            update_option( 'utm_news_language_pref', $language_pref );
        }
    }
    
    // Save AI enabled toggle
    $ai_enabled = isset( $_POST['utm_news_ai_enabled'] ) ? '1' : '0';
    update_option( 'utm_news_ai_enabled', $ai_enabled );

    // Save SDG icon visibility toggle
    $show_sdg_icons = isset( $_POST['utm_news_show_sdg_icons'] ) ? '1' : '0';
    update_option( 'utm_news_show_sdg_icons', $show_sdg_icons );
    
    add_settings_error( 'utm_news_settings', 'settings_updated', 'Settings saved successfully.', 'success' );
}

// Register admin menu
add_action( 'admin_menu', 'utm_news_register_settings_menu' );

// Handle form submission (must happen before rendering)
add_action( 'admin_init', 'utm_news_save_settings' );
