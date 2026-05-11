<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/context.php';

if ( ! utm_sustainable_module_is_allowed_context() ) {
    return;
}

require_once __DIR__ . '/content-transform.php';
require_once __DIR__ . '/sync-service.php';
require_once __DIR__ . '/menu-service.php';
require_once __DIR__ . '/rest.php';
