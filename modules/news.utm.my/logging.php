<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Write errors to modules/news.utm.my-errors.log
 * 
 * @param string $message Error message to log
 */
function utm_news_log_error( $message ) {
    $log_file = dirname( __DIR__ ) . '/news.utm.my-errors.log';
    $timestamp = date( 'Y-m-d H:i:s' );
    $log_entry = "[{$timestamp}] ERROR: {$message}\n";
    
    $result = @file_put_contents( $log_file, $log_entry, FILE_APPEND | LOCK_EX );
    if ( $result === false ) {
        error_log( 'UTM News: Failed to write to error log' );
    }
}
