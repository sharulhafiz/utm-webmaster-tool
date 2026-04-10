<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Load context check function
require_once __DIR__ . '/context.php';

// If this is not a news.utm.my context, stop loading the module
if (!utm_news_module_is_allowed_context()) {
    return;
}

// Load legacy module code
require_once __DIR__ . '/legacy.php';
