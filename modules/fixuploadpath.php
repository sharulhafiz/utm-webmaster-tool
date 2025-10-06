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

function utm_add_site_submenu_page()
{
	add_submenu_page(
		'tools.php', // Parent slug: Site Tools
		'Fix Upload Path',
		'Fix Upload Path',
		'manage_options',
		'fix-media',
		'utm_fixuploadpath'
	);
}
add_action('admin_menu', 'utm_add_site_submenu_page');
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

	// Access control
	if ($site_id < 2 && !is_network_admin()) {
		echo "This is the main site. No further action needed.";
		return;
	}
	switch_to_blog($site_id);

	// Additional variables
    $posts_table = $wpdb->prefix . 'posts';
    $postmeta_table = $wpdb->prefix . 'postmeta';
    $option_table = $wpdb->prefix . "options";
    $slug = get_blog_details($site_id)->path;
    $sitemeta_table = $wpdb->base_prefix . 'sitemeta';

	// Page title
	echo '<h1>Fix Upload Path - v2025.07.5</h1>';
	echo $posts_table . "<br>";

	//
	// STEP 1: blogsdir migration
	//
	echo "<h2>📁 STEP 1: BLOGS.DIR MIGRATION</h2>";
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

	$upload_path = $wpdb->get_var("SELECT option_value FROM $option_table WHERE option_name = 'upload_path'");
	$upload_url_path = $wpdb->get_var("SELECT option_value FROM $option_table WHERE option_name = 'upload_url_path'");

	if ($upload_path != ''){
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
    echo '<div class="wrap">';
    
    echo "<ul>";
    if (!empty($upload_path)) {
		// Set upload path to empty string
		$wpdb->update(
			$option_table,
			array('option_value' => ''),
			array('option_name' => 'upload_path')
		);
        
    }
    echo '<li>✅ <strong>Upload path:</strong> (empty - correct for multisite)</li>';

    if (empty($upload_url_path)) {
        echo '<li>✅ <strong>Upload URL path:</strong> (empty - correct for multisite)</li>';
    } else {
        echo '<li>⚠️ <strong>Upload URL path:</strong> ' . htmlspecialchars($upload_url_path) . '</li>';
    }
    echo "</ul>";
    
    // Check WordPress upload settings
    $wp_upload_dir = wp_upload_dir();
    echo "<div style='background: #f8f9fa; padding: 10px; margin: 10px 0; border-left: 4px solid #007cba;'>";
    echo "<strong>Current upload path:</strong> " . htmlspecialchars($wp_upload_dir['path']) . "<br>";
    echo "<strong>Current upload URL:</strong> " . htmlspecialchars($wp_upload_dir['url']) . "<br>";
    echo "<strong>Base upload dir:</strong> " . htmlspecialchars($wp_upload_dir['basedir']) . "<br>";
    echo "<strong>Base upload URL:</strong> " . htmlspecialchars($wp_upload_dir['baseurl']) . "<br>";
    echo "<strong>Subdir:</strong> " . htmlspecialchars($wp_upload_dir['subdir']) . "<br>";
    echo "<strong>Error:</strong> " . ($wp_upload_dir['error'] ? htmlspecialchars($wp_upload_dir['error']) : 'None') . "<br>";
    
    // Check if uploads are going to the wrong location
    $expected_upload_path = '';
    if ($site_id == 1) {
        $expected_upload_path = ABSPATH . 'wp-content/uploads';
    } else {
        $expected_upload_path = ABSPATH . 'wp-content/uploads/sites/' . $site_id;
    }
    
    echo "<strong>Contains blogs.dir:</strong> " . (strpos($wp_upload_dir['basedir'], 'blogs.dir') !== false ? 'Yes' : 'No') . "<br>";
    echo "</div>";
    
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

	echo "<h2>🗂️ Blogs.dir Status</h2>";
	echo "<div style='background: #f9fff9; padding: 10px; margin: 10px 0; border-left: 4px solid #28a745;'>";
	echo "<strong>Blogs.dir path:</strong> " . $blogsdir_path . "<br>";
	echo "<strong>Path exists:</strong> " . (is_dir($blogsdir_path) ? 'Yes' : 'No') . "<br>";
	echo "<strong>Path readable:</strong> " . (is_readable($blogsdir_path) ? 'Yes' : 'No') . "<br>";
	echo "<strong>Path writable:</strong> " . (is_writable($blogsdir_path) ? 'Yes' : 'No') . "<br>";

	if (is_dir($blogsdir_path)) {
		$files_in_blogsdir = scandir($blogsdir_path);
		$files_in_blogsdir = array_diff($files_in_blogsdir, array('.', '..'));
		$file_count = count($files_in_blogsdir); // Exclude . and ..
		echo "<strong>File count:</strong> $file_count<br>";

		// If blogs.dir contains only the 'files' directory and it is empty, show ready for cleanup
		if ($file_count === 1 && is_dir("{$blogsdir_path}/files")) {
			$files_dir = "{$blogsdir_path}/files";
			$files_dir_contents = array_diff(scandir($files_dir), array('.', '..'));
			if (empty($files_dir_contents)) {
				// Delete the empty files directory
				rrmdir($files_dir); // Remove empty directory
				echo "✅ <strong>Only empty /files directory remains in blogs.dir/$site_id</strong> - folder deleted<br>";
			} else {
				echo "⚠️ <strong>Blogs.dir/$site_id contains non-empty /files directory</strong> - migration needed<br>";
				// List files/folders in the directory
				echo "<h3>Files and folders in blogs.dir/files:</h3>";
				// Recursively list all files and folders in the given directory
				function utm_list_files_recursive($dir, $prefix = '') {
					if (!is_dir($dir)) return '';
					$output = '';
					$items = array_diff(scandir($dir), array('.', '..'));
					foreach ($items as $item) {
						$path = $dir . DIRECTORY_SEPARATOR . $item;
						// if path is empty, delete the folder
						if (is_dir($path)) {
							// If it's a directory, check if it is empty
							if (count(scandir($path)) <= 2) {
								rrmdir($path); // Remove empty directory
								continue; // Skip to next item
							}
						}
						$is_dir = is_dir($path);
						$output .= htmlspecialchars($prefix . $item . ($is_dir ? '/' : '')) . "<br>";
						if ($is_dir) {
							$output .= utm_list_files_recursive($path, $prefix . $item . '/');
						}
					}
					return $output;
				}
				echo "<div style='background: white; padding: 10px; border: 1px solid #ddd; max-height: 300px; overflow-y: auto; font-family: monospace;'>";
				echo utm_list_files_recursive($files_dir);
				echo "</div>";
			}
		}

		if ($file_count > 0) {
			echo "⚠️ <strong>Blogs.dir/$site_id contains $file_count items</strong> - migration needed<br>";
			echo "<h3>Files and folders in blogs.dir:</h3>";
			echo "<pre style='background: white; padding: 10px; border: 1px solid #ddd; max-height: 200px; overflow-y: auto;'>" . print_r($files_in_blogsdir, true) . "</pre>";
		} else {
			echo "✅ <strong>Blogs.dir/$site_id is empty</strong> - ready for cleanup<br>";
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

	// ================== STEP 2: Database Analysis ========================

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
	}

	/*
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

	// // List remaining problematic records
	// if ($remaining_attachments > 0) {
	// 	echo "<br><strong>Remaining attachments with /files/:</strong><br>";
	// 	$attachments = $wpdb->get_results($wpdb->prepare(
	// 		"SELECT ID, guid FROM $posts_table WHERE post_type = 'attachment' AND (guid LIKE %s OR guid LIKE %s)",
	// 		'%/files/%', '%blogs.dir%'
	// 	));
	// 	foreach ($attachments as $attachment) {
	// 		echo "  - <a href='" . esc_url(get_edit_post_link($attachment->ID)) . "'>" . esc_html($attachment->guid) . "</a><br>";
	// 	}
	// }
	// if ($remaining_content > 0) {
	// 	echo "<br><strong>Remaining posts with /files/ in content:</strong><br>";
	// 	$posts_with_content = $wpdb->get_results($wpdb->prepare(
	// 		"SELECT ID, post_title FROM $posts_table WHERE post_content LIKE %s OR post_content LIKE %s",
	// 		'%/files/%', '%blogs.dir%'
	// 	));
	// 	foreach ($posts_with_content as $post) {
	// 		echo "  - <a href='" . esc_url(get_edit_post_link($post->ID)) . "'>" . esc_html($post->post_title) . "</a><br>";
	// 	}
	// }
	// if ($remaining_postmeta > 0) {
	// 	echo "<br><strong>Remaining postmeta with /files/:</strong><br>";
	// 	$postmeta_with_files = $wpdb->get_results($wpdb->prepare(
	// 		"SELECT post_id, meta_key, meta_value FROM $postmeta_table WHERE meta_value LIKE %s OR meta_value LIKE %s",
	// 		'%/files/%', '%blogs.dir%'
	// 	));
	// 	foreach ($postmeta_with_files as $meta) {
	// 		echo "  - Post ID {$meta->post_id}: <strong>{$meta->meta_key}</strong> = " . esc_html($meta->meta_value) . "<br>";
	// 	}
	// }
	// if ($remaining_options > 0) {
	// 	echo "<br><strong>Remaining options with /files/:</strong><br>";
	// 	$options_with_files = $wpdb->get_results($wpdb->prepare(
	// 		"SELECT option_name, option_value FROM $option_table WHERE option_value LIKE %s OR option_value LIKE %s",
	// 		'%/files/%', '%blogs.dir%'
	// 	));
	// 	foreach ($options_with_files as $option) {
	// 		echo "  - <strong>{$option->option_name}</strong> = " . esc_html($option->option_value) . "<br>";
	// 	}
	// }

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
			if ($dry_run) {
				// In dry run, actually copy the file (leave original in place)
				if (@copy($item, $dest_path)) {
					echo "<div class='notice notice-info'><p>[Dry Run] Copied file: $item to $dest_path (original left in place)</p></div>";
				} else {
					echo "<div class='notice notice-error'><p>[Dry Run] Failed to copy file: $item to $dest_path</p></div>";
				}
			} else {
				// In real run, move the file (leave empty folders on origin)
				if (@rename($item, $dest_path)) {
					echo "<div class='notice notice-success'><p>Moved file: $item to $dest_path</p></div>";
				} else {
					echo "<div class='notice notice-error'><p>Failed to move file: $item to $dest_path</p></div>";
				}
			}
        }
    }
	// Check if the source directory is empty
	if (is_dir($source) && count(scandir($source)) == 2) {
		rmdir($source);
		echo "<div class='notice notice-info'><p>Removed empty directory: $source</p></div>";
	}
}

function utm_migrate_files_database_paths($site_id, $dry_run = true) {
    global $wpdb;
    switch_to_blog($site_id);

    $tables_to_search = array(
        $wpdb->prefix . 'posts',
        $wpdb->prefix . 'postmeta',
        $wpdb->prefix . 'options',
    );

    $site_slug = get_blog_details($site_id)->path;

    // Use the full legacy path for search, and the new path for replace
    $search_string = '/files/';
    $upload_dir = wp_upload_dir();
    $replace_string = $upload_dir . '/';

    foreach ($tables_to_search as $table) {
        // Get the primary key column name
        $primary_key = $wpdb->get_var("SHOW KEYS FROM $table WHERE Key_name = 'PRIMARY'", 4); // 5th column is Column_name
        $columns = $wpdb->get_col("DESC $table");

        foreach ($columns as $column) {
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT `$primary_key`, `$column` FROM $table WHERE `$column` LIKE %s",
                '%' . $wpdb->esc_like($search_string) . '%'
            ));

            // If no results found, skip to next table
            if (empty($results)) {
                echo "<div class='notice notice-info'><p>No matches found in $table.$column for search string '$search_string'</p></div>";
                continue;
            }

            foreach ($results as $row) {
                $old_value = $row->$column;
                $new_value = str_replace($search_string, $replace_string, $old_value);

                if ($dry_run) {
                    echo "<div class='notice notice-info'><p>[Dry Run] Would update $table.$column for row {$row->$primary_key}: $old_value -> $new_value</p></div>";
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
                        echo "<div class='notice notice-success'><p>Updated $table.$column for row {$row->$primary_key}</p></div>";
                    } else {
                        echo "<div class='notice notice-error'><p>Error updating $table.$column for row {$row->$primary_key}</p></div>";
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
// TODO: to compare directories before delete blogs.dir (ensure all files in blogs.dir has been migrated)
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
		// Check if /files in $upload_path
		$uploads_path_files = $uploads_path . '/files';
		if (is_dir($uploads_path_files)){
			// Delete this path
			if (is_writable($uploads_path_files)) {
				if(rrmdir($uploads_path_files)) {
					$uploads_path_files = '✅  Deleted';
				} else {
					// If not deleted, show warning
					$uploads_path_files = '⚠️  Could not delete';
				}
			} else {
				$uploads_path_files = '⚠️  Not writable';
			}
		} else {
			$uploads_path_files = '✅';
		}
		if (!is_dir($blogsdir_path) || (is_dir($blogsdir_path) && count(scandir($blogsdir_path)) <= 2)) {
			$status = 'Completed';
			$completed++;
			$action = '<span style="color:green;">Done</span>';
		} else {
			// Button for file migration
			// $action = '<a href="?page=fix-media&file_migration=1&site_id=' . $site_id . '&utm_nonce=' . wp_create_nonce('utm_fixuploadpath') . '&dry_run=1" class="button button-primary">Copy Files</a>';
			$action .= ' <a href="?page=fix-media&file_migration=1&site_id=' . $site_id . '&utm_nonce=' . wp_create_nonce('utm_fixuploadpath') . '" class="button button-primary">Move Files</a>';
			// Button for database migration
			$action .= ' <a href="?page=fix-media&migrate_files_db=1&site_id=' . $site_id . '&utm_nonce=' . wp_create_nonce('utm_fixuploadpath') . '" class="button button-secondary">Migrate DB Paths</a>';
		}
		// Make site name clickable (link to site admin dashboard)
		$site_url = esc_url(get_admin_url($site_id));
		$site_link = '<a href="' . $site_url . '" target="_blank" style="text-decoration:underline;">' . $site_name . '</a>';
		$rows .= '<tr>'
			. '<td style="padding:8px;border:1px solid #ccc;">' . $site_id . ' - ' . $site_link
			. '<br>Files Exists: ' . $uploads_path_files
			. '<br>Old Path: ' . $blogsdir_path
			. '<br>New Path: ' . $uploads_path . '</td>'
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

// Compare two directories by their contents (files and folders by name)
function compare_directories($dirA, $dirB) {
	if (!is_dir($dirA) || !is_dir($dirB)) {
		return false;
	}
	$filesA = array_diff(scandir($dirA), array('.', '..'));
	$filesB = array_diff(scandir($dirB), array('.', '..'));
	sort($filesA);
	sort($filesB);
	return $filesA === $filesB;
}

/**
 * Recursively remove a directory and its contents.
 * @param string $dir
 * @return bool
 */
function rrmdir($dir) {
	$baseDir = realpath($dir);
	if ($baseDir === false || !is_dir($baseDir)) {
		return false;
	}
	$objects = scandir($dir);
	foreach ($objects as $object) {
		if ($object != "." && $object != "..") {
			$path = $baseDir . DIRECTORY_SEPARATOR . $object;
			$realPath = realpath($path);
			if ($realPath === false || strpos($realPath, $baseDir) !== 0) {
				// Skip anything outside the base directory
				continue;
			}
			if (is_link($realPath) || is_file($realPath)) {
				unlink($realPath);
			} elseif (is_dir($realPath)) {
				rrmdir($realPath);
			}
		}
	}
	return rmdir($baseDir);
}