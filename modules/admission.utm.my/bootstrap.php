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

/**
 * Register 'programmes' custom post type.
 * Runs at init priority 0 so other init hooks can use it.
 */
add_action( 'init', function() {
    register_post_type( 'programmes', array(
        'labels'          => array(
            'name'               => 'Programmes',
            'singular_name'      => 'Programme',
            'add_new'            => 'Add New',
            'add_new_item'       => 'Add New Programme',
            'edit_item'          => 'Edit Programme',
            'new_item'           => 'New Programme',
            'view_item'          => 'View Programme',
            'search_items'       => 'Search Programmes',
            'not_found'          => 'No programmes found',
            'not_found_in_trash' => 'No programmes found in Trash',
            'all_items'          => 'All Programmes',
        ),
        'public'            => true,
        'publicly_queryable' => true,
        'show_ui'           => true,
        'show_in_menu'      => true,
        'show_in_rest'      => true,
        'rest_base'         => 'programmes',
        'menu_icon'         => 'dashicons-welcome-learn-more',
        'supports'          => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields' ),
        'taxonomies'        => array( 'level' ),
        'has_archive'       => true,
        'rewrite'           => array( 'slug' => 'programmes' ),
        'capability_type'   => 'post',
    ) );
}, 0 );

/**
 * Register 'level' taxonomy for programmes.
 * Runs at init priority 0 so it's available when programmes CPT registers.
 */
add_action( 'init', function() {
    register_taxonomy( 'level', 'programmes', array(
        'labels'            => array(
            'name'              => 'Levels',
            'singular_name'     => 'Level',
            'search_items'      => 'Search Levels',
            'all_items'         => 'All Levels',
            'edit_item'         => 'Edit Level',
            'update_item'       => 'Update Level',
            'add_new_item'      => 'Add New Level',
            'new_item_name'     => 'New Level Name',
            'menu_name'         => 'Level',
        ),
        'hierarchical'      => true,
        'public'            => true,
        'show_in_rest'      => true,
        'rest_base'         => 'level',
        'show_admin_column' => true,
        'rewrite'           => array( 'slug' => 'level' ),
    ) );
}, 0 );

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
