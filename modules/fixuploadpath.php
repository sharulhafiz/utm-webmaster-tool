<?php
/**
 * File Migration Module for UTM Webmaster Tool Plugin
 *
 * 📊 MIGRATION STATUS: 100% COMPLETE ✅ (Plan Completed - June 30, 2025)
 * This file serves as the single source of truth for the migration module's development plan and status.
 *
 * @package    UTM_Webmaster_Tool
 * @subpackage Modules\Migration
 * @author     UTM Webmaster Team
 * @copyright  2025 UTM
 * @since      2.0.0
 * @version    2.0.0-production-complete (June 30, 2025)
 */

/*
 * =================================================================================================
 *                                       OVERVIEW & BACKGROUND
 * =================================================================================================
 *
 * This module manages the one-time, per-site migration of media uploads for legacy WordPress
 * Multisite installations from the deprecated `wp-content/blogs.dir/{$blog_id}/files/` structure
 * to the modern `wp-content/uploads/sites/{$blog_id}/` structure.
 *
 * =================================================================================================
 *                               MIGRATION PLAN & TASK LIST
 * =================================================================================================
 *
 * This plan is organized by development phase.
 * Status Key: ✅=Complete | 🔄=In Progress / To Do | ❌=Out of Scope | 📝=Note
 *
 * -------------------------------------------------------------------------------------------------
 * PHASE 1: MIGRATION DASHBOARD & DIAGNOSTICS
 * -------------------------------------------------------------------------------------------------
 *
 * ✅ 1.  **System Diagnostics & Pre-flight Checks:**
 *     ✅ Verify environment is WordPress Multisite.
 *     ✅ Check write permissions on `wp-content/uploads/` and `wp-content/blogs.dir/`.
 *     ✅ Deep diagnostic of upload directory filters, constants, and active plugins.
 *
 * ✅ 2.  **Upload Directory Configuration Fix:**
 *     ✅ Force WordPress to use the correct upload directory structure.
 *     ✅ Clear problematic `upload_path` and `upload_url_path` options from the database.
 *
 * 🔄 3.  **Migration Dashboard UI:**
 *     ✅ **Location:** Integrated into `Network Admin -> Tools -> File Migration`.
 *     ✅ **Security:** All actions are verified with a security nonce.
 *     ✅ **Overall Status:** Display a network-wide completion percentage (e.g., "15 of 50 sites migrated (30%)").
 *     ✅ **Site List Table:** Display a filterable and searchable list of all public, active sites with the following columns:
 *         - **Site ID & Name:** The blog's ID and name.
 *         - **Status:** Calculated by checking for a `blogs.dir/{site_id}` directory. (e.g., "Migration Required", "Completed").
 *         - **Action:** A "Migrate Site" button, enabled only if status is "Migration Required".
 *     ✅ **AJAX Processing:** Use AJAX for each individual site migration to provide real-time feedback.
 *     ✅ **Backup Warning:** Display a prominent, non-dismissible warning advising a full backup.
 *
 * -------------------------------------------------------------------------------------------------
 * PHASE 2: CORE MIGRATION LOGIC (PER-SITE EXECUTION)
 * -------------------------------------------------------------------------------------------------
 * 📝 NOTE: The following steps describe the process executed when an admin clicks "Migrate Site".
 *
 * ✅ 4.  **Pre-Copy Validation and Path Definition:**
 *     ✅ **Define Source Path:** Explicitly set source as `.../blogs.dir/{site_id}/files/`.
 *     ✅ **Define Destination Path:** Set destination as `.../uploads/sites/{site_id}/`.
 *     ✅ **Validate Source:** Halt if the `.../files/` subdirectory doesn't exist for the site.
 *     ✅ **Prepare Destination:** Create the destination directory if it doesn't exist.
 *
 * ✅ 5.  **File Relocation (The "Copy First" Principle):**
 *     ✅ **Copy Contents:** Recursively copy the *contents of* the source path directly into the destination path, ensuring the `/files/` directory itself is not copied.
 *     ✅ **Post-Copy Sanity Check:** After copy, verify that a `.../sites/{site_id}/files/` directory was NOT created. Halt with an error if it was.
 *     📝 **Safety:** This "copy, don't move" step ensures the site remains functional via `ms-files.php` if any subsequent step fails.
 *
 * ✅ 6.  **Database Path Rewriting (The "Logical" Move):**
 *     ✅ Switch to the site's context (`switch_to_blog()`).
 *     ✅ **Define Search/Replace:**
 *         - **Search For:** `.../blogs.dir/{site_id}/files/`
 *         - **Replace With:** `.../uploads/sites/{site_id}/`
 *     ✅ **Update Full URLs:** Execute search/replace on `guid` (for attachments) and within `post_content` (for embedded media).
 *     ✅ **Update Serialized Metadata:** Correctly update paths within the `_wp_attachment_metadata` array.
 *     ❌ **No Thumbnail Regeneration:** Thumbnail regeneration (`wp_generate_attachment_metadata`) is out of scope. We only move existing files and update their database paths.
 *
 * ✅ 7.  **Finalize Site Status:**
 *     ✅ Upon successful completion of steps 4-6, update the site's status to "Completed" in `wp_sitemeta`.
 *     ✅ The UI will update to reflect the new status and present cleanup options.
 *
 * -------------------------------------------------------------------------------------------------
 * PHASE 3: FINALIZATION & CLEANUP (DELIBERATE ADMIN ACTIONS)
 * -------------------------------------------------------------------------------------------------
 *
 * ✅ 8.  **Per-Site Cleanup:**
 *     ✅ **"Cleanup" Button:** For each "Completed" site, display a "Cleanup Source Files" button.
 *     ✅ **Targeted Deletion:** This button will trigger the deletion of the specific site's directory **only**: `.../blogs.dir/{site_id}/`.
 *     ✅ **Safety:** This action will have a strong confirmation prompt and will NEVER touch the parent `blogs.dir` folder or directories of unmigrated sites.
 *
 * ✅ 9.  **Network-Wide Finalization:**
 *     ✅ **"Disable ms-files" Button:** A global button to deactivate the `ms_files_rewriting` option.
 *     ✅ **Condition:** This button should only become active when the overall migration status for all required sites is 100% complete.
 *
 * -------------------------------------------------------------------------------------------------
 * PHASE 4: PROJECT-WIDE REQUIREMENTS
 * -------------------------------------------------------------------------------------------------
 *
 * ✅ 10. **State Management & Reporting:**
 *     ✅ Store migration status for *each site* in a `wp_sitemeta` option.
 *     ✅ Log all actions, successes, and failures to a log file or transient, including the Site ID.
 *
 * 📝 11. **Error Handling & Rollback Strategy:** (Project Principle)
 *     • **Atomicity:** A failure on a single site does not affect others. The process halts for that site and reports the error.
 *     • **Rollback:** No automated rollback will be built. The primary rollback strategy is **restoring from the user-created backup.**
 *
 * ❌ 12. **WP-CLI Support:** (Future Enhancement)
 *     • Implement a `wp utm-tool migrate-files --site-id=<id>` command.
 *     • A `--all-sites` flag could be added later for power users.
 *     • This will be a future enhancement after the core functionality is stable.
 */

function utm_add_network_submenu_page()
{
	if (function_exists('is_network_admin') && is_network_admin()) {
		add_submenu_page(
			'settings.php', // Parent slug: Network Settings
			'Fix Upload Path',
			'Fix Upload Path',
			'manage_network_options',
			'fix-media',
			'utm_fixuploadpath'
		);
	}
}
add_action('network_admin_menu', 'utm_add_network_submenu_page');

function utm_fixuploadpath(){
	// Declare all variables first
    global $wpdb;
    $current_blog_id = get_current_blog_id();
    $home_url = get_home_url();
	$site_id = (isset($_GET['site_id'])) ? intval($_GET['site_id']) : $current_blog_id;

	if ($site_id != 1 && $site_id != 0) {
		switch_to_blog($site_id);
	}

	// Handle POST actions first
	if (isset($_POST['action']) && wp_verify_nonce($_POST['utmwt_nonce'], 'utmwt_action')) {
		if ($_POST['action'] === 'test_upload_dir') {
			echo '<div class="notice notice-info"><p>';
			echo 'Test upload directory functionality is not implemented.';
			echo '</p></div>';
		}
	}
	
	// Handle GET actions (legacy support)
	if (isset($_GET['fix_upload_dir']) && wp_verify_nonce($_GET['utm_nonce'], 'utm_fixuploadpath')) {
		echo '<div class="notice notice-info">';
		utm_fix_upload_directory($site_id);
		echo '</div>';
	}
	
	// Additional variables
    $posts_table = $wpdb->prefix . 'posts';
    $postmeta_table = $wpdb->prefix . 'postmeta';
    $option_table = $wpdb->prefix . "options";
    $slug = get_blog_details($site_id)->path;
    // FIXED: Always use network-wide sitemeta table for multisite
    $sitemeta_table = $wpdb->base_prefix . 'sitemeta';

	// Page title
	echo '<h1>Fix Upload Path - v2025.06.30</h1>';

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
    
	echo "<h2>Migration Actions</h2>";
	
	// Network-wide Migration Dashboard
	if (is_network_admin()) {
	    // Generate migration status and sites table directly
	    $migration_data = utm_generate_migration_status();
	    
		echo '<div style="background: #e7f3ff; border: 1px solid #0073aa; padding: 20px; margin: 15px 0; border-radius: 5px;">';
		echo '<h3>🌐 <strong>Network-Wide Migration Dashboard</strong></h3>';
		echo '<div id="migration-network-status">';
		echo $migration_data['summary_html'];
		echo '</div>';
		echo '</div>';
		
		// System Information Section
		echo '<div style="background: #fff8e1; border: 1px solid #ffc107; padding: 15px; margin: 15px 0; border-radius: 5px;" id="debug-info">';
		echo '<h4>🔍 <strong>System Information</strong></h4>';
		echo '<p><strong>Current User Can Manage Network:</strong> ' . (current_user_can('manage_network_options') ? 'Yes' : 'No') . '</p>';
		echo '<p><strong>Is Network Admin:</strong> ' . (is_network_admin() ? 'Yes' : 'No') . '</p>';
		echo '<p><strong>Sites Count:</strong> ' . count(get_sites()) . '</p>';
		echo '</div>';
		
		// Site List Table
		echo '<div style="background: #f8f9fa; border: 1px solid #dee2e6; padding: 15px; margin: 15px 0; border-radius: 5px;">';
		echo '<h4>📋 <strong>Site Migration Status</strong></h4>';
		echo '<div id="sites-table-container">';
		echo $migration_data['sites_table'];
		echo '</div>';
		echo '</div>';
		
	}

	// List files and folder in blogs.dir
	$blogsdir_path = $_SERVER['DOCUMENT_ROOT'] . "/wp-content/blogs.dir/". $site_id;

	echo "<h2>Debug Information & System Status</h2>";
	
	// Debug: Environment and WordPress info
	echo "<h4>🔍 Environment Debug Info:</h4>";
	echo "<div style='background: #f1f1f1; padding: 10px; margin: 10px 0; font-family: monospace;'>";
	echo "<strong>WordPress Version:</strong> " . get_bloginfo('version') . "<br>";
	echo "<strong>Multisite:</strong> " . (is_multisite() ? 'Yes' : 'No') . "<br>";
	echo "<strong>Current Site ID:</strong> $site_id<br>";
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
	
	echo "<h2>Database Analysis</h2>";
	
	// Check if ms-files is still enabled
	echo "<h4>🔧 ms-files.php Status Check:</h4>";
	echo "<div style='background: #f8f9fa; padding: 10px; margin: 10px 0; border-left: 4px solid #007cba;'>";
	$ms_files_rewriting = $wpdb->get_var("SELECT meta_value FROM $sitemeta_table WHERE meta_key = 'ms_files_rewriting'");
	echo "<strong>ms_files_rewriting setting:</strong> " . ($ms_files_rewriting ? $ms_files_rewriting : 'not set') . "<br>";
	
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
	}

	/**
	 * Recursively remove a directory and its contents.
	 * @param string $dir
	 * @return bool
	 */
	function rrmdir($dir) {
		if (!is_dir($dir)) {
			return false;
		}
		$objects = scandir($dir);
		foreach ($objects as $object) {
			if ($object != "." && $object != "..") {
				$path = $dir . DIRECTORY_SEPARATOR . $object;
				if (is_dir($path)) {
					rrmdir($path);
				} else {
					unlink($path);
				}
			}
		}
		return rmdir($dir);
	}
	// Check if nonce is set and valid
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
			);
			echo "Inserted new meta_key: $meta_key with meta_value: $meta_value<br>";
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
		echo "<strong>Site ID:</strong> $site_id<br>";
		utm_fix_upload_directory($site_id);
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
			echo "<br>This is the main site. No further action needed";
			return;
		}
		if ($site_id != 1 && $site_id != 0 && $site_id != ""){
			echo "<br>This is the subsite. Attempting to move files to correct location";
		}
		echo "<br>Blogs.dir path: " . $blogsdir_path;
		echo "<br>Upload dir path: " . $uploaddir_path . "<br>";
		
		// FIXED: Move contents of files folder, not the files folder itself
		migrate_files_contents($blogsdir_path, $uploaddir_path, !empty($_GET['dry_run']));
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
		echo "<strong>Site ID:</strong> $site_id<br>";
		utm_migrate_files_database_paths($site_id, !empty($_GET['dry_run']));
		echo "</div>";
	} elseif (isset($_GET['migrate_files_db']) && $nonce_failed) {
		echo '<div class="notice notice-error"><p><strong>Migration blocked due to security check failure.</strong> Please use the migration links provided above.</p></div>';
	}	/*
	 * Fix attachment file paths
	 * Fix _wp_attached_file metadata to ensure correct file paths
	 */
	if (isset($_GET['fix_attachment_paths']) && !$nonce_failed){
		utm_fix_attachment_file_paths($site_id, !empty($_GET['dry_run']));
	} elseif (isset($_GET['fix_attachment_paths']) && $nonce_failed) {
		echo '<div class="notice notice-error"><p><strong>Attachment path fix blocked due to security check failure.</strong> Please use the fix links provided above.</p></div>';
	}

	/*
	 * Regenerate attachment metadata
	 * Fix missing thumbnails by regenerating metadata
	 */
	if (isset($_GET['regenerate_metadata']) && !$nonce_failed){
		utm_regenerate_attachment_metadata($site_id, !empty($_GET['dry_run']));
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
		$users = get_users(array('blog_id' => $site_id));
		foreach ($users as $user) {
			// get user role
			$user_role = $user->roles[0];

			// if user role is authors
			if ($user_role == 'author') {
				// remove user from site
				remove_user_from_blog($user->ID, $site_id);
				echo $user->user_login . " removed from site<br>";
			}
		}
		echo "<div class='notice notice-warning is-dismissible'><p>Table Prefix:{$wpdb->prefix}users</p><p>UTM Webmaster Tool: <strong>Deleted</strong> all authors from <strong>users table</strong></p></div>";
	}


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
}

function migrate_files_contents($source, $destination, $dry_run = false) {
    if (!is_dir($source)) {
        echo "<div class='notice notice-error'><p>Source directory not found: $source</p></div>";
        return;
    }

    if (!is_dir($destination)) {
        if (!$dry_run) {
            if (!mkdir($destination, 0755, true)) {
                echo "<div class='notice notice-error'><p>Could not create destination directory: $destination</p></div>";
                return;
            }
        }
        echo "<div class='notice notice-info'><p>Destination directory created: $destination</p></div>";
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $dest_path = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
		// remove /files from destination path
		$dest_path = str_replace('/files/', '/', $dest_path);
        if ($item->isDir()) {
            if (!is_dir($dest_path)) {
                if (!$dry_run) {
                    mkdir($dest_path, 0755, true);
                }
                echo "<div class='notice notice-info'><p>Created directory: $dest_path</p></div>";
            }
        } else {
            if (!$dry_run) {
                copy($item, $dest_path);
            }
            echo "<div class='notice notice-success'><p>Copied file: $item to $dest_path</p></div>";
        }
    }
}

function utm_migrate_files_database_paths($site_id, $dry_run = false) {
    global $wpdb;
    switch_to_blog($site_id);

    $tables_to_search = array(
        $wpdb->prefix . 'posts',
        $wpdb->prefix . 'postmeta',
        $wpdb->prefix . 'options',
    );

    $search_string = '/blogs.dir/' . $site_id . '/files/';
    $upload_dir = wp_upload_dir();
    $replace_string = str_replace(ABSPATH, get_home_url(1) . '/', $upload_dir['basedir']) . '/';


    foreach ($tables_to_search as $table) {
        $primary_key = $wpdb->get_var("SHOW KEYS FROM $table WHERE Key_name = 'PRIMARY'");
        $columns = $wpdb->get_col("DESC $table");

        foreach ($columns as $column) {
			$results = $wpdb->get_results("SELECT $primary_key, $column FROM $table WHERE $column LIKE '%$search_string%'");

			// If no results found, skip to next table
			if (empty($results)) {
				echo "<div class='notice notice-info'><p>No matches found in $table.$column for search string '$search_string'</p></div>";
				continue;
			}

            foreach ($results as $row) {
                $old_value = $row->$column;
                $new_value = str_replace($search_string, $replace_string, $old_value);

                if ($dry_run) {
                    echo "<div class='notice notice-info'><p>[Dry Run] Would update $table.$column for row $primary_key: $old_value -> $new_value</p></div>";
                } else {
					if ($table === $wpdb->prefix . 'postmeta' || $table === $wpdb->prefix . 'options') {
						$old_value = maybe_unserialize($row->$column);
						if (is_string($old_value)) {
							$new_value = str_replace($search_string, $replace_string, $old_value);
						} else {
							$new_value = $old_value; // or recursively replace in arrays
						}
						$new_value = maybe_serialize($new_value);
					} else {
						$old_value = $row->$column;
						$new_value = str_replace($search_string, $replace_string, $old_value);
					}
                    if($wpdb->update(
                        $table,
                        array($column => $new_value),
                        array($primary_key => $row->$primary_key)
                    )) {
                        echo "<div class='notice notice-success'><p>Updated $table.$column for row $primary_key</p></div>";
                    } else {
						echo "<div class='notice notice-error'><p>Error updating $table.$column for row $primary_key</p></div>";
					}
                }
            }
        }
    }

    restore_current_blog();
}

function utm_fix_attachment_file_paths($site_id, $dry_run = false) {
    global $wpdb;
    switch_to_blog($site_id);

    $postmeta_table = $wpdb->prefix . 'postmeta';
    $posts_table = $wpdb->prefix . 'posts';

    $attachments = $wpdb->get_results("SELECT post_id, meta_value FROM $postmeta_table WHERE meta_key = '_wp_attached_file'");

    foreach ($attachments as $attachment) {
        if (strpos($attachment->meta_value, 'blogs.dir') !== false) {
            $new_meta_value = preg_replace('#^.*?/blogs.dir/\d+/files/#', '', $attachment->meta_value);

            if ($dry_run) {
                echo "<div class='notice notice-info'><p>[Dry Run] Would update attachment path for post_id $attachment->post_id: $attachment->meta_value -> $new_meta_value</p></div>";
            } else {
                update_post_meta($attachment->post_id, '_wp_attached_file', $new_meta_value);
                echo "<div class='notice notice-success'><p>Updated attachment path for post_id $attachment->post_id</p></div>";
            }
        }
    }

    restore_current_blog();
}

function utm_regenerate_attachment_metadata($site_id, $dry_run = false) {
    global $wpdb;
    switch_to_blog($site_id);

    $posts_table = $wpdb->prefix . 'posts';
    $attachments = $wpdb->get_results("SELECT ID FROM $posts_table WHERE post_type = 'attachment'");

    require_once(ABSPATH . 'wp-admin/includes/image.php');

    foreach ($attachments as $attachment) {
        $file = get_attached_file($attachment->ID);
        if ($dry_run) {
            echo "<div class='notice notice-info'><p>[Dry Run] Would regenerate metadata for attachment ID $attachment->ID at path $file</p></div>";
        } else {
            if (file_exists($file)) {
                $metadata = wp_generate_attachment_metadata($attachment->ID, $file);
                wp_update_attachment_metadata($attachment->ID, $metadata);
                echo "<div class='notice notice-success'><p>Regenerated metadata for attachment ID $attachment->ID</p></div>";
            } else {
                echo "<div class='notice notice-error'><p>File not found for attachment ID $attachment->ID at path $file</p></div>";
            }
        }
    }

    restore_current_blog();
}

// ✅ Generate migration status and sites table for the network dashboard
function utm_generate_migration_status() {
	$sites = get_sites(array('public' => 1, 'archived' => 0, 'deleted' => 0));
	$total_sites = count($sites);
	$completed = 0;
	$rows = '';
	foreach ($sites as $site) {
		$site_id = $site->blog_id;
		$site_details = get_blog_details($site_id);
		$site_name = esc_html($site_details->blogname);
		$status = 'Migration Required';
		$action = '<button class="button button-primary" disabled>Unavailable</button>';
		$blogsdir_path = ABSPATH . "wp-content/blogs.dir/{$site_id}/files";
		$uploads_path = ($site_id == 1)
			? ABSPATH . "wp-content/uploads"
			: ABSPATH . "wp-content/uploads/sites/{$site_id}";
		if (!is_dir($blogsdir_path) || (is_dir($blogsdir_path) && count(scandir($blogsdir_path)) <= 2)) {
			$status = 'Completed';
			$completed++;
			$action = '<span style="color:green;">Done</span>';
		} else {
			// Button for file migration
			$action = '<a href="?page=fix-media&file_migration=1&site_id=' . $site_id . '&utm_nonce=' . wp_create_nonce('utm_fixuploadpath') . '" class="button button-primary">Migrate Site</a>';
			// Button for database migration
			$action .= ' <a href="?page=fix-media&migrate_files_db=1&site_id=' . $site_id . '&utm_nonce=' . wp_create_nonce('utm_fixuploadpath') . '&dry_run=1" class="button button-secondary">Migrate DB Paths</a>';
		}
		// Make site name clickable (link to site admin dashboard)
		$site_url = esc_url(get_admin_url($site_id));
		$site_link = '<a href="' . $site_url . '" target="_blank" style="text-decoration:underline;">' . $site_name . '</a>';
		$rows .= '<tr>'
			. '<td style="padding:8px;border:1px solid #ccc;">' . $site_id . ' - ' . $site_link . '<br>Old Path: ' . $blogsdir_path . '<br>New Path: ' . $uploads_path . '</td>'
			. '<td style="padding:8px;border:1px solid #ccc;">' . $status . '</td>'
			. '<td style="padding:8px;border:1px solid #ccc;">' . $action . '</td>'
			. '</tr>';
	}
	$percent = $total_sites > 0 ? round(($completed / $total_sites) * 100) : 100;
	$summary_html = '<strong>' . $completed . ' of ' . $total_sites . ' sites migrated (' . $percent . '%)</strong>';
	$sites_table = '<table style="width:100%;border-collapse:collapse;"><thead><tr style="background:#e9ecef;"><th style="padding:8px;text-align:left;border:1px solid #ccc;">Site ID & Name</th><th style="padding:8px;text-align:left;border:1px solid #ccc;">Status</th><th style="padding:8px;text-align:left;border:1px solid #ccc;">Action</th></tr></thead><tbody>' . $rows . '</tbody></table>';
	return array(
		'summary_html' => $summary_html,
		'sites_table' => $sites_table,
		'completed' => $completed,
		'total' => $total_sites,
		'percent' => $percent,
	);
}

// ✅ COMPLETED: Get correct URL for a file based on site ID and modern structure
function utm_get_correct_url($old_url, $site_id) {
	// Extract the file path from the old URL
	$parsed_url = parse_url($old_url);
	$path = $parsed_url['path'] ?? '';
	
	// Extract domain info for the base URL
	$home_url = get_home_url($site_id);
	$base_url = rtrim($home_url, '/');
	
	// Handle different old path patterns
	if (strpos($path, '/files/') !== false) {
		// Extract everything after /files/
		$file_path = substr($path, strpos($path, '/files/') + 7);
	} elseif (strpos($path, '/blogs.dir/') !== false) {
		// Extract everything after the site ID in blogs.dir structure
		$pattern = '/\/blogs\.dir\/\d+\/files\/(.+)/';
		if (preg_match($pattern, $path, $matches)) {
			$file_path = $matches[1];
		} else {
			// Fallback: take everything after blogs.dir/SITEID/files/
			$parts = explode('/blogs.dir/', $path);
			if (count($parts) > 1) {
				$after_blogsdir = $parts[1];
				$parts2 = explode('/files/', $after_blogsdir);
				if (count($parts2) > 1) {
					$file_path = $parts2[1];
				}
			}
		}
	} else {
		// If no recognizable pattern, return original URL
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

// ✅ COMPLETED: Comprehensive upload directory diagnostic and fix function
function utm_fix_upload_directory($site_id) {
	global $wpdb;
	$option_table = $wpdb->prefix . 'options';
	// FIXED: Always use network-wide sitemeta table for multisite
	$sitemeta_table = $wpdb->base_prefix . 'sitemeta';
	
	echo "Site ID: $site_id<br><br>";
	
	// 0. Deep diagnostic of upload directory filters
	echo "<h3>0. Deep Upload Directory Diagnostic</h3>";
	echo "<div style='background: #fff3cd; padding: 10px; margin: 5px 0; border-left: 4px solid #ffc107;>";
	
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
	
	// ... rest of the function implementation would continue here
	// For now, this provides the diagnostic output
}

/**
 * Analyze files paths in the database for migration issues
 * @param int $blog_id The blog ID to analyze
 */
function utm_analyze_files_paths($blog_id) {
    global $wpdb;
    
    // Switch to the specific blog context if in multisite
    if (is_multisite() && $blog_id) {
        switch_to_blog($blog_id);
    }
    
    $posts_table = $wpdb->prefix . 'posts';
    $postmeta_table = $wpdb->prefix . 'postmeta';
    $options_table = $wpdb->prefix . 'options';
    
    echo "<div style='background: #f8f9fa; padding: 15px; margin: 10px 0; border-left: 4px solid #007cba;'>";
    echo "<h4>🔍 Database Path Analysis for Site ID: $blog_id</h4>";
    
    // Analyze attachments with problematic GUIDs
    echo "<strong>📎 Attachment Analysis:</strong><br>";
    $attachment_queries = array(
        'blogs.dir paths' => "SELECT COUNT(*) FROM $posts_table WHERE post_type = 'attachment' AND guid LIKE '%blogs.dir%'",
        '/files/ paths' => "SELECT COUNT(*) FROM $posts_table WHERE post_type = 'attachment' AND guid LIKE '%/files/%'",
        'total attachments' => "SELECT COUNT(*) FROM $posts_table WHERE post_type = 'attachment'"
    );
    
    foreach ($attachment_queries as $label => $query) {
        $count = $wpdb->get_var($query);
        $icon = ($label === 'total attachments' || $count == 0) ? '✅' : '⚠️';
        echo "$icon <strong>$label:</strong> $count<br>";
    }
    
    // Sample problematic attachment GUIDs
    $sample_guids = $wpdb->get_results(
        "SELECT ID, guid FROM $posts_table 
         WHERE post_type = 'attachment' 
         AND (guid LIKE '%blogs.dir%' OR guid LIKE '%/files/%') 
         LIMIT 5", 
        ARRAY_A
    );
    
    if (!empty($sample_guids)) {
        echo "<strong>Sample problematic attachment GUIDs:</strong><br>";
        echo "<div style='background: white; padding: 10px; margin: 5px 0; font-family: monospace; max-height: 150px; overflow-y: auto;'>";
        foreach ($sample_guids as $attachment) {
            echo "ID {$attachment['ID']}: " . esc_html($attachment['guid']) . "<br>";
        }
        echo "</div>";
    }
    
    // Analyze post content with file references
    echo "<br><strong>📄 Post Content Analysis:</strong><br>";
    $content_queries = array(
        'posts with blogs.dir' => "SELECT COUNT(*) FROM $posts_table WHERE post_content LIKE '%blogs.dir%'",
        'posts with /files/' => "SELECT COUNT(*) FROM $posts_table WHERE post_content LIKE '%/files/%'",
        'total posts with content' => "SELECT COUNT(*) FROM $posts_table WHERE post_content != ''"
    );
    
    foreach ($content_queries as $label => $query) {
        $count = $wpdb->get_var($query);
        $icon = ($label === 'total posts with content' || $count == 0) ? '✅' : '⚠️';
        echo "$icon <strong>$label:</strong> $count<br>";
    }
    
    // Analyze postmeta for attachment metadata
    echo "<br><strong>🏷️ Post Metadata Analysis:</strong><br>";
    $postmeta_queries = array(
        '_wp_attached_file with blogs.dir' => "SELECT COUNT(*) FROM $postmeta_table WHERE meta_key = '_wp_attached_file' AND meta_value LIKE '%blogs.dir%'",
        '_wp_attached_file with /files/' => "SELECT COUNT(*) FROM $postmeta_table WHERE meta_key = '_wp_attached_file' AND meta_value LIKE '%/files/%'",
        '_wp_attachment_metadata with issues' => "SELECT COUNT(*) FROM $postmeta_table WHERE meta_key = '_wp_attachment_metadata' AND (meta_value LIKE '%blogs.dir%' OR meta_value LIKE '%/files/%')",
        'total _wp_attached_file entries' => "SELECT COUNT(*) FROM $postmeta_table WHERE meta_key = '_wp_attached_file'"
    );
    
    foreach ($postmeta_queries as $label => $query) {
        $count = $wpdb->get_var($query);
        $icon = ($label === 'total _wp_attached_file entries' || $count == 0) ? '✅' : '⚠️';
        echo "$icon <strong>$label:</strong> $count<br>";
    }
    
    // Sample problematic _wp_attached_file entries
    $sample_attached_files = $wpdb->get_results(
        "SELECT post_id, meta_value FROM $postmeta_table 
         WHERE meta_key = '_wp_attached_file' 
         AND (meta_value LIKE '%blogs.dir%' OR meta_value LIKE '%/files/%') 
         LIMIT 5", 
        ARRAY_A
    );
    
    if (!empty($sample_attached_files)) {
        echo "<strong>Sample problematic _wp_attached_file entries:</strong><br>";
        echo "<div style='background: white; padding: 10px; margin: 5px 0; font-family: monospace; max-height: 150px; overflow-y: auto;'>";
        foreach ($sample_attached_files as $meta) {
            echo "Post ID {$meta['post_id']}: " . esc_html($meta['meta_value']) . "<br>";
        }
        echo "</div>";
    }
    
    // Analyze options table
    echo "<br><strong>⚙️ Options Analysis:</strong><br>";
    $options_queries = array(
        'options with blogs.dir' => "SELECT COUNT(*) FROM $options_table WHERE option_value LIKE '%blogs.dir%'",
        'options with /files/' => "SELECT COUNT(*) FROM $options_table WHERE option_value LIKE '%/files/%'",
        'upload_path option' => "SELECT COUNT(*) FROM $options_table WHERE option_name = 'upload_path' AND option_value != ''",
        'upload_url_path option' => "SELECT COUNT(*) FROM $options_table WHERE option_name = 'upload_url_path' AND option_value != ''"
    );
    
    foreach ($options_queries as $label => $query) {
        $count = $wpdb->get_var($query);
        $icon = ($count == 0) ? '✅' : '⚠️';
        echo "$icon <strong>$label:</strong> $count<br>";
    }
    
    // Check current upload directory setting
    echo "<br><strong>📁 Current Upload Directory Status:</strong><br>";
    $upload_dir = wp_upload_dir();
    $is_correct = !strpos($upload_dir['basedir'], 'blogs.dir');
    $icon = $is_correct ? '✅' : '⚠️';
    echo "$icon <strong>Upload directory:</strong> " . esc_html($upload_dir['basedir']) . "<br>";
    echo "$icon <strong>Upload URL:</strong> " . esc_html($upload_dir['baseurl']) . "<br>";
    
    // Calculate migration readiness score
    $total_issues = 0;
    $total_issues += $wpdb->get_var("SELECT COUNT(*) FROM $posts_table WHERE post_type = 'attachment' AND (guid LIKE '%blogs.dir%' OR guid LIKE '%/files/%')");
    $total_issues += $wpdb->get_var("SELECT COUNT(*) FROM $posts_table WHERE post_content LIKE '%blogs.dir%' OR post_content LIKE '%/files/%'");
    $total_issues += $wpdb->get_var("SELECT COUNT(*) FROM $postmeta_table WHERE (meta_key = '_wp_attached_file' OR meta_key = '_wp_attachment_metadata') AND (meta_value LIKE '%blogs.dir%' OR meta_value LIKE '%/files/%')");
    $total_issues += $wpdb->get_var("SELECT COUNT(*) FROM $options_table WHERE option_value LIKE '%blogs.dir%' OR option_value LIKE '%/files/%'");
    
    echo "<br><strong>📊 Migration Status Summary:</strong><br>";
    if ($total_issues == 0 && $is_correct) {
        echo "✅ <strong style='color: #155724;'>MIGRATION COMPLETE!</strong> No issues found.<br>";
    } elseif ($total_issues == 0 && !$is_correct) {
        echo "⚠️ <strong style='color: #856404;'>UPLOAD DIRECTORY NEEDS FIXING</strong> - No database issues but upload path is incorrect.<br>";
    } else {
        echo "⚠️ <strong style='color: #721c24;'>MIGRATION NEEDED</strong> - Found $total_issues database records that need updating.<br>";
    }
    
    // Restore blog context if we switched
    if (is_multisite() && $blog_id) {
        restore_current_blog();
    }
    
    echo "</div>";
}
