<?php
/*
Plugin Name: UTM Webmaster Tool
Plugin URI: https://osca.utm.my/webteam
Description: Tool for UTM Webmaster.
Author: UTM Webmaster
Network: true
Author URI: https://people.utm.my/sharulhafiz
Version: 5.31
*/
define('utm_plugin_version', '5.31');
define('utm_network_site_url', get_site_url());

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


