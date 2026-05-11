<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Determine whether the news module should run for current host.
 *
 * @return bool
 */
function utm_news_module_is_allowed_context() {
    $host = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '';

    return 'news.utm.my' === $host;
}
