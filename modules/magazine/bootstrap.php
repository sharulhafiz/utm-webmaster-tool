<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Magazine module — bootstrap.
 *
 * Provides a low-friction way to upload AI-generated static-site ZIP packages
 * and publish them under /magazine/<slug>/ on chancellery.utm.my.
 */

if ( ! function_exists( 'utm_magazine_is_allowed_context' ) ) {
    require_once __DIR__ . '/context.php';
}

if ( ! utm_magazine_is_allowed_context() ) {
    return;
}

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/zip-handler.php';
require_once __DIR__ . '/shortcode.php';
require_once __DIR__ . '/admin-page.php';

/**
 * Register the custom rewrite rule + query vars on init.
 *
 * Single request: magazine/<slug>/      → pagename=magazine/<slug>
 * Asset sub-path: magazine/<slug>/css/x → pagename=magazine/<slug> + qs magazine_asset
 */
add_action( 'init', function () {
    add_rewrite_rule(
        '^magazine/([^/]+)/(.+?)(?:\?|$)',
        'index.php?pagename=magazine/$matches[1]&magazine_asset=$matches[2]',
        'top'
    );

    add_rewrite_rule(
        '^magazine/([^/]+)/?$',
        'index.php?pagename=magazine/$matches[1]',
        'top'
    );

    add_rewrite_rule(
        '^magazine/?$',
        'index.php?pagename=magazine',
        'top'
    );
});

add_filter( 'query_vars', function ( $vars ) {
    $vars[] = 'magazine_asset';
    return $vars;
});

/**
 * Rewrite endpoint for flushing: mag rewrite rules.
 */
add_action( 'init', function () {
    add_rewrite_endpoint( 'magazine', EP_ROOT );
});
