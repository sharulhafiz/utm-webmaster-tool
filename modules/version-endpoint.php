<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Public version endpoint for rollout verification.
 *
 * Route: GET /wp-json/utm-webmaster/v1/version
 *
 * @return array
 */
function utm_webmaster_rest_version() {
    return array(
        'ok'             => true,
        'plugin'         => 'utm-webmaster-tool',
        'version'        => defined( 'UTM_PLUGIN_VERSION' ) ? UTM_PLUGIN_VERSION : null,
        'plugin_version'  => defined( 'UTM_PLUGIN_VERSION' ) ? UTM_PLUGIN_VERSION : null,
        'site_url'       => get_site_url(),
        'generated_at'   => current_time( 'mysql' ),
    );
}

/**
 * Register REST route for plugin version.
 *
 * @return void
 */
function utm_webmaster_register_version_route() {
    register_rest_route(
        'utm-webmaster/v1',
        '/version',
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'utm_webmaster_rest_version',
            'permission_callback' => '__return_true',
        )
    );
}
add_action( 'rest_api_init', 'utm_webmaster_register_version_route' );
