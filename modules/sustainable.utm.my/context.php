<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Check if sustainable module should run for current host.
 *
 * @return bool
 */
function utm_sustainable_module_is_allowed_context() {
    $host = wp_parse_url( home_url(), PHP_URL_HOST );

    return is_string( $host ) && 'sustainable.utm.my' === strtolower( $host );
}
