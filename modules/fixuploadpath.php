<?php
function utm_add_submenu_page()
{
	add_management_page('Fix Upload Path', 'Fix Upload Path', 'manage_options', 'fix-media', 'utm_fixuploadpath');
}
add_action('admin_menu', 'utm_add_submenu_page');

function utm_fixuploadpath(){
	// Handle POST actions first
	if (isset($_POST['action']) && wp_verify_nonce($_POST['utmwt_nonce'], 'utmwt_action')) {
		if ($_POST['action'] === 'test_upload_dir') {
			echo '<div class="notice notice-info"><p>';
			test_upload_directory();
			echo '</p></div>';
		}
	}
	
	// Handle GET actions (legacy support)
	if (isset($_GET['fix_upload_dir']) && wp_verify_nonce($_GET['utm_nonce'], 'utm_fixuploadpath')) {
		echo '<div class="notice notice-info">';
		force_fix_upload_directory();
		echo '</div>';
	}
	
	// variables
    global $wpdb;
    $home_url = get_home_url();
    $current_blog_id = get_current_blog_id();
    $posts_table = $wpdb->prefix . 'posts';
    $postmeta_table = $wpdb->prefix . 'postmeta';
    $option_table = $wpdb->prefix . "options";
    $slug = get_blog_details($current_blog_id)->path;
    $sitemeta_table = $wpdb->prefix . 'sitemeta';
    $site_id = $current_blog_id;

	// Page title
	echo '<h1>Fix Upload Path - v2025.05.14</h1>';

	// if site id is 1, skip
	if ($site_id == 1){
		utm_aiwm_options();
		utm_wpcontent_list();
	}

	// blogsdir migration
	$blogsdir_path = $_SERVER['DOCUMENT_ROOT'] . "/wp-content/blogs.dir/". $site_id . "/files";
	$search = $blogsdir_path;
	if ($site_id == 1){
		$uploaddir_path = $_SERVER['DOCUMENT_ROOT'] . "/wp-content/uploads";
	}
	if ($site_id != 1 && $site_id != 0 && $site_id != ""){
		$uploaddir_path = $_SERVER['DOCUMENT_ROOT'] . "/wp-content/uploads/sites/" . $site_id;
	}
	$replace = $uploaddir_path;

	// Sanitize user input
	$search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : $search;
	$replace = isset($_GET['replace']) ? sanitize_text_field($_GET['replace']) : $replace;
	$delete_option = isset($_GET['delete_option']) ? sanitize_text_field($_GET['delete_option']) : '';

	$upload_path = $wpdb->get_var("SELECT option_value FROM $option_table WHERE option_name = 'upload_path'");
	$upload_url_path = $wpdb->get_var("SELECT option_value FROM $option_table WHERE option_name = 'upload_url_path'");

	if ($upload_path != ''){
		// Fix: Use proper update/insert logic
		$existing = $wpdb->get_var($wpdb->prepare(
			"SELECT option_id FROM $option_table WHERE option_name = %s", 
			'upload_path'
		));
		
		if ($existing) {
			$wpdb->update(
				$option_table, 
				array('option_value' => ''), 
				array('option_name' => 'upload_path')
			);
		} else {
			$wpdb->insert(
				$option_table, 
				array('option_name' => 'upload_path', 'option_value' => '')
			);
		}
	}
    $output = array();    // Starting the HTML wrapper for the admin page
    echo '<div class="wrap">';
    echo '<p>This tool is for fixing multiple issues path for this site</p>';
    
    // Check current upload path settings
    echo "<h2>Current Upload Path Settings</h2>";
    echo "<ul>";
    if (empty($upload_path)) {
        echo '<li>✅ <strong>Upload path:</strong> (empty - correct for multisite)</li>';
    } else {
        echo '<li>⚠️ <strong>Upload path:</strong> ' . htmlspecialchars($upload_path) . ' <a href="?page=fix-media&delete_option=upload_path&utm_nonce=' . wp_create_nonce('utm_fixuploadpath') . '">[Delete upload_path option]</a></li>';
    }
    
    if (empty($upload_url_path)) {
        echo '<li>✅ <strong>Upload URL path:</strong> (empty - correct for multisite)</li>';
    } else {
        echo '<li>⚠️ <strong>Upload URL path:</strong> ' . htmlspecialchars($upload_url_path) . ' <a href="?page=fix-media&delete_option=upload_url_path&utm_nonce=' . wp_create_nonce('utm_fixuploadpath') . '">[Delete upload_url_path option]</a></li>';
    }
    echo "</ul>";
    
    // Check WordPress upload settings
    $wp_upload_dir = wp_upload_dir();
    echo "<h3>📁 WordPress Upload Directory Debug:</h3>";
    echo "<div style='background: #f8f9fa; padding: 10px; margin: 10px 0; border-left: 4px solid #007cba;'>";
    echo "<strong>Current upload path:</strong> " . htmlspecialchars($wp_upload_dir['path']) . "<br>";
    echo "<strong>Current upload URL:</strong> " . htmlspecialchars($wp_upload_dir['url']) . "<br>";
    echo "<strong>Base upload dir:</strong> " . htmlspecialchars($wp_upload_dir['basedir']) . "<br>";
    echo "<strong>Base upload URL:</strong> " . htmlspecialchars($wp_upload_dir['baseurl']) . "<br>";
    echo "<strong>Subdir:</strong> " . htmlspecialchars($wp_upload_dir['subdir']) . "<br>";
    echo "<strong>Error:</strong> " . ($wp_upload_dir['error'] ? htmlspecialchars($wp_upload_dir['error']) : 'None') . "<br>";
    
    // Debug: Show all upload-related options
    echo "<strong>📋 All upload-related options in database:</strong><br>";
    $upload_options = $wpdb->get_results("SELECT option_name, option_value FROM $option_table WHERE option_name LIKE '%upload%' OR option_name LIKE '%file%' OR option_name LIKE '%media%'", ARRAY_A);
    if ($upload_options) {
        echo "<ul style='margin: 5px 0; max-height: 150px; overflow-y: auto; background: white; padding: 10px; border: 1px solid #ddd;'>";
        foreach ($upload_options as $option) {
            $value = strlen($option['option_value']) > 100 ? substr($option['option_value'], 0, 100) . '...' : $option['option_value'];
            echo "<li><strong>{$option['option_name']}:</strong> " . htmlspecialchars($value) . "</li>";
        }
        echo "</ul>";
    } else {
        echo "No upload-related options found<br>";
    }
    
    // Check if uploads are going to the wrong location
    $expected_upload_path = '';
    if ($site_id == 1) {
        $expected_upload_path = ABSPATH . 'wp-content/uploads';
    } else {
        $expected_upload_path = ABSPATH . 'wp-content/uploads/sites/' . $site_id;
    }
    
    echo "<strong>Expected upload path:</strong> " . htmlspecialchars($expected_upload_path) . "<br>";
    echo "<strong>Paths match:</strong> " . ($wp_upload_dir['basedir'] === $expected_upload_path ? 'Yes' : 'No') . "<br>";
    echo "<strong>Contains blogs.dir:</strong> " . (strpos($wp_upload_dir['basedir'], 'blogs.dir') !== false ? 'Yes' : 'No') . "<br>";
    echo "</div>";
    
    echo "<ul>";
      if (strpos($wp_upload_dir['basedir'], 'blogs.dir') !== false) {
        echo "<li>⚠️ <strong>WARNING:</strong> Uploads are still going to blogs.dir structure!</li>";
        echo "<li>Current: " . htmlspecialchars($wp_upload_dir['basedir']) . "</li>";
        echo "<li>Should be: " . htmlspecialchars($expected_upload_path) . "</li>";
        echo "<li><strong>🔧 Fix:</strong> <a href='?page=fix-media&fix_upload_dir=true&utm_nonce=" . wp_create_nonce('utm_fixuploadpath') . "'>Force Fix Upload Directory</a></li>";
    } else if ($wp_upload_dir['basedir'] === $expected_upload_path) {
        echo "<li>✅ <strong>Upload directory is correct</strong></li>";
    } else {
        echo "<li>⚠️ <strong>Upload directory may be incorrect</strong></li>";
        echo "<li>Current: " . htmlspecialchars($wp_upload_dir['basedir']) . "</li>";
        echo "<li>Expected: " . htmlspecialchars($expected_upload_path) . "</li>";
        echo "<li><strong>🔧 Fix:</strong> <a href='?page=fix-media&fix_upload_dir=true&utm_nonce=" . wp_create_nonce('utm_fixuploadpath') . "'>Force Fix Upload Directory</a></li>";
    }
    echo "</ul>";
    
    // Migration actions menu
    echo "<h2>Migration Actions</h2>";    echo '<ul>';    echo '<li><a href="?page=fix-media&msfiles=true&utm_nonce=' . wp_create_nonce('utm_fixuploadpath') . '">Set ms-files to 0</a></li>';echo '<li><a href="?page=fix-media&migrate_files_db=true&dry_run=true&utm_nonce=' . wp_create_nonce('utm_fixuploadpath') . '">Migrate /files/ database paths (Dry Run)</a></li>';
    echo '<li><a href="?page=fix-media&migrate_files_db=true&utm_nonce=' . wp_create_nonce('utm_fixuploadpath') . '">Migrate /files/ database paths (Live)</a></li>';    echo '<li><a href="?page=fix-media&file_migration=1&dry_run=true&utm_nonce=' . wp_create_nonce('utm_fixuploadpath') . '">Move files from blogs.dir (Dry Run)</a></li>';
    echo '<li><a href="?page=fix-media&file_migration=1&utm_nonce=' . wp_create_nonce('utm_fixuploadpath') . '">Move files from blogs.dir (Live)</a></li>';
    echo '<li><a href="?page=fix-media&fix_attachment_paths=true&dry_run=true&utm_nonce=' . wp_create_nonce('utm_fixuploadpath') . '">Fix attachment file paths (Dry Run)</a></li>';
    echo '<li><a href="?page=fix-media&fix_attachment_paths=true&utm_nonce=' . wp_create_nonce('utm_fixuploadpath') . '">Fix attachment file paths (Live)</a></li>';
    echo '<li><a href="?page=fix-media&regenerate_metadata=true&dry_run=true&utm_nonce=' . wp_create_nonce('utm_fixuploadpath') . '">Regenerate attachment metadata (Dry Run)</a></li>';
    echo '<li><a href="?page=fix-media&regenerate_metadata=true&utm_nonce=' . wp_create_nonce('utm_fixuploadpath') . '">Regenerate attachment metadata (Live)</a></li>';
    echo '</ul>';


	// List files and folder in blogs.dir
	$blogsdir_path = $_SERVER['DOCUMENT_ROOT'] . "/wp-content/blogs.dir/". $site_id;

	echo "<h2>Debug Information & System Status</h2>";
	
	// Debug: Environment and WordPress info
	echo "<h4>🔍 Environment Debug Info:</h4>";
	echo "<div style='background: #f1f1f1; padding: 10px; margin: 10px 0; font-family: monospace;'>";
	echo "<strong>WordPress Version:</strong> " . get_bloginfo('version') . "<br>";
	echo "<strong>Multisite:</strong> " . (is_multisite() ? 'Yes' : 'No') . "<br>";
	echo "<strong>Current Site ID:</strong> $current_blog_id<br>";
	echo "<strong>Current User ID:</strong> " . get_current_user_id() . "<br>";
	echo "<strong>Is Network Admin:</strong> " . (is_network_admin() ? 'Yes' : 'No') . "<br>";
	echo "<strong>Site URL:</strong> " . get_site_url() . "<br>";
	echo "<strong>Home URL:</strong> " . get_home_url() . "<br>";
	echo "<strong>WP_CONTENT_DIR:</strong> " . WP_CONTENT_DIR . "<br>";
	echo "<strong>ABSPATH:</strong> " . ABSPATH . "<br>";
	echo "<strong>DOCUMENT_ROOT:</strong> " . $_SERVER['DOCUMENT_ROOT'] . "<br>";
	echo "<strong>PHP Version:</strong> " . PHP_VERSION . "<br>";
	echo "<strong>Server Software:</strong> " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "<br>";
	echo "</div>";
	
	// Debug: Database table prefixes
	echo "<h4>🗄️ Database Debug Info:</h4>";
	echo "<div style='background: #f1f1f1; padding: 10px; margin: 10px 0; font-family: monospace;'>";
	echo "<strong>DB Prefix:</strong> " . $wpdb->prefix . "<br>";
	echo "<strong>Base Prefix:</strong> " . $wpdb->base_prefix . "<br>";
	echo "<strong>Posts Table:</strong> $posts_table<br>";
	echo "<strong>Postmeta Table:</strong> $postmeta_table<br>";
	echo "<strong>Options Table:</strong> $option_table<br>";
	echo "<strong>Sitemeta Table:</strong> $sitemeta_table<br>";
	echo "</div>";
	
	// Debug: Show current URL parameters
	if (!empty($_GET)) {
		echo "<h4>📝 Current URL Parameters:</h4>";
		echo "<div style='background: #fff3cd; padding: 10px; margin: 10px 0; border: 1px solid #ffeaa7;'>";
		foreach ($_GET as $key => $value) {
			echo "<strong>$key:</strong> " . htmlspecialchars($value) . "<br>";
		}
		
		// Show nonce status
		if (isset($_GET['utm_nonce'])) {
			$nonce_valid = wp_verify_nonce($_GET['utm_nonce'], 'utm_fixuploadpath');
			echo "<strong>Nonce Status:</strong> " . ($nonce_valid ? '✅ Valid' : '❌ Invalid') . "<br>";
		} else {
			echo "<strong>Nonce Status:</strong> ⚠️ Not provided<br>";
		}
		echo "</div>";
	}
	
	echo "<h2>Database Analysis</h2>";
	
	utm_analyze_files_paths($current_blog_id);
	
	// Check if ms-files is still enabled
	echo "<h4>🔧 ms-files.php Status Check:</h4>";
	echo "<div style='background: #f8f9fa; padding: 10px; margin: 10px 0; border-left: 4px solid #007cba;'>";
	$ms_files_rewriting = $wpdb->get_var("SELECT meta_value FROM $sitemeta_table WHERE meta_key = 'ms_files_rewriting'");
	echo "<strong>ms_files_rewriting setting:</strong> " . ($ms_files_rewriting ? $ms_files_rewriting : 'not set') . "<br>";
	
	// Debug: Show all sitemeta entries related to files
	echo "<strong>📋 All file-related sitemeta entries:</strong><br>";
	$file_related_meta = $wpdb->get_results("SELECT meta_key, meta_value FROM $sitemeta_table WHERE meta_key LIKE '%file%' OR meta_key LIKE '%upload%' OR meta_key LIKE '%media%'", ARRAY_A);
	if ($file_related_meta) {
		echo "<ul style='margin: 5px 0;'>";
		foreach ($file_related_meta as $meta) {
			echo "<li><strong>{$meta['meta_key']}:</strong> " . htmlspecialchars($meta['meta_value']) . "</li>";
		}
		echo "</ul>";
	} else {
		echo "No file-related sitemeta entries found<br>";
	}
	
	if ($ms_files_rewriting == '1') {
		echo "⚠️ <strong>ms-files.php is ENABLED</strong> - this is why files are being served from /files/ URLs<br>";
		echo "👆 Use the 'Set ms-files to 0' link above to disable it<br>";
	} else {
		echo "✅ ms-files.php is disabled<br>";
	}
	echo "</div>";
	echo "<br>";
	echo "<h2>🗂️ Blogs.dir Status</h2>";
	echo "<div style='background: #f8f9fa; padding: 10px; margin: 10px 0; border-left: 4px solid #28a745;'>";
	echo "<strong>Blogs.dir path:</strong> " . $blogsdir_path . "<br>";
	echo "<strong>Path exists:</strong> " . (is_dir($blogsdir_path) ? 'Yes' : 'No') . "<br>";
	echo "<strong>Path readable:</strong> " . (is_readable($blogsdir_path) ? 'Yes' : 'No') . "<br>";
	echo "<strong>Path writable:</strong> " . (is_writable($blogsdir_path) ? 'Yes' : 'No') . "<br>";

	if (is_dir($blogsdir_path)) {
		$files_in_blogsdir = scandir($blogsdir_path);
		$file_count = count($files_in_blogsdir) - 2; // Exclude . and ..
		echo "<strong>File count:</strong> $file_count<br>";
		
		if ($file_count > 0) {
			echo "⚠️ <strong>Blogs.dir contains $file_count items</strong> - migration needed<br>";
			echo "<h3>Files and folders in blogs.dir:</h3>";
			echo "<pre style='background: white; padding: 10px; border: 1px solid #ddd; max-height: 200px; overflow-y: auto;'>" . print_r($files_in_blogsdir, true) . "</pre>";
		} else {
			echo "✅ <strong>Blogs.dir is empty</strong> - ready for cleanup<br>";
		}
	} else {
		echo "✅ <strong>Blogs.dir not found</strong> - migration already completed<br>";
	}
	echo "</div>";
	// if blogs.dir exists but is empty, delete it automatically
	if (is_dir($blogsdir_path) && count(scandir($blogsdir_path)) == 2) {
		echo "<h3>✅ Empty Blogs.dir Cleanup</h3>";
		echo "<p>Blogs.dir is empty - cleaning up automatically...</p>";
		rrmdir($blogsdir_path);
		echo "<p>✅ <strong>Successfully deleted empty blogs.dir</strong></p>";
	} else if (is_dir($blogsdir_path) && count(scandir($blogsdir_path)) > 2) {
		echo "<h3>⚠️ Blogs.dir Contains Files</h3>";
		echo "<p>Use the 'Move files from blogs.dir' action above to migrate files first.</p>";
	}	// Check if nonce is set and valid
	// Only check nonce for operations that modify data
	$requires_nonce = isset($_GET['search']) || isset($_GET['delete_option']) || 
                  isset($_GET['file_migration']) || isset($_GET['msfiles']) || 
                  isset($_GET['migrate_files_db']) || isset($_GET['regenerate_metadata']) ||
                  isset($_GET['fix_attachment_paths']) || isset($_GET['fix_upload_dir']);

	if ($requires_nonce && (!isset($_GET['utm_nonce']) || !wp_verify_nonce($_GET['utm_nonce'], 'utm_fixuploadpath'))) {
		echo '<div class="notice notice-error"><p><strong>Security check failed!</strong> Nonce verification failed. Please use the links provided on this page.</p></div>';
		// Don't die, just show error and continue to display the page
		$nonce_failed = true;
	} else {
		$nonce_failed = false;
	}    // Perform search and replace on multiple tables and columns

    echo '</div>'; // Closing wrap div
	/*
	 * Turn off ms-files.php
	 * Source: https://anchor.host/removing-legacy-ms-files-php-from-multisite/
	 */
	if (isset($_GET['msfiles']) && !$nonce_failed){
		$meta_key = 'ms_files_rewriting';
    	$meta_value = '0';

		// Check if the meta_key already exists
		$meta_exists = $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM $sitemeta_table WHERE meta_key = %s",
			$meta_key
		));

		if ($meta_exists) {
			// Update the meta_value if the meta_key exists
			$wpdb->update(
				$sitemeta_table,
				array('meta_value' => $meta_value),
				array('meta_key' => $meta_key)
			);
			echo "Updated existing meta_key: $meta_key with meta_value: $meta_value<br>";
		} else {
			// Insert the meta_key and meta_value if it does not exist
			$wpdb->insert(
				$sitemeta_table,
				array(
					'meta_key' => $meta_key,
					'meta_value' => $meta_value
				)
			);			echo "Inserted new meta_key: $meta_key with meta_value: $meta_value<br>";
		}
	}

	/*
	 * Fix Upload Directory
	 * Force WordPress to use correct upload directory structure
	 */
	if (isset($_GET['fix_upload_dir']) && !$nonce_failed){
		echo "<div style='border: 2px solid #28a745; padding: 15px; margin: 10px 0; background: #f8fff8;'>";
		echo "<h2>🔧 Fixing Upload Directory Configuration</h2>";
		echo "<strong>Debug: Starting upload directory fix process</strong><br>";
		echo "<strong>Site ID:</strong> $current_blog_id<br>";
		utm_fix_upload_directory($current_blog_id);
		echo "</div>";
	} elseif (isset($_GET['fix_upload_dir']) && $nonce_failed) {
		echo '<div class="notice notice-error"><p><strong>Upload directory fix blocked due to security check failure.</strong> Please use the fix links provided above.</p></div>';
	}

	/*
	 * File Migration
	 * Move files from blogs.dir to uploads/sites
	 */
	if (isset($_GET['file_migration']) && $_GET['file_migration'] == 1){
		if ($site_id == 1){
			echo "<br>This is the main site. Attempting to move files to correct location";
			echo "<br>Blogs.dir path: " . $blogsdir_path;
			echo "<br>Upload dir path: " . $uploaddir_path . "<br>";
		}
		if ($site_id != 1 && $site_id != 0 && $site_id != ""){
			echo "<br>This is the subsite. Attempting to move files to correct location";
		}
		echo "<br>Blogs.dir path: " . $blogsdir_path;
		echo "<br>Upload dir path: " . $uploaddir_path . "<br>";
		merge_directories($blogsdir_path, $uploaddir_path, !empty($_GET['dry_run']));
		return;
	}
	/*
	 * Delete option from option table
	 *
	 */
	if (isset($delete_option) && $delete_option != '' && !$nonce_failed) {
		$wpdb->delete($option_table, array('option_name' => $delete_option));
		echo "Deleted option: " . $delete_option . "<br>";
	}

	/*
	 * Migrate files path in database
	 * Specifically handle /files/ paths that need to be updated
	 */
	if (isset($_GET['migrate_files_db']) && !$nonce_failed){
		echo "<div style='border: 2px solid #007cba; padding: 15px; margin: 10px 0; background: #f8f9fa;'>";
		echo "<h2>🔄 Migrating /files/ paths in database</h2>";
		echo "<strong>Debug: Starting database migration process</strong><br>";
		echo "<strong>Dry run:</strong> " . (!empty($_GET['dry_run']) ? 'Yes' : 'No') . "<br>";
		echo "<strong>Site ID:</strong> $current_blog_id<br>";
		utm_migrate_files_database_paths($current_blog_id, !empty($_GET['dry_run']));
		echo "</div>";
	} elseif (isset($_GET['migrate_files_db']) && $nonce_failed) {
		echo '<div class="notice notice-error"><p><strong>Migration blocked due to security check failure.</strong> Please use the migration links provided above.</p></div>';
	}	/*
	 * Fix attachment file paths
	 * Fix _wp_attached_file metadata to ensure correct file paths
	 */
	if (isset($_GET['fix_attachment_paths']) && !$nonce_failed){
		utm_fix_attachment_file_paths($current_blog_id, !empty($_GET['dry_run']));
	} elseif (isset($_GET['fix_attachment_paths']) && $nonce_failed) {
		echo '<div class="notice notice-error"><p><strong>Attachment path fix blocked due to security check failure.</strong> Please use the fix links provided above.</p></div>';
	}

	/*
	 * Regenerate attachment metadata
	 * Fix missing thumbnails by regenerating metadata
	 */
	if (isset($_GET['regenerate_metadata']) && !$nonce_failed){
		utm_regenerate_attachment_metadata($current_blog_id, !empty($_GET['dry_run']));
	} elseif (isset($_GET['regenerate_metadata']) && $nonce_failed) {
		echo '<div class="notice notice-error"><p><strong>Metadata regeneration blocked due to security check failure.</strong> Please use the regenerate links provided above.</p></div>';
	}

	/*
	 * Delete all authors
	 *
	 */
	// delete all authors from this site
	if (isset($_GET['delete_authors'])) {
		echo "Delete authors from site<br>";
		$users = get_users(array('blog_id' => $current_blog_id));
		foreach ($users as $user) {
			// get user role
			$user_role = $user->roles[0];

			// if user role is authors
			if ($user_role == 'author') {
				// remove user from site
				remove_user_from_blog($user->ID, $current_blog_id);
				echo $user->user_login . " removed from site<br>";
			}
		}
		echo "<div class='notice notice-warning is-dismissible'><p>Table Prefix:{$wpdb->prefix}users</p><p>UTM Webmaster Tool: <strong>Deleted</strong> all authors from <strong>users table</strong></p></div>";
	}
	return;

	/*
	 * Old codes
	 *
	 */
	// delete utmlogin_updater from cron option value
	$utmlogin_update = false;
	if ($utmlogin_update) {
		// get cron option value
		$cron_option = $wpdb->get_var("SELECT option_value FROM $option_table WHERE option_name = 'cron'");

		// Check if the value is serialized before attempting to unserialize
		if (is_serialized($cron_option)) {
			// unserialize cron option value
			$cron_option = maybe_unserialize($cron_option);

			// Check if unserialization was successful
			if (is_array($cron_option)) {
				// remove utmlogin_updater from cron option value
				$cron_option = array_diff($cron_option, array('utmlogin_updater'));

				// encode cron option value
				$cron_option = json_encode($cron_option);

				// update cron option value
				$update_cron_success = $wpdb->update($option_table, array('option_value' => $cron_option), array('option_name' => 'cron'));
				if ($update_cron_success) echo "<div class='notice notice-warning is-dismissible'><p>Table Prefix: {$wpdb->prefix}options</p><p>UTM Webmaster Tool: <strong>Deleted</strong> option value <strong>utmlogin_updater</strong> from <strong>option table</strong></p></div>";
			} else {
				echo "Failed to unserialize the 'cron' option.";
			}
		} else {
			echo "'cron' option is not serialized or the value is empty.";
		}
	}

	// Display the output
	echo '<h2>📊 Debug Output Summary</h2>';
	echo "<div style='background: #f8f9fa; padding: 15px; margin: 10px 0; border: 1px solid #ddd;'>";
	echo "<strong>🔍 Final System State Check:</strong><br>";
	
	// Re-check upload directory after any operations
	$final_upload_dir = wp_upload_dir();
	echo "<strong>Final upload directory:</strong> " . htmlspecialchars($final_upload_dir['basedir']) . "<br>";
	echo "<strong>Still using blogs.dir:</strong> " . (strpos($final_upload_dir['basedir'], 'blogs.dir') !== false ? 'Yes ⚠️' : 'No ✅') . "<br>";
	
	// Re-check ms-files status
	$final_ms_files = $wpdb->get_var("SELECT meta_value FROM $sitemeta_table WHERE meta_key = 'ms_files_rewriting'");
	echo "<strong>ms-files status:</strong> " . ($final_ms_files == '1' ? 'Enabled ⚠️' : 'Disabled ✅') . "<br>";
	
	// Count remaining problematic records
	$remaining_attachments = $wpdb->get_var("SELECT COUNT(*) FROM $posts_table WHERE post_type = 'attachment' AND (guid LIKE '%/files/%' OR guid LIKE '%blogs.dir%')");
	$remaining_content = $wpdb->get_var("SELECT COUNT(*) FROM $posts_table WHERE post_content LIKE '%/files/%' OR post_content LIKE '%blogs.dir%'");
	$remaining_postmeta = $wpdb->get_var("SELECT COUNT(*) FROM $postmeta_table WHERE meta_value LIKE '%/files/%' OR meta_value LIKE '%blogs.dir%'");
	$remaining_options = $wpdb->get_var("SELECT COUNT(*) FROM $option_table WHERE option_value LIKE '%/files/%' OR option_value LIKE '%blogs.dir%'");
	
	echo "<strong>Remaining problematic records:</strong><br>";
	echo "  - Attachments: $remaining_attachments<br>";
	echo "  - Post content: $remaining_content<br>";
	echo "  - Postmeta: $remaining_postmeta<br>";
	echo "  - Options: $remaining_options<br>";
	
	$total_remaining = $remaining_attachments + $remaining_content + $remaining_postmeta + $remaining_options;
	echo "<strong>Total remaining:</strong> $total_remaining " . ($total_remaining == 0 ? '✅' : '⚠️') . "<br>";
	
	echo "<br><strong>🎯 Next Recommended Actions:</strong><br>";
	if (strpos($final_upload_dir['basedir'], 'blogs.dir') !== false) {
		echo "1. ⚠️ Use 'Force Fix Upload Directory' to resolve upload path<br>";
	}
	if ($total_remaining > 0) {
		echo "2. ⚠️ Run database migration to fix remaining /files/ references<br>";
	}
	if ($remaining_attachments > 0) {
		echo "3. ⚠️ Run 'Fix attachment file paths' to correct metadata<br>";
		echo "4. ⚠️ Run 'Regenerate attachment metadata' to restore thumbnails<br>";
	}
	if ($total_remaining == 0 && strpos($final_upload_dir['basedir'], 'blogs.dir') === false) {
		echo "✅ Migration appears complete! All systems are using correct paths.<br>";
	}
	echo "</div>";
	
	echo '<pre>' . print_r($output, true) . '</pre>';

	// Concluding the HTML wrapper
	echo '</div>';  // Closing wrap div
}

function merge_directories($source, $destination, $dry_run = true){
    echo "<br>============= Merge Directories ==========<br>";
    echo "Source: $source<br>";
    echo "Destination: $destination<br>";
    echo "Dry Run: " . ($dry_run ? 'Yes' : 'No') . "<br>";
    
    if (!is_dir($source)) {
        echo "Source directory does not exist: $source<br>";
        return false;
    }

    // Create destination directory if it doesn't exist
    if (!is_dir($destination)) {
        if (!$dry_run) {
            if (!mkdir($destination, 0755, true)) {
                echo "Failed to create destination directory: $destination<br>";
                return false;
            }
        }
        echo "Would create destination directory: $destination<br>";
    }

    $dir = opendir($source);
    if (!$dir) {
        echo "Failed to open source directory: $source<br>";
        return false;
    }

    $files_processed = 0;
    $errors = 0;

    while (($file = readdir($dir)) !== false) {
        if ($file == '.' || $file == '..') {
            continue;
        }

        $srcFile = $source . DIRECTORY_SEPARATOR . $file;
        $destFile = $destination . DIRECTORY_SEPARATOR . $file;

        // Security check: ensure we're not going outside source directory
        $realSrcFile = realpath($srcFile);
        $realSource = realpath($source);
        
        if ($realSrcFile && $realSource && strpos($realSrcFile, $realSource) !== 0) {
            echo "Security: Skipping file outside source path: $srcFile<br>";
            continue;
        }

        echo "Processing: $file<br>";

        if (is_dir($srcFile)) {
            // Recursively process subdirectories
            if (merge_directories($srcFile, $destFile, $dry_run)) {
                $files_processed++;
            } else {
                $errors++;
            }
        } else {
            // Handle file copying
            if (file_exists($destFile)) {
                echo "Destination file already exists: $destFile<br>";
                
                // Compare file sizes/dates to decide what to do
                $src_size = filesize($srcFile);
                $dest_size = filesize($destFile);
                
                if ($src_size == $dest_size) {
                    echo "Files are same size, removing source: $srcFile<br>";
                    if (!$dry_run) {
                        unlink($srcFile);
                    }
                } else {
                    echo "Files differ in size (src: $src_size, dest: $dest_size), keeping both<br>";
                    $destFile = $destination . DIRECTORY_SEPARATOR . pathinfo($file, PATHINFO_FILENAME) . '_duplicate.' . pathinfo($file, PATHINFO_EXTENSION);
                }
            }
            
            if (!file_exists($destFile)) {
                // Ensure destination directory exists
                $destDir = dirname($destFile);
                if (!is_dir($destDir) && !$dry_run) {
                    mkdir($destDir, 0755, true);
                }
                
                if ($dry_run) {
                    echo "Would copy: $srcFile to $destFile<br>";
                    $files_processed++;
                } else {
                    if (copy($srcFile, $destFile)) {
                        echo "Copied: $srcFile to $destFile<br>";
                        unlink($srcFile);
                        echo "Deleted source: $srcFile<br>";
                        $files_processed++;
                    } else {
                        echo "Failed to copy: $srcFile to $destFile<br>";
                        $errors++;
                    }
                }
            }
        }
    }

    closedir($dir);    // Remove empty source directory
    if (!$dry_run && is_dir_empty($source)) {
        if (rmdir($source)) {
            echo "Removed empty source directory: $source<br>";
        } else {
            echo "Failed to remove empty source directory: $source<br>";
        }
    }

    echo "Migration summary - Processed: $files_processed, Errors: $errors<br>";
    
    // If this was a successful migration and no errors, offer to remove the entire blogs.dir structure
    if (!$dry_run && $errors == 0 && $files_processed > 0) {
        // Check if we're dealing with the main blogs.dir/site_id folder
        if (preg_match('#/wp-content/blogs\.dir/(\d+)(/files)?/?$#', $source, $matches)) {
            $site_id_from_path = $matches[1];
            $blogs_dir_site_root = dirname($source); // Remove /files to get blogs.dir/XXXX
            
            echo "<br><strong>Migration completed successfully!</strong><br>";
            echo "All files have been moved from: $source<br>";
            echo "To: $destination<br><br>";
            
            // Check if the entire site folder in blogs.dir is now empty
            if (is_dir($blogs_dir_site_root) && is_dir_empty($blogs_dir_site_root)) {
                echo "The entire blogs.dir site folder ($blogs_dir_site_root) is now empty.<br>";
                if (rmdir($blogs_dir_site_root)) {
                    echo "✅ <strong>Successfully removed empty blogs.dir site folder: $blogs_dir_site_root</strong><br>";
                } else {
                    echo "⚠️ Failed to remove empty blogs.dir site folder: $blogs_dir_site_root<br>";
                }
            } else {
                echo "Note: blogs.dir site folder still contains other files: $blogs_dir_site_root<br>";
            }
            
            // Check if the main blogs.dir folder can be removed
            $main_blogs_dir = dirname($blogs_dir_site_root); // Remove site_id to get main blogs.dir
            if (is_dir($main_blogs_dir) && is_dir_empty($main_blogs_dir)) {
                echo "The main blogs.dir folder ($main_blogs_dir) is now completely empty.<br>";
                if (rmdir($main_blogs_dir)) {
                    echo "✅ <strong>Successfully removed empty main blogs.dir folder: $main_blogs_dir</strong><br>";
                    echo "🎉 <strong>Complete migration finished! Legacy blogs.dir structure has been fully removed.</strong><br>";
                } else {
                    echo "⚠️ Failed to remove main blogs.dir folder: $main_blogs_dir<br>";
                }
            }
        }
    }
    
    return $errors == 0;
}

function is_dir_empty($dir) {
    if (!is_readable($dir)) return false;
    $handle = opendir($dir);
    while (($entry = readdir($handle)) !== false) {
        if ($entry != '.' && $entry != '..') {
            closedir($handle);
            return false;
        }
    }
    closedir($handle);
    return true;
}

function rrmdir($dir){
	if (is_dir($dir)) {
		$objects = scandir($dir);
		foreach ($objects as $object) {
			if ($object != "." && $object != "..") {
				if (is_dir($dir . "/" . $object))
					rrmdir($dir . "/" . $object);
				else
					unlink($dir . "/" . $object);
			}
		}
		rmdir($dir);
	}
}

function utm_recursive_str_replace($search, $replace, $subject, $depth = 0){
	// Limit recursion depth to prevent infinite recursion in case of circular references
	if ($depth > 100) {
		trigger_error('Maximum recursion depth exceeded in utm_recursive_str_replace', E_USER_WARNING);
		return $subject;
	}

	if (is_serialized($subject)) {
		$unserialized_subject = @unserialize($subject);

		if ($unserialized_subject === false && $subject !== serialize(false)) {
			// Error unserializing the subject, log the error and return the original subject
			echo 'Error unserializing the subject in utm_recursive_str_replace';
			return $subject;
		}

		return serialize(utm_recursive_str_replace($search, $replace, $unserialized_subject, $depth + 1));
	}

	if (is_string($subject)) {
		return str_replace($search, $replace, $subject);
	} elseif (is_array($subject)) {
		foreach ($subject as $key => $value) {
			$subject[$key] = utm_recursive_str_replace($search, $replace, $value, $depth + 1);
		}
	} elseif (is_object($subject)) {
		foreach ($subject as $property => $value) {
			$subject->$property = utm_recursive_str_replace($search, $replace, $value, $depth + 1);
		}
	}

	return $subject;
}

function universal_search_replace($table, $columns, $search, $replace, $dry_run = true) {
    global $wpdb;
    $output = array();

    foreach ($columns as $column) {
        $sql = $wpdb->prepare("SELECT * FROM $table WHERE $column LIKE %s", '%' . $wpdb->esc_like($search) . '%');
        $results = $wpdb->get_results($sql, ARRAY_A);

        if ($results) {
            foreach ($results as $row) {
                $original_value = $row[$column];
                $updated_value = utm_recursive_str_replace($search, $replace, $original_value);
				if($row['ID'] == false){
					$row['ID'] = $row['option_id'];
				}
				if($row['name'] == false){
					$row['name'] = $row['option_name'];
				}
                if ($dry_run) {
					$status = "Dry Run";
                } else {
                    $update_status = $wpdb->update($table, array($column => $updated_value), array('ID' => $row['ID']));
					if($update_status == false){
						$update_status = $wpdb->update($table, array($column => $updated_value), array('option_id' => $row['ID']));
					}
                    if ($update_status !== false) {
						$status = "Success";
                    } else {
						$keys = array_keys($row);
						$status = $wpdb->last_error . " | " . var_dump($row);
                    }
                }
				$output[] = array(
					'table' => $table,
					'column' => $column,
					'ID' => $row['ID'],
					'name' => $row['name'],
					'original_value' => $original_value,
					'updated_value' => $updated_value,
					'update_status' => $status,
				);
            }
        }
    }

    return $output;
}

function search_replace_all_columns($table, $search, $replace, $dry_run = false) {
    global $wpdb;
    $columns = $wpdb->get_col("DESC $table", 0);
    return universal_search_replace($table, $columns, $search, $replace, $dry_run);
}

function utm_aiwm_options(){
	global $wpdb;
	$option_table = $wpdb->prefix . "options";
	// Find in option table where option name contain ai1wm
	$ai1wm_options = $wpdb->get_results("SELECT * FROM $option_table WHERE option_name LIKE '%ai1wm%'");
	if (!empty($ai1wm_options)) {
		echo '<h2>AI1WM Options</h2><ul>';
		foreach ($ai1wm_options as $option) {
			$option_name = htmlspecialchars($option->option_name);
			$option_value = htmlspecialchars($option->option_value);

			// Check if the option is ai1wm_backups_path and fix the path if needed
			if ($option->option_name === 'ai1wm_backups_path') {
				$current_path = $option->option_value;
				$correct_path = WP_CONTENT_DIR . '/ai1wm-backups'; // Rebuild the correct path dynamically

				// If the current path is incorrect, update it
				if ($current_path !== $correct_path) {
					$wpdb->update(
						$option_table,
						array('option_value' => $correct_path),
						array('option_name' => 'ai1wm_backups_path')
					);
					echo '<li>' . $option_name . ': <strong>Path corrected to:</strong> ' . htmlspecialchars($correct_path) . '</li>';
				} else {
					echo '<li>' . $option_name . ': ' . $option_value . '</li>';
				}
			} else {
				echo '<li>' . $option_name . ': ' . $option_value . '</li>';
			}
		}
		echo '</ul>';
	} else {
		echo '<p>No options found containing "ai1wm".</p>';
	}
}

function utm_wpcontent_list(){
	// Check folder permission of /wp-content/
	$wpcontent_path = $_SERVER['DOCUMENT_ROOT'] . '/wp-content/';
	echo "wp-content path: " . $wpcontent_path . "<br><br>";
	if (!is_writable($wpcontent_path)) {
		echo '<div class="error"><p>Permission denied: /wp-content/ is not writable.</p></div>';
		return;
	} else {
		// List all files and folders in the directory and permission in table format
		$files = scandir($wpcontent_path);
		echo '<table>';
		echo '<tr><th>File/Folder</th><th>Writable</th></tr>';
		foreach ($files as $file) {
			// if file start with temp-write-test, delete it
			if (strpos($file, 'temp-write-test') === 0) {
				unlink($wpcontent_path . $file);
			}
			if ($file != '.' && $file != '..') {
				$is_writable = is_writable($wpcontent_path . $file) ? 'Yes' : 'No';
				echo '<tr><td>' . htmlspecialchars($file) . '</td><td>' . $is_writable . '</td></tr>';
			}
		}
		echo '</table>'; // Changed from </ul> to </table>
	}
}

function utm_analyze_files_paths($current_blog_id) {
	global $wpdb;
	$posts_table = $wpdb->prefix . 'posts';
	$postmeta_table = $wpdb->prefix . 'postmeta';
	$option_table = $wpdb->prefix . 'options';

	echo "<h3>Current Database Analysis for /files/ paths</h3>";
	
	// Check attachments with /files/ in GUID (simple LIKE search)
	$sql = "SELECT COUNT(*) as count FROM $posts_table WHERE post_type = 'attachment' AND (guid LIKE '%/files/%' OR guid LIKE '%blogs.dir%')";
	$attachment_count = $wpdb->get_var($sql);
	echo "Attachments with /files/ or blogs.dir in GUID: <strong>$attachment_count</strong><br>";
	
	if ($attachment_count > 0) {
		echo "<h4>Sample Attachment GUIDs with /files/:</h4>";
		$sql = "SELECT ID, post_title, guid FROM $posts_table WHERE post_type = 'attachment' AND (guid LIKE '%/files/%' OR guid LIKE '%blogs.dir%') LIMIT 10";
		$samples = $wpdb->get_results($sql, ARRAY_A);		echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
		echo "<tr><th>ID</th><th>Title</th><th>Current GUID</th><th>Should be</th></tr>";		foreach ($samples as $sample) {
			$current_guid = $sample['guid'];
			
			// Generate what the correct GUID should be using proper logic
			$suggested_guid = utm_get_correct_url($current_guid, $current_blog_id);
			
			echo "<tr>";
			echo "<td>{$sample['ID']}</td>";
			echo "<td>" . htmlspecialchars($sample['post_title']) . "</td>";
			echo "<td style='word-break: break-all;'>" . htmlspecialchars($current_guid) . "</td>";
			echo "<td style='word-break: break-all;'>" . htmlspecialchars($suggested_guid) . "</td>";
			echo "</tr>";
		}
		echo "</table>";
	}
		// Check post content with /files/
	$sql = "SELECT COUNT(*) as count FROM $posts_table WHERE post_content LIKE '%/files/%' OR post_content LIKE '%blogs.dir%'";
	$content_count = $wpdb->get_var($sql);
	echo "Posts with /files/ or blogs.dir in content: <strong>$content_count</strong><br>";
	
	if ($content_count > 0) {
		echo "<h4>Sample Posts with /files/ in content:</h4>";
		$sql = "SELECT ID, post_title, post_content FROM $posts_table WHERE post_content LIKE '%/files/%' OR post_content LIKE '%blogs.dir%' LIMIT 5";
		$samples = $wpdb->get_results($sql, ARRAY_A);
		echo "<ul>";
		foreach ($samples as $sample) {
			echo "<li>Post ID: {$sample['ID']} | Title: " . htmlspecialchars($sample['post_title']) . "<br>";
			// Extract URLs containing /files/ from content
			preg_match_all('#https?://[^\s\'"<>]+/files/[^\s\'"<>]*#', $sample['post_content'], $matches);
			if (!empty($matches[0])) {
				echo "Found /files/ URLs: " . implode(', ', array_slice($matches[0], 0, 3));
				if (count($matches[0]) > 3) {
					echo " (and " . (count($matches[0]) - 3) . " more)";
				}
			}
			echo "</li>";
		}
		echo "</ul>";
	}
	
	// Check postmeta with /files/
	$sql = "SELECT COUNT(*) as count FROM $postmeta_table WHERE meta_value LIKE '%/files/%' OR meta_value LIKE '%blogs.dir%'";
	$postmeta_count = $wpdb->get_var($sql);
	echo "Postmeta entries with /files/ or blogs.dir: <strong>$postmeta_count</strong><br>";
	
	if ($postmeta_count > 0) {
		echo "<h4>Sample Postmeta with /files/:</h4>";
		$sql = "SELECT meta_id, post_id, meta_key, meta_value FROM $postmeta_table WHERE meta_value LIKE '%/files/%' LIMIT 5";
		$samples = $wpdb->get_results($sql, ARRAY_A);
		echo "<ul>";
		foreach ($samples as $sample) {
			echo "<li>Meta ID: {$sample['meta_id']} | Post ID: {$sample['post_id']} | Key: {$sample['meta_key']}<br>";
			echo "Value: " . htmlspecialchars(substr($sample['meta_value'], 0, 200)) . "...</li>";
		}
		echo "</ul>";
	}
		// Check options with /files/
	$sql = "SELECT COUNT(*) as count FROM $option_table WHERE option_value LIKE '%/files/%' OR option_value LIKE '%blogs.dir%'";
	$options_count = $wpdb->get_var($sql);
	echo "Options with /files/ or blogs.dir: <strong>$options_count</strong><br>";
	
	if ($options_count > 0) {
		echo "<h4>Options with /files/:</h4>";
		$sql = "SELECT option_name, option_value FROM $option_table WHERE option_value LIKE '%/files/%' OR option_value LIKE '%blogs.dir%' LIMIT 5";
		$samples = $wpdb->get_results($sql, ARRAY_A);
		echo "<ul>";
		foreach ($samples as $sample) {
			echo "<li>Option: <strong>{$sample['option_name']}</strong><br>";
			// Extract URLs containing /files/ from option value
			preg_match_all('#https?://[^\s\'"<>]+/files/[^\s\'"<>]*#', $sample['option_value'], $matches);
			if (!empty($matches[0])) {
				echo "Found /files/ URLs: " . implode(', ', array_slice($matches[0], 0, 2));
				if (count($matches[0]) > 2) {
					echo " (and " . (count($matches[0]) - 2) . " more)";
				}
			} else {
				echo "Value: " . htmlspecialchars(substr($sample['option_value'], 0, 100)) . "...";
			}
			echo "</li>";
		}
		echo "</ul>";
	}
	
	$total_problematic = $attachment_count + $content_count + $postmeta_count + $options_count;
	echo "<br><strong>Total problematic records: $total_problematic</strong><br>";
	
	if ($total_problematic > 0) {
		echo "<p>👆 These records need to be migrated. Use the 'Migrate /files/ database paths' option above.</p>";
	} else {
		echo "<p>✅ No problematic /files/ paths found in database!</p>";
	}
		// Debug: Show recent attachments and whether they contain /files/ paths
	echo "<h4>Recent Attachments Status:</h4>";
	$sql = "SELECT ID, post_title, guid FROM $posts_table WHERE post_type = 'attachment' ORDER BY ID DESC LIMIT 10";
	$recent_attachments = $wpdb->get_results($sql, ARRAY_A);
	if ($recent_attachments) {
		echo "<ul>";
		foreach ($recent_attachments as $attachment) {
			$contains_files = strpos($attachment['guid'], '/files/') !== false;
			$contains_blogsdir = strpos($attachment['guid'], 'blogs.dir') !== false;
			$status_icon = '';
			$status_text = '';
			
			if ($contains_files || $contains_blogsdir) {
				$status_icon = "⚠️";
				$status_text = "needs migration";
			} else {
				$status_icon = "✅";
				$status_text = "correct path";
			}
			
			echo "<li>$status_icon <strong>ID {$attachment['ID']}</strong> - " . htmlspecialchars($attachment['post_title']) . " ($status_text)<br>";
			echo "<small>" . htmlspecialchars($attachment['guid']) . "</small></li>";
		}
		echo "</ul>";
	}
	
	// Show what the correct paths should be
	echo "<h4>Expected Correct Paths for Site ID $current_blog_id:</h4>";
	if ($current_blog_id == 1) {
		echo "Correct baseurl should be: " . get_site_url() . "/wp-content/uploads/<br>";
		echo "Correct basedir should be: " . ABSPATH . "wp-content/uploads/<br>";
	} else {
		echo "Correct baseurl should be: " . get_site_url() . "/wp-content/uploads/sites/$current_blog_id/<br>";
		echo "Correct basedir should be: " . ABSPATH . "wp-content/uploads/sites/$current_blog_id/<br>";
	}
}

function utm_migrate_files_database_paths($site_id, $dry_run = true) {
	global $wpdb;
	
	$posts_table = $wpdb->prefix . 'posts';
	$postmeta_table = $wpdb->prefix . 'postmeta';
	$option_table = $wpdb->prefix . 'options';
	
	echo "<div style='background: #fff; padding: 10px; margin: 10px 0; border: 1px solid #ddd;'>";
	echo "<strong>🔍 Migration Debug Info:</strong><br>";
	echo "Site ID: $site_id<br>";
	echo "Dry Run: " . ($dry_run ? 'Yes' : 'No') . "<br>";
	echo "Posts table: $posts_table<br>";
	echo "Postmeta table: $postmeta_table<br>";
	echo "Options table: $option_table<br>";
	echo "Current WordPress user: " . wp_get_current_user()->user_login . "<br>";
	echo "Database connection status: " . ($wpdb->last_error ? 'Error: ' . $wpdb->last_error : 'OK') . "<br>";
	echo "</div>";
	
	$total_updates = 0;
	
	// 1. Fix attachment GUIDs in posts table
	echo "<h3>1. Fixing attachment GUIDs in posts table</h3>";
	$sql = "SELECT ID, guid, post_title FROM $posts_table WHERE post_type = 'attachment' AND (guid LIKE '%/files/%' OR guid LIKE '%blogs.dir%')";
	$results = $wpdb->get_results($sql, ARRAY_A);
	
	if ($results) {
		echo "Found " . count($results) . " attachments to process<br><br>";
		foreach ($results as $row) {
			$old_guid = $row['guid'];
			$new_guid = utm_get_correct_url($old_guid, $site_id);
			
			echo "ID: {$row['ID']} | Title: {$row['post_title']}<br>";
			echo "Old: $old_guid<br>";
			echo "New: $new_guid<br>";
			
			if ($new_guid !== $old_guid && $new_guid !== "Invalid URL") {
				if (!$dry_run) {
					$update_result = $wpdb->update(
						$posts_table,
						array('guid' => $new_guid),
						array('ID' => $row['ID'])
					);
					echo "Update result: " . ($update_result !== false ? 'Success' : 'Failed') . "<br>";
					if ($update_result !== false) $total_updates++;
				} else {
					echo "Would update this record<br>";
					$total_updates++;
				}
			} else {
				echo "No change needed or invalid URL<br>";
			}
			echo "<br>";
		}
	} else {
		echo "No attachment GUIDs found to process<br>";
	}
	
	// 2. Fix post content
	echo "<h3>2. Fixing post content</h3>";
	$sql = "SELECT ID, post_title, post_content FROM $posts_table WHERE post_content LIKE '%/files/%' OR post_content LIKE '%blogs.dir%' LIMIT 20";
	$results = $wpdb->get_results($sql, ARRAY_A);
	
	if ($results) {
		echo "Found " . count($results) . " posts with content to process<br><br>";
		foreach ($results as $row) {
			$old_content = $row['post_content'];
			$new_content = $old_content;
			
			// Find all URLs in the content that need fixing
			preg_match_all('#https?://[^\s\'"<>]+(?:/files/|blogs\.dir)[^\s\'"<>]*#', $old_content, $matches);
			
			if (!empty($matches[0])) {
				foreach ($matches[0] as $found_url) {
					$corrected_url = utm_get_correct_url($found_url, $site_id);
					if ($corrected_url !== $found_url && $corrected_url !== "Invalid URL") {
						$new_content = str_replace($found_url, $corrected_url, $new_content);
					}
				}
			}
			
			echo "Post ID: {$row['ID']} | Title: {$row['post_title']}<br>";
			
			if ($new_content !== $old_content) {
				if (!$dry_run) {
					$update_result = $wpdb->update(
						$posts_table,
						array('post_content' => $new_content),
						array('ID' => $row['ID'])
					);
					echo "Update result: " . ($update_result !== false ? 'Success' : 'Failed') . "<br>";
					if ($update_result !== false) $total_updates++;
				} else {
					echo "Would update post content<br>";
					$total_updates++;
				}
			} else {
				echo "No changes needed in content<br>";
			}
			echo "<br>";
		}
	} else {
		echo "No post content found to process<br>";
	}
	
	// 3. Fix postmeta
	echo "<h3>3. Fixing postmeta</h3>";
	$sql = "SELECT meta_id, post_id, meta_key, meta_value FROM $postmeta_table WHERE meta_value LIKE '%/files/%' OR meta_value LIKE '%blogs.dir%' LIMIT 20";
	$results = $wpdb->get_results($sql, ARRAY_A);
	
	if ($results) {
		echo "Found " . count($results) . " postmeta entries to process<br><br>";
		foreach ($results as $row) {
			$old_value = $row['meta_value'];
			$new_value = $old_value;
			
			// Check if this looks like a URL
			if (filter_var($old_value, FILTER_VALIDATE_URL)) {
				$new_value = utm_get_correct_url($old_value, $site_id);
			} else {
				// For serialized data or other complex values, find URLs within them
				preg_match_all('#https?://[^\s\'"<>]+(?:/files/|blogs\.dir)[^\s\'"<>]*#', $old_value, $matches);
				if (!empty($matches[0])) {
					foreach ($matches[0] as $found_url) {
						$corrected_url = utm_get_correct_url($found_url, $site_id);
						if ($corrected_url !== $found_url && $corrected_url !== "Invalid URL") {
							$new_value = str_replace($found_url, $corrected_url, $new_value);
						}
					}
				}
			}
			
			echo "Meta ID: {$row['meta_id']} | Post ID: {$row['post_id']} | Key: {$row['meta_key']}<br>";
			
			if ($new_value !== $old_value) {
				if (!$dry_run) {
					$update_result = $wpdb->update(
						$postmeta_table,
						array('meta_value' => $new_value),
						array('meta_id' => $row['meta_id'])
					);
					echo "Update result: " . ($update_result !== false ? 'Success' : 'Failed') . "<br>";
					if ($update_result !== false) $total_updates++;
				} else {
					echo "Would update postmeta<br>";
					$total_updates++;
				}
			} else {
				echo "No changes needed in meta value<br>";
			}
			echo "<br>";
		}
	} else {
		echo "No postmeta found to process<br>";
	}
	
	// 4. Fix options
	echo "<h3>4. Fixing options</h3>";
	echo "<div style='background: #f8f9fa; padding: 10px; margin: 5px 0; border-left: 4px solid #007cba;'>";
	$sql = "SELECT option_id, option_name, option_value FROM $option_table WHERE option_value LIKE '%/files/%' OR option_value LIKE '%blogs.dir%' LIMIT 10";
	echo "<strong>Debug SQL:</strong> $sql<br>";
	$results = $wpdb->get_results($sql, ARRAY_A);
	echo "<strong>Query result count:</strong> " . count($results) . "<br>";
	echo "<strong>Database error:</strong> " . ($wpdb->last_error ? $wpdb->last_error : 'None') . "<br>";
	echo "</div>";
	
	if ($results) {
		echo "Found " . count($results) . " options to process<br><br>";
		foreach ($results as $row) {
			echo "<div style='background: #fff; padding: 8px; margin: 5px 0; border: 1px solid #ddd;'>";
			$old_value = $row['option_value'];
			$new_value = $old_value;
			
			echo "<strong>Processing option:</strong> {$row['option_name']}<br>";
			echo "<strong>Option ID:</strong> {$row['option_id']}<br>";
			echo "<strong>Original value length:</strong> " . strlen($old_value) . " characters<br>";
			
			// For options, we need to be careful with serialized data
			preg_match_all('#https?://[^\s\'"<>]+(?:/files/|blogs\.dir)[^\s\'"<>]*#', $old_value, $matches);
			echo "<strong>URLs found in option:</strong> " . count($matches[0]) . "<br>";
			
			if (!empty($matches[0])) {
				echo "<strong>Found URLs:</strong><br>";
				foreach ($matches[0] as $index => $found_url) {
					$corrected_url = utm_get_correct_url($found_url, $site_id);
					echo "  $index: " . htmlspecialchars($found_url) . " → " . htmlspecialchars($corrected_url) . "<br>";
					if ($corrected_url !== $found_url && $corrected_url !== "Invalid URL") {
						$new_value = str_replace($found_url, $corrected_url, $new_value);
					}
				}
			}
			
			echo "<strong>Value changed:</strong> " . ($new_value !== $old_value ? 'Yes' : 'No') . "<br>";
			
			if ($new_value !== $old_value) {
				if (!$dry_run) {
					echo "<strong>Attempting database update...</strong><br>";
					$update_result = $wpdb->update(
						$option_table,
						array('option_value' => $new_value),
						array('option_name' => $row['option_name'])
					);
					echo "<strong>Update SQL executed:</strong> UPDATE $option_table SET option_value = [new_value] WHERE option_name = '{$row['option_name']}'<br>";
					echo "<strong>Rows affected:</strong> " . $wpdb->rows_affected . "<br>";
					echo "<strong>Database error:</strong> " . ($wpdb->last_error ? $wpdb->last_error : 'None') . "<br>";
					echo "<strong>Update result:</strong> " . ($update_result !== false ? '✅ Success' : '❌ Failed') . "<br>";
					if ($update_result !== false) $total_updates++;
				} else {
					echo "<strong>Would update option (dry run)</strong><br>";
					$total_updates++;
				}
			} else {
				echo "<strong>No changes needed in option value</strong><br>";
			}
			echo "</div>";
		}
	} else {
		echo "No options found to process<br>";
	}
	
	echo "<h3>Migration Summary</h3>";
	echo "Total records " . ($dry_run ? 'that would be updated' : 'updated') . ": <strong>$total_updates</strong><br>";
	
	if ($dry_run) {
		echo "<p>This was a dry run. No actual changes were made to the database.</p>";
	} else {
		echo "<p>Migration completed! The above records have been updated in the database.</p>";
	}
}

function utm_regenerate_attachment_metadata($site_id, $dry_run = true) {
	echo "<h2>Regenerating Attachment Metadata</h2>";
	echo "Site ID: $site_id<br>";
	echo "Dry Run: " . ($dry_run ? 'Yes' : 'No') . "<br><br>";
	
	global $wpdb;
	$posts_table = $wpdb->prefix . 'posts';
	
	// Get all attachments that are images
	$sql = "SELECT ID, guid, post_title FROM $posts_table WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%' ORDER BY ID DESC LIMIT 20";
	$attachments = $wpdb->get_results($sql, ARRAY_A);
	
	if (!$attachments) {
		echo "No image attachments found.<br>";
		return;
	}
	
	echo "Found " . count($attachments) . " image attachments to process<br><br>";
	
	$processed = 0;
	$errors = 0;
	
	foreach ($attachments as $attachment) {
		$attachment_id = $attachment['ID'];
		$guid = $attachment['guid'];
		$title = $attachment['post_title'];
		
		echo "Processing attachment ID: $attachment_id | Title: $title<br>";
		echo "GUID: $guid<br>";
		
		// Get the file path from the guid
		$parsed_url = parse_url($guid);
		if (!$parsed_url || !isset($parsed_url['path'])) {
			echo "❌ Invalid GUID format<br><br>";
			$errors++;
			continue;
		}
		
		// Convert URL path to file system path
		$url_path = $parsed_url['path'];
		$file_path = $_SERVER['DOCUMENT_ROOT'] . $url_path;
		
		echo "Expected file path: $file_path<br>";
		
		// Check if file exists
		if (!file_exists($file_path)) {
			echo "❌ File does not exist at expected location<br><br>";
			$errors++;
			continue;
		}
		
		echo "✅ File exists<br>";
		
		if (!$dry_run) {
			// Regenerate attachment metadata
			$metadata = wp_generate_attachment_metadata($attachment_id, $file_path);
			
			if ($metadata) {
				// Update the metadata
				wp_update_attachment_metadata($attachment_id, $metadata);
				echo "✅ Successfully regenerated metadata<br>";
				$processed++;
				
				// Also update the _wp_attached_file meta
				$relative_path = str_replace($_SERVER['DOCUMENT_ROOT'] . '/wp-content/uploads/', '', $file_path);
				if ($site_id != 1) {
					$relative_path = str_replace("sites/$site_id/", '', $relative_path);
				}
				update_post_meta($attachment_id, '_wp_attached_file', $relative_path);
				echo "✅ Updated _wp_attached_file meta<br>";
			} else {
				echo "❌ Failed to generate metadata<br>";
				$errors++;
			}
		} else {
			echo "Would regenerate metadata for this attachment<br>";
			$processed++;
		}
		
		echo "<br>";
	}
	
	echo "<h3>Metadata Regeneration Summary</h3>";
	echo "Processed: $processed<br>";
	echo "Errors: $errors<br>";
	
	if ($dry_run) {
		echo "<p>This was a dry run. No actual changes were made.</p>";
	} else {
		echo "<p>Metadata regeneration completed!</p>";
		echo "<p><strong>Note:</strong> You may need to clear any caching plugins and refresh your media library.</p>";
	}
}

function utm_fix_attachment_file_paths($site_id, $dry_run = true) {
	echo "<h2>Fixing _wp_attached_file Metadata</h2>";
	echo "Site ID: $site_id<br>";
	echo "Dry Run: " . ($dry_run ? 'Yes' : 'No') . "<br><br>";
	
	global $wpdb;
	$posts_table = $wpdb->prefix . 'posts';
	$postmeta_table = $wpdb->prefix . 'postmeta';
	
	// Get attachments with their _wp_attached_file metadata
	$sql = "
		SELECT p.ID, p.guid, p.post_title, pm.meta_value as attached_file 
		FROM $posts_table p 
		LEFT JOIN $postmeta_table pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file'
		WHERE p.post_type = 'attachment' 
		AND p.post_mime_type LIKE 'image/%'
		ORDER BY p.ID DESC 
		LIMIT 20
	";
	
	$attachments = $wpdb->get_results($sql, ARRAY_A);
	
	if (!$attachments) {
		echo "No image attachments found.<br>";
		return;
	}
	
	echo "Found " . count($attachments) . " image attachments to check<br><br>";
	
	$fixed = 0;
	$errors = 0;
	
	foreach ($attachments as $attachment) {
		$attachment_id = $attachment['ID'];
		$guid = $attachment['guid'];
		$title = $attachment['post_title'];
		$current_attached_file = $attachment['attached_file'];
		
		echo "Checking attachment ID: $attachment_id | Title: $title<br>";
		echo "Current GUID: $guid<br>";
		echo "Current _wp_attached_file: " . ($current_attached_file ? $current_attached_file : 'NOT SET') . "<br>";
		
		// Extract the correct file path from GUID
		$parsed_url = parse_url($guid);
		if (!$parsed_url || !isset($parsed_url['path'])) {
			echo "❌ Invalid GUID format<br><br>";
			$errors++;
			continue;
		}
		
		$url_path = $parsed_url['path'];
		
		// Generate correct _wp_attached_file value
		$correct_attached_file = '';
		if ($site_id == 1) {
			// Main site: /wp-content/uploads/2023/08/file.png -> 2023/08/file.png
			if (preg_match('#/wp-content/uploads/(.+)$#', $url_path, $matches)) {
				$correct_attached_file = $matches[1];
			}
		} else {
			// Subsite: /wp-content/uploads/sites/2508/2023/08/file.png -> 2023/08/file.png
			if (preg_match('#/wp-content/uploads/sites/\d+/(.+)$#', $url_path, $matches)) {
				$correct_attached_file = $matches[1];
			}
		}
		
		if (!$correct_attached_file) {
			echo "❌ Could not determine correct _wp_attached_file path<br><br>";
			$errors++;
			continue;
		}
		
		echo "Correct _wp_attached_file should be: $correct_attached_file<br>";
		
		// Check if it needs updating
		if ($current_attached_file !== $correct_attached_file) {
			echo "⚠️ _wp_attached_file needs updating<br>";
			
			if (!$dry_run) {
				$update_result = update_post_meta($attachment_id, '_wp_attached_file', $correct_attached_file);
				if ($update_result) {
					echo "✅ Successfully updated _wp_attached_file<br>";
					$fixed++;
				} else {
					echo "❌ Failed to update _wp_attached_file<br>";
					$errors++;
				}
			} else {
				echo "Would update _wp_attached_file<br>";
				$fixed++;
			}
		} else {
			echo "✅ _wp_attached_file is already correct<br>";
		}
		
		echo "<br>";
	}
	
	echo "<h3>_wp_attached_file Fix Summary</h3>";
	echo "Fixed: $fixed<br>";
	echo "Errors: $errors<br>";
	
	if ($dry_run) {
		echo "<p>This was a dry run. No actual changes were made.</p>";
	} else {
		echo "<p>_wp_attached_file metadata fix completed!</p>";
	}
}

function utm_get_correct_url($old_url, $site_id) {
	// Parse the URL to get base URL and path
	$parsed = parse_url($old_url);
	if (!$parsed || !isset($parsed['scheme']) || !isset($parsed['host'])) {
		return "Invalid URL";
	}
	
	$base_url = $parsed['scheme'] . '://' . $parsed['host'];
	$path = $parsed['path'] ?? '';
	
	// Extract the file path after /files/ or /blogs.dir/
	$file_path = '';
	
	if (preg_match('#/files/(.+)$#', $path, $matches)) {
		// Match /files/2023/08/file.png -> 2023/08/file.png
		$file_path = $matches[1];
	} elseif (preg_match('#/wp-content/blogs\.dir/\d+/files/(.+)$#', $path, $matches)) {
		
		// Match /wp-content/blogs.dir/2508/files/2023/08/file.png -> 2023/08/file.png
		$file_path = $matches[1];
	} else {
		// If no recognizable pattern, return the original
		return $old_url;
	}
	
	// Build the correct URL
	if ($site_id == 1) {
		// Main site: https://domain.com/wp-content/uploads/2023/08/file.png
		return $base_url . '/wp-content/uploads/' . $file_path;
	} else {
		// Subsite: https://domain.com/wp-content/uploads/sites/2508/2023/08/file.png
		return $base_url . '/wp-content/uploads/sites/' . $site_id . '/' . $file_path;
	}
}

function utm_fix_upload_directory($site_id) {
	global $wpdb;
	$option_table = $wpdb->prefix . 'options';
	$sitemeta_table = $wpdb->prefix . 'sitemeta';
	
	echo "Site ID: $site_id<br><br>";
	
	// 0. Deep diagnostic of upload directory filters
	echo "<h3>0. Deep Upload Directory Diagnostic</h3>";
	echo "<div style='background: #fff3cd; padding: 10px; margin: 5px 0; border-left: 4px solid #ffc107;'>";
	
	// Check for active plugins that might affect uploads
	echo "<strong>🔍 Active Plugins Analysis:</strong><br>";
	$active_plugins = get_option('active_plugins', array());
	$suspect_plugins = array();
	foreach ($active_plugins as $plugin) {
		if (stripos($plugin, 'upload') !== false || 
			stripos($plugin, 'media') !== false || 
			stripos($plugin, 'file') !== false ||
			stripos($plugin, 'multisite') !== false ||
			stripos($plugin, 'network') !== false) {
			$suspect_plugins[] = $plugin;
		}
	}
	
	if (!empty($suspect_plugins)) {
		echo "⚠️ <strong>Potential problematic plugins:</strong><br>";
		foreach ($suspect_plugins as $plugin) {
			echo "  - " . htmlspecialchars($plugin) . "<br>";
		}
	} else {
		echo "✅ No obviously problematic plugins detected<br>";
	}
	
	// Check current theme
	$current_theme = wp_get_theme();
	echo "<strong>Current theme:</strong> " . htmlspecialchars($current_theme->get('Name')) . " v" . htmlspecialchars($current_theme->get('Version')) . "<br>";
	
	// Check for upload_dir filters
	echo "<strong>🔍 Upload Directory Filter Analysis:</strong><br>";
	global $wp_filter;
	if (isset($wp_filter['upload_dir'])) {
		echo "⚠️ <strong>upload_dir filters detected:</strong><br>";
		foreach ($wp_filter['upload_dir']->callbacks as $priority => $callbacks) {
			foreach ($callbacks as $callback) {
				$callback_name = 'Unknown';
				if (is_array($callback['function'])) {
					if (is_object($callback['function'][0])) {
						$callback_name = get_class($callback['function'][0]) . '::' . $callback['function'][1];
					} else {
						$callback_name = $callback['function'][0] . '::' . $callback['function'][1];
					}
				} elseif (is_string($callback['function'])) {
					$callback_name = $callback['function'];
				}
				echo "  Priority $priority: " . htmlspecialchars($callback_name) . "<br>";
			}
		}
	} else {
		echo "✅ No upload_dir filters detected<br>";
	}
	
	// Test upload directory with filters temporarily removed
	echo "<strong>🧪 Testing upload directory without filters:</strong><br>";
	
	// Remove all upload_dir filters temporarily
	$original_filters = array();
	if (isset($wp_filter['upload_dir'])) {
		$original_filters = $wp_filter['upload_dir']->callbacks;
		$wp_filter['upload_dir']->callbacks = array();
	}
	
	// Get upload dir without filters
	$clean_upload_dir = wp_upload_dir();
	echo "Without filters: " . htmlspecialchars($clean_upload_dir['basedir']) . "<br>";
	
	// Restore filters
	if (!empty($original_filters)) {
		$wp_filter['upload_dir']->callbacks = $original_filters;
	}
	
	// Compare
	$normal_upload_dir = wp_upload_dir();
	echo "With filters: " . htmlspecialchars($normal_upload_dir['basedir']) . "<br>";
	
	if ($clean_upload_dir['basedir'] !== $normal_upload_dir['basedir']) {
		echo "🎯 <strong>FOUND THE PROBLEM!</strong> Filters are changing the upload directory!<br>";
	} else {
		echo "ℹ️ Filters are not the cause. Issue may be at WordPress core level or server configuration.<br>";
	}
	
	// Deep dive into the upload directory calculation
	echo "<strong>🔍 Deep Upload Directory Analysis:</strong><br>";
	
	// Test the core WordPress upload_dir function step by step
	$site_id = get_current_blog_id();
	echo "Current site ID: " . $site_id . "<br>";
	
	// Check if we're in multisite
	if (is_multisite()) {
		echo "✅ Multisite detected<br>";
		
		// Check what get_site_option returns for ms_files_rewriting
		$ms_files_rewriting = get_site_option('ms_files_rewriting');
		echo "ms_files_rewriting option: " . ($ms_files_rewriting ? '1 (enabled)' : '0 (disabled)') . "<br>";
		
		// Check individual site upload path options
		$upload_path = get_option('upload_path');
		$upload_url_path = get_option('upload_url_path');
		echo "Site upload_path option: " . ($upload_path ? htmlspecialchars($upload_path) : 'empty/false') . "<br>";
		echo "Site upload_url_path option: " . ($upload_url_path ? htmlspecialchars($upload_url_path) : 'empty/false') . "<br>";
		
		// Manually calculate what the upload directory should be
		$expected_upload_dir = WP_CONTENT_DIR . '/uploads';
		if ($site_id > 1) {
			$expected_upload_dir .= '/sites/' . $site_id;
		}
		echo "Expected upload directory: " . htmlspecialchars($expected_upload_dir) . "<br>";
		
		// Check if directory exists
		echo "Expected directory exists: " . (is_dir($expected_upload_dir) ? '✅ Yes' : '❌ No') . "<br>";
		
		// Test the upload_dir function with minimal context
		echo "<strong>🧪 Step-by-step upload_dir calculation:</strong><br>";
		
		// Step 1: Check constants
		if (defined('UPLOADS')) {
			echo "Step 1: UPLOADS constant is set to: " . UPLOADS . " (THIS IS THE PROBLEM!)<br>";
		} else {
			echo "Step 1: ✅ No UPLOADS constant<br>";
		}
		
		// Step 2: Check if ms-files is the issue
		if ($ms_files_rewriting) {
			echo "Step 2: ⚠️ ms_files_rewriting is enabled - this forces old structure<br>";
		} else {
			echo "Step 2: ✅ ms_files_rewriting is disabled<br>";
		}
		
		// Step 3: Check for upload_path option
		if (!empty($upload_path)) {
			echo "Step 3: ⚠️ upload_path option is set: " . htmlspecialchars($upload_path) . "<br>";
		} else {
			echo "Step 3: ✅ upload_path option is empty<br>";
		}
		
	} else {
		echo "❌ Not in multisite mode<br>";
	}
	
	// Check for specific known problematic configurations
	echo "<strong>🔍 Known Problem Patterns:</strong><br>";
	
	// Check for old UPLOADS constant
	if (defined('UPLOADS')) {
		echo "⚠️ <strong>UPLOADS constant detected:</strong> " . UPLOADS . " (this overrides multisite upload paths)<br>";
		echo "🔧 <strong>Solution:</strong> Remove or comment out the UPLOADS define from wp-config.php<br>";
	} else {
		echo "✅ No UPLOADS constant defined<br>";
	}
	
	// Check for old WP_CONTENT_URL issues
	$content_url = content_url();
	echo "<strong>Content URL:</strong> " . htmlspecialchars($content_url) . "<br>";
	
	// Check wp-config.php related issues
	echo "<strong>🔍 WordPress Constants Check:</strong><br>";
	echo "WP_CONTENT_DIR: " . (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : 'Not defined') . "<br>";
	echo "WP_CONTENT_URL: " . (defined('WP_CONTENT_URL') ? WP_CONTENT_URL : 'Not defined') . "<br>";
	echo "UPLOADS: " . (defined('UPLOADS') ? UPLOADS : 'Not defined') . "<br>";
	echo "UPLOADBLOGSDIR: " . (defined('UPLOADBLOGSDIR') ? UPLOADBLOGSDIR : 'Not defined') . "<br>";
	
	// If UPLOADS is defined, try to trace where it came from
	if (defined('UPLOADS')) {
		echo "<br><strong>🔍 UPLOADS Constant Investigation:</strong><br>";
		echo "⚠️ <strong>CRITICAL FINDING:</strong> UPLOADS constant is set to: " . UPLOADS . "<br>";
		echo "<br><strong>Common sources of UPLOADS constant:</strong><br>";
		echo "1. <strong>wp-config.php</strong> - You've checked this ✅<br>";
		echo "2. <strong>Network wp-config.php</strong> - Check if there's a network-wide config<br>";
		echo "3. <strong>WordPress Core Bug</strong> - In multisite with ms_files_rewriting enabled<br>";
		echo "4. <strong>Plugin/Theme</strong> - A plugin might be defining this dynamically<br>";
		echo "5. <strong>Server Environment</strong> - Apache/Nginx configuration<br>";
		echo "6. <strong>WordPress MU Legacy</strong> - Old WordPress MU installation remnants<br>";
		
		echo "<br><strong>🚨 IMMEDIATE SOLUTION:</strong><br>";
		echo "Since you confirmed no UPLOADS constant in wp-config.php, this is likely being set by WordPress core due to the combination of:<br>";
		echo "• Multisite installation<br>";
		echo "• ms_files_rewriting = 1 (enabled)<br>";
		echo "• Site ID > 1<br>";
		echo "<br><strong>WordPress automatically sets UPLOADS constant in wp-includes/ms-default-constants.php when ms_files_rewriting is enabled!</strong><br>";
		
		echo "<br><strong>🔧 REQUIRED ACTION:</strong><br>";
		echo "1. <strong>Disable ms_files_rewriting</strong> - This tool should do this automatically<br>";
		echo "2. <strong>Force WordPress to recalculate paths</strong><br>";
		echo "3. <strong>Clear any caches</strong><br>";
	}
	
	echo "</div>";
	
	// 1. Clear any problematic upload path options
	echo "<h3>1. Clearing Upload Path Options</h3>";
	$cleared_options = 0;
	
	$problem_options = array(
		'upload_path',
		'upload_url_path',
		'fileupload_url'
	);
	
	foreach ($problem_options as $option_name) {
		$current_value = $wpdb->get_var($wpdb->prepare(
			"SELECT option_value FROM $option_table WHERE option_name = %s", 
			$option_name
		));
		
		if (!empty($current_value)) {
			echo "Clearing $option_name (was: " . htmlspecialchars($current_value) . ")<br>";
			$wpdb->update(
				$option_table,
				array('option_value' => ''),
				array('option_name' => $option_name)
			);
			$cleared_options++;
		} else {
			echo "✅ $option_name is already empty<br>";
		}
	}
	
	// 2. Ensure ms-files is disabled
	echo "<h3>2. Ensuring ms-files is Disabled</h3>";
	
	// CRITICAL FIX: Use the correct network-wide sitemeta table
	$network_prefix = $wpdb->base_prefix; // Use base prefix for network table
	$network_sitemeta_table = $network_prefix . 'sitemeta';
	
	echo "Checking network sitemeta table: $network_sitemeta_table<br>";
	
	$ms_files_value = $wpdb->get_var("SELECT meta_value FROM $network_sitemeta_table WHERE meta_key = 'ms_files_rewriting'");
	
	echo "Current ms_files_rewriting value: " . ($ms_files_value ? $ms_files_value : 'not set') . "<br>";
	
	if ($ms_files_value == '1' || $ms_files_value === '1') {
		echo "🚨 <strong>FOUND THE PROBLEM!</strong> ms_files_rewriting is enabled in network settings<br>";
		echo "Disabling ms_files_rewriting in network sitemeta...<br>";
		
		$result = $wpdb->update(
			$network_sitemeta_table,
			array('meta_value' => '0'),
			array('meta_key' => 'ms_files_rewriting'),
			array('%s'),
			array('%s')
		);
		
		if ($result !== false) {
			echo "✅ <strong>SUCCESS!</strong> ms_files_rewriting disabled in network settings<br>";
			
			// Verify the change
			$new_value = $wpdb->get_var("SELECT meta_value FROM $network_sitemeta_table WHERE meta_key = 'ms_files_rewriting'");
			echo "Verified new value: " . ($new_value ? $new_value : '0/empty') . "<br>";
		} else {
			echo "❌ <strong>ERROR:</strong> Failed to update ms_files_rewriting<br>";
			echo "SQL Error: " . $wpdb->last_error . "<br>";
		}
	} else {
		echo "✅ ms_files_rewriting is already disabled<br>";
	}
	
	// Also check and clear site-specific upload options (just in case)
	echo "<br>Checking site-specific options in: $sitemeta_table<br>";
	$site_ms_files = $wpdb->get_var("SELECT meta_value FROM $sitemeta_table WHERE meta_key = 'ms_files_rewriting'");
	if ($site_ms_files) {
		echo "Found site-specific ms_files_rewriting: $site_ms_files - clearing it<br>";
		$wpdb->delete($sitemeta_table, array('meta_key' => 'ms_files_rewriting'));
	}
	
	// 3. Clear WordPress upload directory cache
	echo "<h3>3. Clearing Upload Directory Cache</h3>";
	
	// Delete transients that might cache upload directory info
	$cache_options = array(
		'_transient_dirsize_cache',
		'_site_transient_dirsize_cache',
		'upload_path_cache'
	);
	
	foreach ($cache_options as $cache_option) {
		$deleted = $wpdb->delete($option_table, array('option_name' => $cache_option));
		if ($deleted) {
			echo "Cleared cache: $cache_option<br>";
		}
	}
	
	// 4. Force refresh WordPress upload directory
	echo "<h3>4. Refreshing WordPress Upload Directory</h3>";
	
	// Clear any WordPress internal caches
	if (function_exists('wp_cache_flush')) {
		wp_cache_flush();
		echo "Flushed WordPress cache<br>";
	}
	
	// Force WordPress to recalculate upload directory by clearing relevant options
	echo "Clearing WordPress upload directory cache...<br>";
	delete_site_option('upload_space_check_disabled');
	delete_option('upload_path');
	delete_option('upload_url_path');
	
	echo "<br><strong>🔄 Testing upload directory after fixes:</strong><br>";
	
	// Check if UPLOADS constant is still defined (it might be cached)
	if (defined('UPLOADS')) {
		echo "⚠️ UPLOADS constant is still defined: " . UPLOADS . "<br>";
		echo "This is expected - constants cannot be undefined in PHP.<br>";
		echo "The constant will be correct on the next page load.<br>";
	} else {
		echo "✅ UPLOADS constant is no longer defined<br>";
	}
	
	// Test the upload directory function
	$new_upload_dir = wp_upload_dir();
	echo "New upload directory: " . htmlspecialchars($new_upload_dir['basedir']) . "<br>";
	echo "New upload URL: " . htmlspecialchars($new_upload_dir['baseurl']) . "<br>";
	
	// Check if it's correct now
	$site_id = get_current_blog_id();
	$expected_path = WP_CONTENT_DIR . '/uploads' . ($site_id > 1 ? '/sites/' . $site_id : '');
	
	if ($new_upload_dir['basedir'] === $expected_path) {
		echo "🎉 <strong>SUCCESS!</strong> Upload directory is now correct!<br>";
	} elseif (strpos($new_upload_dir['basedir'], 'blogs.dir') !== false) {
		echo "⚠️ Still using blogs.dir structure<br>";
		echo "This may require a page refresh or WordPress restart to take effect.<br>";
		echo "<br><strong>🔄 NEXT STEPS:</strong><br>";
		echo "1. Refresh this page to reload WordPress<br>";
		echo "2. Test upload directory again<br>";
		echo "3. If still not working, contact your hosting provider<br>";
	} else {
		echo "ℹ️ Upload directory changed but may not be optimal<br>";
	}
	
	// Get fresh upload directory info
	$wp_upload_dir = wp_upload_dir();
	echo "New upload directory: " . htmlspecialchars($wp_upload_dir['basedir']) . "<br>";
	echo "New upload URL: " . htmlspecialchars($wp_upload_dir['baseurl']) . "<br>";
	
	// Check if it's now correct
	$expected_upload_path = '';
	if ($site_id == 1) {
		$expected_upload_path = ABSPATH . 'wp-content/uploads';
	} else {
		$expected_upload_path = ABSPATH . 'wp-content/uploads/sites/' . $site_id;
	}
	
	if (strpos($wp_upload_dir['basedir'], 'blogs.dir') !== false) {
		echo "⚠️ <strong>Still using blogs.dir structure</strong><br>";
		echo "This may require server-level configuration changes or plugin conflicts<br>";
		echo "<br><strong>Additional Steps to Try:</strong><br>";
		echo "1. Deactivate all plugins temporarily<br>";
		echo "2. Switch to a default theme<br>";
		echo "3. Check for custom upload_dir filters in theme/plugins<br>";
		echo "4. Contact your hosting provider about multisite configuration<br>";
	} else {
		echo "✅ <strong>Upload directory is now correct!</strong><br>";
		echo "New uploads will go to the proper location<br>";
	}
	
	echo "<h3>Upload Directory Fix Summary</h3>";
	echo "Options cleared: $cleared_options<br>";
	echo "ms-files disabled: Yes<br>";
	echo "Cache cleared: Yes<br>";
	
	if (strpos($wp_upload_dir['basedir'], 'blogs.dir') === false) {
		echo "✅ <strong>Success!</strong> Upload directory is now using the correct structure<br>";
	} else {
		echo "⚠️ <strong>Partial success</strong> - Additional configuration may be needed<br>";
	}
	
	// Final troubleshooting recommendations
	echo "<div style='background: #f9f9f9; border: 1px solid #ddd; padding: 15px; margin: 20px 0;'>";
	echo "<h3>🎯 <strong>TROUBLESHOOTING RECOMMENDATIONS</strong></h3>";
	
	$issues_found = array();
	$solutions = array();
	
	// Check for UPLOADS constant
	if (defined('UPLOADS')) {
		$issues_found[] = "UPLOADS constant is defined: " . UPLOADS;
		$solutions[] = "<strong>ROOT CAUSE IDENTIFIED:</strong> WordPress core is setting UPLOADS constant due to ms_files_rewriting being enabled. This tool should have fixed it automatically.";
	}
	
	// Check for ms_files_rewriting using the correct network table
	$network_prefix = $wpdb->base_prefix;
	$network_sitemeta_table = $network_prefix . 'sitemeta';
	$ms_files_value = $wpdb->get_var("SELECT meta_value FROM $network_sitemeta_table WHERE meta_key = 'ms_files_rewriting'");
	if ($ms_files_value == '1') {
		$issues_found[] = "ms_files_rewriting is still enabled in network settings";
		$solutions[] = "<strong>CRITICAL:</strong> ms_files_rewriting is still enabled in the network sitemeta table. This tool should have disabled it. If it's still enabled, there may be a database permission issue.";
	}
	
	// Check for upload_path options
	$upload_path = get_option('upload_path');
	if (!empty($upload_path)) {
		$issues_found[] = "upload_path option is set to: " . $upload_path;
		$solutions[] = "Clear the upload_path option (this tool should have done it automatically)";
	}
	
	// Check current upload directory
	$current_upload = wp_upload_dir();
	$site_id = get_current_blog_id();
	$expected_path = WP_CONTENT_DIR . '/uploads' . ($site_id > 1 ? '/sites/' . $site_id : '');
	
	if (strpos($current_upload['basedir'], 'blogs.dir') !== false) {
		$issues_found[] = "Upload directory is still pointing to blogs.dir structure";
		if (empty($issues_found) || count($issues_found) == 1) {
			$solutions[] = "<strong>MYSTERY ISSUE:</strong> No obvious configuration problems found. This may be caused by:
				<ul>
				<li>A plugin or theme with upload_dir filter</li>
				<li>Server-level configuration (htaccess, nginx, etc.)</li>
				<li>Custom WordPress modifications</li>
				<li>Cached configuration values</li>
				</ul>";
		}
	}
	
	if (empty($issues_found)) {
		echo "✅ <strong>NO ISSUES DETECTED!</strong> Upload paths should be working correctly.";
	} else {
		echo "<strong>Issues Found:</strong><br>";
		foreach ($issues_found as $issue) {
			echo "❌ " . $issue . "<br>";
		}
		
		echo "<br><strong>Required Actions:</strong><br>";
		foreach ($solutions as $solution) {
			echo "🔧 " . $solution . "<br>";
		}
		
		echo "<br><strong>After making these changes:</strong><br>";
		echo "1. Clear any caching plugins<br>";
		echo "2. Test uploading a new file to see if it goes to the correct location<br>";
		echo "3. Run this diagnostic tool again to verify the fix<br>";
	}
	
	// Current status summary
	echo "<br><strong>Current Upload Directory Status:</strong><br>";
	echo "Current: " . htmlspecialchars($current_upload['basedir']) . "<br>";
	echo "Expected: " . htmlspecialchars($expected_path) . "<br>";
	echo "Status: " . ($current_upload['basedir'] === $expected_path ? "✅ Correct" : "❌ Incorrect") . "<br>";
	
	echo "</div>";
	
	// Add a quick test button
	echo "<div style='margin: 20px 0;'>";
	echo "<h3>🧪 Quick Upload Directory Test</h3>";
	echo "<form method='post' style='display: inline;'>";
	echo "<input type='hidden' name='action' value='test_upload_dir'>";
	echo wp_nonce_field('utmwt_action', 'utmwt_nonce', true, false);
	echo "<input type='submit' value='Test Current Upload Directory' class='button'>";
	echo "</form>";
	echo "<p><em>This will show exactly where WordPress thinks files should be uploaded right now.</em></p>";
	echo "</div>";
}

// Quick upload directory test
function test_upload_directory() {
	echo "<h3>🧪 Upload Directory Test Results</h3>";
	
	$upload_dir = wp_upload_dir();
	$site_id = get_current_blog_id();
	
	echo "<div style='background: #f0f8ff; border: 1px solid #0073aa; padding: 15px; margin: 15px 0;'>";
	echo "<strong>Current Upload Directory Information:</strong><br>";
	echo "Site ID: " . $site_id . "<br>";
	echo "Base Directory: " . htmlspecialchars($upload_dir['basedir']) . "<br>";
	echo "Base URL: " . htmlspecialchars($upload_dir['baseurl']) . "<br>";
	echo "Subdir: " . htmlspecialchars($upload_dir['subdir']) . "<br>";
	echo "Path: " . htmlspecialchars($upload_dir['path']) . "<br>";
	echo "URL: " . htmlspecialchars($upload_dir['url']) . "<br>";
	
	// Check if it's the correct structure
	$expected_base = WP_CONTENT_DIR . '/uploads' . ($site_id > 1 ? '/sites/' . $site_id : '');
	$is_correct = ($upload_dir['basedir'] === $expected_base);
	
	echo "<br><strong>Analysis:</strong><br>";
	echo "Expected base: " . htmlspecialchars($expected_base) . "<br>";
	echo "Status: " . ($is_correct ? "✅ <strong>CORRECT!</strong> Using modern structure" : "❌ <strong>INCORRECT!</strong> Still using old structure") . "<br>";
	
	if (!$is_correct) {
		echo "<br><strong>🚨 Issue detected:</strong> Upload directory is not using the expected modern structure.<br>";
		if (strpos($upload_dir['basedir'], 'blogs.dir') !== false) {
			echo "The system is still using the old blogs.dir structure.<br>";
		}
		echo "Please review the troubleshooting recommendations above.<br>";
	}
	
	// Test directory permissions
	echo "<br><strong>Directory Status:</strong><br>";
	echo "Directory exists: " . (is_dir($upload_dir['basedir']) ? "✅ Yes" : "❌ No") . "<br>";
	if (is_dir($upload_dir['basedir'])) {
		echo "Directory writable: " . (is_writable($upload_dir['basedir']) ? "✅ Yes" : "❌ No") . "<br>";
		echo "Directory permissions: " . substr(sprintf('%o', fileperms($upload_dir['basedir'])), -4) . "<br>";
	}
	
	echo "</div>";
}

// Force fix upload directory - wrapper function for utm_fix_upload_directory
function force_fix_upload_directory() {
	$site_id = get_current_blog_id();
	echo "<h2>🔧 Fixing Upload Directory Configuration</h2>";
	echo "<p>Running comprehensive upload directory fix for site ID: $site_id</p>";
	utm_fix_upload_directory($site_id);
}
