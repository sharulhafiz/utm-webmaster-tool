<?php
/**
 * Admission Module Context Validation
 *
 * Determines if current request context is allowed to load admission module features.
 * Admission module features are restricted to the admission.utm.my host.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if current context is allowed for admission module.
 *
 * The admission module is restricted to admission.utm.my host.
 *
 * @return bool
 */
function utm_admission_is_allowed_context() {
	$home_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

	$host = '';
	if ( isset( $_SERVER['HTTP_HOST'] ) ) {
		$host = strtolower( (string) wp_unslash( $_SERVER['HTTP_HOST'] ) );
	} elseif ( is_string( $home_host ) ) {
		$host = strtolower( $home_host );
	}

	return 'admission.utm.my' === $host;
}
