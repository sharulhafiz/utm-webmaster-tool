<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Static HTML Publisher — frontend template rendering.
 *
 * Two hooks:
 * 1. template_redirect — serve index.html as complete document when a
 *    Page has an active static package.
 * 2. template_redirect (early) — serve static assets (CSS, JS, images)
 *    under the Page URL via the rewrite rule.
 */

/**
 * Detect whether the current query is for a Page with a static package.
 *
 * @return int|false Page ID or false.
 */
function utm_shp_current_page_id() {
    // Must be a singular page view (not archive, not search).
    if ( ! is_singular( 'page' ) ) {
        return false;
    }

    $post = get_queried_object();
    if ( ! $post || 'page' !== $post->post_type ) {
        return false;
    }

    return (int) $post->ID;
}

/**
 * Serve a static asset file with the correct Content-Type header.
 *
 * Called from template_redirect when the rewrite rule captures
 * <page-path>/<filename.ext>.
 */
function utm_shp_serve_asset() {
    $asset_name = get_query_var( 'static_asset' );
    $page_path  = get_query_var( 'page_path' );

    if ( empty( $asset_name ) || empty( $page_path ) ) {
        return;
    }

    // Resolve the page from the captured path.
    $page = get_page_by_path( $page_path );
    if ( ! $page ) {
        return;
    }

    $page_id = (int) $page->ID;
    if ( ! utm_shp_has_package( $page_id ) ) {
        return;
    }

    $package_dir = utm_shp_package_dir( $page_id );
    $file        = $package_dir . '/' . $asset_name;

    // Canonical containment check.
    $real_file     = realpath( $file );
    $real_base     = realpath( $package_dir );
    if ( false === $real_file || false === $real_base ) {
        wp_die( 'Asset not found.', 'Not Found', [ 'response' => 404 ] );
    }
    if ( 0 !== strpos( $real_file, $real_base ) ) {
        wp_die( 'Forbidden.', 'Forbidden', [ 'response' => 403 ] );
    }
    if ( ! is_file( $real_file ) ) {
        wp_die( 'Asset not found.', 'Not Found', [ 'response' => 404 ] );
    }

    // Content-Type map.
    $types = [
        'css'   => 'text/css',
        'js'    => 'application/javascript',
        'json'  => 'application/json',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'svg'   => 'image/svg+xml',
        'webp'  => 'image/webp',
        'avif'  => 'image/avif',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'eot'   => 'application/vnd.ms-fontobject',
        'otf'   => 'font/otf',
        'ico'   => 'image/x-icon',
        'txt'   => 'text/plain',
        'map'   => 'application/json',
        'pdf'   => 'application/pdf',
        'mp4'   => 'video/mp4',
        'webm'  => 'video/webm',
    ];

    $ext  = strtolower( pathinfo( $asset_name, PATHINFO_EXTENSION ) );
    $mime = $types[ $ext ] ?? 'application/octet-stream';

    header( 'Content-Type: ' . $mime );
    header( 'Cache-Control: public, max-age=86400' );
    header( 'X-Content-Type-Options: nosniff' );

    readfile( $real_file );
    exit;
}

/**
 * Template redirect: intercept Page requests and serve static package.
 *
 * When a published Page has an active static package, this completely
 * replaces the normal WordPress page output. The package index.html is
 * served as its own complete HTML document — no get_header(), get_footer(),
 * or theme wrapping.
 */
add_action( 'template_redirect', function () {
    // 1. Serve static assets if the rewrite rule matched.
    if ( ! empty( get_query_var( 'static_asset' ) ) ) {
        utm_shp_serve_asset();
        return;
    }

    // 2. Serve index.html for the Page itself.
    $page_id = utm_shp_current_page_id();
    if ( ! $page_id ) {
        return;
    }

    if ( ! utm_shp_has_package( $page_id ) ) {
        return;
    }

    // Check publish status: only serve for published pages.
    $post = get_post( $page_id );
    if ( ! $post || 'publish' !== $post->post_status ) {
        return;
    }

    $index = utm_shp_index_html( $page_id );
    if ( ! $index ) {
        return;
    }

    // Serve the complete HTML document.
    header( 'Content-Type: text/html; charset=utf-8' );
    header( 'X-Content-Type-Options: nosniff' );
    // Prevent WordPress from injecting its own headers/footer.
    status_header( 200 );

    readfile( $index );
    exit;
}, 1 );
