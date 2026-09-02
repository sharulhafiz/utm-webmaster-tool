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
    if ( ! is_singular( UTM_SHP_POST_TYPES ) ) {
        return false;
    }

    $post = get_queried_object();
    if ( ! $post || ! utm_shp_is_supported_type( $post->post_type ) ) {
        return false;
    }

    return (int) $post->ID;
}

/**
 * URL-based asset detection — no rewrite rules required.
 *
 * Parses REQUEST_URI to find requests like /<site-path>/<slug>/<asset.ext>.
 * Returns [page_id, file] if the asset belongs to a page with an active
 * package, or false.
 *
 * Handles multisite by stripping the site path prefix.
 */
function utm_shp_detect_asset_request() {
    // Only intercept non-admin, non-REST requests with a file extension.
    if ( is_admin() || wp_doing_ajax() ) {
        return false;
    }

    $uri = trim( wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ), '/' );
    if ( empty( $uri ) ) {
        return false;
    }

    $parts = explode( '/', $uri );
    $last  = end( $parts );
    $ext   = strtolower( pathinfo( $last, PATHINFO_EXTENSION ) );

    // Only intercept requests that look like file assets.
    $asset_exts = [ 'css', 'js', 'json', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif', 'woff', 'woff2', 'ttf', 'eot', 'otf', 'ico', 'txt', 'map', 'pdf', 'mp4', 'webm' ];
    if ( ! in_array( $ext, $asset_exts, true ) ) {
        return false;
    }

    // Need at least <slug>/<asset.ext> — 2+ parts.
    if ( count( $parts ) < 2 ) {
        return false;
    }

    // Generate all possible slug/asset splits.
    // For URL parts ['magazine', 'nadi', 'js', 'app.js']:
    //   slug='magazine'          asset='nadi/js/app.js'
    //   slug='nadi'              asset='js/app.js'        ← correct
    //   slug='js'                asset='app.js'
    //   slug='magazine/nadi'     asset='js/app.js'
    //   slug='nadi/js'           asset='app.js'
    //   slug='magazine/nadi/js'  asset='app.js'
    $splits = [];
    $n = count( $parts );
    for ( $start = 0; $start < $n; $start++ ) {
        for ( $end = $start + 1; $end < $n; $end++ ) {
            $slug_len  = $end - $start;
            $slug      = implode( '/', array_slice( $parts, $start, $slug_len ) );
            $asset_sub = implode( '/', array_slice( $parts, $end ) );
            $splits[]  = [ 'slug' => $slug, 'asset' => $asset_sub ];
        }
    }

    foreach ( $splits as $split ) {
        $candidate = $split['slug'];
        $asset_sub = $split['asset'];

        // Skip if candidate looks like a hidden file.
        if ( '.' === $candidate[0] ) {
            continue;
        }

        $page = get_page_by_path( $candidate );
        if ( ! $page || ! utm_shp_is_supported_type( $page->post_type ) ) {
            continue;
        }

        $page_id = (int) $page->ID;
        if ( ! utm_shp_has_package( $page_id ) ) {
            continue;
        }

        $package_dir = utm_shp_package_dir( $page_id );
        $file        = $package_dir . '/' . $asset_sub;

        // Must exist and be a real file.
        if ( ! is_file( $file ) ) {
            continue;
        }

        // Path traversal guard.
        $real_file = realpath( $file );
        $real_base = realpath( $package_dir );
        if ( false === $real_file || false === $real_base ) {
            continue;
        }
        if ( 0 !== strpos( $real_file, $real_base ) ) {
            continue;
        }

        return [ 'page_id' => $page_id, 'file' => $real_file ];
    }

    return false;
}

/**
 * Serve a static asset file with the correct Content-Type header.
 *
 * @param int    $page_id Page/Post ID that owns the package.
 * @param string $file    Absolute path to the asset file.
 */
function utm_shp_serve_asset_from( $page_id, $file ) {
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

    $ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
    $mime = $types[ $ext ] ?? 'application/octet-stream';

    header( 'Content-Type: ' . $mime );
    header( 'Cache-Control: public, max-age=86400' );
    header( 'X-Content-Type-Options: nosniff' );

    readfile( $file );
    exit;
}

/**
 * Template redirect: intercept Page/Post requests and serve static package.
 *
 * When a published Page/Post has an active static package, this completely
 * replaces the normal WordPress output. The package index.html is served
 * as its own complete HTML document — no get_header(), get_footer(), or
 * theme wrapping. Static assets (CSS, JS, images) under the page URL are
 * also served from the package directory.
 *
 * Asset detection is URL-based (no rewrite rules required).
 */
// Suppress WordPress canonical trailing-slash redirect for static asset URLs.
add_filter( 'redirect_canonical', function ( $redirect_url, $requested_url ) {
    $asset = utm_shp_detect_asset_request();
    if ( $asset ) {
        return false; // Prevent redirect.
    }
    return $redirect_url;
}, 5, 2 );

add_action( 'template_redirect', function () {
    // 1. Serve static assets via URL-based detection (no rewrite rules needed).
    $asset = utm_shp_detect_asset_request();
    if ( $asset ) {
        utm_shp_serve_asset_from( $asset['page_id'], $asset['file'] );
        return;
    }

    // 2. Serve index.html for the Page/Post itself.
    $page_id = utm_shp_current_page_id();
    if ( ! $page_id ) {
        return;
    }

    if ( ! utm_shp_has_package( $page_id ) ) {
        return;
    }

    // Check publish status: only serve for published posts.
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
