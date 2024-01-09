<?php
/*
Plugin Name: UTM Webmaster Tool
Plugin URI: http://digital.utm.my/web
Description: Tool for UTM Webmaster.
Author: UTM Webmaster
Network: true
Version: 5.17
Author URI: http://people.utm.my/sharulhafiz
*/
require_once ABSPATH . 'wp-admin/includes/ms.php';
include(plugin_dir_path(__FILE__) . 'shortcodes.php');
include(plugin_dir_path(__FILE__) . 'listblogs.php');
include(plugin_dir_path(__FILE__) . 'multisite-api.php');
include(plugin_dir_path(__FILE__) . 'multisite-statistics.php');
include(plugin_dir_path(__FILE__) . 'modules/googleanalytics.php');
include(plugin_dir_path(__FILE__) . 'modules/bulkdeleteuser.php');
include(plugin_dir_path(__FILE__) . 'modules/migrate-upload.php');
include(plugin_dir_path(__FILE__) . 'modules/fixuploadpath.php');
include(plugin_dir_path(__FILE__) . 'modules/fixuserrole.php');
include(plugin_dir_path(__FILE__) . 'function.php');
include(plugin_dir_path(__FILE__) . 'modules/comment_anti_spam/comment_anti_spam.php');
include(plugin_dir_path(__FILE__) . 'modules/people/redirect_to_site.php');
include(plugin_dir_path(__FILE__) . 'modules/disableplugin.php');

if (!class_exists('WP_List_Table')) {
	require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

$url = plugin_dir_path(__FILE__);
define("utm_webmaster_plugin_path", plugin_dir_path(__FILE__));
define("utm_webmaster_plugin_url", WP_PLUGIN_URL . "/" . basename($url) . "/");

// // register hook
// register_activation_hook(__FILE__, 'notice_to_single_site_wp');
// function notice_to_single_site_wp()
// {
// 	if (is_multisite() == False) {
// 		echo "FOR MULTISITE ONLY!";
// 	}
// }

// add to menu in network
function register_admin_menu()
{
	add_menu_page(
		__('UTM Webmaster Tool', 'textdomain'),
		'UTM Webmaster Tool',
		'manage_options',
		'multisite_statistics',
		'multisite_statistics',
		25
	);
	add_submenu_page('multisite_statistics', 'Orphan Users', 'Orphan Users', 'manage_options', 'delete_orphan_user', 'delete_orphan_user');
	add_submenu_page('multisite_statistics', 'Add To Blogs', 'Add To Blogs', 'manage_options', 'add_user_to_blogs', 'add_user_to_blogs');
	add_submenu_page('multisite_statistics', 'Network Admin', 'Network Admin', 'manage_options', 'change_network_admin_email', '');
	add_submenu_page('multisite_statistics', 'Disable Plugin', 'Disable Plugin', 'manage_options', 'network_deactivation_page', 'network_deactivation_page');
}
add_action('network_admin_menu', 'register_admin_menu');

// add to menu in site
function register_site_admin_menu(){
	add_submenu_page('tools.php', 'Fix User Role', 'Fix User Role', 'manage_options', 'restore_default_user_roles', 'restore_default_user_roles');
}
add_action('admin_menu', 'register_site_admin_menu');