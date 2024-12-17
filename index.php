<?php
/*
Plugin Name: UTM Webmaster Tool
Plugin URI: http://osca.utm.my/webteam
Description: Tool for UTM Webmaster.
Author: UTM Webmaster
Network: true
Version: 5.24
Author URI: http://people.utm.my/sharulhafiz
*/
define('utm_plugin_version', '5.25');
define('utm_network_site_url', get_site_url());

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
// include(plugin_dir_path(__FILE__) . 'modules/comment_anti_spam/comment_anti_spam.php');
include(plugin_dir_path(__FILE__) . 'modules/people/redirect_to_site.php');
include(plugin_dir_path(__FILE__) . 'modules/disableplugin.php');
include(plugin_dir_path(__FILE__) . 'modules/staffapi.php'); // only load on registrar.utm.my domain
include(plugin_dir_path(__FILE__) . 'modules/bulk-add-user.php');
include_once(plugin_dir_path(__FILE__) . 'modules/postExport.php'); // Export post to csv - 18 March 2024
include_once(plugin_dir_path(__FILE__) . 'modules/nlp-to-ics.php'); // Convert natural text into .ics file
include_once(plugin_dir_path(__FILE__) . 'modules/content-visibility-shortcodes.php'); // show content based on user - 12/8/2024
// include_once(plugin_dir_path(__FILE__) . 'modules/popup-ads.php'); // Popup ads - 13 May 2024
include_once(plugin_dir_path(__FILE__) . 'modules/api_tester.php'); // Check plugin version - 3 Oct 2024
include_once(plugin_dir_path(__FILE__) . 'modules/smtp.php'); // GMAIL SMPT - 22 Oct 2024
include_once(plugin_dir_path(__FILE__) . 'modules/loginlogger.php'); // Login logger - 10 Nov 2024
include_once(plugin_dir_path(__FILE__) . 'modules/registrar.php'); // Registrar code - 20 Nov 2024
include_once(plugin_dir_path(__FILE__) . 'modules/heartbeat.php'); // Heartbeat - 12 Dec 2024

if (!class_exists('WP_List_Table')) {
	require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

$url = plugin_dir_path(__FILE__);
// if not defined, define it
if (!defined("utm_webmaster_plugin_path")) define("utm_webmaster_plugin_path", plugin_dir_path(__FILE__));
if (!defined("utm_webmaster_plugin_url")) define("utm_webmaster_plugin_url", WP_PLUGIN_URL . "/" . basename($url) . "/");

// // register hook
// register_activation_hook(__FILE__, 'notice_to_single_site_wp');
// function notice_to_single_site_wp()
// {
// 	if (is_multisite() == False) {
// 		echo "FOR MULTISITE ONLY!";
// 	}
// }

// add to menu in network
function utm_register_admin_menu()
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
	// a page that fetch a list of api endpoint and display response
	add_submenu_page('multisite_statistics', 'API Tester', 'API Tester', 'manage_options', 'api_tester', 'api_tester');
}
add_action('network_admin_menu', 'utm_register_admin_menu');
