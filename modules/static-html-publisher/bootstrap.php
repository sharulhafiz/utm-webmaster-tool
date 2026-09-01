<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Static HTML Publisher — bootstrap.
 *
 * Upload AI-generated static-site ZIP packages and publish them as
 * complete HTML documents on normal WordPress Pages.
 *
 * Architecture: Page ID is the stable storage identity. When a published
 * Page has an active static package, the package index.html completely
 * replaces normal WordPress page output. Static assets (CSS, JS, images)
 * resolve under the same Page URL.
 */

require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/metabox.php';
require_once __DIR__ . '/admin-js.php';
require_once __DIR__ . '/template.php';

/**
 * Register custom rewrite rules and query vars for static asset serving.
 *
 * Pattern: <page-path>/<file-with-extension> → static_asset=<filename>
 * Page-path is greedy so nested pages like /campaign/open-day-2026/ work.
 */
add_action( 'init', function () {
    add_rewrite_tag( '%static_asset%', '([^/]+\.[a-z0-9]{2,10})' );

    // Catch page asset requests before WordPress's page query rejects them.
    // Excludes .php to avoid intercepting wp-login.php, xmlrpc.php, etc.
    add_rewrite_rule(
        '^(.+)/([^/]+\.(?:css|js|json|png|jpe?g|gif|svg|webp|woff2?|ttf|eot|otf|ico|txt|map|pdf|avif|mp4|webm))$',
        'index.php?static_asset=$matches[2]&page_path=$matches[1]',
        'top'
    );
}, 1 );

add_filter( 'query_vars', function ( $vars ) {
    $vars[] = 'page_path';
    return $vars;
});
