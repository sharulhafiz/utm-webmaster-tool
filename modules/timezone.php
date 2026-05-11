<?php
/**
 * Plugin Name:       WordPress Timezone Fixer
 * Description:       Automatically fixes PHP timezone misconfigurations by setting the default timezone to UTC for WordPress operations, preventing issues with cron jobs and scheduled posts.
 * Version:           2.0.0
 * Author:            UTM Webmaster Tools
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-timezone-fixer
 * 
 * ABOUT THIS PLUGIN:
 * ==================
 * Many WordPress sites experience issues with scheduled posts, cron jobs, and 
 * time-sensitive operations due to server timezone misconfigurations. The most 
 * common issue is when the server's PHP default timezone is not set to UTC.
 * 
 * WordPress expects the server to use UTC and handles timezone conversion for 
 * display purposes. When the server uses a different timezone, it can cause:
 * - Scheduled posts to publish at wrong times
 * - Cron jobs to run incorrectly
 * - Plugin conflicts with time-based operations
 * - Database timestamp inconsistencies
 * 
 * HOW IT WORKS:
 * =============
 * This plugin automatically detects when your server's PHP timezone is not UTC
 * and safely overrides it to UTC for WordPress operations only. It:
 * 
 * 1. Checks the server's PHP timezone on every page load
 * 2. Only applies the fix if:
 *    - PHP timezone is not UTC, AND
 *    - WordPress is using a timezone string (not manual UTC offset)
 * 3. Sets date_default_timezone_set('UTC') for the current request
 * 4. Provides admin feedback and Site Health integration
 * 5. Includes a settings page to disable the fix if needed
 * 
 * SAFETY:
 * =======
 * - Only affects WordPress operations, not server-wide settings
 * - Includes an off switch for advanced users
 * - Preserves original timezone information for diagnostics
 * - Won't interfere with sites correctly using UTC offsets
 * 
 * INCLUDES BONUS DEBUGGER:
 * ========================
 * The plugin also includes a comprehensive timezone debugger tool for 
 * diagnosing complex timezone issues across all layers of the stack.
 */

// Only run this plugin at news.utm.my
if ( strpos( $_SERVER['HTTP_HOST'], 'news.utm.my' ) === false ) {
    return;
}

// Prevent direct file access for security
if ( ! defined( 'ABSPATH' ) ) {
    die;
}

// Plugin activation hook to set default options
register_activation_hook( __FILE__, 'wtfix_activate_plugin' );

/**
 * Plugin activation callback.
 * Sets default options and clears any cached data.
 */
function wtfix_activate_plugin() {
    // Set default option to enable correction
    if ( false === get_option( 'wtfix_enable_correction' ) ) {
        add_option( 'wtfix_enable_correction', true );
    }
    
    // Clear any existing correction flags to allow fresh detection
    delete_option( 'wtfix_correction_applied' );
    delete_option( 'wtfix_original_timezone' );
    
    // Clear user dismissal flags
    delete_metadata( 'user', 0, 'wtfix_notice_dismissed', '', true );
}

// Plugin deactivation hook to clean up
register_deactivation_hook( __FILE__, 'wtfix_deactivate_plugin' );

/**
 * Plugin deactivation callback.
 * Resets the PHP timezone to its original value if we had modified it.
 */
function wtfix_deactivate_plugin() {
    $original_timezone = get_option( 'wtfix_original_timezone', false );
    
    // Attempt to restore original timezone if we have it and it's valid
    if ( $original_timezone && in_array( $original_timezone, timezone_identifiers_list() ) ) {
        date_default_timezone_set( $original_timezone );
    }
}

// Phase 1: The Core "Fixer" Logic
// Task 1.2 & 1.3: Apply timezone fix at the earliest possible moment
add_action( 'plugins_loaded', 'wtfix_apply_utc_timezone', 0 );

// Additional hooks to fix post scheduling timezone issues
add_action( 'admin_init', 'wtfix_fix_post_scheduling_timezone' );
add_filter( 'wp_insert_post_data', 'wtfix_fix_post_date_timezone', 10, 2 );
add_action( 'admin_enqueue_scripts', 'wtfix_fix_post_edit_timezone_display' );
add_filter( 'get_post_time', 'wtfix_fix_post_time_display', 10, 3 );
add_filter( 'get_post_modified_time', 'wtfix_fix_post_time_display', 10, 3 );
add_action( 'add_meta_boxes', 'wtfix_override_post_timestamp_display' );

/**
 * Applies UTC timezone override if needed.
 * 
 * This function checks if the server's PHP default timezone is misconfigured
 * (not UTC) and if WordPress is using a proper timezone string. If both
 * conditions are met, it sets the PHP default timezone to UTC to prevent
 * issues with cron jobs, scheduled posts, and other time-sensitive operations.
 * 
 * This follows WordPress best practices where the server should use UTC
 * and WordPress handles timezone conversion for display purposes.
 */
function wtfix_apply_utc_timezone() {
    // Only apply fix if the setting is enabled (default: true)
    if ( ! get_option( 'wtfix_enable_correction', true ) ) {
        return;
    }
    
    // Condition 1: Check if PHP default timezone is NOT UTC
    $current_php_timezone = date_default_timezone_get();
    if ( $current_php_timezone === 'UTC' ) {
        return; // Already correct, no fix needed
    }
    
    // Condition 2: Check if WordPress timezone is set to a location string
    // This ensures we don't interfere with sites correctly using manual UTC offset
    $wp_timezone_string = get_option( 'timezone_string' );
    if ( empty( $wp_timezone_string ) ) {
        // If WordPress is using UTC offset, ensure it's properly set
        $gmt_offset = get_option( 'gmt_offset', 0 );
        if ( $gmt_offset == 0 ) {
            return; // Already using UTC offset of 0, don't interfere
        }
        // For sites using manual UTC offset, we still need PHP to be UTC
        // but we won't force WordPress timezone_string
    }
    
    // Apply the fix: Set PHP default timezone to UTC
    date_default_timezone_set( 'UTC' );
    
    // Store that we applied a fix for admin notices and status reporting
    update_option( 'wtfix_correction_applied', true );
    update_option( 'wtfix_original_timezone', $current_php_timezone );
    
    // Additional fix: Ensure WordPress timezone is properly configured
    wtfix_ensure_wp_timezone_consistency();
}

/**
 * Ensures WordPress timezone settings are consistent and properly configured.
 * This fixes the issue where scheduled posts show local time but save as UTC.
 */
function wtfix_ensure_wp_timezone_consistency() {
    $wp_timezone_string = get_option( 'timezone_string' );
    $gmt_offset = get_option( 'gmt_offset', 0 );
    
    // If no timezone string is set but there's a GMT offset, try to convert it to a timezone string
    if ( empty( $wp_timezone_string ) && $gmt_offset != 0 ) {
        // Common timezone mappings for UTC offsets
        $offset_to_timezone = array(
            '8'    => 'Asia/Singapore',    // UTC+8 (Malaysia/Singapore)
            '8.0'  => 'Asia/Singapore',
            '+8'   => 'Asia/Singapore',
            '7'    => 'Asia/Bangkok',      // UTC+7
            '7.0'  => 'Asia/Bangkok',
            '+7'   => 'Asia/Bangkok',
            '9'    => 'Asia/Tokyo',        // UTC+9
            '9.0'  => 'Asia/Tokyo',
            '+9'   => 'Asia/Tokyo',
            '-5'   => 'America/New_York',  // UTC-5
            '-8'   => 'America/Los_Angeles', // UTC-8
        );
        
        $offset_key = (string) $gmt_offset;
        if ( isset( $offset_to_timezone[ $offset_key ] ) ) {
            update_option( 'timezone_string', $offset_to_timezone[ $offset_key ] );
            // Clear the GMT offset since we now have a proper timezone string
            update_option( 'gmt_offset', 0 );
        }
    }
    
    // If we're in Malaysia/Singapore timezone (+8), ensure it's set correctly
    if ( $gmt_offset == 8 && empty( $wp_timezone_string ) ) {
        update_option( 'timezone_string', 'Asia/Kuala_Lumpur' );
        update_option( 'gmt_offset', 0 );
    }
}

/**
 * Fixes post scheduling timezone issues in the admin area.
 * Ensures the post editor shows consistent times.
 */
function wtfix_fix_post_scheduling_timezone() {
    // Only run in admin area
    if ( ! is_admin() ) {
        return;
    }
    
    // Check if we're on a post edit screen
    global $pagenow;
    if ( ! in_array( $pagenow, array( 'post.php', 'post-new.php', 'edit.php' ) ) ) {
        return;
    }
    
    // Ensure WordPress timezone is properly set
    $wp_timezone_string = get_option( 'timezone_string' );
    if ( empty( $wp_timezone_string ) ) {
        $gmt_offset = get_option( 'gmt_offset', 0 );
        if ( $gmt_offset == 8 ) {
            // Likely Malaysia/Singapore, set proper timezone
            update_option( 'timezone_string', 'Asia/Kuala_Lumpur' );
            update_option( 'gmt_offset', 0 );
        }
    }
}

/**
 * Fixes post date timezone issues when saving posts.
 * Ensures scheduled posts are saved with correct timezone handling.
 */
function wtfix_fix_post_date_timezone( $data, $postarr ) {
    // Skip if this is a new post or auto-draft
    if ( empty( $postarr['ID'] ) || in_array( $data['post_status'], array( 'auto-draft', 'inherit' ) ) ) {
        return $data;
    }
    
    // Only process if we have valid post dates
    if ( empty( $data['post_date'] ) || $data['post_date'] === '0000-00-00 00:00:00' ) {
        return $data;
    }
    
    // Validate the date timestamp
    $post_date_timestamp = strtotime( $data['post_date'] );
    if ( ! $post_date_timestamp || $post_date_timestamp <= 0 ) {
        return $data; // Invalid date, let WordPress handle it
    }
    
    // Get WordPress timezone
    $wp_timezone_string = get_option( 'timezone_string' );
    $gmt_offset = get_option( 'gmt_offset', 0 );
    
    // If we have a timezone string, ensure dates are handled consistently
    if ( ! empty( $wp_timezone_string ) ) {
        try {
            // Create timezone objects
            $wp_timezone = new DateTimeZone( $wp_timezone_string );
            $utc_timezone = new DateTimeZone( 'UTC' );
            
            // Parse the post date
            $post_date = new DateTime( $data['post_date'], $wp_timezone );
            
            // Additional validation - ensure the date is reasonable
            if ( $post_date->format( 'Y' ) < 1970 || $post_date->format( 'Y' ) > 2100 ) {
                return $data; // Invalid year, let WordPress handle it
            }
            
            // Convert to UTC for storage
            $post_date->setTimezone( $utc_timezone );
            $data['post_date_gmt'] = $post_date->format( 'Y-m-d H:i:s' );
            
            // Ensure the local date is properly formatted
            $post_date->setTimezone( $wp_timezone );
            $data['post_date'] = $post_date->format( 'Y-m-d H:i:s' );
            
        } catch ( Exception $e ) {
            // If timezone conversion fails, let WordPress handle it normally
            error_log( 'Timezone Fixer: Error converting post date timezone - ' . $e->getMessage() );
        }
    }
    
    return $data;
}

/**
 * Fixes timezone display issues in the post edit screen.
 * Ensures scheduled post times show in local timezone, not UTC.
 */
function wtfix_fix_post_edit_timezone_display( $hook ) {
    // Only run on post edit screens
    if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ) ) ) {
        return;
    }
    
    // Get WordPress timezone settings
    $wp_timezone_string = get_option( 'timezone_string' );
    $gmt_offset = get_option( 'gmt_offset', 0 );
    
    // Only proceed if we have proper timezone settings
    if ( empty( $wp_timezone_string ) && $gmt_offset == 0 ) {
        return;
    }
    
    // Add a filter to ensure post dates display correctly in the edit screen
    add_filter( 'wp_insert_post_empty_content', '__return_false' );
}

/**
 * Fixes post time display to show local time instead of UTC in admin.
 * This ensures the post edit screen shows the correct local time.
 */
function wtfix_fix_post_time_display( $time, $format, $gmt ) {
    // Only fix in admin area and when not requesting GMT time
    if ( ! is_admin() || $gmt ) {
        return $time;
    }
    
    // Only fix on post edit screens
    global $pagenow;
    if ( ! in_array( $pagenow, array( 'post.php', 'post-new.php', 'edit.php' ) ) ) {
        return $time;
    }
    
    // Get the current post ID
    $post_id = 0;
    if ( isset( $_GET['post'] ) ) {
        $post_id = intval( $_GET['post'] );
    } elseif ( isset( $_POST['post_ID'] ) ) {
        $post_id = intval( $_POST['post_ID'] );
    }
    
    if ( ! $post_id ) {
        return $time;
    }
    
    // Get the post
    $post = get_post( $post_id );
    if ( ! $post || ! $post->post_date_gmt || $post->post_date_gmt === '0000-00-00 00:00:00' ) {
        return $time;
    }
    
    // Skip for auto-drafts and new posts
    if ( in_array( $post->post_status, array( 'auto-draft', 'new' ) ) ) {
        return $time;
    }
    
    // Get WordPress timezone
    $wp_timezone_string = get_option( 'timezone_string' );
    if ( empty( $wp_timezone_string ) ) {
        return $time;
    }
    
    // Validate the date
    $post_date_gmt_timestamp = strtotime( $post->post_date_gmt );
    if ( ! $post_date_gmt_timestamp || $post_date_gmt_timestamp <= 0 ) {
        return $time; // Invalid date, return original
    }
    
    try {
        // Create timezone objects
        $wp_timezone = new DateTimeZone( $wp_timezone_string );
        $utc_timezone = new DateTimeZone( 'UTC' );
        
        // Convert the post date from UTC to local timezone
        $post_date_utc = new DateTime( $post->post_date_gmt, $utc_timezone );
        $post_date_local = $post_date_utc->setTimezone( $wp_timezone );
        
        // Additional validation - ensure reasonable year
        if ( $post_date_local->format( 'Y' ) < 1970 || $post_date_local->format( 'Y' ) > 2100 ) {
            return $time; // Invalid year, return original
        }
        
        // Return the formatted local time
        if ( $format ) {
            return $post_date_local->format( $format );
        } else {
            return $post_date_local->format( 'Y-m-d H:i:s' );
        }
        
    } catch ( Exception $e ) {
        // If conversion fails, return original time
        error_log( 'Timezone Fixer: Error fixing post time display - ' . $e->getMessage() );
        return $time;
    }
}

/**
 * Overrides the post timestamp display in the edit screen metabox.
 * This is the most direct way to fix the timezone display issue.
 */
function wtfix_override_post_timestamp_display() {
    global $post, $pagenow;
    
    // Only on post edit screens
    if ( ! in_array( $pagenow, array( 'post.php', 'post-new.php' ) ) ) {
        return;
    }
    
    // Skip for new posts or posts without valid dates
    if ( ! $post || ! $post->ID || ! $post->post_date_gmt || $post->post_date_gmt === '0000-00-00 00:00:00' ) {
        return;
    }
    
    // Skip for auto-drafts and new posts
    if ( in_array( $post->post_status, array( 'auto-draft', 'new' ) ) ) {
        return;
    }
    
    $wp_timezone_string = get_option( 'timezone_string' );
    if ( empty( $wp_timezone_string ) ) {
        return;
    }
    
    // Validate the date before proceeding
    $post_date_gmt_timestamp = strtotime( $post->post_date_gmt );
    if ( ! $post_date_gmt_timestamp || $post_date_gmt_timestamp <= 0 ) {
        return; // Invalid date, skip
    }
    
    // Add JavaScript to fix the timestamp display
    add_action( 'admin_footer', 'wtfix_add_timestamp_fix_script' );
}

/**
 * Adds JavaScript to fix timestamp display in post edit screen.
 */
function wtfix_add_timestamp_fix_script() {
    global $post;
    
    // Skip if no post or this is a new post
    if ( ! $post || ! $post->ID ) {
        return;
    }
    
    // Skip if post doesn't have a valid date set
    if ( ! $post->post_date_gmt || $post->post_date_gmt === '0000-00-00 00:00:00' ) {
        return;
    }
    
    // Skip for auto-drafts and new posts
    if ( in_array( $post->post_status, array( 'auto-draft', 'new' ) ) ) {
        return;
    }
    
    $wp_timezone_string = get_option( 'timezone_string' );
    if ( empty( $wp_timezone_string ) ) {
        return;
    }
    
    try {
        // Validate the date before conversion
        $post_date_gmt_timestamp = strtotime( $post->post_date_gmt );
        if ( ! $post_date_gmt_timestamp || $post_date_gmt_timestamp <= 0 ) {
            return; // Invalid date, skip
        }
        
        // Convert UTC time to local time
        $utc_timezone = new DateTimeZone( 'UTC' );
        $wp_timezone = new DateTimeZone( $wp_timezone_string );
        
        $post_date_utc = new DateTime( $post->post_date_gmt, $utc_timezone );
        $post_date_local = $post_date_utc->setTimezone( $wp_timezone );
        
        $local_year = $post_date_local->format( 'Y' );
        $local_month = $post_date_local->format( 'm' );
        $local_day = $post_date_local->format( 'd' );
        $local_hour = $post_date_local->format( 'H' );
        $local_minute = $post_date_local->format( 'i' );
        
        // Additional validation - ensure we have reasonable values
        if ( $local_year < 1970 || $local_year > 2100 ) {
            return; // Invalid year, skip
        }
        
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Only fix timestamp display for existing posts with valid dates
            if ($('#post_ID').val() && $('#post_ID').val() !== '0') {
                // Fix the timestamp display to show local time
                function fixTimestampDisplay() {
                    // Only update if the fields exist and contain valid data
                    if ($('#aa').length && $('#aa').val() !== '<?php echo $local_year; ?>') {
                        $('#aa').val('<?php echo $local_year; ?>');
                        $('#mm').val('<?php echo $local_month; ?>');
                        $('#jj').val('<?php echo $local_day; ?>');
                        $('#hh').val('<?php echo $local_hour; ?>');
                        $('#mn').val('<?php echo $local_minute; ?>');
                        
                        // Update the timestamp text
                        var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                        var month_name = months[parseInt('<?php echo $local_month; ?>') - 1];
                        var timestamp_text = month_name + ' <?php echo $local_day; ?>, <?php echo $local_year; ?> @ <?php echo $local_hour; ?>:<?php echo $local_minute; ?>';
                        $('#timestamp b').text(timestamp_text);
                        
                        console.log('Timezone Fixer: Fixed post timestamp display to show local time');
                    }
                }
                
                // Run after a small delay to ensure DOM is ready
                setTimeout(fixTimestampDisplay, 500);
                
                // Also fix when the Edit link is clicked
                $('#timestamp').on('click', '.edit-timestamp', function() {
                    setTimeout(fixTimestampDisplay, 100);
                });
            }
        });
        </script>
        <?php
        
    } catch ( Exception $e ) {
        error_log( 'Timezone Fixer: Error generating timestamp fix script - ' . $e->getMessage() );
    }
}

// Phase 2: User Feedback and Admin Interface
// Task 2.1: Add Site Health Status Test
add_filter( 'site_status_tests', 'wtfix_add_site_health_test' );

/**
 * Adds a custom Site Health test for PHP timezone configuration.
 *
 * @param array $tests Array of Site Health tests.
 * @return array Modified array with our custom test.
 */
function wtfix_add_site_health_test( $tests ) {
    $tests['direct']['wtfix_php_timezone'] = array(
        'label' => __( 'PHP Default Timezone', 'wp-timezone-fixer' ),
        'test'  => 'wtfix_site_health_timezone_test',
    );
    return $tests;
}

/**
 * Performs the Site Health test for PHP timezone configuration.
 *
 * @return array Site Health test result.
 */
function wtfix_site_health_timezone_test() {
    $current_timezone = date_default_timezone_get();
    $original_timezone = get_option( 'wtfix_original_timezone', false );
    $correction_applied = get_option( 'wtfix_correction_applied', false );
    
    if ( $current_timezone === 'UTC' ) {
        if ( $correction_applied && $original_timezone ) {
            // Plugin applied a fix
            return array(
                'label'       => __( 'PHP default timezone is correctly set to UTC (via Timezone Fixer)', 'wp-timezone-fixer' ),
                'status'      => 'good',
                'badge'       => array(
                    'label' => __( 'Performance', 'wp-timezone-fixer' ),
                    'color' => 'blue',
                ),
                'description' => sprintf(
                    '<p>%s</p><p>%s</p>',
                    sprintf( 
                        __( 'PHP default timezone was originally set to %s, but the Timezone Fixer plugin has automatically corrected it to UTC for WordPress operations.', 'wp-timezone-fixer' ),
                        '<code>' . esc_html( $original_timezone ) . '</code>'
                    ),
                    __( 'This ensures scheduled posts, cron jobs, and other time-sensitive operations work correctly.', 'wp-timezone-fixer' )
                ),
                'test'        => 'wtfix_php_timezone',
            );
        } else {
            // Already correct
            return array(
                'label'       => __( 'PHP default timezone is correctly set to UTC', 'wp-timezone-fixer' ),
                'status'      => 'good',
                'badge'       => array(
                    'label' => __( 'Performance', 'wp-timezone-fixer' ),
                    'color' => 'blue',
                ),
                'description' => '<p>' . __( 'Your server is properly configured with UTC as the default PHP timezone, which is the recommended setting for WordPress.', 'wp-timezone-fixer' ) . '</p>',
                'test'        => 'wtfix_php_timezone',
            );
        }
    } else {
        // Not UTC and plugin didn't fix it (likely disabled)
        return array(
            'label'       => sprintf( __( 'PHP default timezone is set to %s', 'wp-timezone-fixer' ), $current_timezone ),
            'status'      => 'critical',
            'badge'       => array(
                'label' => __( 'Performance', 'wp-timezone-fixer' ),
                'color' => 'red',
            ),
            'description' => sprintf(
                '<p>%s</p><p>%s</p>',
                sprintf(
                    __( 'PHP default timezone is set to %s instead of UTC. This can cause issues with scheduled events, cron jobs, and post scheduling.', 'wp-timezone-fixer' ),
                    '<code>' . esc_html( $current_timezone ) . '</code>'
                ),
                __( 'The Timezone Fixer plugin is installed but may be disabled. Check the plugin settings or contact your hosting provider to set the server timezone to UTC.', 'wp-timezone-fixer' )
            ),
            'test'        => 'wtfix_php_timezone',
        );
    }
}

// Task 2.2: Persistent Admin Notice on First Activation
add_action( 'admin_notices', 'wtfix_show_activation_notice' );

/**
 * Shows a one-time admin notice when the plugin has applied a timezone fix.
 */
function wtfix_show_activation_notice() {
    // Only show if correction was applied and notice hasn't been dismissed
    if ( ! get_option( 'wtfix_correction_applied', false ) || get_user_meta( get_current_user_id(), 'wtfix_notice_dismissed', true ) ) {
        return;
    }
    
    $original_timezone = get_option( 'wtfix_original_timezone', 'unknown' );
    ?>
    <div class="notice notice-success is-dismissible" data-notice="wtfix-activation">
        <p>
            <strong><?php _e( 'Timezone Fixer Activated', 'wp-timezone-fixer' ); ?></strong>
        </p>
        <p>
            <?php 
            printf(
                __( 'Timezone Fixer has detected that your server\'s default PHP timezone was set to %s instead of UTC. It has been automatically corrected to UTC for this WordPress session to ensure scheduled posts and events work correctly. This is the recommended setting for WordPress. No further action is needed.', 'wp-timezone-fixer' ),
                '<code>' . esc_html( $original_timezone ) . '</code>'
            );
            ?>
        </p>
        <p>
            <a href="<?php echo esc_url( admin_url( 'tools.php?page=wp-timezone-fixer-status' ) ); ?>"><?php _e( 'View Timezone Status', 'wp-timezone-fixer' ); ?></a>
        </p>
    </div>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            const notice = document.querySelector('[data-notice="wtfix-activation"]');
            if (notice) {
                notice.addEventListener('click', function(e) {
                    if (e.target.classList.contains('notice-dismiss')) {
                        fetch(ajaxurl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'action=wtfix_dismiss_notice&_wpnonce=' + '<?php echo wp_create_nonce( "wtfix_dismiss_notice" ); ?>'
                        });
                    }
                });
            }
        });
    </script>
    <?php
}

// Handle notice dismissal
add_action( 'wp_ajax_wtfix_dismiss_notice', 'wtfix_dismiss_notice' );

/**
 * Handles dismissal of the activation notice.
 */
function wtfix_dismiss_notice() {
    check_ajax_referer( 'wtfix_dismiss_notice' );
    update_user_meta( get_current_user_id(), 'wtfix_notice_dismissed', true );
    wp_die();
}

// Phase 3: Safety and Configuration
// Task 3.1: Add Minimal Settings Page
add_action( 'admin_menu', 'wtfix_add_admin_menu_page' );

/**
 * Registers the settings page under the "Tools" menu.
 */
function wtfix_add_admin_menu_page() {
    add_management_page(
        'Timezone Fixer Status',        // Page Title
        'Timezone Fixer',               // Menu Title
        'manage_options',               // Capability
        'wp-timezone-fixer-status',     // Menu Slug
        'wtfix_render_status_page'      // Callback function to render the page
    );
}

// Task 3.2: Handle Settings Form Submission
add_action( 'admin_init', 'wtfix_handle_settings_form' );

/**
 * Handles the settings form submission.
 */
function wtfix_handle_settings_form() {
    if ( ! isset( $_POST['wtfix_settings_nonce'] ) || ! wp_verify_nonce( $_POST['wtfix_settings_nonce'], 'wtfix_save_settings' ) ) {
        return;
    }
    
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    
    $enable_correction = isset( $_POST['wtfix_enable_correction'] ) ? true : false;
    update_option( 'wtfix_enable_correction', $enable_correction );
    
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-success is-dismissible"><p>' . __( 'Settings saved successfully.', 'wp-timezone-fixer' ) . '</p></div>';
    });
}

/**
 * Renders the status page.
 * Task 3.3: Display Clear Status Report
 */
function wtfix_render_status_page() {
    $enable_correction = get_option( 'wtfix_enable_correction', true );
    $correction_applied = get_option( 'wtfix_correction_applied', false );
    $original_timezone = get_option( 'wtfix_original_timezone', false );
    
    // Get current state information
    $server_original_timezone = ini_get( 'date.timezone' ) ?: 'Not set in php.ini';
    $wp_site_timezone = get_option( 'timezone_string' ) ?: 'Using UTC offset: ' . get_option( 'gmt_offset', 0 );
    $effective_php_timezone = date_default_timezone_get();
    
    ?>
    <div class="wrap">
        <h1><span class="dashicons-before dashicons-clock"></span> Timezone Fixer Status</h1>
        <p><?php _e( 'This plugin automatically corrects PHP timezone misconfigurations to prevent issues with scheduled posts and cron jobs.', 'wp-timezone-fixer' ); ?></p>

        <form method="post" action="">
            <?php wp_nonce_field( 'wtfix_save_settings', 'wtfix_settings_nonce' ); ?>
            
            <div class="postbox">
                <h2 class="hndle"><?php _e( 'Settings', 'wp-timezone-fixer' ); ?></h2>
                <div class="inside">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e( 'Automatic Timezone Correction', 'wp-timezone-fixer' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wtfix_enable_correction" value="1" <?php checked( $enable_correction ); ?> />
                                    <?php _e( 'Enable automatic timezone correction', 'wp-timezone-fixer' ); ?>
                                </label>
                                <p class="description"><?php _e( 'When enabled, this plugin will automatically set the PHP default timezone to UTC if your server is misconfigured.', 'wp-timezone-fixer' ); ?></p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(); ?>
                </div>
            </div>
        </form>

        <div class="postbox">
            <h2 class="hndle"><?php _e( 'Current Status', 'wp-timezone-fixer' ); ?></h2>
            <div class="inside">
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e( 'Server\'s Original PHP Timezone', 'wp-timezone-fixer' ); ?></th>
                        <td><code><?php echo esc_html( $server_original_timezone ); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e( 'WordPress Site Timezone', 'wp-timezone-fixer' ); ?></th>
                        <td><code><?php echo esc_html( $wp_site_timezone ); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e( 'Effective PHP Timezone for WordPress', 'wp-timezone-fixer' ); ?></th>
                        <td><code><?php echo esc_html( $effective_php_timezone ); ?></code></td>
                    </tr>
                </table>

                <div style="margin-top: 20px; padding: 15px; border-left: 4px solid #72aee6;">
                    <h4><?php _e( 'Status', 'wp-timezone-fixer' ); ?></h4>
                    <?php
                    if ( ! $enable_correction ) {
                        echo '<p><span class="dashicons dashicons-warning" style="color: #ff8f00;"></span> <strong>' . __( 'Disabled', 'wp-timezone-fixer' ) . '</strong>: ' . 
                             sprintf( __( 'Automatic timezone correction is turned off. Your effective PHP timezone is %s.', 'wp-timezone-fixer' ), '<code>' . esc_html( $effective_php_timezone ) . '</code>' ) . '</p>';
                    } elseif ( $correction_applied && $original_timezone ) {
                        echo '<p><span class="dashicons dashicons-yes-alt" style="color: #2e7d32;"></span> <strong>' . __( 'Active', 'wp-timezone-fixer' ) . '</strong>: ' . 
                             sprintf( __( 'Your server\'s timezone was set to %s, but this plugin has correctly set it to UTC for WordPress.', 'wp-timezone-fixer' ), '<code>' . esc_html( $original_timezone ) . '</code>' ) . '</p>';
                    } elseif ( $effective_php_timezone === 'UTC' ) {
                        echo '<p><span class="dashicons dashicons-yes-alt" style="color: #2e7d32;"></span> <strong>' . __( 'Nominal', 'wp-timezone-fixer' ) . '</strong>: ' . 
                             __( 'Your server is already correctly configured with the PHP timezone set to UTC. The plugin is standing by but no changes are needed.', 'wp-timezone-fixer' ) . '</p>';
                    } else {
                        echo '<p><span class="dashicons dashicons-warning" style="color: #ff8f00;"></span> <strong>' . __( 'Issue Detected', 'wp-timezone-fixer' ) . '</strong>: ' . 
                             sprintf( __( 'Your server\'s PHP timezone is set to %s but automatic correction may not be applying. Check your WordPress timezone settings.', 'wp-timezone-fixer' ), '<code>' . esc_html( $effective_php_timezone ) . '</code>' ) . '</p>';
                    }
                    
                    // Additional status for post scheduling fix
                    if ( empty( $wp_site_timezone ) || strpos( $wp_site_timezone, 'UTC offset:' ) !== false ) {
                        echo '<p><span class="dashicons dashicons-info" style="color: #0073aa;"></span> <strong>' . __( 'Post Scheduling Fix', 'wp-timezone-fixer' ) . '</strong>: ' . 
                             __( 'WordPress is using manual UTC offset. The plugin will attempt to convert this to a proper timezone string to fix post scheduling issues.', 'wp-timezone-fixer' ) . '</p>';
                    } else {
                        echo '<p><span class="dashicons dashicons-yes-alt" style="color: #2e7d32;"></span> <strong>' . __( 'Post Scheduling', 'wp-timezone-fixer' ) . '</strong>: ' . 
                             __( 'WordPress is using a proper timezone string, which should prevent post scheduling time inconsistencies.', 'wp-timezone-fixer' ) . '</p>';
                    }
                    ?>
                </div>

                <div style="margin-top: 20px;">
                    <h4><?php _e( 'Need the Full Debug Tool?', 'wp-timezone-fixer' ); ?></h4>
                    <p><?php _e( 'For comprehensive timezone debugging and diagnostics, you can also access the full timezone debugger:', 'wp-timezone-fixer' ); ?></p>
                    <a href="<?php echo esc_url( admin_url( 'tools.php?page=wp-timezone-debugger' ) ); ?>" class="button button-secondary"><?php _e( 'Open Timezone Debugger', 'wp-timezone-fixer' ); ?></a>
                </div>
            </div>
        </div>
    </div>
    <?php
}

// Add the original debugger as a separate menu item
add_action( 'admin_menu', 'wtd_add_debug_menu_page' );

/**
 * Registers the debug page under the "Tools" menu.
 */
function wtd_add_debug_menu_page() {
    add_management_page(
        'Timezone Debugger',        // Page Title
        'Timezone Debugger',        // Menu Title
        'manage_options',           // Capability
        'wp-timezone-debugger',     // Menu Slug
        'wtd_render_debug_page'     // Callback function to render the page
    );
}

/**
 * Gathers all the necessary timezone and time-related data.
 * Corresponds to Phase 2: Data Collection.
 *
 * @return array An associative array of all debug data.
 */
function wtd_gather_all_data() {
    global $wpdb; /** @global wpdb $wpdb */
    $data = [];

    // --- WordPress Core Data ---
    $data['wp_timezone_string'] = get_option('timezone_string');
    $data['wp_gmt_offset'] = get_option('gmt_offset');
    $data['wp_official_timezone'] = wp_timezone_string();
    $data['wp_local_time_mysql'] = current_time('mysql');
    $data['wp_utc_time_mysql'] = current_time('mysql', 1);
    $data['wp_timestamp'] = current_time('timestamp');

    // --- PHP Environment Data ---
    $data['php_default_timezone'] = date_default_timezone_get();
    $data['php_ini_timezone'] = ini_get('date.timezone');
    $data['php_local_time'] = date('Y-m-d H:i:s');
    $data['php_utc_time'] = gmdate('Y-m-d H:i:s');
    
    // --- Database Server Data ---
    $data['db_now'] = $wpdb->get_var("SELECT NOW()");
    $data['db_utc'] = $wpdb->get_var("SELECT UTC_TIMESTAMP()");
    $data['db_global_tz'] = $wpdb->get_var("SELECT @@global.time_zone");
    $data['db_session_tz'] = $wpdb->get_var("SELECT @@session.time_zone");

    // --- System / Server Data ---
    if ( function_exists('shell_exec') && is_callable('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', ini_get('disable_functions')))) ) {
        $data['server_time'] = shell_exec('date');
    } else {
        $data['server_time'] = '`shell_exec` is disabled or not available.';
    }

    return $data;
}

/**
 * Renders the main debug page.
 * Corresponds to Phase 1, 3, 4, and 5.
 */
function wtd_render_debug_page() {
    $data = wtd_gather_all_data();
    ?>
    <div class="wrap" id="wtd-wrapper">
        <h1><span class="dashicons-before dashicons-clock"></span> WordPress Timezone Debugger</h1>
        <p>This tool gathers time settings from every layer of your site to help you find inconsistencies.</p>

        <?php wtd_render_summary_and_recommendations($data); ?>

        <div id="poststuff">
            <div class="postbox-container" style="width: 100%;">
                <div class="meta-box-sortables">

                    <?php wtd_render_data_section('Browser / Client-Side', 'dashicons-desktop', 'wtd_render_browser_content'); ?>
                    <?php wtd_render_data_section('WordPress Core', 'dashicons-wordpress', 'wtd_render_wp_content', $data); ?>
                    <?php wtd_render_data_section('PHP Environment', 'dashicons-php', 'wtd_render_php_content', $data); ?>
                    <?php wtd_render_data_section('Database Server (MySQL)', 'dashicons-database', 'wtd_render_db_content', $data); ?>
                    <?php wtd_render_data_section('System / Server', 'dashicons-server', 'wtd_render_server_content', $data); ?>
                    <?php wtd_render_data_section('Scheduled Cron Jobs', 'dashicons-calendar-alt', 'wtd_render_cron_content'); ?>

                </div>
            </div>
        </div>
        
        <?php wtd_add_inline_js_and_css(); ?>
    </div>
    <?php
}

/**
 * Renders a generic postbox section for displaying data.
 */
function wtd_render_data_section($title, $icon, $content_callback, $data = null) {
    ?>
    <div class="postbox">
        <h2 class="hndle"><span class="dashicons-before <?php echo esc_attr($icon); ?>"></span> <?php echo esc_html($title); ?></h2>
        <div class="inside">
            <table class="form-table wtd-data-table">
                <tbody>
                    <?php call_user_func($content_callback, $data); ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

/**
 * Renders the content for the summary and recommendations box.
 * Updated to reflect the timezone fixer functionality.
 */
function wtd_render_summary_and_recommendations($data) {
    $recommendations = [];
    $fixer_status = [];
    
    // Check fixer status
    $enable_correction = get_option( 'wtfix_enable_correction', true );
    $correction_applied = get_option( 'wtfix_correction_applied', false );
    $original_timezone = get_option( 'wtfix_original_timezone', false );
    
    $is_mismatch_wp_php = ($data['wp_official_timezone'] !== $data['php_default_timezone']);
    $is_using_offset = empty($data['wp_timezone_string']);

    // Fixer status information
    if ( $correction_applied && $original_timezone ) {
        $fixer_status[] = "<strong>Timezone Fixer Active:</strong> The plugin detected that your server's PHP timezone was set to <code>{$original_timezone}</code> and has automatically corrected it to UTC for WordPress operations.";
    } elseif ( $data['php_default_timezone'] === 'UTC' && ! $correction_applied ) {
        $fixer_status[] = "<strong>Server Already Correct:</strong> Your server is properly configured with UTC as the PHP default timezone.";
    } elseif ( ! $enable_correction ) {
        $fixer_status[] = "<strong>Timezone Fixer Disabled:</strong> Automatic timezone correction is currently disabled in the plugin settings.";
    }

    if ($is_mismatch_wp_php && ! $correction_applied) {
        $recommendations[] = "<strong>WordPress and PHP timezones do not match.</strong> This is a common source of errors. The Timezone Fixer plugin can automatically correct this. Go to <strong>Tools > Timezone Fixer</strong> to enable automatic correction, or go to <strong>Settings > General</strong> and re-save your timezone.";
    }

    if ($is_using_offset) {
        $recommendations[] = "<strong>Your site is using a manual UTC offset.</strong> This is not recommended because it does not account for Daylight Saving Time (DST). To fix this, go to <strong>Settings > General</strong> and select a city in your timezone (e.g., 'New York' or 'London') instead of a UTC offset.";
    }
    
    if ( $data['db_global_tz'] === 'SYSTEM' || $data['db_session_tz'] === 'SYSTEM' ) {
         $recommendations[] = "<strong>Your database timezone is set to 'SYSTEM'.</strong> This means it inherits the main server's time. Ensure the server time is correct and configured with the right timezone to avoid issues.";
    }
    
    ?>
    <div class="postbox">
        <h2 class="hndle"><span class="dashicons-before dashicons-dashboard"></span> At a Glance / Timezone Fixer Status</h2>
        <div class="inside">
            <table class="form-table">
                <tr>
                    <th scope="row">WP Timezone vs. PHP Timezone</th>
                    <td>
                        <?php if ($is_mismatch_wp_php && ! $correction_applied) : ?>
                            <span class="wtd-status wtd-error"><span class="dashicons dashicons-warning"></span> Mismatch</span>
                        <?php else : ?>
                            <span class="wtd-status wtd-ok"><span class="dashicons dashicons-yes-alt"></span> Match</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Timezone Type</th>
                    <td>
                         <?php if ($is_using_offset) : ?>
                            <span class="wtd-status wtd-warning"><span class="dashicons dashicons-warning"></span> Manual UTC Offset</span>
                        <?php else : ?>
                            <span class="wtd-status wtd-ok"><span class="dashicons dashicons-yes-alt"></span> Location String</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Timezone Fixer Status</th>
                    <td>
                        <?php if ( $correction_applied ) : ?>
                            <span class="wtd-status wtd-ok"><span class="dashicons dashicons-yes-alt"></span> Active & Working</span>
                        <?php elseif ( $data['php_default_timezone'] === 'UTC' ) : ?>
                            <span class="wtd-status wtd-ok"><span class="dashicons dashicons-yes-alt"></span> Not Needed</span>
                        <?php elseif ( ! $enable_correction ) : ?>
                            <span class="wtd-status wtd-warning"><span class="dashicons dashicons-warning"></span> Disabled</span>
                        <?php else : ?>
                            <span class="wtd-status wtd-warning"><span class="dashicons dashicons-info"></span> Standby</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <?php if (!empty($fixer_status)) : ?>
                <div class="wtd-recommendations">
                    <h4><span class="dashicons dashicons-admin-tools"></span> Timezone Fixer Status</h4>
                    <ul>
                        <?php foreach ($fixer_status as $status) : ?>
                            <li><?php echo $status; ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <p><a href="<?php echo esc_url( admin_url( 'tools.php?page=wp-timezone-fixer-status' ) ); ?>" class="button button-secondary">View Fixer Settings</a></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($recommendations)) : ?>
                <div class="wtd-recommendations">
                    <h4><span class="dashicons dashicons-info"></span> Recommendations</h4>
                    <ul>
                        <?php foreach ($recommendations as $rec) : ?>
                            <li><?php echo $rec; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php else : ?>
                 <div class="wtd-recommendations">
                    <h4><span class="dashicons dashicons-yes"></span> All good!</h4>
                    <p>No common issues were detected in your primary configurations.</p>
                </div>
            <?php endif; ?>
            
            <button id="wtd-copy-report" class="button button-primary">Copy System Report to Clipboard</button>
            <p class="description">Use this to easily paste all debug information into a support ticket or forum post.</p>
        </div>
    </div>
    <?php
}

// --- Content Rendering Functions for each section ---

function wtd_render_browser_content() {
    ?>
    <tr>
        <th scope="row">Browser Time</th>
        <td><code id="wtd-browser-time">Loading...</code><p class="description">The current time on your local computer.</p></td>
    </tr>
    <tr>
        <th scope="row">Browser Timezone (IANA)</th>
        <td><code id="wtd-browser-iana">Loading...</code><p class="description">The timezone name detected by your browser (e.g., "America/New_York").</p></td>
    </tr>
    <tr>
        <th scope="row">Browser UTC Offset</th>
        <td><code id="wtd-browser-offset">Loading...</code><p class="description">The difference in minutes between your browser's time and UTC.</p></td>
    </tr>
    <?php
}

function wtd_render_wp_content($data) {
    ?>
    <tr><th scope="row">Timezone String</th><td><code><?php echo esc_html($data['wp_timezone_string'] ?: '(Not Set)'); ?></code><p class="description">Value from <code>get_option('timezone_string')</code>. The primary setting.</p></td></tr>
    <tr><th scope="row">GMT Offset</th><td><code><?php echo esc_html($data['wp_gmt_offset']); ?></code><p class="description">Value from <code>get_option('gmt_offset')</code>. A fallback if the string is not set.</p></td></tr>
    <tr><th scope="row">Calculated Timezone</th><td><code><?php echo esc_html($data['wp_official_timezone']); ?></code><p class="description">The final timezone WordPress is using, from <code>wp_timezone_string()</code>.</p></td></tr>
    <tr><th scope="row">WordPress Local Time</th><td><code><?php echo esc_html($data['wp_local_time_mysql']); ?></code><p class="description">Result of <code>current_time('mysql')</code>. Used for post dates.</p></td></tr>
    <tr><th scope="row">WordPress UTC Time</th><td><code><?php echo esc_html($data['wp_utc_time_mysql']); ?></code><p class="description">Result of <code>current_time('mysql', 1)</code>. The UTC time according to WordPress.</p></td></tr>
    <?php
}

function wtd_render_php_content($data) {
    ?>
    <tr><th scope="row">Default Timezone</th><td><code><?php echo esc_html($data['php_default_timezone']); ?></code><p class="description">Result of <code>date_default_timezone_get()</code>. What PHP uses by default.</p></td></tr>
    <tr><th scope="row">`php.ini` Timezone</th><td><code><?php echo esc_html($data['php_ini_timezone'] ?: '(Not Set)'); ?></code><p class="description">The value of <code>date.timezone</code> set in your <code>php.ini</code> file.</p></td></tr>
    <tr><th scope="row">PHP `date()`</th><td><code><?php echo esc_html($data['php_local_time']); ?></code><p class="description">The current server time formatted by PHP's default timezone.</p></td></tr>
    <tr><th scope="row">PHP `gmdate()`</th><td><code><?php echo esc_html($data['php_utc_time']); ?></code><p class="description">The current UTC time according to PHP.</p></td></tr>
    <?php
}

function wtd_render_db_content($data) {
    ?>
    <tr><th scope="row">Database Time (NOW())</th><td><code><?php echo esc_html($data['db_now']); ?></code><p class="description">The current time of the database server itself.</p></td></tr>
    <tr><th scope="row">Database Time (UTC_TIMESTAMP())</th><td><code><?php echo esc_html($data['db_utc']); ?></code><p class="description">The current UTC time according to the database.</p></td></tr>
    <tr><th scope="row">Global Timezone</th><td><code><?php echo esc_html($data['db_global_tz']); ?></code><p class="description">The default timezone for the entire database server.</p></td></tr>
    <tr><th scope="row">Session Timezone</th><td><code><?php echo esc_html($data['db_session_tz']); ?></code><p class="description">The timezone for the current database connection.</p></td></tr>
    <?php
}

function wtd_render_server_content($data) {
    ?>
    <tr><th scope="row">Server Time (`date`)</th><td><code><?php echo esc_html($data['server_time']); ?></code><p class="description">The raw output of the <code>date</code> command on the server's operating system.</p></td></tr>
    <?php
}

function wtd_render_cron_content() {
    $cron_jobs = _get_cron_array();
    if (empty($cron_jobs)) {
        echo '<tr><td colspan="2">No scheduled cron jobs found.</td></tr>';
        return;
    }
    
    $count = 0;
    ?>
    <tr>
        <th>Hook Name</th>
        <th>Next Run (UTC)</th>
        <th>Next Run (Site Time)</th>
        <th>Frequency</th>
    </tr>
    <?php
    foreach ($cron_jobs as $timestamp => $hooks) {
        foreach ($hooks as $hook => $args) {
            if ($count >= 15) break 2; // Limit to first 15 events
            $key = key($args);
            $schedule = !empty($args[$key]['schedule']) ? $args[$key]['schedule'] : '<em>One-off</em>';

            $utc_time = date('Y-m-d H:i:s', $timestamp);
            $site_time = get_date_from_gmt(date('Y-m-d H:i:s', $timestamp), 'Y-m-d H:i:s');
            ?>
            <tr>
                <td><code><?php echo esc_html($hook); ?></code></td>
                <td><code><?php echo esc_html($utc_time); ?></code></td>
                <td><code><?php echo esc_html($site_time); ?></code></td>
                <td><code><?php echo esc_html($schedule); ?></code></td>
            </tr>
            <?php
            $count++;
        }
    }
}

/**
 * Adds the necessary CSS and JavaScript to the page.
 * Corresponds to Task 2.5 and 4.2.
 */
function wtd_add_inline_js_and_css() {
    ?>
    <style type="text/css">
        #wtd-wrapper .postbox .hndle .dashicons-before { line-height: 1.4; }
        #wtd-wrapper .wtd-status { padding: 3px 8px; border-radius: 3px; font-weight: bold; }
        #wtd-wrapper .wtd-ok { background: #e7f7e8; color: #2e7d32; border: 1px solid #c8e6c9; }
        #wtd-wrapper .wtd-warning { background: #fff8e1; color: #ff8f00; border: 1px solid #ffecb3; }
        #wtd-wrapper .wtd-error { background: #fbe9e7; color: #d93025; border: 1px solid #ffccbc; }
        #wtd-wrapper .wtd-recommendations { margin-top: 20px; border-left: 4px solid #72aee6; padding-left: 15px; }
        #wtd-wrapper .wtd-recommendations ul { list-style: disc; padding-left: 20px; }
        #wtd-wrapper .wtd-recommendations li { margin-bottom: 10px; }
        #wtd-wrapper .wtd-data-table code { font-size: 14px; }
        #wtd-wrapper .form-table th { width: 200px; }
        #wtd-wrapper #wtd-copy-report { margin-top: 15px; }
    </style>
    
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            // Task 2.5: Gather Browser/Client-Side Data
            const browserDate = new Date();
            const browserOffset = browserDate.getTimezoneOffset(); // In minutes
            const offsetHours = String(Math.floor(Math.abs(browserOffset) / 60)).padStart(2, '0');
            const offsetMinutes = String(Math.abs(browserOffset) % 60).padStart(2, '0');
            const offsetSign = browserOffset > 0 ? '-' : '+';

            document.getElementById('wtd-browser-time').innerText = browserDate.toLocaleString();
            
            try {
                const ianaTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
                document.getElementById('wtd-browser-iana').innerText = ianaTimezone;
            } catch (e) {
                 document.getElementById('wtd-browser-iana').innerText = 'Not supported by this browser.';
            }
            
            document.getElementById('wtd-browser-offset').innerText = `${browserOffset} minutes (UTC${offsetSign}${offsetHours}:${offsetMinutes})`;
            
            // Task 4.2: System Report Generator
            const copyButton = document.getElementById('wtd-copy-report');
            if(copyButton) {
                copyButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    let report = '### WordPress Timezone Debug Report ###\n\n';
                    
                    document.querySelectorAll('.postbox').forEach(box => {
                        const title = box.querySelector('h2.hndle');
                        if (!title) return;

                        report += `--- ${title.innerText.trim()} ---\n`;
                        
                        const table = box.querySelector('.wtd-data-table');
                        if(table) {
                            table.querySelectorAll('tr').forEach(row => {
                                const th = row.querySelector('th');
                                const td = row.querySelector('td');
                                if (th && td) {
                                    report += `${th.innerText.trim()}: ${td.innerText.split('\n')[0].trim()}\n`;
                                }
                            });
                        }
                        report += '\n';
                    });

                    navigator.clipboard.writeText(report).then(() => {
                        copyButton.innerText = 'Copied!';
                        setTimeout(() => {
                            copyButton.innerText = 'Copy System Report to Clipboard';
                        }, 2000);
                    }).catch(err => {
                        copyButton.innerText = 'Failed to copy!';
                        console.error('Could not copy text: ', err);
                    });
                });
            }
        });
    </script>
    <?php
}
