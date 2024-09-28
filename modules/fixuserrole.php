<?php
// ==================== Fix User Role ====================
// add to menu in site
function register_fixUserRole_admin_menu(){
	add_submenu_page('users.php', 'Fix User Role', 'Fix User Role', 'manage_options', 'restore_default_user_roles', 'restore_default_user_roles');
}
add_action('admin_menu', 'register_fixUserRole_admin_menu');

function restore_default_user_roles()
{
	global $wpdb;
	$site_id = null;

	echo "<h1>Fix User Role</h1>";

	// Get current page url without query parameters
	$url = strtok($_SERVER['REQUEST_URI'], '?');

	// if GET site_id is set, use it
	if (isset($_GET['site_id'])) {
		$site_id = $_GET['site_id'];
	} else {
		// Get the site's ID
		$site_id = get_current_blog_id();
	}

	// Make the input field readonly if site ID is not main site
	$readonly = $site_id != 1 ? "readonly" : "";

	// Create input form
	echo "<form action='". $url . "' method='get'>";
	echo "<input type='hidden' name='page' value='restore_default_user_roles' />";
	echo "<label for='site_id'>Site ID: </label>";
	echo "<input type='text' name='site_id' value='$site_id' $readonly/>";
	echo "<input type='submit' value='Fix user role this site' />";
	echo "</form>";

	// If the form has not been submitted, stop the script
	if (!isset($_GET['site_id'])) {
		// Compare current user's role with the default roles
		restore_default_wp_roles();

		return;
	}

	// switch to the site
	if ($site_id !== null) {
		switch_to_blog($site_id);
	}

	echo "Option table: $wpdb->options<br>";

	// Locate existing user_roles
	$user_roles = $wpdb->get_results("SELECT option_name FROM `" . $wpdb->options . "` WHERE option_name LIKE '%user_roles'");
	echo "List of user_roles: ";
	foreach ($user_roles as $user_role) {
		echo $user_role->option_name. ", ";
	}

	$table_prefix = $wpdb->get_blog_prefix($site_id);
	echo "<br>Table prefix: $table_prefix<br>";

    // Construct the option name dynamically
    $roles_option_name = $table_prefix . 'user_roles';
	echo "Roles option name: $roles_option_name<br>";

	// Check if $user_roles is like $roles_option_name
	$option_exists = false;
	foreach ($user_roles as $user_role) {
		if ($user_role->option_name == $roles_option_name) {
			$option_exists = true;
			echo "Option $roles_option_name exists<br>";
			// view values
			$user_roles_value = get_option($roles_option_name);
			echo "<pre>";
			print_r($user_roles_value);
			echo "</pre>";

		}
	}

	// load default roles
	$default_roles = 'a:5:{s:13:"administrator";a:2:{s:4:"name";s:13:"Administrator";s:12:"capabilities";a:61:{s:13:"switch_themes";b:1;s:11:"edit_themes";b:1;s:16:"activate_plugins";b:1;s:12:"edit_plugins";b:1;s:10:"edit_users";b:1;s:10:"edit_files";b:1;s:14:"manage_options";b:1;s:17:"moderate_comments";b:1;s:17:"manage_categories";b:1;s:12:"manage_links";b:1;s:12:"upload_files";b:1;s:6:"import";b:1;s:15:"unfiltered_html";b:1;s:10:"edit_posts";b:1;s:17:"edit_others_posts";b:1;s:20:"edit_published_posts";b:1;s:13:"publish_posts";b:1;s:10:"edit_pages";b:1;s:4:"read";b:1;s:8:"level_10";b:1;s:7:"level_9";b:1;s:7:"level_8";b:1;s:7:"level_7";b:1;s:7:"level_6";b:1;s:7:"level_5";b:1;s:7:"level_4";b:1;s:7:"level_3";b:1;s:7:"level_2";b:1;s:7:"level_1";b:1;s:7:"level_0";b:1;s:17:"edit_others_pages";b:1;s:20:"edit_published_pages";b:1;s:13:"publish_pages";b:1;s:12:"delete_pages";b:1;s:19:"delete_others_pages";b:1;s:22:"delete_published_pages";b:1;s:12:"delete_posts";b:1;s:19:"delete_others_posts";b:1;s:22:"delete_published_posts";b:1;s:20:"delete_private_posts";b:1;s:18:"edit_private_posts";b:1;s:18:"read_private_posts";b:1;s:20:"delete_private_pages";b:1;s:18:"edit_private_pages";b:1;s:18:"read_private_pages";b:1;s:12:"delete_users";b:1;s:12:"create_users";b:1;s:17:"unfiltered_upload";b:1;s:14:"edit_dashboard";b:1;s:14:"update_plugins";b:1;s:14:"delete_plugins";b:1;s:15:"install_plugins";b:1;s:13:"update_themes";b:1;s:14:"install_themes";b:1;s:11:"update_core";b:1;s:10:"list_users";b:1;s:12:"remove_users";b:1;s:13:"promote_users";b:1;s:18:"edit_theme_options";b:1;s:13:"delete_themes";b:1;s:6:"export";b:1;}}s:6:"editor";a:2:{s:4:"name";s:6:"Editor";s:12:"capabilities";a:34:{s:17:"moderate_comments";b:1;s:17:"manage_categories";b:1;s:12:"manage_links";b:1;s:12:"upload_files";b:1;s:15:"unfiltered_html";b:1;s:10:"edit_posts";b:1;s:17:"edit_others_posts";b:1;s:20:"edit_published_posts";b:1;s:13:"publish_posts";b:1;s:10:"edit_pages";b:1;s:4:"read";b:1;s:7:"level_7";b:1;s:7:"level_6";b:1;s:7:"level_5";b:1;s:7:"level_4";b:1;s:7:"level_3";b:1;s:7:"level_2";b:1;s:7:"level_1";b:1;s:7:"level_0";b:1;s:17:"edit_others_pages";b:1;s:20:"edit_published_pages";b:1;s:13:"publish_pages";b:1;s:12:"delete_pages";b:1;s:19:"delete_others_pages";b:1;s:22:"delete_published_pages";b:1;s:12:"delete_posts";b:1;s:19:"delete_others_posts";b:1;s:22:"delete_published_posts";b:1;s:20:"delete_private_posts";b:1;s:18:"edit_private_posts";b:1;s:18:"read_private_posts";b:1;s:20:"delete_private_pages";b:1;s:18:"edit_private_pages";b:1;s:18:"read_private_pages";b:1;}}s:6:"author";a:2:{s:4:"name";s:6:"Author";s:12:"capabilities";a:10:{s:12:"upload_files";b:1;s:10:"edit_posts";b:1;s:20:"edit_published_posts";b:1;s:13:"publish_posts";b:1;s:4:"read";b:1;s:7:"level_2";b:1;s:7:"level_1";b:1;s:7:"level_0";b:1;s:12:"delete_posts";b:1;s:22:"delete_published_posts";b:1;}}s:11:"contributor";a:2:{s:4:"name";s:11:"Contributor";s:12:"capabilities";a:5:{s:10:"edit_posts";b:1;s:4:"read";b:1;s:7:"level_1";b:1;s:7:"level_0";b:1;s:12:"delete_posts";b:1;}}s:10:"subscriber";a:2:{s:4:"name";s:10:"Subscriber";s:12:"capabilities";a:2:{s:4:"read";b:1;s:7:"level_0";b:1;}}}';
	
	if (!$option_exists) {
		echo "Option $roles_option_name does not exist<br>";

		// Add option
		$wpdb->insert($wpdb->options, array('option_name' => $roles_option_name, 'option_value' => $default_roles));
		echo "Added option $roles_option_name<br>";
		echo "User roles is fixed.<br>";
	} else {
		// update option
		$wpdb->update($wpdb->options, array('option_value' => $default_roles), array('option_name' => $roles_option_name));
		echo "Option $roles_option_name updated with default roles<br>";
	}

	// Return to current blog
	restore_current_blog();

	// If there is an option with the correct prefix, delete others
	// if ($option_exists) {
	// 	foreach ($user_roles as $user_role) {
	// 		if ($user_role->option_name !== $roles_option_name) {
	// 			$wpdb->delete($wpdb->options, array('option_name' => $user_role->option_name));
	// 			echo "Deleted option ". $user_role->option_name. "<br>";
	// 		}
	// 	}
	// }

	// // If $user_roles are more than 1 and $option_exists is false, rename the first option to $correct_option_name
	// if (count($user_roles) > 1 && !$option_exists) {
	// 	$wpdb->update($wpdb->options, array('option_name' => $roles_option_name), array('option_name' => $user_roles[0]->option_name));
	// 	echo "Renamed option ". $user_roles[0]->option_name. " to $roles_option_name<br>";
	// 	// delete others
	// 	for ($i = 1; $i < count($user_roles); $i++) {
	// 		$wpdb->delete($wpdb->options, array('option_name' => $user_roles[$i]->option_name));
	// 		echo "Deleted option ". $user_roles[$i]->option_name. "<br>";
	// 	}
	// }

    // Get the current roles
	// $current_roles = get_option($roles_option_name);

	// // Check if the current roles differ from the default roles
	// if ($current_roles !== $default_roles) {
	// 	// Update the roles
	// 	$role_update = update_option($roles_option_name, $default_roles);
	// 	if ($role_update) {
	// 		echo "User roles fixed.<br>";
	// 	} else {
	// 		echo "User roles fix failed.";
	// 	}
	// }

	

    // Delete all authors
    // Link to delete all authors if GET parameter is set
    if (isset($_GET['delete_role'])) {
        $role = $_GET['delete_role'];
        delete_role($role);
    } else {
        echo "Delete users: ";
        echo "<a href='?page=restore_default_user_roles&delete_role=author'>Authors</a> | ";
        echo "<a href='?page=restore_default_user_roles&delete_role=subscriber'>Subscribers</a>";
    }
}

function restore_default_wp_roles()
{
	// Administrator role
	if (!get_role('administrator')) {
		add_role('administrator', 'Administrator', array(
			'update_core' => true,
			'update_plugins' => true,
			'update_themes' => true,
			'install_plugins' => true,
			'install_themes' => true,
			'delete_themes' => true,
			'delete_plugins' => true,
			'edit_plugins' => true,
			'edit_themes' => true,
			'edit_files' => true,
			'manage_options' => true,
			'moderate_comments' => true,
			'manage_categories' => true,
			'manage_links' => true,
			'edit_others_posts' => true,
			'edit_pages' => true,
			'edit_others_pages' => true,
			'edit_published_pages' => true,
			'publish_pages' => true,
			'delete_pages' => true,
			'delete_others_pages' => true,
			'delete_published_pages' => true,
			'delete_others_posts' => true,
			'delete_private_posts' => true,
			'edit_private_posts' => true,
			'read_private_posts' => true,
			'delete_private_pages' => true,
			'edit_private_pages' => true,
			'read_private_pages' => true,
			'unfiltered_html' => true,
			'edit_dashboard' => true,
			'customize' => true,
			'delete_site' => true,
			'create_users' => true,
			'delete_users' => true,
			'edit_users' => true,
			'list_users' => true,
			'manage_network_plugins' => true,
			'manage_sites' => true,
			'manage_network_users' => true,
			'manage_network_themes' => true,
			'manage_network_options' => true,
			'remove_users' => true,
			'promote_users' => true,
			'edit_theme_options' => true,
			'export' => true,
			'import' => true,
			'activate_plugins' => true
		));
		echo "Administrator role restored.<br>";
	} else {
		echo "Administrator role exists.<br>";
	}
	

	// Editor role
	if (!get_role('editor')) {
		add_role('editor', 'Editor', array(
			'moderate_comments' => true,
			'manage_categories' => true,
			'manage_links' => true,
			'edit_others_posts' => true,
			'edit_pages' => true,
			'edit_others_pages' => true,
			'edit_published_pages' => true,
			'publish_pages' => true,
			'delete_pages' => true,
			'delete_others_pages' => true,
			'delete_published_pages' => true,
			'delete_others_posts' => true,
			'delete_private_posts' => true,
			'edit_private_posts' => true,
			'read_private_posts' => true,
			'delete_private_pages' => true,
			'edit_private_pages' => true,
			'read_private_pages' => true,
			'unfiltered_html' => true,
			'edit_published_posts' => true,
			'upload_files' => true,
			'publish_posts' => true,
			'delete_published_posts' => true,
			'edit_posts' => true,
			'delete_posts' => true
		));
		echo "Editor role restored.<br>";
	} else {
		echo "Editor role exists.<br>";
	}

	// Author role
	if (!get_role('author')) {
		add_role('author', 'Author', array(
			'upload_files' => true,
			'edit_posts' => true,
			'edit_published_posts' => true,
			'publish_posts' => true,
			'read' => true,
			'delete_posts' => true,
			'delete_published_posts' => true
		));
		echo "Author role restored.<br>";
	} else {
		echo "Author role exists.<br>";
	}

	// Subscriber role
	if (!get_role('subscriber')) {
		add_role('subscriber', 'Subscriber', array(
			'read' => true
		));
		echo "Subscriber role restored.<br>";
	} else {
		echo "Subscriber role exists.<br>";
	}
}

function delete_role($role = 'subscriber')
{
	echo "<h1>Delete All {$role}</h1>";
	echo "<pre>";
	$blogusers = get_users('role=' . $role . '&orderby=nicename&order=ASC');
	foreach ($blogusers as $user) {
		$user_id = $user->ID;
		$usernames = $user->user_login;
		remove_user_from_blog($user_id);
		echo "User " . $usernames . " has been deleted from blog<br>";

	}
	echo "</pre>";
}