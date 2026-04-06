<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/context.php';

if ( ! utm_news_module_is_allowed_context() ) {
    return;
}

// Feature modules - loaded in dependency order
require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/legacy-import.php';
require_once __DIR__ . '/admin-settings.php';
require_once __DIR__ . '/ai-services.php';
require_once __DIR__ . '/frontend-content.php';
require_once __DIR__ . '/admin-regeneration.php';
require_once __DIR__ . '/widget-audio-playlist.php';
require_once __DIR__ . '/rest-debug.php';
require_once __DIR__ . '/policy-editorial.php';
