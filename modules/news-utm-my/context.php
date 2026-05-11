<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check if the current request is allowed for news.utm.my module
 * 
 * The news module only runs on news.utm.my host to avoid loading
 * extensive news-specific features on other sites in the network.
 * 
 * @return bool True if HTTP_HOST is 'news.utm.my', false otherwise
 */
function utm_news_module_is_allowed_context() {
    $utm_http_host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
    return $utm_http_host === 'news.utm.my';
}
