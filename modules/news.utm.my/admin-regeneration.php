<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
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
function utm_news_render_regenerate_metabox( $post ) {
    if ( ! isset( $post->ID ) ) {
        return;
    }
    
    // Get current status
    $has_summary = ! empty( trim( $post->post_excerpt ) );
    $has_audio = ! empty( get_post_meta( $post->ID, '_audio_attachment_id', true ) );
    
    // Build status indicators
    $summary_status = $has_summary ? '✅ Generated' : '❌ Not generated';
    $audio_status = $has_audio ? '✅ Generated' : '❌ Not generated';
    ?>
    <div>
        <p><strong>Current Status:</strong></p>
        <ul>
            <li>AI Summary: <?php echo esc_html( $summary_status ); ?></li>
            <li>Audio Shortcast: <?php echo esc_html( $audio_status ); ?></li>
        </ul>
        
        <p><strong>Regenerate Summary:</strong></p>
        <form method="post" action="" style="margin-bottom: 15px;">
            <?php wp_nonce_field( 'utm_news_regenerate_summary_nonce', '_utm_news_regenerate_summary_nonce' ); ?>
            <input type="hidden" name="utm_news_regenerate_summary" value="1">
            <button type="submit" class="button button-secondary" style="width: 100%;">Regenerate Summary Only</button>
        </form>
        
        <p><strong>Regenerate Audio:</strong></p>
        <form method="post" action="" style="margin-bottom: 15px;">
            <?php wp_nonce_field( 'utm_news_regenerate_audio_nonce', '_utm_news_regenerate_audio_nonce' ); ?>
            <input type="hidden" name="utm_news_regenerate_audio" value="1">
            <button type="submit" class="button button-secondary" style="width: 100%;">Regenerate Audio Only</button>
        </form>
        
        <p><strong>Regenerate Both:</strong></p>
        <form method="post" action="">
            <?php wp_nonce_field( 'utm_news_regenerate_both_nonce', '_utm_news_regenerate_both_nonce' ); ?>
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
    if ( ! is_admin() || 'post.php' !== $pagenow ) {
        return;
    }
    
    // Only process if editing (not on other post.php actions)
    if ( ! isset( $_GET['action'] ) || 'edit' !== $_GET['action'] ) {
        return;
    }
    
    // Check user capability
    if ( ! current_user_can( 'edit_posts' ) ) {
        return;
    }
    
    // Get post ID
    if ( ! isset( $_GET['post'] ) ) {
        return;
    }
    
    $post_id = intval( $_GET['post'] );
    if ( $post_id <= 0 ) {
        return;
    }
    
    // Determine which action to take
    $regenerate_summary = isset( $_POST['utm_news_regenerate_summary'] ) && '1' === $_POST['utm_news_regenerate_summary'];
    $regenerate_audio = isset( $_POST['utm_news_regenerate_audio'] ) && '1' === $_POST['utm_news_regenerate_audio'];
    $regenerate_both = isset( $_POST['utm_news_regenerate_both'] ) && '1' === $_POST['utm_news_regenerate_both'];
    
    // If none submitted, return early
    if ( ! $regenerate_summary && ! $regenerate_audio && ! $regenerate_both ) {
        return;
    }
    
    // Verify appropriate nonce based on action
    if ( $regenerate_summary ) {
        if ( ! isset( $_POST['_utm_news_regenerate_summary_nonce'] ) || ! wp_verify_nonce( $_POST['_utm_news_regenerate_summary_nonce'], 'utm_news_regenerate_summary_nonce' ) ) {
            utm_news_log_error( 'Regenerate summary nonce verification failed' );
            return;
        }
        
        // Clear existing excerpt
        wp_update_post( array(
            'ID' => $post_id,
            'post_excerpt' => ''
        ) );
        
        // Regenerate summary only
        $summary_result = utm_news_generate_ai_summary( $post_id, true );
        
        if ( $summary_result ) {
            set_transient( 'utm_news_regenerate_success_' . $post_id, 'summary_success', 45 );
        } else {
            set_transient( 'utm_news_regenerate_success_' . $post_id, 'summary_failed', 45 );
        }
        
    } elseif ( $regenerate_audio ) {
        if ( ! isset( $_POST['_utm_news_regenerate_audio_nonce'] ) || ! wp_verify_nonce( $_POST['_utm_news_regenerate_audio_nonce'], 'utm_news_regenerate_audio_nonce' ) ) {
            utm_news_log_error( 'Regenerate audio nonce verification failed' );
            return;
        }
        
        // Delete existing audio
        delete_post_meta( $post_id, '_audio_attachment_id' );
        
        // Regenerate audio only
        $audio_result = utm_news_generate_tts_audio( $post_id, true );
        
        if ( $audio_result ) {
            set_transient( 'utm_news_regenerate_success_' . $post_id, 'audio_success', 45 );
        } else {
            set_transient( 'utm_news_regenerate_success_' . $post_id, 'audio_failed', 45 );
        }
        
    } elseif ( $regenerate_both ) {
        if ( ! isset( $_POST['_utm_news_regenerate_both_nonce'] ) || ! wp_verify_nonce( $_POST['_utm_news_regenerate_both_nonce'], 'utm_news_regenerate_both_nonce' ) ) {
            utm_news_log_error( 'Regenerate both nonce verification failed' );
            return;
        }
        
        // Clear existing excerpt and audio
        wp_update_post( array(
            'ID' => $post_id,
            'post_excerpt' => ''
        ) );
        delete_post_meta( $post_id, '_audio_attachment_id' );
        
        // Regenerate both
        $summary_result = utm_news_generate_ai_summary( $post_id, true );
        $audio_result = utm_news_generate_tts_audio( $post_id, true );
        
        if ( $summary_result && $audio_result ) {
            set_transient( 'utm_news_regenerate_success_' . $post_id, 'both_success', 45 );
        } elseif ( $summary_result ) {
            set_transient( 'utm_news_regenerate_success_' . $post_id, 'summary_only', 45 );
        } elseif ( $audio_result ) {
            set_transient( 'utm_news_regenerate_success_' . $post_id, 'audio_only', 45 );
        } else {
            set_transient( 'utm_news_regenerate_success_' . $post_id, 'both_failed', 45 );
        }
    }
    
    // Redirect to prevent resubmission
    wp_redirect( admin_url( 'post.php?post=' . $post_id . '&action=edit' ) );
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
    if ( ! $post || ! isset( $post->ID ) ) {
        return;
    }
    
    $status = get_transient( 'utm_news_regenerate_success_' . $post->ID );
    if ( $status ) {
        delete_transient( 'utm_news_regenerate_success_' . $post->ID );
        
        switch ( $status ) {
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

// Phase 5: Register meta box for manual regenerate
add_action( 'add_meta_boxes', 'utm_news_add_regenerate_metabox' );

// Phase 5: Handle manual regenerate
add_action( 'admin_init', 'utm_news_handle_manual_regenerate' );

// Display regenerate notices
add_action( 'admin_notices', 'utm_news_show_regenerate_notice' );
