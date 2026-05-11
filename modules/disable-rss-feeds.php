<?php
/**
 * Disable Dashboard RSS Feeds
 *
 * Removes slow RSS feed widgets from the WordPress dashboard
 * to reduce admin page stalls caused by external feed requests.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Remove dashboard RSS-related widgets.
 */
function utm_disable_dashboard_rss_widgets() {
    // Remove WordPress Events and News.
    remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );

    // Remove WordPress News (legacy).
    remove_meta_box( 'dashboard_secondary', 'dashboard', 'side' );

    // Remove Quick Press widget (also fetches external data).
    remove_meta_box( 'dashboard_quick_press', 'dashboard', 'side' );

    // Remove incoming links widget.
    remove_meta_box( 'dashboard_incoming_links', 'dashboard', 'normal' );

    // Remove plugins widget.
    remove_meta_box( 'dashboard_plugins', 'dashboard', 'normal' );
}
add_action( 'wp_dashboard_setup', 'utm_disable_dashboard_rss_widgets', 999 );

/**
 * Disable RSS feed fetching in SimplePie (used by RSS widgets).
 */
function utm_disable_simplepie_cache() {
    add_filter(
        'wp_feed_cache_transient_lifetime',
        function() {
            return 0;
        }
    );
}
add_action( 'init', 'utm_disable_simplepie_cache' );

/**
 * Block HTTP requests for WordPress dashboard feed endpoints.
 *
 * @param false|array|WP_Error $pre  Return value before HTTP request.
 * @param array                $args Request arguments.
 * @param string               $url  Request URL.
 * @return false|array|WP_Error
 */
function utm_block_dashboard_http_requests( $pre, $args, $url ) {
    if ( strpos( $url, 'wordpress.org' ) !== false && strpos( $url, 'feed' ) !== false ) {
        return new WP_Error( 'http_request_blocked', 'Dashboard RSS feeds are disabled for performance.' );
    }

    return $pre;
}
add_filter( 'pre_http_request', 'utm_block_dashboard_http_requests', 10, 3 );
