<?php
/*
Plugin Name: UTM Webmaster Tool
Plugin URI: http://osca.utm.my/webteam
Description: Tool for UTM Webmaster.
Author: UTM Webmaster
Network: true
Author URI: http://people.utm.my/sharulhafiz
Version: 5.27
*/
define('utm_plugin_version', '5.27');
define('utm_network_site_url', get_site_url());

require_once ABSPATH . 'wp-admin/includes/ms.php';

$modules_dir = plugin_dir_path(__FILE__) . 'modules/';
$files_to_remove = ['smtp.php','fixuploadpath-copy.php','generate_ics.php','visitor_manager.php','notes.txt','deletecomments.php','allinonemigration.php','update.php','nlp-to-ics.php'];
$folders_to_remove = ['comment_anti_spam'];

// remove folder in $folders_to_remove
foreach ($folders_to_remove as $folder) {
	if (is_dir($modules_dir . $folder)) {
		// remove all files in the folder, including hidden files
		$files = glob($modules_dir . $folder . '/{,.}*', GLOB_BRACE);
		foreach ($files as $file) {
			if (is_file($file)) {
				unlink($file);
			}
		}
		// remove the folder
		rmdir($modules_dir . $folder);
	}
}

// remove file in $files_to_remove
foreach ($files_to_remove as $file) {
	if (file_exists($modules_dir . $file)) {
		unlink($modules_dir . $file);
	}
}

foreach (glob($modules_dir . '*.php') as $file) {
    include_once($file);
}

if (!class_exists('WP_List_Table')) {
	require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

$url = plugin_dir_path(__FILE__);
// if not defined, define it
if (!defined("utm_webmaster_plugin_path")) define("utm_webmaster_plugin_path", plugin_dir_path(__FILE__));
if (!defined("utm_webmaster_plugin_url")) define("utm_webmaster_plugin_url", WP_PLUGIN_URL . "/" . basename($url) . "/");

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
}
add_action('network_admin_menu', 'utm_register_admin_menu');
