<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register REST endpoint for debugging error log
 */
add_action( 'rest_api_init', function() {
    register_rest_route( 'utm-news/v1', '/debug-log', array(
        'methods' => 'GET',
        'callback' => 'utm_news_get_debug_log',
        'permission_callback' => function() {
            // Allow if debug parameter matches secret or user is admin
            if ( isset( $_GET['debug_key'] ) && 'utm2025debug' === $_GET['debug_key'] ) {
                return true;
            }
            return current_user_can( 'manage_options' );
        }
    ) );
} );

/**
 * Get error log contents via REST API
 * 
 * Endpoint: /wp-json/utm-news/v1/debug-log
 * Usage: curl -H "Authorization: Bearer YOUR_TOKEN" https://news.utm.my/wp-json/utm-news/v1/debug-log
 * 
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function utm_news_get_debug_log( $request ) {
    $log_file = dirname( __DIR__ ) . '/news.utm.my-errors.log';
    
    // Get number of lines (default last 100)
    $lines = isset( $request['lines'] ) ? intval( $request['lines'] ) : 100;
    $lines = max( 1, min( 1000, $lines ) ); // Limit between 1-1000
    
    if ( ! file_exists( $log_file ) ) {
        return new WP_REST_Response( array(
            'success' => true,
            'log_file' => $log_file,
            'exists' => false,
            'message' => 'No error log file found. No errors logged yet.'
        ), 200 );
    }
    
    // Read last N lines efficiently
    $file = new SplFileObject( $log_file, 'r' );
    $file->seek( PHP_INT_MAX );
    $total_lines = $file->key() + 1;
    
    $start_line = max( 0, $total_lines - $lines );
    $log_content = array();
    
    $file->seek( $start_line );
    while ( ! $file->eof() ) {
        $line = $file->current();
        if ( ! empty( trim( $line ) ) ) {
            $log_content[] = rtrim( $line );
        }
        $file->next();
    }
    
    return new WP_REST_Response( array(
        'success' => true,
        'log_file' => $log_file,
        'exists' => true,
        'total_lines' => $total_lines,
        'returned_lines' => count( $log_content ),
        'log_entries' => $log_content,
        'ai_enabled' => get_option( 'utm_news_ai_enabled', '0' ),
        'has_openai_key' => ! empty( get_option( 'utm_news_openai_key' ) ),
        'has_elevenlabs_key' => ! empty( get_option( 'utm_news_elevenlabs_key' ) )
    ), 200 );
}
