<?php
// =================================================================================================
// Bot Access Manager
// =================================================================================================
function is_allowed_bot() {
    if (!isset($_SERVER['HTTP_USER_AGENT'])) {
        return false; // No User-Agent header present
    }

    $user_agent = $_SERVER['HTTP_USER_AGENT'];
    $allowed_bots = ['Googlebot', 'bingbot', 'UptimeRobot', 'ChatGPT-User', 'monitoring360bot', 'ahrefsbot'];

    foreach ($allowed_bots as $bot) {
        if (stripos($user_agent, $bot) !== false) {
            return true;
        }
    }
    return false;
}

function manage_bot_access() {
    if (!isset($_SERVER['HTTP_USER_AGENT'])) {
        error_log('Blocked access: No User-Agent header present.');
        header("HTTP/1.1 403 Forbidden");
        exit("Access denied: No User-Agent header present.");
    }

    if (is_allowed_bot()) {
        error_log('Allowed bot: ' . $_SERVER['HTTP_USER_AGENT']);
        return; // Allow access for allowed bots
    }
    
    if (preg_match('/bot|crawl|slurp|spider/i', $_SERVER['HTTP_USER_AGENT'])) {
        error_log('Blocked bot: ' . $_SERVER['HTTP_USER_AGENT']);
        header("HTTP/1.1 403 Forbidden");
        exit("Access denied for unauthorized bots.");
    }
}
add_action('init', 'manage_bot_access');

// =================================================================================================
// Limit Concurrent Users
// =================================================================================================
function limit_concurrent_users() {
    if (is_allowed_bot()) return; // Don't limit allowed bots
    if (isset($_COOKIE['utmwp'])) {
        error_log('User has utmwp cookie, not limiting.');
        return; // Exclude users with 'utmwp' cookies
    }
    // if ip start wit 10, return
    if (strpos($_SERVER['REMOTE_ADDR'], '10.') === 0) {
        error_log('Allowed IP: ' . $_SERVER['REMOTE_ADDR']);
        return;
    }
    // if allowed domain, return
    $allowed_domain = ['osca.utm.my'];

    foreach ($allowed_domain as $domain) {
        if (stripos($_SERVER['HTTP_HOST'], $domain) !== false) {
            error_log('Allowed Domain: ' . $_SERVER['HTTP_HOST']);
        return;
        }
    }

    // if server load average is less than 25, return
    if (function_exists('sys_getloadavg') && sys_getloadavg()[0] < 25) {
        error_log('Server Load Average: ' . sys_getloadavg()[0]);
        return;
    }

    // Start session if not already started
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }

    $max_users = 10;
    $option_key = 'global_active_users';
    $active_users = get_site_option($option_key, []);

    // Clean expired sessions
    $active_users = array_filter($active_users, function($timestamp) {
        return $timestamp > time() - 300; // Active within the last 5 minutes
    });

    if (count($active_users) >= $max_users) {
        echo "The site is currently experiencing high traffic. Please try again later.";
        error_log('Blocked access: Maximum concurrent users reached.');
        die();
    }

    // Add current user/session
    $session_id = session_id();
    $active_users[$session_id] = time();
    $update_status = update_site_option($option_key, $active_users);

    if (!$update_status) {
        error_log('Failed to update site option.');
    }

    error_log('Active users: ' . count($active_users));
}

function limit_page_views_per_session() {
    if (is_allowed_bot()) return; // Don't limit allowed bots

    $max_pages = 10;
    $views = isset($_COOKIE['page_views']) ? intval($_COOKIE['page_views']) : 0;

    if ($views >= $max_pages) {
        // Do shortcode to display waiting page
        echo "You have reached the maximum page views for this session. Please wait a while.";
        die();
    }

    // Increment page views
    setcookie('page_views', $views + 1, time() + (600), '/');
}

add_action('template_redirect', 'limit_concurrent_users', 10);
add_action('template_redirect', 'limit_page_views_per_session', 10);

// =================================================================================================
// Limit Bot Access
// =================================================================================================
function limit_bot_access() {
    if (!is_allowed_bot()) return; // Only apply to allowed bots

    $active_bots = get_site_option('active_bots', []);
    
    // Clean expired bot sessions
    $active_bots = array_filter($active_bots, function($timestamp) {
        return $timestamp > time() - 300; // Active within the last 5 minutes
    });

    if (count($active_bots) >= 1) {
        header("HTTP/1.1 429 Too Many Requests");
        exit("Only one bot is allowed at a time.");
    }

    // Track bot session
    $active_bots[session_id()] = time();
    update_site_option('active_bots', $active_bots);
}
add_action('init', 'limit_bot_access');

// =================================================================================================
// Show Statistics In #footer-left (Dashboard)
// =================================================================================================
function show_statistics_visitor_manager() {
    $concurrent_users = count(get_site_option('global_active_users') ?: []);
    $active_bots = count(get_site_option('active_bots') ?: []);
    $views = isset($_COOKIE['page_views']) ? intval($_COOKIE['page_views']) : 0;

    $visitor_manager_text = "Concurrent Users: {$concurrent_users} | Active Bots: {$active_bots} | Page Views: {$views}";
    echo "<p>{$visitor_manager_text}</p>";
}
add_action('admin_notices', 'show_statistics_visitor_manager');