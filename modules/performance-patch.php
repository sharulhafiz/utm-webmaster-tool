<?php
/**
 * Performance Patch Module: Disable Excessive Rewrite Flushing.
 *
 * This module programmatically removes the `flush_rules` actions that are
 * incorrectly hooked to 'shutdown' by the Divi theme and Bloom plugin.
 * This prevents catastrophic performance degradation caused by flushing
 * rewrite rules on every single page load.
 *
 * @package     UTM-WP-Plugin
 * @version     1.0.0
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * The main initialization function for the performance patch.
 * We hook this into 'init' with a very late priority (999) to ensure that
 * the theme and all plugins have already added their hooks, allowing us to remove them.
 */
function utm_performance_patch_init() {
    // Check if the global $wp_rewrite object is set. If not, we can't do anything.
    if ( ! isset( $GLOBALS['wp_rewrite'] ) ) {
        return;
    }

    // Access the global object that holds all registered hooks.
    global $wp_filter;

    // The problematic hook is 'shutdown'.
    $hook_name = 'shutdown';

    // Check if there are any actions registered on the 'shutdown' hook.
    if ( ! isset( $wp_filter[ $hook_name ] ) ) {
        return;
    }

    // Iterate through all priority levels for the 'shutdown' hook.
    foreach ( $wp_filter[ $hook_name ]->callbacks as $priority => $callbacks ) {
        // Iterate through each registered callback at this priority level.
        foreach ( $callbacks as $callback_id => $callback_data ) {
            // The callback function is stored in the 'function' key.
            $callback = $callback_data['function'];

            // We are looking for a callback that is an array, where the first element
            // is an object of the WP_Rewrite class and the second is the string 'flush_rules'.
            if ( is_array( $callback ) && isset( $callback[0] ) && is_object( $callback[0] ) && 'WP_Rewrite' === get_class( $callback[0] ) && 'flush_rules' === $callback[1] ) {
                
                // We found it! Now we remove it using the exact same signature it was added with.
                remove_action( $hook_name, $callback, $priority );
            }
        }
    }
}

// Hook our patching function to run very late in the WordPress initialization process.
add_action( 'init', 'utm_performance_patch_init', 999 );

/**
 * Short-circuits the get_plugins() function on non-admin page loads.
 *
 * @param array $plugins Array of plugins.
 * @return array The original array in the admin, or an empty array on the front-end.
 */
function utm_hotfix_skip_get_plugins_on_frontend( $plugins ) {
    // is_admin() is the most reliable way to check if we're on a front-end page.
    // We only want to interfere on the front-end.
    if ( ! is_admin() ) {
        // By returning an empty array, we stop WordPress from scanning the disk.
        // Divi's compatibility check will see an empty list and do nothing.
        return array();
    }
    
    // In the admin area, return the plugins array untouched.
    return $plugins;
}

// The 'all_plugins' filter is applied inside get_plugins(). Hooking into it allows us to control the output.
add_filter( 'all_plugins', 'utm_hotfix_skip_get_plugins_on_frontend' );

/**
 * Initializes the patch to prevent Divi's slow glob() scan.
 */
function utm_hotfix_divi_glob_cache_init() {
    // Only run this on the front-end. It's not needed in the admin.
    if ( is_admin() ) {
        return;
    }

    // Ensure Divi's class and constant are available before we proceed.
    if ( ! class_exists( 'ET_Builder_Background_Pattern_Options' ) || ! defined( 'ET_BUILDER_DIR' ) ) {
        return;
    }

    // Define the cache key and the glob path from Divi's code.
    $transient_key = 'utm_cached_divi_pattern_files';
    $glob_path     = ET_BUILDER_DIR . 'feature/background-masks/pattern/*.php';

    // Try to get the file list from our cache.
    $cached_files = get_transient( $transient_key );

    if ( false === $cached_files ) {
        // Cache is empty, so we run the slow glob() function ONCE.
        $cached_files = glob( $glob_path );

        // If glob() fails or returns nothing, store an empty array to prevent re-scanning.
        if ( ! is_array( $cached_files ) ) {
            $cached_files = [];
        }

        // Store the result in our cache for 1 day.
        set_transient( $transient_key, $cached_files, DAY_IN_SECONDS );
    }

    try {
        // Use Reflection to access the private static property.
        $reflection_class = new ReflectionClass( 'ET_Builder_Background_Pattern_Options' );
        $settings_property = $reflection_class->getProperty( '_settings' );
        $settings_property->setAccessible( true ); // Make the private property accessible.

        // Create the initial data structure that the original code expects.
        // The thumbnail_settings() method is public and returns a static array, so we can replicate it.
        $preloaded_settings = array(
            'styles'    => $cached_files,
            'thumbnail' => array(
                'height' => '60px',
                'width'  => '80px',
            ),
        );

        // Set the static property with our pre-loaded, cached data.
        // The 'null' first argument is for setting static properties.
        $settings_property->setValue( null, $preloaded_settings );

    } catch ( ReflectionException $e ) {
        // If Reflection fails for any reason, log the error and do nothing.
        // This ensures the site doesn't crash if Divi changes their code.
        error_log( 'UTM Hotfix Error: Could not patch Divi glob(). ' . $e->getMessage() );
    }
}

// THIS IS THE FIX:
// Run the patch on 'template_redirect', which guarantees that the theme and
// all its constants (like ET_BUILDER_DIR) are fully loaded and defined.
// add_action( 'template_redirect', 'utm_hotfix_divi_glob_cache_init' );