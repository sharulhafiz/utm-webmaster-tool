<?php
// Last updated: 22 Dec 2025
// Compatibility shim - all functionality moved to modules/news.utm.my/ for modular architecture

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Load the modular implementation from bootstrap
require_once __DIR__ . '/news.utm.my/bootstrap.php';
