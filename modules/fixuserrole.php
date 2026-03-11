<?php
// ==================== Fix User Role ====================
// add to menu in site
function register_fixUserRole_admin_menu(){
    add_submenu_page('utm-webmaster-dashboard', 'Fix User Role', 'Fix User Role', 'manage_options', 'restore_default_user_roles', 'restore_default_user_roles');
}
add_action('admin_menu', 'register_fixUserRole_admin_menu');

function restore_default_user_roles(){
	global $wpdb;
	$site_id = null;

	echo "<h1>Fix User Role v25.5.16</h1>";

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

	// Add nonce for security
	$nonce = wp_create_nonce('fix_user_role_nonce');

	// Create input form
	echo "<form action='". $url . "' method='get'>";
	echo "<input type='hidden' name='page' value='restore_default_user_roles' />";
	echo "<input type='hidden' name='fix_user_role_nonce' value='$nonce' />";
	echo "<label for='site_id'>Site ID: </label>";
	echo "<input type='text' name='site_id' value='$site_id' $readonly/>";
	echo "<input type='submit' value='Fix user role this site' />";
	echo "</form>";

	// Delete all authors
    // Link to delete all authors if GET parameter is set
    if (isset($_GET['delete_role'])) {
        $role = $_GET['delete_role'];
        delete_role($role);
    } else {
        echo "Delete users: ";
        echo "<a href='?page=restore_default_user_roles&delete_role=author'>Authors</a> | ";
        echo "<a href='?page=restore_default_user_roles&delete_role=subscriber'>Subscribers</a>";
		echo "<br>";
    }

	// If the form has not been submitted, stop the script
	if (isset($_GET['site_id']) && $_GET['site_id'] != $site_id) {
		$site_id = $_GET['site_id'];
		echo "Site ID: $site_id<br>";
	} else {
		echo "Site ID: $site_id<br>";
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
		echo $user_role->option_name . ", ";
	}

	// Construct the option name dynamically
	$table_prefix = $wpdb->get_blog_prefix($site_id);
	$roles_option_name = $table_prefix . 'user_roles';

	// If more than 1 result is found, delete all but $table_prefix . 'user_roles'
	if (count($user_roles) > 1) {
		foreach ($user_roles as $user_role) {
			if ($user_role->option_name != $roles_option_name) {
				if ($wpdb->delete($wpdb->options, array('option_name' => $user_role->option_name))){
					echo "<br>Deleted user role: " . $user_role->option_name . "<br>";
				}
			}
		}
	}

	$table_prefix = $wpdb->get_blog_prefix($site_id);
	echo "<br>Table prefix: $table_prefix<br>";

	echo "Roles option name: $roles_option_name<br>";

	// Use WordPress API to reset roles to official defaults
	if ( ! function_exists( 'populate_roles' ) ) {
        require_once ABSPATH . 'wp-admin/includes/schema.php';
    }
    if ( function_exists( 'populate_roles' ) ) {
        populate_roles();
        echo "WordPress default roles have been restored using populate_roles().<br>";
    } else {
        echo "populate_roles() function not found. Unable to reset to official defaults.<br>";
    }

	// Ensure level_10 capability is added to administrator role
    $role = get_role('administrator');
    if ($role) {
        $role->add_cap('level_10');
    }

	// view values
	global $wp_roles;
	if ( ! isset( $wp_roles ) ) {
		$wp_roles = new WP_Roles();
	}
	echo "<pre>";
	print_r($wp_roles->roles);
	echo "</pre>";

	// Return to current blog
	restore_current_blog();
}

function delete_role($role = 'subscriber'){
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

function wp_default_cap() {
    return array(
        'administrator' => array(
            'name' => 'Administrator',
            'capabilities' => array(
                'switch_themes' => true,
                'edit_themes' => true,
                'activate_plugins' => true,
                'edit_plugins' => true,
                'edit_users' => true,
                'edit_files' => true,
                'manage_options' => true,
                'moderate_comments' => true,
                'manage_categories' => true,
                'manage_links' => true,
                'upload_files' => true,
                'import' => true,
                'unfiltered_html' => true,
                'edit_posts' => true,
                'edit_others_posts' => true,
                'edit_published_posts' => true,
                'publish_posts' => true,
                'edit_pages' => true,
                'read' => true,
                'level_10' => true,
                'level_9' => true,
                'level_8' => true,
                'level_7' => true,
                'level_6' => true,
                'level_5' => true,
                'level_4' => true,
                'level_3' => true,
                'level_2' => true,
                'level_1' => true,
                'level_0' => true,
                'edit_others_pages' => true,
                'edit_published_pages' => true,
                'publish_pages' => true,
                'delete_pages' => true,
                'delete_others_pages' => true,
                'delete_published_pages' => true,
                'delete_posts' => true,
                'delete_others_posts' => true,
                'delete_published_posts' => true,
                'delete_private_posts' => true,
                'edit_private_posts' => true,
                'read_private_posts' => true,
                'delete_private_pages' => true,
                'edit_private_pages' => true,
                'read_private_pages' => true,
                'delete_users' => true,
                'create_users' => true,
                'unfiltered_upload' => true,
                'edit_dashboard' => true,
                'update_plugins' => true,
                'delete_plugins' => true,
                'install_plugins' => true,
                'update_themes' => true,
                'install_themes' => true,
                'update_core' => true,
                'list_users' => true,
                'remove_users' => true,
                'promote_users' => true,
                'edit_theme_options' => true,
                'delete_themes' => true,
                'export' => true,
                'manage_sites' => true,
                'manage_network_users' => true,
                'manage_network_plugins' => true,
                'manage_network_themes' => true,
                'manage_network_options' => true,
            ),
        ),
        'editor' => array(
            'name' => 'Editor',
            'capabilities' => array(
                'moderate_comments' => true,
                'manage_categories' => true,
                'manage_links' => true,
                'upload_files' => true,
                'unfiltered_html' => true,
                'edit_posts' => true,
                'edit_others_posts' => true,
                'edit_published_posts' => true,
                'publish_posts' => true,
                'edit_pages' => true,
                'read' => true,
                'level_7' => true,
                'level_6' => true,
                'level_5' => true,
                'level_4' => true,
                'level_3' => true,
                'level_2' => true,
                'level_1' => true,
                'level_0' => true,
                'edit_others_pages' => true,
                'edit_published_pages' => true,
                'publish_pages' => true,
                'delete_pages' => true,
                'delete_others_pages' => true,
                'delete_published_pages' => true,
                'delete_posts' => true,
                'delete_others_posts' => true,
                'delete_published_posts' => true,
                'delete_private_posts' => true,
                'edit_private_posts' => true,
                'read_private_posts' => true,
                'delete_private_pages' => true,
                'edit_private_pages' => true,
                'read_private_pages' => true,
            ),
        ),
        'author' => array(
            'name' => 'Author',
            'capabilities' => array(
                'upload_files' => true,
                'edit_posts' => true,
                'edit_published_posts' => true,
                'publish_posts' => true,
                'read' => true,
                'level_2' => true,
                'level_1' => true,
                'level_0' => true,
                'delete_posts' => true,
                'delete_published_posts' => true,
            ),
        ),
        'contributor' => array(
            'name' => 'Contributor',
            'capabilities' => array(
                'edit_posts' => true,
                'read' => true,
                'level_1' => true,
                'level_0' => true,
                'delete_posts' => true,
            ),
        ),
        'subscriber' => array(
            'name' => 'Subscriber',
            'capabilities' => array(
                'read' => true,
                'level_0' => true,
            ),
        ),
    );
}
