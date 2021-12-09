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
Version: 4
Author URI: http://corporateaffairs.utm.my
*/
require_once ABSPATH . 'wp-admin/includes/ms.php';
include(plugin_dir_path(__FILE__) . 'shortcodes.php');
include(plugin_dir_path(__FILE__) . 'listblogs.php');
include(plugin_dir_path(__FILE__) . 'multisite-api.php');
include(plugin_dir_path(__FILE__) . 'multisite-statistics.php');
include(plugin_dir_path(__FILE__) . 'googleanalytics.php');
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



// delete orphan users
function delete_orphan_user()
{
	global $wpdb;
	$user_name = 'deleted';
	$user_email = 'deleted@utm.my';
	$user_id = username_exists($user_name);
	if (!$user_id && email_exists($user_email) == false) {
		$random_password = wp_generate_password($length = 12, $include_standard_special_chars = false);
		$user_id = wp_create_user($user_name, $random_password, $user_email);
		echo "User deleted created<br>";
	} else {
		echo "User deleted exists<br>";
	}
	$users = $wpdb->get_results("SELECT ID, user_login FROM $wpdb->users");
	echo count($users) . " users found<br>";
	$usersarray = array();
	$i = 0;
	foreach ($users as $user) :

		$user_login = $user->user_login; // get login
		$user_id = $user->ID; // get ID

		// check for name
		if (($user_login != 'deleteduser') && ($i < count($users))) {
			$user_blogs = get_blogs_of_user($user_id); // get related sites
			// check if empty
			if (empty($user_blogs)) {
				array_push($usersarray, $user_id);
				$i += 1;
			}
		}
	endforeach;
?>
	<script>
		var sec = 0;

		function pad(val) {
			return val > 9 ? val : "0" + val;
		}
		setInterval(function() {
			jQuery("#seconds").html(pad(++sec % 60));
			jQuery("#minutes").html(pad(parseInt(sec / 60, 10)));
		}, 1000);
	</script>
	Deletion in process<br>Elapsed time: <span id="minutes"></span>:<span id="seconds"></span><br>
	No. of users delete: <span id="deletedusers">0</span>/<?php echo count($users); ?><br>
	Deleted users: <br>
	<span id="status"></span>
	<script>
		ajaxtimeout = setTimeout(function() {
			location.reload();
		}, 300000); //set timeout
		var users_info = <?php echo json_encode($usersarray); ?>;
		console.log(users_info);
		$ = jQuery;
		var each = '';
		j = 0;

		function nextAjax(i) {
			clearTimeout(ajaxtimeout); //reset timeout
			n = new Date($.now()); //set running time
			if (n.getMinutes() < 10) {
				minutes = "" + 0 + n.getMinutes();
			} else {
				minutes = n.getMinutes();
			}
			m = n.getHours() + ':' + minutes;

			var data = {
				'action': 'delete_orphan_users_ajax',
				'user_id': users_info[i]
			};
			$.ajax({
				type: 'POST',
				url: ajaxurl,
				// dataType: "json",
				async: true,
				data: data,
				success: function(response) {
					j++;
					$("#status").prepend(j + '. ' + m + ' ' + response);
					$("#deletedusers").html(j);
					nextAjax(j);
				},
				error: function(xhr, textStatus, errorThrown) {
					$("#status").prepend(j + '. ' + m + ' failed!<br>');
					j++;
					nextAjax(j);
				}
			});
		}
		if (users_info.length > 0) {
			console.log('Start')
			nextAjax(j);
		} else {
			$("#status").prepend("No orphan user available");
		}
	</script>
<?php
} // end utm webmaster user

add_action('wp_ajax_delete_orphan_users_ajax', 'delete_orphan_users_ajax');

// ajax delete orphan users
function delete_orphan_users_ajax()
{
	global $wpdb; // this is how you get access to the database

	$user_id = intval($_POST['user_id']);
	$user_info = get_userdata($user_id);
	$username = $user_info->user_login;
	$email = $user_info->user_email;
	$user = get_user_by('login', 'deleted');
	wpmu_delete_user($user_id, $user->ID); // delete user
	echo $username . " - " . $email .  "<br>";

	wp_die(); // this is required to terminate immediately and return a proper response
}

// add user to blogs
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

add_action('init', 'redirect_admin_to_own_site');
function redirect_admin_to_own_site()
{
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
}
