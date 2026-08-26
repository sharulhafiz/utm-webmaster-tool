<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Magazine shortcodes + asset serving fallback.
 *
 * [magazine_item slug="my-post"]   — outputs stored index.html inline
 * [magazine_listing]               — lists all published items
 */

/**
 * Allow mag_ prefixed query vars.
 */
add_filter( 'query_vars', function ( $vars ) {
    $vars[] = 'magazine_slug';
    $vars[] = 'magazine_asset';
    return $vars;
});

/**
 * During template loading, swap the query to the real magazine page when
 * WP rewrite resolves pagename=magazine/<slug> to a non-existent WP page.
 */
add_action( 'template_redirect', function () {
    $pagename = get_query_var( 'pagename', '' );

    if ( 'magazine' === $pagename || preg_match( '/^magazine\/([^/]+)$/', $pagename, $m ) ) {
        // Check for asset sub-path (served by PHP when nginx location block absent).
        $asset = get_query_var( 'magazine_asset', '' );
        if ( ! empty( $asset ) && isset( $m[1] ) ) {
            utm_magazine_serve_asset( $m[1], $asset );
            exit;
        }
    }
});

/**
 * Serve a static asset from the magazine-publications directory.
 *
 * This is a fallback for when the nginx location block is not yet deployed.
 * Content-Type is inferred from extension; no PHP/JS execution.
 */
function utm_magazine_serve_asset( $slug, $asset_path ) {
    $base = utm_magazine_dir( $slug );
    $file = $base . '/' . $asset_path;

    $real_file  = realpath( $file );
    $real_base  = realpath( $base );

    if ( false === $real_file || false === $real_base ) {
        wp_die( 'Asset not found.', 'Not Found', [ 'response' => 404 ] );
    }

    if ( 0 !== strpos( $real_file, $real_base ) ) {
        wp_die( 'Forbidden.', 'Forbidden', [ 'response' => 403 ] );
    }

    if ( ! is_file( $real_file ) ) {
        wp_die( 'Asset not found.', 'Not Found', [ 'response' => 404 ] );
    }

    // Content-Type map (limited but safe).
    $types = [
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'json' => 'application/json',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'webp' => 'image/webp',
        'woff' => 'font/woff',
        'woff2'=> 'font/woff2',
        'ttf'  => 'font/ttf',
        'eot'  => 'application/vnd.ms-fontobject',
        'otf'  => 'font/otf',
        'ico'  => 'image/x-icon',
        'html' => 'text/html',
        'htm'  => 'text/html',
        'txt'  => 'text/plain',
    ];

    $ext  = strtolower( pathinfo( $asset_path, PATHINFO_EXTENSION ) );
    $mime = $types[ $ext ] ?? 'application/octet-stream';

    header( 'Content-Type: ' . $mime );
    header( 'Cache-Control: public, max-age=86400' );
    readfile( $real_file );
    exit;
}

/**
 * [magazine_item slug="my-post"] shortcode — outputs stored index.html inline.
 *
 * The HTML is rendered in the WordPress post/page where the shortcode appears.
 * Asset references are rewritten to absolute /magazine/<slug>/ paths so they
 * resolve through nginx (or the PHP fallback above).
 */
add_shortcode( 'magazine_item', function ( $atts ) {
    $slug = isset( $atts['slug'] ) ? sanitize_title( $atts['slug'] ) : '';

    if ( empty( $slug ) ) {
        return '<p class="magazine-error">Missing slug attribute.</p>';
    }

    $item = utm_magazine_get_item( $slug );
    if ( ! $item ) {
        return '<p class="magazine-error">Magazine item not found.</p>';
    }

    $index = utm_magazine_dir( $slug ) . '/index.html';
    if ( ! is_file( $index ) ) {
        return '<p class="magazine-error">Static content missing for this item.</p>';
    }

    $html = file_get_contents( $index );
    $home = home_url( '/magazine/' . $slug . '/' );

    // Rewrite relative asset paths to absolute /magazine/<slug>/ paths.
    $html = preg_replace(
        '/(src|href)=["\'](?!(?:https?:|data:|mailto:|#|\/))([^"\']+)["\']/',
        '$1="' . esc_url( $home ) . '$2"',
        $html
    );

    return $html;
});

/**
 * [magazine_listing] shortcode — renders a simple grid of published items.
 */
add_shortcode( 'magazine_listing', function () {
    $items = utm_magazine_get_items( 'published' );
    if ( empty( $items ) ) {
        return '<p class="magazine-empty">No magazine items published yet.</p>';
    }

    $out  = '<div class="magazine-listing">';
    foreach ( $items as $item ) {
        $url  = home_url( '/magazine/' . $item->slug . '/' );
        $title = esc_html( $item->title ?: $item->slug );
        $out .= '<div class="magazine-listing__item">';
        $out .= '<h3><a href="' . esc_url( $url ) . '">' . $title . '</a></h3>';
        $out .= '</div>';
    }
    $out .= '</div>';

    return $out;
});
