<?php
/**
 * People Module - WordPress Multisite User Management
 * 
 * This module handles all operations related to wordpress multisite of people.utm.my.
 *
 * Features:
 * - Shows site list on homepage for users to manually choose their site
 * - Auto-creates a personal site if user doesn't have one
 * - Handles site status activation and management
 * - Blocks main site dashboard access for users without administrator role
 * - Automatically fixes Divi theme onboarding redirect loops with cache clearing
 * 
 * User Access Control:
 * - Super admins: Full access to all sites including main site dashboard
 * - Administrators (main site): Full access to main site dashboard
 * - Regular users: Redirected to homepage showing their site list (via shortcode)
 * - No automatic dashboard redirects - users manually choose which site to visit
 * 
 * Divi Theme Fix:
 * - Detects Divi/Divi child themes automatically
 * - Disables onboarding wizard that causes redirect loops
 * - Clears WordPress object cache to ensure immediate effect (bypasses OPcache delays)
 * - Fixes happen organically when users login or visit their sites
 * - Prevents the need for manual WP-CLI intervention
 * 
 * Security & Performance:
 * - Shortcode only runs on main site (prevents infinite loops)
 * - Single-hop redirects prevent any redirect loop possibility
 * - Cache clearing ensures option updates are immediately visible
 * - Organic fixing approach (no bulk operations on 3000+ sites)
 * 
 * @package UTM_Webmaster_Tool
 * @version 3.1.0
 */
// if WP_CLI is not defined, set it to false to avoid errors
if ( ! defined( 'WP_CLI' ) ) {
    define( 'WP_CLI', false );
}

// Only enable on people.utm.my main domain (no subdomains)
if ( ! isset( $_SERVER['HTTP_HOST'] ) || $_SERVER['HTTP_HOST'] !== 'people.utm.my' ) {
    return;
}

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// ==================================================================
// HELPER FUNCTIONS
// ==================================================================

/**
 * Log site-related actions for debugging and auditing
 * 
 * @param int    $blog_id Blog/Site ID
 * @param string $action  Action type (created, activated, redirect, error)
 * @param string $message Additional message
 * @return void
 */
function utm_log_site_action( $blog_id, $action, $message = '' ) {
    if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
        return; // Only log when debug mode is enabled
    }
    
    $user_id = get_current_user_id();
    $user = get_userdata( $user_id );
    $username = $user ? $user->user_email : 'Unknown';
    
    $log_message = sprintf(
        '[UTM People] Action: %s | Blog ID: %d | User: %s (%d) | Message: %s',
        $action,
        $blog_id,
        $username,
        $user_id,
        $message
    );
    
    error_log( $log_message );
}

/**
 * Get all sites where user has administrator role
 * 
 * This function retrieves ALL user sites including archived/spam ones,
 * then automatically activates them if the user is an administrator.
 * This ensures archived sites are reactivated when users log in.
 * 
 * @param int $user_id User ID
 * @return array Array of site objects with blog_id, name, and admin_url
 */
function utm_get_user_admin_sites( $user_id ) {
    // Check cache first
    $cache_key = 'utm_user_admin_sites_' . $user_id;
    $cached = get_transient( $cache_key );
    
    if ( false !== $cached ) {
        return $cached;
    }
    
    // Get ALL user blogs including archived, spam, and deleted (but not mature)
    // This allows us to find and reactivate archived sites
    $user_blogs = get_blogs_of_user( $user_id, true );
    $admin_sites = array();
    
    foreach ( $user_blogs as $blog ) {
        $blog_id = (int) $blog->userblog_id;
        
        // Skip permanently deleted sites
        if ( get_blog_status( $blog_id, 'deleted' ) == 1 ) {
            continue;
        }
        
        switch_to_blog( $blog_id );
        
        // Check if user has administrator capability on this site
        $is_admin_here = current_user_can( 'administrator' );
        
        if ( $is_admin_here ) {
            // Ensure site is active before adding to list
            utm_ensure_site_active( $blog_id );

            
            $admin_sites[] = array(
                'blog_id'   => $blog_id,
                'name'      => get_bloginfo( 'name' ),
                'admin_url' => get_admin_url( $blog_id ),
                'site_url'  => get_site_url( $blog_id ),
            );
        }
        
        restore_current_blog();
    }
    
    // Cache for 5 minutes
    set_transient( $cache_key, $admin_sites, 5 * MINUTE_IN_SECONDS );
    
    return $admin_sites;
}

/**
 * Ensure site is active and public
 * 
 * @param int $blog_id Blog/Site ID
 * @return bool True if site was activated, false if already active or failed
 */
function utm_ensure_site_active( $blog_id ) {
    global $wpdb;
    
    $public   = get_blog_status( $blog_id, 'public' );
    $archived = get_blog_status( $blog_id, 'archived' );
    $spam     = get_blog_status( $blog_id, 'spam' );
    $deleted  = get_blog_status( $blog_id, 'deleted' );
    $mature   = get_blog_status( $blog_id, 'mature' );
    
    // If site needs activation
    if ( $public != 1 || $archived == 1 || $spam == 1 || $deleted == 1 || $mature == 1 ) {
        $updated = $wpdb->update(
            $wpdb->blogs,
            array(
                'public'   => 1,
                'archived' => 0,
                'spam'     => 0,
                'deleted'  => 0,
                'mature'   => 0
            ),
            array( 'blog_id' => $blog_id ),
            array( '%d', '%d', '%d', '%d', '%d' ),
            array( '%d' )
        );
        
        if ( false !== $updated ) {
            utm_log_site_action( $blog_id, 'activated', 'Site automatically activated' );
            
            // Clear site-specific caches
            clean_blog_cache( $blog_id );
            
            return true;
        }
        
        return false;
    }
    
    return false; // Already active, no changes made
}

/**
 * Get or create a user's admin URL on their own site
 * 
 * @deprecated 3.0.0 No longer used - users are shown site list instead of auto-redirect
 * @param int $user_id User ID
 * @return string Admin URL of user's site
 */
function utm_get_or_create_user_admin_url( $user_id ) {
    // Check if we have a cached admin URL
    $cached_url = get_user_meta( $user_id, 'utm_cached_admin_url', true );
    if ( ! empty( $cached_url ) ) {
        // Verify the site still exists and user still has admin access
        $blog_id = url_to_postid( $cached_url );
        if ( $blog_id && get_blog_status( $blog_id, 'deleted' ) != 1 ) {
            return $cached_url;
        }
    }
    
    $admin_sites = utm_get_user_admin_sites( $user_id );
    $admin_url   = '';
    
    // Use the first admin site found
    if ( ! empty( $admin_sites ) ) {
        $first_site = $admin_sites[0];
        $blog_id    = $first_site['blog_id'];
        
        $admin_url = $first_site['admin_url'];
        
        // Cache the admin URL
        update_user_meta( $user_id, 'utm_cached_admin_url', $admin_url );
        
        utm_log_site_action( $blog_id, 'redirect', 'User redirected to existing admin site' );
    } else {
        // No admin site found - create one
        $site_url = utm_create_user_site( $user_id );
        
        if ( empty( $site_url ) || is_wp_error( $site_url ) ) {
            utm_log_site_action( 0, 'error', 'Failed to create site for user ' . $user_id );
            return home_url( '/' );
        }
        
        $admin_url = trailingslashit( $site_url ) . 'wp-admin/';
        
        // Cache the new admin URL
        update_user_meta( $user_id, 'utm_cached_admin_url', $admin_url );
    }
    
    return $admin_url;
}

// ==================================================================
// DIVI THEME FIX
// ==================================================================

/**
 * Check and fix Divi onboarding on admin_init
 * 
 * Runs early on admin pages to catch and fix Divi issues before redirects happen
 */
function utm_check_and_fix_divi_onboarding() {
    if ( ! is_user_logged_in() ) {
        return;
    }
    
    $blog_id = get_current_blog_id();
    
    // Don't fix main site
    if ( $blog_id == 1 ) {
        return;
    }
    
}
add_action( 'admin_init', 'utm_check_and_fix_divi_onboarding', 5 );

/**
 * One-time Divi fix when user visits their site (front-end or admin)
 * 
 * Runs on init to fix issues opportunistically without bulk operations
 */
function utm_divi_onboarding_fix_on_logged_visit() {
    if ( ! is_user_logged_in() ) {
        return;
    }
    
    $blog_id = get_current_blog_id();
    
    // Don't fix main site
    if ( $blog_id == 1 ) {
        return;
    }

}
add_action( 'init', 'utm_divi_onboarding_fix_on_logged_visit', 6 );

// ==================================================================
// REDIRECT HANDLERS
// ==================================================================

/**
 * Block non-super-admins from accessing main site dashboard
 * 
 * Hooked to admin_init, this ensures only super admins can access the main site's
 * WordPress dashboard. Other users are redirected to their own site wp-admin if they
 * have one, otherwise to the homepage where their site will be auto-created.
 */
add_action( 'admin_init', 'utm_block_main_admin_access' );
function utm_block_main_admin_access() {
    if ( ! is_user_logged_in() ) {
        return;
    }

    // Only act on the main site's admin area
    if ( get_current_blog_id() != 1 ) {
        return;
    }

    $user_id = get_current_user_id();

    // Allow only super admins full access to main site dashboard
    if ( is_super_admin( $user_id ) ) {
        return;
    }

    // Skip AJAX/CRON/admin-post to avoid breaking background/admin-post flows
    if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) return;
    if ( defined( 'DOING_CRON' ) && DOING_CRON ) return;
    if ( isset( $_SERVER['SCRIPT_NAME'] ) && strpos( $_SERVER['SCRIPT_NAME'], 'admin-post.php' ) !== false ) return;

    // Allow filtering of block behavior
    $should_block = apply_filters( 'utm_should_block_main_admin', true, $user_id );
    if ( ! $should_block ) {
        return;
    }

    // Check if user has their own site to redirect to
    $admin_sites = utm_get_user_admin_sites( $user_id );
    
    if ( ! empty( $admin_sites ) ) {
        // Redirect to their first admin site's dashboard
        $redirect_url = $admin_sites[0]['admin_url'];
        utm_log_site_action( $admin_sites[0]['blog_id'], 'redirect', 'User redirected from main admin to their own site dashboard' );
    } else {
        // No site found - redirect to homepage for auto-creation
        $redirect_url = home_url( '/' );
        utm_log_site_action( 0, 'redirect', 'User blocked from main site dashboard, redirected to homepage for site auto-creation' );
    }
    
    wp_safe_redirect( $redirect_url );
    exit;
}

/**
 * Login redirect: Send non-super-admins to their own site or homepage
 * 
 * Only super admins can access the main site dashboard. Regular users are redirected
 * to their own site's wp-admin if they have one, otherwise to the homepage where their
 * site will be auto-created via the wp_login hook.
 * 
 * Respects explicit redirect_to parameters for front-end redirects.
 * 
 * @param string  $redirect_to           The redirect destination URL
 * @param string  $requested_redirect_to The requested redirect destination URL passed as a parameter
 * @param WP_User $user                  WP_User object
 * @return string Modified redirect URL
 */
add_filter( 'login_redirect', 'utm_login_redirect_to_site_list', 10, 3 );
function utm_login_redirect_to_site_list( $redirect_to, $requested, $user ) {
    // If no user object or not a WP_User yet, do nothing
    if ( empty( $user ) || ! ( $user instanceof WP_User ) ) {
        return $redirect_to;
    }

    // Main site only
    if ( get_current_blog_id() != 1 ) {
        return $redirect_to;
    }

    // Exclude only super admins - they can access the main site admin
    if ( is_super_admin( $user->ID ) ) {
        return $redirect_to;
    }

    // Allow filtering of redirect behavior
    $should_redirect = apply_filters( 'utm_should_redirect_login', true, $user->ID, $redirect_to );
    if ( ! $should_redirect ) {
        return $redirect_to;
    }

    // If redirect_to targets the main site's admin (or is empty), override to homepage
    $main_admin = admin_url(); // admin URL for current (main) site
    $should_override = false;

    if ( empty( $redirect_to ) ) {
        $should_override = true;
    } else {
        // Normalize for comparison
        $normalized_redirect = strtolower( $redirect_to );
        $normalized_admin    = strtolower( $main_admin );
        if (
            strpos( $normalized_redirect, $normalized_admin ) === 0 ||
            // Also catch relative admin paths
            strpos( $normalized_redirect, '/wp-admin' ) === 0
        ) {
            $should_override = true;
        }
    }

    if ( ! $should_override ) {
        return $redirect_to;
    }

    // Check if user has their own site to redirect to
    $admin_sites = utm_get_user_admin_sites( $user->ID );
    
    if ( ! empty( $admin_sites ) ) {
        // Redirect to their first admin site's dashboard
        $redirect_url = $admin_sites[0]['admin_url'];
        utm_log_site_action( $admin_sites[0]['blog_id'], 'redirect', 'User login redirected to their own site dashboard' );
    } else {
        // No site found - redirect to homepage where site will be auto-created via wp_login hook
        $redirect_url = home_url( '/' );
        utm_log_site_action( 0, 'redirect', 'User login redirected to homepage - site will be auto-created' );
    }
    
    // Clear any cached errors after successful login
    delete_transient( 'utm_site_creation_error_' . $user->ID );
    
    return $redirect_url;
}

/**
 * Sanitize username for use in site paths (nginx-safe)
 * 
 * Removes dots and special characters that could cause nginx routing issues
 * 
 * @param string $username Username or email prefix
 * @return string Sanitized username safe for URL paths
 */
function utm_sanitize_username_for_path( $username ) {
    // Remove dots and other special characters that could cause nginx issues
    $sanitized = preg_replace( '/[^a-zA-Z0-9_-]/', '', $username );
    
    // If the result is empty or too short, use a fallback
    if ( empty( $sanitized ) || strlen( $sanitized ) < 2 ) {
        $sanitized = 'user' . substr( md5( $username ), 0, 6 );
    }
    
    // Convert to lowercase for consistency
    $sanitized = strtolower( $sanitized );
    
    return $sanitized;
}

/**
 * Create a personal site for a user with race condition prevention
 * 
 * @param int $user_id User ID
 * @return string|WP_Error Site URL on success, WP_Error on failure
 */
function utm_create_user_site( $user_id ) {
    // Prevent race condition - check if site creation is already in progress
    $lock_key = 'utm_creating_site_' . $user_id;
    $lock_value = get_transient( $lock_key );
    
    if ( false !== $lock_value ) {
        // Another process is already creating the site, wait a moment
        sleep( 2 );
        
        // Check if site was created
        $admin_sites = utm_get_user_admin_sites( $user_id );
        if ( ! empty( $admin_sites ) ) {
            return $admin_sites[0]['site_url'];
        }
    }
    
    // Set lock for 30 seconds
    set_transient( $lock_key, time(), 30 );
    
    $user = get_userdata( $user_id );
    if ( ! $user ) {
        delete_transient( $lock_key );
        return new WP_Error( 'invalid_user', 'User does not exist' );
    }
    
    $email = $user->user_email;
    $username_part = current( explode( '@', $email ) );
    
    // Use our custom sanitizer instead of WordPress's sanitize_user
    $slug = utm_sanitize_username_for_path( $username_part );
    $base_slug = $slug;
    $domain = get_network()->domain;
    $title = 'Personal Website of ' . $user->display_name;
    $date_str = date( 'Ymd' );
    $i = 0;
    
    // Check if site exists, if yes, append date
    while ( domain_exists( $domain, '/' . $slug . '/' ) ) {
        $slug = $base_slug . $date_str;
        $i++;
        if ( $i > 1 ) {
            $slug = $base_slug . $date_str . $i;
        }
        
        // Prevent infinite loop
        if ( $i > 100 ) {
            delete_transient( $lock_key );
            return new WP_Error( 'site_creation_failed', 'Unable to find unique site slug' );
        }
    }
    
    // Apply filter to allow customization of site creation parameters
    $site_params = apply_filters( 'utm_create_user_site_params', array(
        'domain' => $domain,
        'path'   => '/' . $slug . '/',
        'title'  => $title,
        'user_id' => $user_id,
        'meta'   => array(),
        'site_id' => 1
    ), $user_id );
    
    $site_id = wpmu_create_blog(
        $site_params['domain'],
        $site_params['path'],
        $site_params['title'],
        $site_params['user_id'],
        $site_params['meta'],
        $site_params['site_id']
    );
    
    // Release lock
    delete_transient( $lock_key );
    
    if ( is_wp_error( $site_id ) ) {
        utm_log_site_action( 0, 'error', 'Site creation failed: ' . $site_id->get_error_message() );
        
        // Store error in transient for user notification
        set_transient( 'utm_site_creation_error_' . $user_id, $site_id->get_error_message(), 60 );
        
        return $site_id;
    }
    
    // Ensure the new site is active
    utm_ensure_site_active( $site_id );
    
    // Clear user's admin sites cache
    delete_transient( 'utm_user_admin_sites_' . $user_id );
    
    // Log successful creation
    utm_log_site_action( $site_id, 'created', 'Personal site created for ' . $user->user_email );
    
    // Fire action for other plugins to hook into
    do_action( 'utm_user_site_created', $site_id, $user_id );
    
    return get_site_url( $site_id );
}

/**
 * Activate all suspended/archived sites for user on login
 * 
 * This runs on login to ensure all user sites that are archived, spam, 
 * or otherwise deactivated are reactivated. Bypasses cache to ensure
 * all sites are checked.
 * 
 * @param string  $user_login Username
 * @param WP_User $user       WP_User object
 */
add_action( 'wp_login', 'utm_activate_user_sites_on_login', 5, 2 );
function utm_activate_user_sites_on_login( $user_login, $user ) {
    $user_id = $user->ID;
    
    // Skip super admins
    if ( is_super_admin( $user_id ) ) {
        return;
    }
    
    // Get ALL user blogs (bypassing any cache) including archived/spam ones
    $user_blogs = get_blogs_of_user( $user_id, true );
    
    if ( empty( $user_blogs ) ) {
        return;
    }
    
    $activated_count = 0;
    
    foreach ( $user_blogs as $blog ) {
        $blog_id = (int) $blog->userblog_id;
        
        // Skip permanently deleted sites
        if ( get_blog_status( $blog_id, 'deleted' ) == 1 ) {
            continue;
        }
        
        // Check if user has administrator capability on this site
        switch_to_blog( $blog_id );
        $is_admin_here = current_user_can( 'administrator' );
        restore_current_blog();
        
        if ( $is_admin_here ) {
            // Ensure site is active
            $was_activated = utm_ensure_site_active( $blog_id );
            if ( $was_activated ) {
                $activated_count++;
            }
        }
    }
    
    // Clear the user's cached admin sites to ensure fresh data
    if ( $activated_count > 0 ) {
        delete_transient( 'utm_user_admin_sites_' . $user_id );
        delete_user_meta( $user_id, 'utm_cached_admin_url' );
        utm_log_site_action( 0, 'activated', "Activated $activated_count site(s) for user on login" );
    }
}

/**
 * Auto-create site for user on login if they don't have one
 * 
 * This runs early in the login process to ensure the site exists
 * before redirect handlers attempt to find it.
 * 
 * @param string  $user_login Username
 * @param WP_User $user       WP_User object
 */
add_action( 'wp_login', 'utm_auto_create_site_on_login', 10, 2 );
function utm_auto_create_site_on_login( $user_login, $user ) {
    $user_id = $user->ID;
    
    // Skip super admins
    if ( is_super_admin( $user_id ) ) {
        return;
    }
    
    // Use our optimized helper function
    $admin_sites = utm_get_user_admin_sites( $user_id );
    
    // If no admin sites found, create one
    if ( empty( $admin_sites ) ) {
        $result = utm_create_user_site( $user_id );
        
        if ( is_wp_error( $result ) ) {
            utm_log_site_action( 0, 'error', 'Auto-create on login failed: ' . $result->get_error_message() );
        }
    }
}

// ==================================================================
// SHORTCODES
// ==================================================================

/**
 * Shortcode to list all sites the current user has admin access to
 * 
 * Usage: [utm_user_sites]
 * 
 * SECURITY: Only works on main site (ID 1) to prevent infinite loops on subsites
 * 
 * @param array $atts Shortcode attributes
 * @return string HTML output
 */
add_shortcode( 'utm_user_sites', 'utm_shortcode_user_sites_list' );
function utm_shortcode_user_sites_list( $atts = array() ) {
    // CRITICAL: Only allow this shortcode on the main site to prevent redirect loops
    if ( get_current_blog_id() != 1 ) {
        return '<!-- utm_user_sites shortcode is only available on the main site -->';
    }
    
    // Parse attributes
    $atts = shortcode_atts( array(
        'show_create_button' => 'yes',
        'show_site_links'    => 'yes',
    ), $atts, 'utm_user_sites' );
    
    $output = '';
    
    if ( ! is_user_logged_in() ) {
        $login_url = wp_login_url( get_permalink() );
        $output .= '<div class="utm-user-sites-notice">';
        $output .= '<p>' . esc_html__( 'You must be logged in to see your sites.', 'utm-webmaster' ) . '</p>';
        $output .= '<p><a href="' . esc_url( $login_url ) . '" class="button">' . esc_html__( 'Log In', 'utm-webmaster' ) . '</a></p>';
        $output .= '</div>';
        return $output;
    }
    
    $user_id = get_current_user_id();
    $admin_sites = utm_get_user_admin_sites( $user_id );
    
    if ( empty( $admin_sites ) ) {
        // Skip super admins - they don't need auto-created sites
        if ( ! is_super_admin( $user_id ) ) {
            // Try to auto-create a site for the user
            $result = utm_create_user_site( $user_id );
            
            if ( ! is_wp_error( $result ) ) {
                // Site was created successfully, refresh the list
                delete_transient( 'utm_user_admin_sites_' . $user_id );
                $admin_sites = utm_get_user_admin_sites( $user_id );
            } else {
                // Store the error for display
                $creation_error = $result->get_error_message();
            }
        }
    }
    
    if ( empty( $admin_sites ) ) {
        $output .= '<div class="utm-user-sites-notice">';
        $output .= '<p>' . esc_html__( 'You don\'t have any sites yet.', 'utm-webmaster' ) . '</p>';
        
        if ( ! empty( $creation_error ) ) {
            $output .= '<p style="color: #d63638;"><strong>' . esc_html__( 'Error creating site:', 'utm-webmaster' ) . '</strong> ' . esc_html( $creation_error ) . '</p>';
        }
        
        if ( $atts['show_create_button'] === 'yes' ) {
            $output .= '<p><a href="' . esc_url( admin_url() ) . '" class="button button-primary">' 
                    . esc_html__( 'Create Your Personal Site', 'utm-webmaster' ) 
                    . '</a></p>';
        }
        
        $output .= '</div>';
        return $output;
    }
    
    $output .= '<div class="utm-user-sites-list-wrapper">';
    $output .= '<h3>' . esc_html__( 'Your Sites', 'utm-webmaster' ) . '</h3>';
    $output .= '<p>' . sprintf( 
        _n( 'You have admin access to %d site:', 'You have admin access to %d sites:', count( $admin_sites ), 'utm-webmaster' ),
        count( $admin_sites )
    ) . '</p>';
    
    $output .= '<ul class="utm-user-sites-list">';
    foreach ( $admin_sites as $site ) {
        $output .= '<li class="utm-site-item">';
        $output .= '<strong>' . esc_html( $site['name'] ) . '</strong><br>';
        
        if ( $atts['show_site_links'] === 'yes' ) {
            $output .= '<a href="' . esc_url( $site['admin_url'] ) . '" class="utm-site-link">' 
                    . esc_html__( 'Visit Dashboard', 'utm-webmaster' ) 
                    . '</a> | ';
            $output .= '<a href="' . esc_url( $site['site_url'] ) . '" class="utm-site-link" target="_blank">' 
                    . esc_html__( 'View Site', 'utm-webmaster' ) 
                    . '</a>';
        }
        
        $output .= '</li>';
    }
    $output .= '</ul>';
    $output .= '</div>';
    
    return $output;
}

// ==================================================================
// ADMIN NOTICES & USER FEEDBACK
// ==================================================================

/**
 * Display admin notice when a new site is created
 * 
 * Shows a welcome message to users when they first access their newly created site
 */
add_action( 'admin_notices', 'utm_new_site_welcome_notice' );
function utm_new_site_welcome_notice() {
    $user_id = get_current_user_id();
    $show_welcome = get_user_meta( $user_id, 'utm_show_welcome_notice', true );
    
    if ( $show_welcome === '1' ) {
        $current_blog_id = get_current_blog_id();
        
        echo '<div class="notice notice-success is-dismissible">';
        echo '<h2>' . esc_html__( '🎉 Welcome to Your Personal Site!', 'utm-webmaster' ) . '</h2>';
        echo '<p>' . esc_html__( 'Your personal website has been created successfully. You can now start adding content, customizing your theme, and making it your own!', 'utm-webmaster' ) . '</p>';
        echo '<p><strong>' . esc_html__( 'Quick Start:', 'utm-webmaster' ) . '</strong></p>';
        echo '<ul style="list-style: disc; margin-left: 20px;">';
        echo '<li>' . sprintf( 
            __( '<a href="%s">Create your first post</a>', 'utm-webmaster' ),
            esc_url( admin_url( 'post-new.php' ) )
        ) . '</li>';
        echo '<li>' . sprintf( 
            __( '<a href="%s">Customize your site appearance</a>', 'utm-webmaster' ),
            esc_url( admin_url( 'customize.php' ) )
        ) . '</li>';
        echo '<li>' . sprintf( 
            __( '<a href="%s">View your site</a>', 'utm-webmaster' ),
            esc_url( get_site_url() )
        ) . '</li>';
        echo '</ul>';
        echo '</div>';
        
        // Clear the flag so it only shows once
        delete_user_meta( $user_id, 'utm_show_welcome_notice' );
    }
}

/**
 * Set flag to show welcome notice after site creation
 */
add_action( 'utm_user_site_created', 'utm_set_welcome_notice_flag', 10, 2 );
function utm_set_welcome_notice_flag( $site_id, $user_id ) {
    update_user_meta( $user_id, 'utm_show_welcome_notice', '1' );
}

/**
 * Clear user's cached admin sites when their role changes
 */
add_action( 'set_user_role', 'utm_clear_user_cache_on_role_change', 10, 3 );
function utm_clear_user_cache_on_role_change( $user_id, $role, $old_roles ) {
    delete_transient( 'utm_user_admin_sites_' . $user_id );
    delete_user_meta( $user_id, 'utm_cached_admin_url' );
}

/**
 * Clear user's cached admin sites when they're added to a blog
 */
add_action( 'add_user_to_blog', 'utm_clear_user_cache_on_blog_add', 10, 3 );
function utm_clear_user_cache_on_blog_add( $user_id, $role, $blog_id ) {
    delete_transient( 'utm_user_admin_sites_' . $user_id );
    delete_user_meta( $user_id, 'utm_cached_admin_url' );
}

/**
 * Clear user's cached admin sites when they're removed from a blog
 */
add_action( 'remove_user_from_blog', 'utm_clear_user_cache_on_blog_remove', 10, 2 );
function utm_clear_user_cache_on_blog_remove( $user_id, $blog_id ) {
    delete_transient( 'utm_user_admin_sites_' . $user_id );
    delete_user_meta( $user_id, 'utm_cached_admin_url' );
}

// ==================================================================
// NETWORK ADMIN TOOLS
// ==================================================================

/**
 * Note: Network admin bulk fixing has been removed in v3.0.0
 * 
 * The module uses organic site management where fixes happen on-demand
 * when users actually use their sites, distributing load over time and
 * avoiding server-intensive bulk operations across 3000+ sites.
 */
