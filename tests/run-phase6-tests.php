<?php
/**
 * Test runner for Phase 6 - Audio Playlist Widget
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    // Try to find WordPress
    $wp_load_paths = array(
        dirname(dirname(__FILE__)) . '/wp-load.php',
        dirname(dirname(dirname(__FILE__))) . '/wp-load.php',
        dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-load.php',
    );
    
    $found = false;
    foreach ($wp_load_paths as $path) {
        if (file_exists($path)) {
            require_once($path);
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        die('Could not find wp-load.php');
    }
}

// Include test file
require_once(__DIR__ . '/test-phase6-audio-playlist-widget.php');
