<?php

/**
 * @package utm-webmaster-tool
 * @version 4
 */
/*
Plugin Name: UTM Webmaster Tool
Plugin URI: http://corporateaffairs.utm.my
Description: Tool for UTM Webmaster.
Author: UTM Webmaster
Network: true
Version: 5.1
Author URI: http://corporateaffairs.utm.my
*/
require_once ABSPATH . 'wp-admin/includes/ms.php';
include(plugin_dir_path(__FILE__) . 'shortcodes.php');
include(plugin_dir_path(__FILE__) . 'listblogs.php');
include(plugin_dir_path(__FILE__) . 'multisite-api.php');
include(plugin_dir_path(__FILE__) . 'multisite-statistics.php');
include(plugin_dir_path(__FILE__) . '/modules/googleanalytics.php');
include(plugin_dir_path(__FILE__) . '/modules/bulkdeleteuser.php');
// include( plugin_dir_path( __FILE__ ) . 'jkncr.php');
// include_once(ABSPATH . 'wp-includes/pluggable.php');

if (!class_exists('WP_List_Table')) {
	require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

$url = plugin_dir_path(__FILE__);
define("utm_webmaster_plugin_path", plugin_dir_path(__FILE__));
define("utm_webmaster_plugin_url", WP_PLUGIN_URL . "/" . basename($url) . "/");

// register hook
register_activation_hook(__FILE__, 'notice_to_single_site_wp');
function notice_to_single_site_wp()
{
	if (is_multisite() == False) {
		echo "FOR MULTISITE ONLY!";
	}
}

// add to menu in network
function register_admin_menu()
{
	add_menu_page(
		__('UTM Webmaster Tool', 'textdomain'),
		'UTM Webmaster Tool',
		'manage_options',
		'multisite_statistics',
		'multisite_statistics',
		'',
		25
	);
	add_submenu_page('multisite_statistics', 'Orphan Users', 'Orphan Users', 'manage_options', 'delete_orphan_user', 'delete_orphan_user');
	add_submenu_page('multisite_statistics', 'Add To Blogs', 'Add To Blogs', 'manage_options', 'add_user_to_blogs', 'add_user_to_blogs');
}
add_action('network_admin_menu', 'register_admin_menu');


// Bulk add user to blogs
function add_user_to_blogs()
{
	global $wpdb;

	echo "<div class='wrap'>";
	echo "<h1>Add User to blogs</h1>";

	if (isset($_POST['username']) && isset($_POST['blogpath'])) {
		$user = get_user_by('login', $_POST['username']);
		if ($user == false) {
			echo $_POST['username'] . " not found";
		} else {
			// explode
			$blogpath = $_POST['blogpath'];
			$blogpath = explode(PHP_EOL, $blogpath);

			$blogs = $wpdb->get_results("SELECT blog_id, domain, path FROM `" . $wpdb->blogs . "` ORDER BY blog_id DESC");
			if ($blogs) {
				$blogs_info = array();
				foreach ($blogs as $blog) {
					foreach ($blogpath as $path) {
						$path = str_replace("http://", "", $path);
						$path = str_replace("https://", "", $path);
						$path = explode("/", $path);
						$path = "/" . $path[1] . "/";
						// echo $path . "<br>";
						// echo $blog->path;
						if (stripos($blog->path, $path) !== false) {
							$slug = $blog->path;
							$id = get_id_from_blogname($slug);

							//ADD USER ID TO BLOG ID AS AN ADMINISTRATOR
							$blog_id = $blog->blog_id;
							$role = 'administrator';
							add_user_to_blog($blog_id, $user->ID, $role);
							$url = get_site_url($blog->blog_id);
							echo $user->user_login . " added to " . $url . "<br>";
						} else {
							// echo "error<br>";
						}
					}
					// break;
				}
			}
		}
	}
?>
	<form action="" id="adduser" method="post" novalidate="novalidate">
		<table class="form-table">
			<tbody>
				<tr class="form-field form-required">
					<th scope="row"><label for="username">Username</label></th>
					<td><input type="text" class="regular-text" name="username" id="username" autocapitalize="none" autocorrect="off" maxlength="60" value="<?php echo $_POST['username']; ?>"></td>
				</tr>
				<tr class="form-field form-required">
					<th scope="row"><label for="blogpath">Blog Path</label></th>
					<td><textarea class="regular-text" name="blogpath" id="blogpath" rows="10" cols="30"><?php echo $_POST['blogpath']; ?></textarea><br></td>
				</tr>
				<tr class="form-field">
					<td colspan="2">User will be added to the founded blogs.</td>
				</tr>
			</tbody>
		</table>
		<p class="submit"><input type="submit" name="add-user" id="add-user" class="button button-primary" value="Add User"></p>
	</form>
<?php
	echo "</div>";
}

// Redirect users to their own blog
add_action('init',  function(){
	$currentblogid = get_current_blog_id();
	$user_id = get_current_user_id(); // get ID
	$user_blogs = get_blogs_of_user($user_id);
	if (is_super_admin() != true) {
		if ($currentblogid == 1) {
			if (is_admin()) {
				foreach ($user_blogs as $blog) {
					wp_redirect($blog->siteurl);
				}
			}
		}
	}
});
