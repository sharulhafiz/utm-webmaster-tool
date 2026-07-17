<?php
/**
 * Module Name: Nginx Cache Purge
 * Description: View nginx FastCGI cache stats, purge cached pages, and list cached URLs.
 * Requires: sudo access to /usr/local/bin/nginx-cache-purge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register admin menu page.
 */
add_action( 'admin_menu', function () {
    add_submenu_page(
        'utm-webmaster-dashboard',
        'Nginx Cache',
        'Nginx Cache',
        'manage_options',
        'nginx-cache',
        'nginx_cache_render_page'
    );
} );

// ─── Cache Purge REST API ───────────────────────────────────────────────
// Allows remote servers (www2-5) to request cache purges via HTTP when
// PHP-FPM and nginx are on different hosts (e.g., space.utm.my www5→www2).

/** UTM server IPs allowed to issue remote purge requests. */
define( 'UTM_CACHE_PURGE_ALLOWED_IPS', [
    '161.139.17.183',  // www2
    '161.139.22.87',   // www3
    '161.139.22.123',  // www4
    '161.139.22.219',  // www5
    '127.0.0.1',
    '::1',
] );

add_action( 'rest_api_init', function () {
    register_rest_route( 'utm/v1', '/cache-purge', [
        'methods'             => 'POST',
        'callback'            => 'utm_cache_purge_handle_request',
        'permission_callback' => function ( WP_REST_Request $r ) {
            // IP allowlist
            $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
            if ( ! in_array( $client_ip, UTM_CACHE_PURGE_ALLOWED_IPS, true ) ) {
                return false;
            }
            // Token verification
            $config = get_site_option( 'utm_cache_purge_config', [] );
            $token  = $config['auth_token'] ?? '';
            if ( empty( $token ) ) {
                return false;
            }
            return hash_equals( $token, $r->get_param( 'token' ) );
        },
    ] );
} );

function utm_cache_purge_run_command( $domain, $args = '' ) {
    $command = '/usr/local/bin/nginx-cache-purge ' . escapeshellarg( $domain );
    if ( ! empty( $args ) ) {
        $command .= ' ' . $args;
    }
    $output_lines = [];
    $exit_code    = 0;
    exec( 'sudo ' . $command . ' 2>&1', $output_lines, $exit_code );
    return [ 'exit_code' => $exit_code, 'output' => implode( "\n", $output_lines ), 'lines' => $output_lines ];
}

function utm_cache_purge_handle_request( WP_REST_Request $r ) {
    $domain = $r->get_param( 'domain' );
    $url    = $r->get_param( 'url' );

    if ( empty( $domain ) ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'Domain is required.' ], 400 );
    }

    $result = utm_cache_purge_run_command( $domain, ! empty( $url ) ? escapeshellarg( $url ) : 'all' );

    if ( 0 === $result['exit_code'] ) {
        return new WP_REST_Response( [ 'success' => true, 'message' => $result['output'] ], 200 );
    }
    return new WP_REST_Response( [ 'success' => false, 'message' => $result['output'] ], 500 );
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'utm/v1', '/cache-stats', [
        'methods'             => 'GET',
        'callback'            => 'utm_cache_stats_handle_request',
        'permission_callback' => function ( WP_REST_Request $r ) {
            $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
            if ( ! in_array( $client_ip, UTM_CACHE_PURGE_ALLOWED_IPS, true ) ) {
                return false;
            }
            $config = get_site_option( 'utm_cache_purge_config', [] );
            $token  = $config['auth_token'] ?? '';
            if ( empty( $token ) ) {
                return false;
            }
            return hash_equals( $token, $r->get_param( 'token' ) );
        },
    ] );
} );

function utm_cache_stats_handle_request( WP_REST_Request $r ) {
    $domain = $r->get_param( 'domain' );
    if ( empty( $domain ) ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'Domain is required.' ], 400 );
    }
    $result = utm_cache_purge_run_command( $domain, '--stats' );
    if ( 0 !== $result['exit_code'] ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => $result['output'] ], 500 );
    }
    $stats = [];
    foreach ( $result['lines'] as $line ) {
        if ( preg_match( '/^([\w\s]+):\s+(.+)$/', $line, $m ) ) {
            $stats[ trim( $m[1] ) ] = trim( $m[2] );
        }
    }
    return new WP_REST_Response( [ 'success' => true, 'stats' => $stats ], 200 );
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'utm/v1', '/cache-urls', [
        'methods'             => 'GET',
        'callback'            => 'utm_cache_urls_handle_request',
        'permission_callback' => function ( WP_REST_Request $r ) {
            $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
            if ( ! in_array( $client_ip, UTM_CACHE_PURGE_ALLOWED_IPS, true ) ) {
                return false;
            }
            $config = get_site_option( 'utm_cache_purge_config', [] );
            $token  = $config['auth_token'] ?? '';
            if ( empty( $token ) ) {
                return false;
            }
            return hash_equals( $token, $r->get_param( 'token' ) );
        },
    ] );
} );

function utm_cache_urls_handle_request( WP_REST_Request $r ) {
    $domain = $r->get_param( 'domain' );
    if ( empty( $domain ) ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'Domain is required.' ], 400 );
    }
    $result = utm_cache_purge_run_command( $domain, '--list' );
    if ( 0 !== $result['exit_code'] ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => $result['output'] ], 500 );
    }
    $urls = [];
    foreach ( $result['lines'] as $line ) {
        $line = trim( $line );
        if ( ! empty( $line ) && ! str_starts_with( $line, '[' ) ) {
            $urls[] = $line;
        }
    }
    return new WP_REST_Response( [ 'success' => true, 'urls' => $urls ], 200 );
}

/**
 * Enqueue admin styles.
 */
add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( 'utm-plugin_page_nginx-cache' !== $hook ) {
        return;
    }
    wp_add_inline_style( 'wp-admin', '
.nginx-cache-wrap { max-width: 960px; }
.nginx-cache-stats { display: flex; gap: 20px; margin: 20px 0; flex-wrap: wrap; }
.nginx-cache-stat-box { flex: 1; min-width: 140px; padding: 20px; background: #fff; border: 1px solid #ddd; border-radius: 4px; text-align: center; }
.nginx-cache-stat-box .stat-value { font-size: 28px; font-weight: 600; line-height: 1.2; }
.nginx-cache-stat-box .stat-label { font-size: 12px; text-transform: uppercase; color: #666; margin-top: 4px; }
.nginx-cache-purge-form { margin: 20px 0; padding: 20px; background: #f0f6fc; border: 1px solid #c3d9f0; border-radius: 4px; }
.nginx-cache-purge-form .button { margin-right: 8px; }
.nginx-cache-msg { padding: 10px 15px; margin: 10px 0; border-radius: 4px; }
.nginx-cache-msg.success { background: #edfaef; border: 1px solid #b7e0b9; color: #216b28; }
.nginx-cache-msg.error { background: #fbeaea; border: 1px solid #f5c6c6; color: #a11f1f; }
.nginx-cache-msg.info { background: #e5f5fa; border: 1px solid #b3dff2; color: #1a5a7a; }

/* Table styles */
.nc-posts-table-wrap { margin: 20px 0; overflow-x: auto; }
.nc-posts-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #ddd; border-radius: 4px; }
.nc-posts-table th { background: #f6f7f9; padding: 10px 12px; text-align: left; font-size: 12px; text-transform: uppercase; color: #555; border-bottom: 2px solid #ddd; white-space: nowrap; position: sticky; top: 0; z-index: 1; }
.nc-posts-table td { padding: 10px 12px; border-bottom: 1px solid #eee; font-size: 13px; vertical-align: middle; }
.nc-posts-table tr:hover { background: #f8faff; }
.nc-posts-table .nc-status { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .3px; }
.nc-status-cached { background: #edfaef; color: #216b28; }
.nc-status-not-cached { background: #fbeaea; color: #a11f1f; }
.nc-status-miss { background: #fff4e5; color: #b76e00; }
.nc-status-loading { background: #f0f0f0; color: #888; }
.nc-pagination { margin: 15px 0; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.nc-pagination .button { min-width: 80px; text-align: center; }
.nc-pagination .nc-page-info { font-size: 13px; color: #666; }
.nc-pagination .nc-per-page { margin-left: auto; }
.nc-filters { display: flex; gap: 12px; margin: 15px 0; flex-wrap: wrap; align-items: center; }
.nc-filters input, .nc-filters select { font-size: 13px; }
.nc-action-btn { cursor: pointer; padding: 4px 12px; border-radius: 4px; font-size: 12px; border: 1px solid #ddd; background: #fff; }
.nc-action-btn:hover { background: #f0f0f0; }
.nc-action-btn.purge { color: #a11f1f; border-color: #f5c6c6; }
.nc-action-btn.purge:hover { background: #fbeaea; }
.nc-action-btn.warm { color: #1a5a7a; border-color: #b3dff2; }
.nc-action-btn.warm:hover { background: #e5f5fa; }
#nc-posts-results { min-height: 100px; }
.nc-toolbar { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 10px; }
.nc-toolbar .button { display: inline-flex; align-items: center; gap: 4px; }
.nc-loading { text-align: center; padding: 40px; color: #888; }
.nc-error { text-align: center; padding: 20px; color: #a11f1f; background: #fbeaea; border-radius: 4px; }
' );
} );

/**
 * Handle purge actions via admin-post.
 */
add_action( 'admin_post_nginx_cache_purge', 'nginx_cache_handle_purge' );
function nginx_cache_handle_purge() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized' );
    }
    check_admin_referer( 'nginx_cache_purge' );

    $domain  = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
    $pattern = isset( $_POST['pattern'] ) ? sanitize_text_field( wp_unslash( $_POST['pattern'] ) ) : '';
    $action  = isset( $_POST['action_type'] ) ? sanitize_key( $_POST['action_type'] ) : 'all';

    if ( empty( $domain ) ) {
        $redirect = add_query_arg( array( 'status' => 'error', 'msg' => urlencode( 'Domain is required.' ) ), wp_get_referer() );
        wp_safe_redirect( $redirect );
        exit;
    }

    $command = '/usr/local/bin/nginx-cache-purge ' . escapeshellarg( $domain );
    if ( 'pattern' === $action && ! empty( $pattern ) ) {
        $command .= ' ' . escapeshellarg( $pattern );
    } elseif ( 'stats' !== $action ) {
        $command .= ' all';
    }

    $output_lines = array();
    $exit_code = 0;
    exec( 'sudo ' . $command . ' 2>&1', $output_lines, $exit_code );
    $output = implode( "\n", $output_lines );

    if ( 0 === $exit_code ) {
        $redirect = add_query_arg( array( 'status' => 'success', 'msg' => urlencode( $output ) ), wp_get_referer() );
    } else {
        $redirect = add_query_arg( array( 'status' => 'error', 'msg' => urlencode( 'Command failed: ' . $output ) ), wp_get_referer() );
    }

    wp_safe_redirect( $redirect );
    exit;
}

/**
 * Handle per-post purge action via AJAX.
 */
add_action( 'wp_ajax_nginx_cache_purge_url', 'nginx_cache_ajax_purge_url' );
function nginx_cache_ajax_purge_url() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( -1 );
    }

    $url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
    $domain = wp_parse_url( $url, PHP_URL_HOST );
    $path   = wp_parse_url( $url, PHP_URL_PATH );

    if ( empty( $domain ) || empty( $path ) ) {
        wp_send_json_error( array( 'msg' => 'Invalid URL.' ) );
    }

    $command = '/usr/local/bin/nginx-cache-purge ' . escapeshellarg( $domain ) . ' ' . escapeshellarg( $path );
    $output_lines = array();
    $exit_code = 0;
    exec( 'sudo ' . $command . ' 2>&1', $output_lines, $exit_code );
    $output = implode( "\n", $output_lines );

    if ( 0 === $exit_code ) {
        wp_send_json_success( array( 'msg' => $output ) );
    } else {
        wp_send_json_error( array( 'msg' => $output ) );
    }
}

/**
 * Handle warm (force-cache) action via AJAX.
 */
add_action( 'wp_ajax_nginx_cache_warm_url', 'nginx_cache_ajax_warm_url' );
function nginx_cache_ajax_warm_url() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( -1 );
    }

    $url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
    if ( empty( $url ) ) {
        wp_send_json_error( array( 'msg' => 'URL is required.' ) );
    }

    $response = wp_remote_get( $url, array(
        'timeout'   => 60,
        'blocking'  => true,
        'sslverify' => false,
        'headers'   => array( 'X-Cache-Warm' => '1' ),
    ) );

    if ( is_wp_error( $response ) ) {
        wp_send_json_error( array( 'msg' => 'Warm failed: ' . $response->get_error_message() ) );
    }

    $code = wp_remote_retrieve_response_code( $response );
    $cache_header = wp_remote_retrieve_header( $response, 'x-cache-status' );
    if ( empty( $cache_header ) ) {
        $cache_header = wp_remote_retrieve_header( $response, 'x-fastcgi-cache' );
    }

    wp_send_json_success( array(
        'msg'  => "Warmed: HTTP {$code}, Cache: " . ( $cache_header ?: 'unknown' ),
        'code' => $code,
    ) );
}

/**
 * Bulk warm all uncached URLs via AJAX (called in batches).
 */
add_action( 'wp_ajax_nginx_cache_bulk_warm', 'nginx_cache_ajax_bulk_warm' );
function nginx_cache_ajax_bulk_warm() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( -1 );
    }

    $domain   = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
    $batch    = isset( $_POST['batch'] ) ? intval( $_POST['batch'] ) : 0;
    $per_batch = 5; // Warm 5 URLs per request

    if ( empty( $domain ) ) {
        wp_send_json_error( array( 'msg' => 'Domain is required.' ) );
    }

    // Get all cached URLs & query ALL published posts
    $cached_urls = nginx_cache_get_cached_urls( $domain, true );
    $cached_index = array();
    foreach ( $cached_urls as $url ) {
        $normalized = str_replace( array( 'https://', 'http://' ), '', $url );
        $normalized = rtrim( $normalized, '/' );
        $cached_index[ $normalized ] = true;
        $cached_index[ $url ] = true;
    }

    $all_types = array_keys( get_post_types( array( 'public' => true ), 'names' ) );
    $all_types = array_diff( $all_types, array( 'attachment', 'revision', 'nav_menu_item' ) );

    $query = new WP_Query( array(
        'post_type'      => $all_types,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ) );

    // Collect all uncached URLs
    $uncached = array();
    foreach ( $query->posts as $post_id ) {
        $permalink = get_permalink( $post_id );
        $path = wp_parse_url( $permalink, PHP_URL_PATH );
        $normalized_url = str_replace( array( 'https://', 'http://' ), '', $permalink );
        $normalized_url = rtrim( $normalized_url, '/' );

        $is_cached = isset( $cached_index[ $normalized_url ] )
                  || isset( $cached_index[ $domain . $path ] )
                  || isset( $cached_index[ $permalink ] );

        if ( ! $is_cached ) {
            $uncached[] = $permalink;
        }
    }

    $total_uncached = count( $uncached );

    if ( 0 === $total_uncached ) {
        // Schedule cache refresh to show updated status
        delete_transient( 'nginx_cached_urls_' . md5( $domain ) );
        wp_send_json_success( array(
            'done'    => true,
            'total'   => 0,
            'warmed'  => 0,
            'msg'     => 'All pages are already cached!',
        ) );
    }

    // Calculate batch range
    $start  = $batch * $per_batch;
    $warmed = 0;
    $errors = array();

    for ( $i = $start; $i < min( $start + $per_batch, $total_uncached ); $i++ ) {
        $url = $uncached[ $i ];
        $response = wp_remote_get( $url, array(
            'timeout'   => 30,
            'blocking'  => true,
            'sslverify' => false,
            'headers'   => array( 'X-Cache-Warm' => '1' ),
        ) );
        if ( ! is_wp_error( $response ) ) {
            $code = wp_remote_retrieve_response_code( $response );
            if ( $code >= 200 && $code < 400 ) {
                $warmed++;
            } else {
                $errors[] = "HTTP {$code}: " . wp_parse_url( $url, PHP_URL_PATH );
            }
        } else {
            $errors[] = $response->get_error_message();
        }
    }

    $next_batch = $batch + 1;
    $is_done    = ( $start + $per_batch ) >= $total_uncached;

    // Schedule cache refresh on completion
    if ( $is_done ) {
        delete_transient( 'nginx_cached_urls_' . md5( $domain ) );
    }

    wp_send_json_success( array(
        'done'         => $is_done,
        'batch'        => $next_batch,
        'total'        => $total_uncached,
        'warmed'       => $warmed,
        'warmed_so_far' => min( $start + $per_batch, $total_uncached ),
        'errors'       => $errors,
        'msg'          => $is_done
            ? "Done! Warmed {$warmed} of {$total_uncached} pages."
            : "Warmed " . min( $start + $per_batch, $total_uncached ) . " of {$total_uncached}...",
    ) );
}

/**
 * Get all cached URLs for a domain.
 */
function nginx_cache_get_cached_urls( $domain, $force_refresh = false ) {
    $cache_key = 'nginx_cached_urls_' . md5( $domain );
    if ( ! $force_refresh ) {
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }
    }

    // Check if this domain's cache is on a remote proxy server
    $config       = get_site_option( 'utm_cache_purge_config', [] );
    $cache_servers = $config['remote_cache_servers'] ?? [];
    $shared_token  = $config['auth_token'] ?? '';

    if ( isset( $cache_servers[ $domain ] ) && ! empty( $shared_token ) ) {
        $server_url = untrailingslashit( $cache_servers[ $domain ] );
        $resp = wp_remote_get( add_query_arg( [
            'action' => 'list',
            'domain' => $domain,
            'token'  => $shared_token,
        ], $server_url . '/utm-purge-handler.php' ), [
            'timeout'   => 10,
            'sslverify' => false,
        ] );
        if ( ! is_wp_error( $resp ) && 200 === wp_remote_retrieve_response_code( $resp ) ) {
            $data = json_decode( wp_remote_retrieve_body( $resp ), true );
            if ( ! empty( $data['success'] ) && isset( $data['urls'] ) ) {
                set_transient( $cache_key, $data['urls'], 2 * MINUTE_IN_SECONDS );
                return $data['urls'];
            }
        }
        return [];
    }

    $command = '/usr/local/bin/nginx-cache-purge ' . escapeshellarg( $domain ) . ' --list';
    $output_lines = array();
    $exit_code = 0;
    exec( 'sudo ' . $command . ' 2>&1', $output_lines, $exit_code );

    $urls = array();
    if ( 0 === $exit_code ) {
        foreach ( $output_lines as $line ) {
            $line = trim( $line );
            if ( ! empty( $line ) && ! str_starts_with( $line, '[' ) ) {
                $urls[] = $line;
            }
        }
    }

    set_transient( $cache_key, $urls, 2 * MINUTE_IN_SECONDS );
    return $urls;
}

/**
 * Get posts with cache status via AJAX.
 */
add_action( 'wp_ajax_nginx_cache_posts_status', 'nginx_cache_ajax_posts_status' );
function nginx_cache_ajax_posts_status() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( -1 );
    }

    $domain    = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
    $page      = isset( $_POST['page'] ) ? max( 1, intval( $_POST['page'] ) ) : 1;
    $per_page  = isset( $_POST['per_page'] ) ? min( 100, max( 5, intval( $_POST['per_page'] ) ) ) : 20;
    $search    = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
    $post_type = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : '';

    if ( empty( $domain ) ) {
        wp_send_json_error( array( 'msg' => 'Domain is required.' ) );
    }

    // Get all cached URLs and normalize the index
    $cached_urls = nginx_cache_get_cached_urls( $domain );
    $cached_index = array();
    foreach ( $cached_urls as $url ) {
        // Normalize: strip scheme, normalize trailing slash
        $normalized = str_replace( array( 'https://', 'http://' ), '', $url );
        $normalized = rtrim( $normalized, '/' );
        $cached_index[ $normalized ] = true;
        // Also index the original
        $cached_index[ $url ] = true;
    }

    // Query posts
    $args = array(
        'post_type'      => $post_type ? array( $post_type ) : array( 'page', 'post' ),
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => 'post_type',
        'order'          => 'ASC',
        's'              => $search,
    );

    $query = new WP_Query( $args );
    $posts = array();

    foreach ( $query->posts as $post ) {
        $permalink = get_permalink( $post->ID );
        $path = wp_parse_url( $permalink, PHP_URL_PATH );

        // Normalize: strip scheme, trailing slash for index lookup
        $normalized_url = str_replace( array( 'https://', 'http://' ), '', $permalink );
        $normalized_url = rtrim( $normalized_url, '/' );
        $normalized_path = ltrim( rtrim( $path, '/' ), '/' );

        $is_cached = isset( $cached_index[ $normalized_url ] )
                  || isset( $cached_index[ $domain . $path ] )
                  || isset( $cached_index[ $normalized_path ] )
                  || isset( $cached_index[ $permalink ] );

        $posts[] = array(
            'id'        => $post->ID,
            'title'     => get_the_title( $post ),
            'url'       => $permalink,
            'path'      => $path,
            'type'      => get_post_type_object( $post->post_type )->labels->singular_name,
            'type_slug' => $post->post_type,
            'cached'    => $is_cached,
        );
    }

    wp_send_json_success( array(
        'posts'      => $posts,
        'total'      => $query->found_posts,
        'pages'      => $query->max_num_pages,
        'page'       => $page,
        'per_page'   => $per_page,
        'post_types' => nginx_cache_get_post_types(),
    ) );
}

/**
 * Get available post types for the filter dropdown.
 */
function nginx_cache_get_post_types() {
    $types = get_post_types( array( 'public' => true ), 'objects' );
    $result = array();
    foreach ( $types as $slug => $obj ) {
        if ( in_array( $slug, array( 'attachment', 'revision', 'nav_menu_item' ), true ) ) {
            continue;
        }
        $result[] = array(
            'slug'  => $slug,
            'label' => $obj->labels->name,
        );
    }
    return $result;
}

/**
 * Get cache stats — cached in transient to avoid slow PHP boots.
 */

/**
 * Get total published content count (posts + pages + public CPTs).
 */
function nginx_cache_get_total_published() {
    $cache_key = 'nginx_cache_total_published';
    $total = get_transient( $cache_key );
    if ( false !== $total ) {
        return $total;
    }

    $all_types = array_keys( get_post_types( array( 'public' => true ), 'names' ) );
    $all_types = array_diff( $all_types, array( 'attachment', 'revision', 'nav_menu_item' ) );

    $query = new WP_Query( array(
        'post_type'      => $all_types,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ) );

    $total = $query->post_count;
    set_transient( $cache_key, $total, HOUR_IN_SECONDS );
    return $total;
}


function nginx_cache_get_stats( $domain, $force_refresh = false ) {
    $cache_key = 'nginx_cache_stats_' . md5( $domain );
    if ( ! $force_refresh ) {
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }
    }

    // Check if this domain's cache is on a remote proxy server
    $config       = get_site_option( 'utm_cache_purge_config', [] );
    $cache_servers = $config['remote_cache_servers'] ?? [];
    $shared_token  = $config['auth_token'] ?? '';

    if ( isset( $cache_servers[ $domain ] ) && ! empty( $shared_token ) ) {
        $server_url = untrailingslashit( $cache_servers[ $domain ] );
        $resp = wp_remote_get( add_query_arg( [
            'action' => 'stats',
            'domain' => $domain,
            'token'  => $shared_token,
        ], $server_url . '/utm-purge-handler.php' ), [
            'timeout'   => 10,
            'sslverify' => false,
        ] );
        if ( ! is_wp_error( $resp ) && 200 === wp_remote_retrieve_response_code( $resp ) ) {
            $data = json_decode( wp_remote_retrieve_body( $resp ), true );
            if ( ! empty( $data['success'] ) && isset( $data['stats'] ) ) {
                $stats = $data['stats'];
                // Attach total published count and percentage
                $total_published         = nginx_cache_get_total_published();
                $stats['total_public']   = $total_published;
                $cached_files            = isset( $stats['Files'] ) ? intval( $stats['Files'] ) : 0;
                $stats['percent']        = $total_published > 0 ? round( ( $cached_files / $total_published ) * 100, 1 ) : 0;
                set_transient( $cache_key, $stats, 2 * MINUTE_IN_SECONDS );
                return $stats;
            }
        }
        return [ 'error' => 'Could not reach cache server' ];
    }

    $output_lines = array();
    $exit_code = 0;
    exec(
        'sudo /usr/local/bin/nginx-cache-purge ' . escapeshellarg( $domain ) . ' --stats 2>&1',
        $output_lines,
        $exit_code
    );
    $output = implode( "\n", $output_lines );

    $stats = array();
    if ( 0 === $exit_code ) {
        foreach ( $output_lines as $line ) {
            if ( preg_match( '/^([\w\s]+):\s+(.+)$/', $line, $m ) ) {
                $stats[ trim( $m[1] ) ] = trim( $m[2] );
            }
        }
    } else {
        $stats = array( 'error' => $output );
    }

    // Attach total published count and percentage
    $total_published         = nginx_cache_get_total_published();
    $stats['total_public']   = $total_published;
    $cached_files            = isset( $stats['Files'] ) ? intval( $stats['Files'] ) : 0;
    $stats['percent']        = $total_published > 0 ? round( ( $cached_files / $total_published ) * 100, 1 ) : 0;

    set_transient( $cache_key, $stats, 2 * MINUTE_IN_SECONDS );
    return $stats;
}

/**
 * Get cache stats via AJAX.
 */
add_action( 'wp_ajax_nginx_cache_stats', 'nginx_cache_ajax_stats' );
function nginx_cache_ajax_stats() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( -1 );
    }

    $domain = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
    if ( empty( $domain ) ) {
        wp_send_json_error( array( 'msg' => 'Domain is required.' ) );
    }

    $stats = nginx_cache_get_stats( $domain, true );

    if ( ! isset( $stats['error'] ) ) {
        wp_send_json_success( $stats );
    } else {
        wp_send_json_error( array( 'msg' => $stats['error'] ) );
    }
}

/**
 * Auto-purge cache when a post is updated.
 *
 * Detects whether the nginx cache lives on this server or a remote proxy
 * server. Local cache → exec(). Remote cache → HTTP POST to REST endpoint.
 */
add_action( 'save_post', 'nginx_cache_auto_purge', 10, 3 );
function nginx_cache_auto_purge( $post_id, $post, $update ) {
    if ( ! $update ) {
        return;
    }
    if ( 'publish' !== $post->post_status ) {
        return;
    }
    if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
        return;
    }

    $domain = wp_parse_url( home_url(), PHP_URL_HOST );
    if ( empty( $domain ) ) {
        return;
    }

    $config       = get_site_option( 'utm_cache_purge_config', [] );
    $cache_servers = $config['remote_cache_servers'] ?? [];
    $shared_token  = $config['auth_token'] ?? '';

    $urls_to_purge = [ get_permalink( $post_id ), home_url() ];

    // Check if this domain's cache lives on a remote proxy server
    if ( isset( $cache_servers[ $domain ] ) && ! empty( $shared_token ) ) {
        // ── Remote cache: HTTP purge ──────────────────────────────────
        $server_url = untrailingslashit( $cache_servers[ $domain ] );
        foreach ( $urls_to_purge as $url ) {
            $url_path = wp_parse_url( $url, PHP_URL_PATH ) ?: '/';
            wp_remote_get( add_query_arg( [
                'action' => 'purge',
                'domain' => $domain,
                'url'    => $url_path,
                'token'  => $shared_token,
            ], $server_url . '/utm-purge-handler.php' ), [
                'timeout'   => 10,
                'blocking'  => false,   // fire-and-forget
                'sslverify' => false,
            ] );
        }
    } else {
        // ── Local cache: direct exec() ────────────────────────────────
        foreach ( $urls_to_purge as $url ) {
            $url_path = wp_parse_url( $url, PHP_URL_PATH );
            if ( ! empty( $url_path ) ) {
                exec(
                    'sudo /usr/local/bin/nginx-cache-purge ' . escapeshellarg( $domain ) . ' ' . escapeshellarg( $url_path ) . ' 2>&1',
                    $output_lines,
                    $exit_code
                );
            }
        }
    }
}

/**
 * Save remote cache configuration via admin-post.
 */
add_action( 'admin_post_utm_cache_purge_config_save', 'utm_cache_purge_config_save_handler' );
function utm_cache_purge_config_save_handler() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized' );
    }
    check_admin_referer( 'utm_cache_purge_config' );

    $auth_token = isset( $_POST['auth_token'] ) ? sanitize_text_field( wp_unslash( $_POST['auth_token'] ) ) : '';

    $remote_domains = isset( $_POST['remote_domain'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['remote_domain'] ) ) : [];
    $remote_urls    = isset( $_POST['remote_url'] ) ? array_map( 'esc_url_raw', wp_unslash( $_POST['remote_url'] ) ) : [];

    $remote_servers = [];
    foreach ( $remote_domains as $i => $dom ) {
        $dom = trim( $dom );
        if ( empty( $dom ) || empty( $remote_urls[ $i ] ) ) {
            continue;
        }
        $remote_servers[ $dom ] = $remote_urls[ $i ];
    }

    update_site_option( 'utm_cache_purge_config', [
        'auth_token'         => $auth_token,
        'remote_cache_servers' => $remote_servers,
    ] );

    wp_safe_redirect( add_query_arg( [ 'status' => 'success', 'msg' => urlencode( 'Remote cache configuration saved.' ) ], wp_get_referer() ) );
    exit;
}

/**
 * Render the admin page.
 */
function nginx_cache_render_page() {
    $domain = wp_parse_url( home_url(), PHP_URL_HOST );
    $status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
    $msg    = isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( $_GET['msg'] ) ) : '';

    $stats = nginx_cache_get_stats( $domain );
    $nc_files = isset( $stats["Files"] ) ? $stats["Files"] . " / " . $stats["total_public"] . " (" . $stats["percent"] . "%)" : "—";
    $nc_size  = isset( $stats['Disk usage'] ) ? $stats['Disk usage'] : '—';
    ?>
    <div class="wrap nginx-cache-wrap">
        <h1>Nginx Cache Management</h1>

        <?php if ( $status && $msg ) : ?>
            <div class="nginx-cache-msg <?php echo esc_attr( $status ); ?>">
                <?php echo esc_html( $msg ); ?>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div id="nginx-cache-stats">
            <div class="nginx-cache-stats">
                <div class="nginx-cache-stat-box">
                    <div class="stat-value" id="nc-domain"><?php echo esc_html( $domain ); ?></div>
                    <div class="stat-label">Current Site</div>
                </div>
                <div class="nginx-cache-stat-box">
                    <div class="stat-value" id="nc-files"><?php echo esc_html( $nc_files ); ?></div>
                    <div class="stat-label">Cached Files</div>
                </div>
                <div class="nginx-cache-stat-box">
                    <div class="stat-value" id="nc-size"><?php echo esc_html( $nc_size ); ?></div>
                    <div class="stat-label">Disk Usage</div>
                </div>
            </div>
            <p class="description" id="nc-refresh"><a href="javascript:void(0)" onclick="nginxCacheRefresh()">↻ Refresh stats</a></p>
        </div>

        <!-- Purge Form -->
        <div class="nginx-cache-purge-form">
            <h2>Purge Cache</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'nginx_cache_purge' ); ?>
                <input type="hidden" name="action" value="nginx_cache_purge">
                <input type="hidden" name="domain" value="<?php echo esc_attr( $domain ); ?>">
                <p>
                    <label><input type="radio" name="action_type" value="all" checked> 
                    <strong>Purge All Cache</strong> — clears every cached page</label>
                </p>
                <p>
                    <label><input type="radio" name="action_type" value="pattern">
                    <strong>Purge by URL pattern</strong> — e.g. <code>/admission/</code> or <code>diploma</code></label>
                    <br>
                    <input type="text" name="pattern" placeholder="e.g. /admission/ or diploma" style="width:300px;margin-top:6px;">
                </p>
                <p><button type="submit" class="button button-primary" onclick="return confirm('Purge nginx cache? This may temporarily increase server load.');">Purge Cache</button></p>
            </form>
        </div>

        <!-- Remote Cache Configuration -->
        <div class="nginx-cache-purge-form" style="background:#fff;border-color:#ddd;">
            <h2>Remote Cache Configuration</h2>
            <p>Configure which domains have their nginx cache on a different server (e.g., <code>space.utm.my</code> PHP on www5, nginx on www2). The plugin will use HTTP purge when a post is saved instead of the local <code>exec()</code>.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'utm_cache_purge_config' ); ?>
                <input type="hidden" name="action" value="utm_cache_purge_config_save">
                <?php
                $cfg = get_site_option( 'utm_cache_purge_config', [] );
                $remote_servers = $cfg['remote_cache_servers'] ?? [];
                $auth_token     = $cfg['auth_token'] ?? '';
                ?>
                <p>
                    <label><strong>Auth Token:</strong><br>
                    <input type="text" name="auth_token" value="<?php echo esc_attr( $auth_token ); ?>" style="width:300px;font-family:monospace;" placeholder="Shared secret for cross-server auth"></label>
                    <span class="description"> — must match on every server instance</span>
                </p>
                <p><strong>Remote Cache Servers</strong> <span class="description">(domain → cache server URL)</span></p>
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th style="text-align:left;padding:4px 8px;border-bottom:1px solid #ddd;width:40%;">Domain</th>
                            <th style="text-align:left;padding:4px 8px;border-bottom:1px solid #ddd;width:40%;">Cache Server URL</th>
                            <th style="text-align:left;padding:4px 8px;border-bottom:1px solid #ddd;width:20%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="nc-remote-rows">
                        <?php foreach ( $remote_servers as $dom => $url ) : ?>
                        <tr>
                            <td style="padding:4px 8px;"><input type="text" name="remote_domain[]" value="<?php echo esc_attr( $dom ); ?>" style="width:100%;font-family:monospace;"></td>
                            <td style="padding:4px 8px;"><input type="url" name="remote_url[]" value="<?php echo esc_attr( $url ); ?>" style="width:100%;font-family:monospace;" placeholder="https://www2.utm.my"></td>
                            <td style="padding:4px 8px;"><button type="button" class="button" onclick="this.closest('tr').remove()">✕ Remove</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button type="button" class="button" onclick="var t=document.getElementById('nc-remote-rows');var r=t.insertRow();r.innerHTML='<td style=\\\"padding:4px 8px;\\\"><input type=\\\"text\\\" name=\\\"remote_domain[]\\\" style=\\\"width:100%;font-family:monospace;\\\" placeholder=\\\"space.utm.my\\\"></td><td style=\\\"padding:4px 8px;\\\"><input type=\\\"url\\\" name=\\\"remote_url[]\\\" style=\\\"width:100%;font-family:monospace;\\\" placeholder=\\\"https://www2.utm.my\\\"></td><td style=\\\"padding:4px 8px;\\\"><button type=\\\"button\\\" class=\\\"button\\\" onclick=\\\"this.closest(\\'tr\\').remove()\\\">✕ Remove</button></td>';">+ Add Domain</button></p>
                <p><button type="submit" class="button button-primary">Save Configuration</button></p>
            </form>
        </div>

        <!-- Auto-Purge Status -->
        <div class="nginx-cache-purge-form" style="background:#fff;border-color:#ddd;">
            <h2>Auto-Purge on Post Update</h2>
            <p>When a post or page is updated, its cached version and the homepage are automatically purged.</p>
            <p class="description">✅ Active — cache for the updated URL + homepage cleared on every <code>save_post</code>.</p>
        </div>

        <!-- Page Cache Status Table -->
        <div class="nginx-cache-purge-form" style="background:#fff;border-color:#ddd;">
            <h2>Page Cache Status</h2>
            <p class="description">Shows all published pages/posts with their nginx cache status. Use this to identify pages that failed to cache and manually warm or purge them.</p>

            <div class="nc-toolbar">
                <button type="button" class="button" onclick="ncLoadPosts(1)">⟳ Load All Pages</button>
                <button type="button" class="button" onclick="ncLoadCachedOnly()">✓ Show Cached Only</button>
                <button type="button" class="button" onclick="ncLoadUncachedOnly()">✗ Show Uncached Only</button>
                <button type="button" class="button button-primary" onclick="ncBulkWarm()">☀ Bulk Cache All</button>
            </div>

            <div id="nc-bulk-progress" style="display:none;margin:10px 0;padding:15px;background:#f0f6fc;border-radius:4px;">
                <p id="nc-bulk-status" style="margin-bottom:8px;font-weight:600;">Preparing...</p>
                <progress id="nc-bulk-bar" max="100" value="0" style="width:100%;height:20px;border-radius:10px;"></progress>
                <p id="nc-bulk-detail" class="description" style="margin-top:6px;"></p>
            </div>

            <div class="nc-filters">
                <select id="nc-filter-type" onchange="ncLoadPosts(1)">
                    <option value="">All post types</option>
                </select>
                <input type="text" id="nc-filter-search" placeholder="Search by title or URL..." style="width:250px;">
                <button type="button" class="button" onclick="ncLoadPosts(1)">🔍 Search</button>
                <button type="button" class="button" onclick="ncFilterClear()">Clear</button>
                <span class="description" id="nc-result-count"></span>
            </div>

            <div id="nc-posts-results">
                <p class="description">Click "Load All Pages" to see every published page/post with its cache status.</p>
            </div>

            <div class="nc-pagination" id="nc-pagination" style="display:none;">
                <button type="button" class="button" id="nc-prev" onclick="ncPrevPage()">← Previous</button>
                <span class="nc-page-info" id="nc-page-info"></span>
                <button type="button" class="button" id="nc-next" onclick="ncNextPage()">Next →</button>
                <span class="nc-per-page">
                    <label>Per page:
                        <select id="nc-per-page" onchange="ncLoadPosts(1)">
                            <option value="10">10</option>
                            <option value="20" selected>20</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </label>
                </span>
            </div>
        </div>
    </div>

    <script>
    var ncCurrentPage = 1;
    var ncTotalPages = 1;
    var ncFilterMode = 'all'; // 'all', 'cached', 'uncached'

    function ncLoadPosts(page) {
        ncCurrentPage = page;
        var resultsEl = document.getElementById('nc-posts-results');
        resultsEl.innerHTML = '<div class="nc-loading">Loading page cache status...</div>';

        var perPage = document.getElementById('nc-per-page').value;
        var search = document.getElementById('nc-filter-search').value.trim();
        var postType = document.getElementById('nc-filter-type').value;
        var domain = document.getElementById('nc-domain').textContent.trim();

        var data = new FormData();
        data.append('action', 'nginx_cache_posts_status');
        data.append('domain', domain);
        data.append('page', page);
        data.append('per_page', perPage);
        data.append('search', search);
        data.append('post_type', postType);

        fetch(ajaxurl, { method: 'POST', body: data })
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                if (resp.success && resp.data) {
                    ncRenderPosts(resp.data);
                } else {
                    resultsEl.innerHTML = '<div class="nc-error">Failed to load: ' + (resp.data && resp.data.msg ? resp.data.msg : 'Unknown error') + '</div>';
                }
            })
            .catch(function() {
                resultsEl.innerHTML = '<div class="nc-error">Request failed — network error.</div>';
            });
    }

    function ncRenderPosts(data) {
        var resultsEl = document.getElementById('nc-posts-results');
        var paginationEl = document.getElementById('nc-pagination');
        var countEl = document.getElementById('nc-result-count');

        ncTotalPages = data.pages || 1;

        // Populate post type filter on first load
        var typeSelect = document.getElementById('nc-filter-type');
        if (typeSelect.options.length <= 1 && data.post_types) {
            data.post_types.forEach(function(t) {
                var opt = document.createElement('option');
                opt.value = t.slug;
                opt.textContent = t.label;
                typeSelect.appendChild(opt);
            });
        }

        if (!data.posts || data.posts.length === 0) {
            resultsEl.innerHTML = '<p class="description" style="padding:20px;text-align:center;">No published pages/posts found' + (ncFilterMode !== 'all' ? ' matching filter' : '') + '.</p>';
            paginationEl.style.display = 'none';
            countEl.textContent = '';
            return;
        }

        // Filter by cache status mode
        var posts = data.posts;
        if (ncFilterMode === 'cached') {
            posts = posts.filter(function(p) { return p.cached; });
        } else if (ncFilterMode === 'uncached') {
            posts = posts.filter(function(p) { return !p.cached; });
        }

        countEl.textContent = posts.length + ' of ' + data.total + ' pages shown';

        var html = '<div class="nc-posts-table-wrap"><table class="nc-posts-table">';
        html += '<thead><tr><th>Status</th><th>Title</th><th>Type</th><th>URL</th><th>Actions</th></tr></thead><tbody>';

        posts.forEach(function(post) {
            var statusClass = post.cached ? 'nc-status-cached' : 'nc-status-not-cached';
            var statusText = post.cached ? 'Cached' : 'Not Cached';
            var displayUrl = post.path;
            if (displayUrl.length > 50) { displayUrl = displayUrl.substring(0, 47) + '...'; }

            html += '<tr>';
            html += '<td><span class="nc-status ' + statusClass + '">' + statusText + '</span></td>';
            html += '<td><strong>' + escHtml(post.title || '(no title)') + '</strong></td>';
            html += '<td><span class="description">' + escHtml(post.type) + '</span></td>';
            html += '<td><code><a href="' + escHtml(post.url) + '" target="_blank" title="' + escHtml(post.url) + '">' + escHtml(displayUrl) + '</a></code></td>';
            html += '<td style="white-space:nowrap;">';
            if (!post.cached) {
                html += '<button class="nc-action-btn warm" onclick="ncWarmPost(\'' + escHtml(post.url) + '\', this)">☀ Warm</button> ';
            } else {
                html += '<button class="nc-action-btn purge" onclick="ncPurgePost(\'' + escHtml(post.url) + '\', this)">✕ Purge</button> ';
            }
            html += '<button class="nc-action-btn warm" onclick="ncWarmPost(\'' + escHtml(post.url) + '\', this)">☀ Warm</button>';
            html += '</td></tr>';
        });

        html += '</tbody></table></div>';
        resultsEl.innerHTML = html;

        // Pagination
        paginationEl.style.display = 'flex';
        document.getElementById('nc-page-info').textContent = 'Page ' + ncCurrentPage + ' of ' + ncTotalPages;
        document.getElementById('nc-prev').disabled = (ncCurrentPage <= 1);
        document.getElementById('nc-next').disabled = (ncCurrentPage >= ncTotalPages);
    }

    function ncPrevPage() {
        if (ncCurrentPage > 1) { ncLoadPosts(ncCurrentPage - 1); }
    }

    function ncNextPage() {
        if (ncCurrentPage < ncTotalPages) { ncLoadPosts(ncCurrentPage + 1); }
    }

    function ncLoadCachedOnly() {
        ncFilterMode = 'cached';
        ncLoadPosts(1);
    }

    function ncLoadUncachedOnly() {
        ncFilterMode = 'uncached';
        ncLoadPosts(1);
    }

    function ncFilterClear() {
        document.getElementById('nc-filter-search').value = '';
        document.getElementById('nc-filter-type').value = '';
        ncFilterMode = 'all';
        document.getElementById('nc-posts-results').innerHTML = '<p class="description">Click "Load All Pages" to see every published page/post with its cache status.</p>';
        document.getElementById('nc-pagination').style.display = 'none';
        document.getElementById('nc-result-count').textContent = '';
    }

    function ncPurgePost(url, btn) {
        if (!confirm('Purge cache for this URL?')) { return; }
        btn.disabled = true;
        btn.textContent = 'Purging...';

        var data = new FormData();
        data.append('action', 'nginx_cache_purge_url');
        data.append('url', url);

        fetch(ajaxurl, { method: 'POST', body: data })
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                if (resp.success) {
                    btn.textContent = '✓ Purged';
                    btn.style.borderColor = '#b7e0b9';
                    btn.style.color = '#216b28';
                    // Refresh after 1s
                    setTimeout(function() { ncLoadPosts(ncCurrentPage); }, 1000);
                } else {
                    alert('Failed: ' + (resp.data && resp.data.msg ? resp.data.msg : 'Unknown'));
                    btn.disabled = false;
                    btn.textContent = '✕ Purge';
                }
            })
            .catch(function() {
                alert('Request failed');
                btn.disabled = false;
                btn.textContent = '✕ Purge';
            });
    }

    function ncWarmPost(url, btn) {
        btn.disabled = true;
        btn.textContent = 'Warming...';

        var data = new FormData();
        data.append('action', 'nginx_cache_warm_url');
        data.append('url', url);

        fetch(ajaxurl, { method: 'POST', body: data })
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                if (resp.success) {
                    btn.textContent = '✓ ' + (resp.data.msg || 'Done');
                    btn.style.borderColor = '#b7e0b9';
                    btn.style.color = '#216b28';
                    setTimeout(function() { ncLoadPosts(ncCurrentPage); }, 2000);
                } else {
                    alert('Warm failed: ' + (resp.data && resp.data.msg ? resp.data.msg : 'Unknown'));
                    btn.disabled = false;
                    btn.textContent = '☀ Warm';
                }
            })
            .catch(function() {
                alert('Request failed');
                btn.disabled = false;
                btn.textContent = '☀ Warm';
            });
    }

    function nginxCacheRefresh() {
        var filesEl = document.getElementById('nc-files');
        var sizeEl = document.getElementById('nc-size');
        filesEl.textContent = '...';
        sizeEl.textContent = '...';

        var data = new FormData();
        data.append('action', 'nginx_cache_stats');
        data.append('domain', document.getElementById('nc-domain').textContent.trim());

        fetch(ajaxurl, { method: 'POST', body: data })
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                if (resp.success && resp.data) {
                    var fCount = resp.data['Files'] || '0';
                    var fTotal = resp.data['total_public'] || 0;
                    var fPct = resp.data['percent'] || 0;
                    filesEl.textContent = fCount + ' / ' + fTotal + ' (' + fPct + '%)';
                    sizeEl.textContent = resp.data['Disk usage'] || '0';
                } else {
                    filesEl.textContent = 'ERR';
                    sizeEl.textContent = 'ERR';
                }
            })
            .catch(function() {
                filesEl.textContent = 'ERR';
                sizeEl.textContent = 'ERR';
            });
    }

    function escHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function ncBulkWarm() {
        if (!confirm('This will load every uncached published page to populate the nginx cache. Continue?')) return;

        var progressEl = document.getElementById('nc-bulk-progress');
        var statusEl = document.getElementById('nc-bulk-status');
        var barEl = document.getElementById('nc-bulk-bar');
        var detailEl = document.getElementById('nc-bulk-detail');
        var domain = document.getElementById('nc-domain').textContent.trim();

        progressEl.style.display = 'block';
        statusEl.textContent = 'Gathering uncached pages...';
        barEl.value = 0;
        detailEl.textContent = '';

        var total = 0;
        var warmed = 0;

        function doBatch(batch) {
            var data = new FormData();
            data.append('action', 'nginx_cache_bulk_warm');
            data.append('domain', domain);
            data.append('batch', batch);

            fetch(ajaxurl, { method: 'POST', body: data })
                .then(function(r) { return r.json(); })
                .then(function(resp) {
                    if (resp.success && resp.data) {
                        total = resp.data.total || total;
                        warmed = resp.data.warmed_so_far || warmed;
                        var pct = total > 0 ? Math.round((Math.min(warmed, total) / total) * 100) : 0;

                        if (resp.data.done) {
                            statusEl.textContent = resp.data.msg || 'Complete!';
                            barEl.value = 100;
                            detailEl.textContent = 'Cached ' + warmed + ' of ' + total + ' pages.';
                            // Refresh the page list
                            setTimeout(function() { ncLoadPosts(1); }, 500);
                        } else {
                            statusEl.textContent = resp.data.msg || 'Warming...';
                            barEl.value = pct;
                            detailEl.textContent = warmed + ' of ' + total + ' pages warmed. Refreshing cache index...';
                            setTimeout(function() { doBatch(resp.data.batch); }, 500);
                        }

                        if (resp.data.errors && resp.data.errors.length > 0) {
                            detailEl.textContent += ' Errors: ' + resp.data.errors.join(', ');
                        }
                    } else {
                        statusEl.textContent = 'Error: ' + (resp.data && resp.data.msg ? resp.data.msg : 'Unknown');
                        barEl.value = 0;
                    }
                })
                .catch(function() {
                    statusEl.textContent = 'Network error — retrying...';
                    setTimeout(function() { doBatch(batch); }, 2000);
                });
        }

        doBatch(0);
    }

    document.addEventListener('DOMContentLoaded', nginxCacheRefresh);
    </script>
    <?php
}
