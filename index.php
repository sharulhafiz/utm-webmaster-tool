<?php
/*
Plugin Name: UTM Webmaster Tool
Plugin URI: https://corporate.utm.my/webteam
Description: Tool for UTM Webmaster.
Author: UTM Webmaster
Network: true
Author URI: https://people.utm.my/sharulhafiz
Version: 5.44
*/

// Exit if accessed directly for security.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define basic constants. These are fine as they are static.
define( 'UTM_PLUGIN_VERSION', '5.44' );
define( 'UTM_WEBMASTER_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'UTM_WEBMASTER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Function to run tasks ONLY ONCE upon plugin activation.
 * This is the correct place for cleanup and setup.
 */
function utm_plugin_activation_hook() {
    $modules_dir = UTM_WEBMASTER_PLUGIN_PATH . 'modules/';
    
    // --- Self-cleanup logic moved here ---
    $files_to_remove = ['smtp.php','fixuploadpath-copy.php','generate_ics.php','visitor_manager.php','notes.txt','deletecomments.php','allinonemigration.php','update.php','nlp-to-ics.php'];
    $folders_to_remove = ['comment_anti_spam'];

    // Remove obsolete folders
    foreach ( $folders_to_remove as $folder ) {
        $folder_path = $modules_dir . $folder;
        if ( is_dir( $folder_path ) ) {
            $files = glob( $folder_path . '/{,.}*', GLOB_BRACE );
            foreach ( $files as $file ) {
                if ( is_file( $file ) ) {
                    unlink( $file );
                }
            }
            rmdir( $folder_path );
        }
    }

    // Remove obsolete files
    foreach ( $files_to_remove as $file ) {
        if ( file_exists( $modules_dir . $file ) ) {
            unlink( $modules_dir . $file );
        }
    }
}
// Register the activation hook.
register_activation_hook( __FILE__, 'utm_plugin_activation_hook' );

/**
 * Return all plugin module slugs in load order.
 *
 * @return array
 */
function utm_get_all_module_slugs() {
    return array(
        'dashboard',
        'analytics',
        'antispam',
        'backup',
        'brokenlink',
        'bulk-add-user',
        'bulkdeleteuser',
        'cache-monitor',
        'chatbot',
        'content-visibility-shortcodes',
        'delete_et_cache_divi',
        'disableplugin',
        'debug',
        'dotx-upload-compat',
        'embed_googledocs',
        'events',
        'fixuploadpath',
        'fixuserrole',
        'formidableforms',
        'function',
        'gdocsImport',
        'googleanalytics',
        'heartbeat',
        'listblogs',
        'mail',
        'migrate-upload',
        'multisite-api',
        'multisite-statistics',
        'news.utm.my',
        'people.utm.my',
        'performance-patch',
        'popup-ads',
        'postExport',
        'profile-photo',
        'protected-content',
        'registrar',
        'seo',
        'shortcodes',
        'sso',
        'staffapi',
        'support.utm.my',
        'timezone',
        'updatenetworkadminemail',
        'usermeta',
        'utmlenses',
        'utm-news-import',
        'disable-rss-feeds',
        'divi-redis-cache',
        'admission.utm.my-programmes-filter',
    );
}

/**
 * Return protected module slugs that cannot be disabled.
 *
 * @return array
 */
function utm_get_protected_module_slugs() {
    return array(
        'dashboard',
        'function',
        'mail',
        'performance-patch',
        'sso',
        'timezone',
    );
}

/**
 * Get persisted module states.
 *
 * @return array
 */
function utm_get_module_states() {
    $all_modules = utm_get_all_module_slugs();
    $states = get_site_option( 'utm_webmaster_module_states', array() );

    if ( ! is_array( $states ) ) {
        $states = array();
    }

    // Default every known module to active.
    foreach ( $all_modules as $module_slug ) {
        if ( ! array_key_exists( $module_slug, $states ) ) {
            $states[ $module_slug ] = 1;
        }
    }

    // Protected modules are always active.
    foreach ( utm_get_protected_module_slugs() as $protected_module ) {
        $states[ $protected_module ] = 1;
    }

    return $states;
}

/**
 * Determine whether a module is active.
 *
 * @param string $module_slug Module slug.
 * @return bool
 */
function utm_is_module_active( $module_slug ) {
    $states = utm_get_module_states();

    return ! empty( $states[ $module_slug ] );
}

/**
 * Persist module active/inactive state.
 *
 * @param string $module_slug Module slug.
 * @param bool   $is_active   Active flag.
 * @return bool
 */
function utm_set_module_state( $module_slug, $is_active ) {
    $all_modules = utm_get_all_module_slugs();

    if ( ! in_array( $module_slug, $all_modules, true ) ) {
        return false;
    }

    if ( in_array( $module_slug, utm_get_protected_module_slugs(), true ) ) {
        return false;
    }

    $states = utm_get_module_states();
    $desired_state = $is_active ? 1 : 0;

    if ( isset( $states[ $module_slug ] ) && (int) $states[ $module_slug ] === $desired_state ) {
        return true;
    }

    $states[ $module_slug ] = $is_active ? 1 : 0;

    return update_site_option( 'utm_webmaster_module_states', $states );
}


/**
 * Load all plugin modules.
 * For better performance, avoid glob() and list files explicitly.
 */
function utm_load_modules() {
    $modules_dir = UTM_WEBMASTER_PLUGIN_PATH . 'modules/';
    $modules = utm_get_all_module_slugs();

    foreach ( $modules as $module ) {
        if ( ! utm_is_module_active( $module ) ) {
            continue;
        }

        $file = $modules_dir . $module . '.php';
        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }

    // Now it's safe to load files that depend on WordPress core.
    if ( ! class_exists( 'WP_List_Table' ) ) {
        require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
    }

    // Define the dynamic site URL constant here, after WordPress has loaded.
    if ( ! defined( 'UTM_NETWORK_SITE_URL' ) ) {
        define( 'UTM_NETWORK_SITE_URL', get_site_url() );
    }
}
// Use the 'plugins_loaded' hook to ensure all plugins are loaded before your modules.
add_action( 'plugins_loaded', 'utm_load_modules' );
