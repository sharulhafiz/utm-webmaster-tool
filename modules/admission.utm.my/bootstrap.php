<?php
/**
 * Admission Module Bootstrap
 *
 * Loads admission module features only when context is admission.utm.my.
 * This is the package bootstrap for the admission.utm.my module.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load context validation.
require_once dirname( __FILE__ ) . '/context.php';

// Return early if not in allowed context.
if ( ! utm_admission_is_allowed_context() ) {
	return;
}

// Load legacy compatibility modules.
require_once dirname( dirname( __FILE__ ) ) . '/admission.utm.my-programmes-filter.php';
require_once dirname( dirname( __FILE__ ) ) . '/admission.utm.my-programmes-import.php';

/**
 * Override template for 'programmes' custom post type.
 *
 * Uses a custom template from the plugin's templates/ directory
 * instead of the Divi Theme Builder body layout.
 * Header and footer are still loaded via get_header()/get_footer().
 */
add_filter( 'template_include', function( $template ) {
    if ( is_singular( 'programmes' ) ) {
        $plugin_template = dirname( dirname( dirname( __FILE__ ) ) ) . '/templates/single-programmes.php';
        if ( file_exists( $plugin_template ) ) {
            return $plugin_template;
        }
    }
    return $template;
}, 99 );
