<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Host gate for the magazine module: loads only on chancellery.utm.my
 * (admin screens load anywhere so ops can manage items centrally).
 *
 * @return bool True when the current request belongs to an allowed host.
 */
function utm_magazine_is_allowed_context() {
    if ( is_admin() || wp_doing_ajax() ) {
        return true;
    }

    $host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : '';

    return 'chancellery.utm.my' === $host;
}
