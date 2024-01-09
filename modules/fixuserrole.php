<?php
// ==================== Fix User Role ====================
function restore_default_user_roles()
{
	echo "<h1>Fix User Role</h1>";
	echo "<pre>";
	// Restore default wp roles
	restore_default_wp_roles();

	// Get default roles
	$default_roles = wp_roles()->roles;

	global $wpdb;
	// Get the correct table prefix for the current site
	$table_prefix = $wpdb->base_prefix;

	// Construct the option name dynamically
	$option_name = $table_prefix . 'user_roles';

	// Get the current roles
	$current_roles = get_option($option_name);

	// Check if the current roles differ from the default roles
	if ($current_roles !== $default_roles) {
		// Update the roles
		$role_update = update_option('wp_user_roles', $default_roles);
		if ($role_update) {
			echo "User roles fixed.";
		} else {
			echo "User roles fix failed.";
		}
	} else {
		echo "User roles already fixed.";
	}
	echo "</pre>";

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
	}

	// Subscriber role
	if (!get_role('subscriber')) {
		add_role('subscriber', 'Subscriber', array(
			'read' => true
		));
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