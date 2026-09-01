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

// Supported post types.
define( 'UTM_SHP_POST_TYPES', [ 'page', 'post' ] );

/**
 * Check if a post type is supported for static HTML publishing.
 */
function utm_shp_is_supported_type( $post_type = null ) {
    if ( ! $post_type ) {
        $post = get_queried_object();
        $post_type = $post ? $post->post_type : null;
    }
    return in_array( $post_type, UTM_SHP_POST_TYPES, true );
}

/**
 * Register custom rewrite rules and query vars for static asset serving.
 *
 * Pattern: <page-path>/<file-with-extension> → static_asset=<filename>
 *
 * The page-path group (.+) is greedy, so for a nested URL like:
 *   /magazine/issue-2026/assets/js/app.js
 * It correctly captures:
 *   page_path    = magazine/issue-2026/assets/js
 *   static_asset = app.js
 * get_page_by_path() then resolves the full hierarchical path.
 *
 * The filename group ([^/]+) allows spaces, hyphens, underscores, and dots
 * in asset names — common in AI-generated ZIPs. Only the final extension
 * determines whether the rule fires; the rule does NOT match .php, .phtml,
 * or other server-executable extensions.
 *
 * Trailing slashes: WordPress strips them before rewrite matching, so
 * /static-test/style.css/ becomes /static-test/style.css for matching.
 * Canonical redirects happen AFTER template_redirect (priority 10 > 1),
 * so asset serving is not affected.
 */
add_action( 'init', function () {
    add_rewrite_tag( '%static_asset%', '([^/]+)' );

    add_rewrite_rule(
        '^(.+)/([^/]+\.(?:css|js|json|png|jpe?g|gif|svg|webp|avif|woff2?|ttf|eot|otf|ico|txt|map|pdf|mp4|webm))$',
        'index.php?static_asset=$matches[2]&page_path=$matches[1]',
        'top'
    );
}, 1 );

add_filter( 'query_vars', function ( $vars ) {
    $vars[] = 'page_path';
    $vars[] = 'static_asset';
    return $vars;
});
