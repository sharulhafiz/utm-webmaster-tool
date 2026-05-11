<?php
/**
 * Heartbeat module.
 *
 * Exposes a lightweight REST API endpoint that returns the current plugin
 * version and the activation status of the companion `utm-wp-plugin`.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Early return if multisite and not on main site
if ( is_multisite() && ! is_main_site() ) {
    return;
}

// Register REST API endpoint
add_action(
    'rest_api_init',
    function() {
        register_rest_route(
            'utm/v1',
            '/version',
            array(
                'methods'             => 'GET',
                'callback'            => 'utm_get_plugin_version',
                'permission_callback' => '__return_true',
            )
        );
    }
);

// REST API callback function to return plugin version
function utm_get_plugin_version() {
    return rest_ensure_response(
        array(
            'plugin_version' => defined( 'UTM_PLUGIN_VERSION' ) ? UTM_PLUGIN_VERSION : 'Unknown',
            'utm_wp_plugin'  => utm_get_utm_wp_plugin_status(),
            'generated_at'   => gmdate( 'c' ),
        )
    );
}

/**
 * Return the activation status of the companion plugin without mutating state.
 *
 * @return string
 */
function utm_get_utm_wp_plugin_status() {
    if ( ! function_exists( 'is_plugin_active' ) ) {
        $plugin_file = ABSPATH . 'wp-admin/includes/plugin.php';

        if ( file_exists( $plugin_file ) ) {
            require_once $plugin_file;
        }
    }

    if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'utm-wp-plugin/index.php' ) ) {
        return 'Activated';
    }

    return 'Deactivated';
}
