<?php
/*
    People Module
    This module handles all operations related to wordpress multisite of people.utm.my.

    Features:
    - Auto create a site for logged in users if it doesn't exist, or redirect to their site if it does.
    - Fetches and displays a list of people from the UTM API.
 */

// Only enable on people.utm.my domain
if ($_SERVER['HTTP_HOST'] !== 'people.utm.my') {
    return;
}

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Helper: Get or create a user's admin URL on their own site
function utm_get_or_create_user_admin_url( $user_id ) {
    $user_blogs = get_blogs_of_user( $user_id );
    $admin_url  = '';

    // Prefer the first site where the user is an Administrator
    foreach ( $user_blogs as $blog ) {
        $blog_id = (int) $blog->userblog_id;
        switch_to_blog( $blog_id );
        $is_admin_here = current_user_can( 'administrator' );
        restore_current_blog();
        if ( $is_admin_here ) {
            $admin_url = get_admin_url( $blog_id );
            break;
        }
    }

    // If the user has no admin site, create a personal site and send to its dashboard
    if ( empty( $admin_url ) ) {
        $site_url = utm_create_user_site( $user_id );
        // Fallback to home if creation failed
        if ( empty( $site_url ) ) {
            return home_url( '/' );
        }
        $admin_url = trailingslashit( $site_url ) . 'wp-admin/';
    }

    return $admin_url;
}

// Redirect non-super-admins away from main-site wp-admin to their own site dashboard
add_action( 'admin_init', 'utm_redirect_main_admin_to_own_site' );
function utm_redirect_main_admin_to_own_site() {
    if ( ! is_user_logged_in() ) {
        return;
    }

    // Only act on the main site's admin area
    if ( get_current_blog_id() != 1 ) {
        return;
    }

    $user_id = get_current_user_id();

    // Exclude super admins
    if ( is_super_admin( $user_id ) ) {
        return;
    }

    // Skip AJAX/CRON/admin-post to avoid breaking background/admin-post flows
    if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) return;
    if ( defined( 'DOING_CRON' ) && DOING_CRON ) return;
    if ( isset( $_SERVER['SCRIPT_NAME'] ) && strpos( $_SERVER['SCRIPT_NAME'], 'admin-post.php' ) !== false ) return;

    // Guard: prevent repeated redirects in case other code bounces back here
    if ( isset( $_GET['utm_redirected'] ) && $_GET['utm_redirected'] ) {
        return;
    }

    $target = utm_get_or_create_user_admin_url( $user_id );
    // Append guard param
    $target = add_query_arg( 'utm_redirected', '1', $target );

    wp_safe_redirect( $target );
    exit;
}

/* Login redirect: If logging in on the main site, send non-super-admins to their own site dashboard
 * while allowing front-end redirects (respecting redirect_to when not pointing to main-site admin).
 */
add_filter( 'login_redirect', 'utm_login_redirect_to_user_site', 10, 3 );
function utm_login_redirect_to_user_site( $redirect_to, $requested, $user ) {
    // If no user object or not a WP_User yet, do nothing
    if ( empty( $user ) || ! ( $user instanceof WP_User ) ) {
        return $redirect_to;
    }

    // Main site only
    if ( get_current_blog_id() != 1 ) {
        return $redirect_to;
    }

    // Exclude super admins
    if ( is_super_admin( $user->ID ) ) {
        return $redirect_to;
    }

    // If redirect_to targets the main site's admin (or is empty), override; otherwise respect it
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

    $target = utm_get_or_create_user_admin_url( $user->ID );
    return add_query_arg( 'utm_redirected', '1', $target );
}





/* Helper function to sanitize username for site paths */
function utm_sanitize_username_for_path( $username ) {
    // Remove dots and other special characters that could cause nginx issues
    $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '', $username);
    
    // If the result is empty or too short, use a fallback
    if ( empty( $sanitized ) || strlen( $sanitized ) < 2 ) {
        $sanitized = 'user' . substr( md5( $username ), 0, 6 );
    }
    
    // Convert to lowercase for consistency
    $sanitized = strtolower( $sanitized );
    
    return $sanitized;
}



/* Function to create a user site */
function utm_create_user_site( $user_id ) {
    $user = get_userdata( $user_id );
    if ( ! $user ) {
        return home_url();
    }
    $email = $user->user_email;
    $username_part = current( explode( '@', $email ) );
    
    // Use our custom sanitizer instead of WordPress's sanitize_user
    $slug = utm_sanitize_username_for_path( $username_part );
    $base_slug = $slug;
    $domain = get_network()->domain;
    $title = 'Personal Website of ' . $user->display_name;
    $date_str = date('Ymd');
    $i = 0;
    // Check if site exists, if yes, append date
    while ( domain_exists( $domain, '/' . $slug . '/' ) ) {
        $slug = $base_slug . $date_str;
        $i++;
        if ( $i > 1 ) {
            $slug = $base_slug . $date_str . $i;
        }
    }
    $site_id = wpmu_create_blog( $domain, '/' . $slug . '/', $title, $user_id, array(), 1 );
    if ( is_wp_error( $site_id ) ) {
        error_log( 'Error creating user site: ' . $site_id->get_error_message() );
        return home_url();
    }
    return get_site_url( $site_id );
}

// Auto-create site for user on login if they don't have one (no redirect here; handled by login_redirect/admin_init)
add_action( 'wp_login', 'utm_auto_create_site_on_login', 10, 2 );
function utm_auto_create_site_on_login( $user_login, $user ) {
    $user_id = $user->ID;
    $user_blogs = get_blogs_of_user( $user_id );
    $has_site = false;
    foreach ( $user_blogs as $blog ) {
        switch_to_blog( $blog->userblog_id );
        if ( current_user_can( 'administrator' ) && !is_super_admin( $user_id ) ) {
            $has_site = true;
        }
        restore_current_blog();
        if ( $has_site ) break;
    }
    if ( ! $has_site ) {
        utm_create_user_site( $user_id );
    }
}

// Shortcode to list all sites the current user has admin access to
add_shortcode('utm_user_sites', 'utm_shortcode_user_sites_list');
function utm_shortcode_user_sites_list() {
    $output = '';
    if ( !is_user_logged_in() ) {
        $output .= '<p>You must be logged in to see your sites.</p>';
        return $output;
    }
    $user_id = get_current_user_id();
    $user_blogs = get_blogs_of_user( $user_id );
    $admin_sites = array();
    foreach ( $user_blogs as $blog ) {
        switch_to_blog( $blog->userblog_id );
        if ( current_user_can( 'administrator' ) && !is_super_admin( $user_id ) ) {
            $admin_sites[] = array(
                'name' => get_bloginfo('name'),
                'dashboard' => admin_url(),
                'site' => get_site_url(),
            );
        }
        restore_current_blog();
    }
    if ( empty($admin_sites) ) {
        $personal_siteURL = utm_create_user_site( $user_id );
        return '<p>Your personal site has been created. Please visit your <a href="' . esc_url($personal_siteURL) . '">personal site</a>.</p>';
    }
    $output .= '<h3></h3><p>You have admin access to the following sites:</p>';
    $output .= '<ul class="utm-user-sites-list">';
    foreach ( $admin_sites as $site ) {
        $output .= '<li>' . esc_html($site['name']) . ' - '
            . '<a href="' . esc_url($site['dashboard']) . '">Visit Dashboard</a> | '
            . '<a href="' . esc_url($site['site']) . '">View Site</a>'
            . '</li>';
    }
    $output .= '</ul>';
    return $output;
}

// ==================================================================
// people.utm.my functions
// ==================================================================


function redirect_to_user_blog(){
	global $wpdb;

	// check if this is main site
	if (!is_main_site()) {
		return;
	}
	// check if user is logged in
	if (!is_user_logged_in()) {
		return;
	}
	// get user ID
	$user_id = get_current_user_id();
	// check if domain is people.utm.my
	if (strpos($_SERVER['HTTP_HOST'], 'people.utm.my') === false || !is_main_site() || is_super_admin($user_id)) {
		return;
	}

	$user_blogs = get_blogs_of_user($user_id);
	foreach ($user_blogs as $blog) {
		// Check blog status
		$public = get_blog_status($blog->userblog_id, 'public');
		$archived = get_blog_status($blog->userblog_id, 'archived');
		$spam = get_blog_status($blog->userblog_id, 'spam');
		$deleted = get_blog_status($blog->userblog_id, 'deleted');
		$mature = get_blog_status($blog->userblog_id, 'mature');
		// If not public or archived or suspended, activate it
		if ($public != 1 || $archived == 1 || $spam == 1 || $deleted == 1 || $mature == 1) {
			$wpdb->update(
				$wpdb->blogs,
				array(
					'public' => 1,
					'archived' => 0,
					'spam' => 0,
					'deleted' => 0,
					'mature' => 0
				),
				array('blog_id' => $blog->userblog_id)
			);
		}
		wp_redirect($blog->siteurl);
		exit;
	}

}
