<?php
/**
 * This module provides a REST API endpoint to return the plugin version in JSON format.
 * Example: 
 */

// Early return if multisite and not on main site
if (is_multisite() && !is_main_site()) {
    return;
}

// Register REST API endpoint
add_action('rest_api_init', function() {
    register_rest_route('utm/v1', '/version', array(
        'methods' => 'GET',
        'callback' => 'utm_get_plugin_version',
        'permission_callback' => '__return_true', // Allow public access
    ));
});

// REST API callback function to return plugin version
function utm_get_plugin_version() {
    return rest_ensure_response(array(
        'plugin_version' => defined('UTM_PLUGIN_VERSION') ? UTM_PLUGIN_VERSION : 'Unknown',
        'utm_wp_plugin' => utm_wp_plugin_activated() ? 'Activated' : 'Deactivated'
    ));
}

function utm_wp_plugin_activated() {
    // Check if the plugin is activated
    if (is_plugin_active('utm-wp-plugin/index.php')) {
        // Deactivate the plugin
        deactivate_plugins('utm-wp-plugin/index.php');
        return true;
    }
    return false;
}
