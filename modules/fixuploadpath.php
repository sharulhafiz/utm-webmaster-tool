<?php
/**
 * File Migration Module for UTM Webmaster Tool Plugin
 *
 * 📊 SINGLE-SITE FOCUSED ✅ (Simplified - October 8, 2025)
 * This module handles per-site migration of media uploads from the legacy blogs.dir structure
 * to the modern uploads/sites structure. Each site must be migrated individually.
 *
 * @package    UTM_Webmaster_Tool
 * @subpackage Modules\Migration
 * @author     UTM Webmaster Team
 * @copyright  2025 UTM
 * @since      2.0.0
 * @version    2.1.0-simplified (October 8, 2025)
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
 * ✅ 3.  **Migration Dashboard UI:**
 *     ✅ **Location:** Integrated into each site's `Tools -> Fix Upload Path` page.
 *     ✅ **Security:** All actions are verified with a security nonce.
 *     ✅ **Site-Specific Tools:** Each site admin can migrate their own site independently.
 *     ✅ **Migration Actions:** File migration, database path fixes, and attachment metadata repairs.
 *     ✅ **Dry Run Support:** All operations support dry-run mode for safety.
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
	
	// Add Media Library Debug tool under Media menu
	add_submenu_page(
		'upload.php', // Parent slug: Media Library
		'Media Debug',
		'Media Debug',
		'manage_options',
		'media-debug',
		'utm_media_debug'
	);
}
add_action('admin_menu', 'utm_add_site_submenu_page');

function utm_fixuploadpath(){
	// Declare all variables first
    global $wpdb;
    $current_blog_id = get_current_blog_id();
    $home_url = get_home_url();
	$site_id = (isset($_GET['site_id'])) ? intval($_GET['site_id']) : $current_blog_id;

	// Access control - skip main site
	if ($site_id == 1) {
		echo '<div class="notice notice-info"><p>This is the main site. Media migration is typically only needed for subsites.</p></div>';
	}
	
	// Switch to the target site context
	if (function_exists('switch_to_blog')) {
		switch_to_blog($site_id);
	}

	// Additional variables
    $posts_table = $wpdb->prefix . 'posts';
    $postmeta_table = $wpdb->prefix . 'postmeta';
    $option_table = $wpdb->prefix . "options";
    $slug = get_blog_details($site_id)->path;
    $sitemeta_table = $wpdb->base_prefix . 'sitemeta';

	// Define paths first
	$blogsdir_parent_path = $_SERVER['DOCUMENT_ROOT'] . "/wp-content/blogs.dir/". $site_id;
	$blogsdir_path = $_SERVER['DOCUMENT_ROOT'] . "/wp-content/blogs.dir/". $site_id . "/files";
	
	if ($site_id == 1){
		$uploaddir_path = $_SERVER['DOCUMENT_ROOT'] . "/wp-content/uploads";
	} else {
		$uploaddir_path = $_SERVER['DOCUMENT_ROOT'] . "/wp-content/uploads/sites/" . $site_id;
	}

	// Page title and path summary
	echo '<div class="wrap">';
	echo '<h1>Fix Upload Path - Migration Tool v2025.10.9</h1>';
	
	// Path Configuration Summary (Always visible at top)
	echo '<div style="background: #e7f3ff; border: 2px solid #0073aa; padding: 20px; margin: 20px 0; border-radius: 5px;">';
	echo '<h2 style="margin-top: 0;">📋 Migration Configuration</h2>';
	echo '<table class="widefat" style="background: white;">';
	echo '<tr><th style="width: 200px;">Site ID</th><td><strong>' . $site_id . '</strong></td></tr>';
	echo '<tr><th>Site Name</th><td><strong>' . esc_html(get_bloginfo('name')) . '</strong></td></tr>';
	echo '<tr><th>Site URL</th><td><strong>' . esc_url(get_site_url()) . '</strong></td></tr>';
	echo '<tr style="background: #fff3cd;"><th>Legacy Path (OLD)</th><td><code>' . esc_html($blogsdir_path) . '</code></td></tr>';
	echo '<tr style="background: #d4edda;"><th>Modern Path (NEW)</th><td><code>' . esc_html($uploaddir_path) . '</code></td></tr>';
	echo '</table>';
	echo '</div>';

	// ============================================================================
	// SYSTEM STATUS CHECKS - Gather all information first
	// ============================================================================
	
	// Check upload path configuration
	$upload_path = $wpdb->get_var("SELECT option_value FROM $option_table WHERE option_name = 'upload_path'");
	$upload_url_path = $wpdb->get_var("SELECT option_value FROM $option_table WHERE option_name = 'upload_url_path'");
	$wp_upload_dir = wp_upload_dir();
	
	// Check ms-files status
	$ms_files_rewriting = $wpdb->get_var("SELECT meta_value FROM $sitemeta_table WHERE meta_key = 'ms_files_rewriting'");
	
	// Check if files exist in blogs.dir
	$files_exist_in_blogsdir = is_dir($blogsdir_path) && count(array_diff(scandir($blogsdir_path), array('.', '..'))) > 0;
	$file_count_blogsdir = $files_exist_in_blogsdir ? count(array_diff(scandir($blogsdir_path), array('.', '..'))) : 0;
	
	// Count database issues
	$db_attachments_with_files = $wpdb->get_var("SELECT COUNT(*) FROM $posts_table WHERE post_type = 'attachment' AND (guid LIKE '%/files/%' OR guid LIKE '%blogs.dir%')");
	$db_posts_with_files = $wpdb->get_var("SELECT COUNT(*) FROM $posts_table WHERE post_content LIKE '%/files/%' OR post_content LIKE '%blogs.dir%'");
	$db_postmeta_with_files = $wpdb->get_var("SELECT COUNT(*) FROM $postmeta_table WHERE meta_value LIKE '%/files/%' OR meta_value LIKE '%blogs.dir%'");
	$db_options_with_files = $wpdb->get_var("SELECT COUNT(*) FROM $option_table WHERE option_value LIKE '%/files/%' OR option_value LIKE '%blogs.dir%'");
	
	// ============================================================================
	// MIGRATION STATUS TABLE
	// ============================================================================
	
	echo '<h2>📊 Migration Status & Actions</h2>';
	echo '<table class="widefat" style="margin-top: 20px;">';
	echo '<thead><tr>';
	echo '<th style="width: 40px;">#</th>';
	echo '<th style="width: 200px;">Migration Step</th>';
	echo '<th>Status</th>';
	echo '<th style="width: 300px;">Actions</th>';
	echo '</tr></thead>';
	echo '<tbody>';
	
	// Row 1: WordPress Upload Configuration
	$config_status = (empty($upload_path) && empty($upload_url_path) && strpos($wp_upload_dir['basedir'], 'blogs.dir') === false);
	echo '<tr' . (!$config_status ? ' style="background: #fff3cd;"' : '') . '>';
	echo '<td><strong>1</strong></td>';
	echo '<td><strong>WordPress Upload Config</strong><br><small>upload_path & upload_url_path</small></td>';
	echo '<td>';
	if ($config_status) {
		echo '✅ <strong>Correct</strong><br><small>Using modern multisite paths</small>';
	} else {
		echo '⚠️ <strong>Needs Fix</strong><br>';
		if (!empty($upload_path)) echo '<small>upload_path: ' . esc_html($upload_path) . '</small><br>';
		if (!empty($upload_url_path)) echo '<small>upload_url_path: ' . esc_html($upload_url_path) . '</small><br>';
		if (strpos($wp_upload_dir['basedir'], 'blogs.dir') !== false) echo '<small>Still using blogs.dir</small>';
	}
	echo '</td>';
	echo '<td>';
	if (!$config_status) {
		echo '<a href="?page=fix-media&fix_upload_config=1&site_id=' . $site_id . '&utm_nonce=' . wp_create_nonce('utm_fixuploadpath') . '" class="button button-primary button-small">Fix Configuration</a>';
	} else {
		echo '<span style="color: #999;">No action needed</span>';
	}
	echo '</td>';
	echo '</tr>';
	
	// Row 2: Files Migration
	echo '<tr' . ($files_exist_in_blogsdir ? ' style="background: #fff3cd;"' : '') . '>';
	echo '<td><strong>2</strong></td>';
	echo '<td><strong>File Migration</strong><br><small>blogs.dir → uploads/sites</small></td>';
	echo '<td>';
	if ($files_exist_in_blogsdir) {
		echo '⚠️ <strong>Pending</strong><br><small>' . $file_count_blogsdir . ' items in blogs.dir</small>';
	} else {
		echo '✅ <strong>Complete</strong><br><small>No files in blogs.dir</small>';
	}
	echo '</td>';
	echo '<td>';
	if ($files_exist_in_blogsdir) {
		echo '<a href="?page=fix-media&file_migration=1&site_id=' . $site_id . '&utm_nonce=' . wp_create_nonce('utm_fixuploadpath') . '&dry_run=1" class="button button-secondary button-small">Dry Run</a> ';
		echo '<a href="?page=fix-media&file_migration=1&site_id=' . $site_id . '&utm_nonce=' . wp_create_nonce('utm_fixuploadpath') . '" class="button button-primary button-small">Migrate Files</a>';
	} else {
		echo '<span style="color: #999;">Migration complete</span>';
	}
	echo '</td>';
	echo '</tr>';
	
	// Row 3: Database - Core Tables (posts, options)
	$db_core_issues = $db_attachments_with_files + $db_posts_with_files + $db_options_with_files;
	echo '<tr' . ($db_core_issues > 0 ? ' style="background: #fff3cd;"' : '') . '>';
	echo '<td><strong>3</strong></td>';
	echo '<td><strong>Database - Core Tables</strong><br><small>Posts, Attachments, Options</small></td>';
	echo '<td>';
	if ($db_core_issues > 0) {
		echo '⚠️ <strong>Needs Migration</strong><br>';
		echo '<small>';
		if ($db_attachments_with_files > 0) echo 'Attachments: ' . $db_attachments_with_files . '<br>';
		if ($db_posts_with_files > 0) echo 'Posts: ' . $db_posts_with_files . '<br>';
		if ($db_options_with_files > 0) echo 'Options: ' . $db_options_with_files;
		echo '</small>';
	} else {
		echo '✅ <strong>Clean</strong><br><small>No legacy paths found</small>';
	}
	echo '</td>';
	echo '<td>';
	if ($db_core_issues > 0) {
		echo '<a href="?page=fix-media&migrate_files_db=1&site_id=' . $site_id . '&utm_nonce=' . wp_create_nonce('utm_fixuploadpath') . '&dry_run=1" class="button button-secondary button-small">Dry Run</a> ';
		echo '<a href="?page=fix-media&migrate_files_db=1&site_id=' . $site_id . '&utm_nonce=' . wp_create_nonce('utm_fixuploadpath') . '" class="button button-primary button-small">Migrate DB</a>';
	} else {
		echo '<span style="color: #999;">No issues found</span>';
	}
	echo '</td>';
	echo '</tr>';
	
	// Row 4: Database - Plugin Tables (postmeta)
	echo '<tr' . ($db_postmeta_with_files > 0 ? ' style="background: #fff3cd;"' : '') . '>';
	echo '<td><strong>4</strong></td>';
	echo '<td><strong>Database - Plugin Data</strong><br><small>Postmeta & Serialized Data</small></td>';
	echo '<td>';
	if ($db_postmeta_with_files > 0) {
		echo '⚠️ <strong>Needs Migration</strong><br><small>Postmeta: ' . $db_postmeta_with_files . ' records</small>';
	} else {
		echo '✅ <strong>Clean</strong><br><small>No legacy paths found</small>';
	}
	echo '</td>';
	echo '<td>';
	if ($db_postmeta_with_files > 0) {
		echo '<a href="?page=fix-media&fix_attachment_paths=1&site_id=' . $site_id . '&utm_nonce=' . wp_create_nonce('utm_fixuploadpath') . '&dry_run=1" class="button button-secondary button-small">Dry Run</a> ';
		echo '<a href="?page=fix-media&fix_attachment_paths=1&site_id=' . $site_id . '&utm_nonce=' . wp_create_nonce('utm_fixuploadpath') . '" class="button button-primary button-small">Fix Metadata</a>';
	} else {
		echo '<span style="color: #999;">No issues found</span>';
	}
	echo '</td>';
	echo '</tr>';
	
	// Row 5: ms-files.php Status
	$msfiles_enabled = ($ms_files_rewriting == '1');
	echo '<tr' . ($msfiles_enabled ? ' style="background: #fff3cd;"' : '') . '>';
	echo '<td><strong>5</strong></td>';
	echo '<td><strong>ms-files.php Legacy Mode</strong><br><small>Should be disabled after migration</small></td>';
	echo '<td>';
	if ($msfiles_enabled) {
		echo '⚠️ <strong>Enabled</strong><br><small>Disable after migration complete</small>';
	} else {
		echo '✅ <strong>Disabled</strong><br><small>Using modern file serving</small>';
	}
	echo '</td>';
	echo '<td>';
	if ($msfiles_enabled) {
		$all_migrated = !$files_exist_in_blogsdir && $db_core_issues == 0 && $db_postmeta_with_files == 0;
		if ($all_migrated) {
			echo '<a href="?page=fix-media&msfiles=1&site_id=' . $site_id . '&utm_nonce=' . wp_create_nonce('utm_fixuploadpath') . '" class="button button-primary button-small">Disable ms-files.php</a>';
		} else {
			echo '<span style="color: #d63638;">Complete migration first</span>';
		}
	} else {
		echo '<span style="color: #999;">Already disabled</span>';
	}
	echo '</td>';
	echo '</tr>';
	
	echo '</tbody>';
	echo '</table>';
	
	// ============================================================================
	// CLEANUP UTILITIES SECTION
	// ============================================================================
	echo '<h2>🧹 Cleanup Utilities</h2>';
	echo '<div style="background: #f8f9fa; border: 1px solid #ddd; padding: 15px; margin: 20px 0;">';
	
	// Check for transients
	$transient_count = $wpdb->get_var("SELECT COUNT(*) FROM $option_table WHERE option_name LIKE '_transient_%dirsize_cache%'");
	
	// Check for known unused plugin options
	$unused_plugins = array(
		'cleantalk_fw_stats' => 'CleanTalk Firewall Statistics',
		'wds_sitemap_options' => 'Smartcrawl Sitemap Options',
	);
	
	$found_unused = array();
	foreach ($unused_plugins as $option_name => $description) {
		$exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $option_table WHERE option_name = %s", $option_name));
		if ($exists) {
			$found_unused[$option_name] = $description;
		}
	}
	
	echo '<h3 style="margin-top: 0;">Available Cleanup Operations</h3>';
	echo '<table class="widefat">';
	echo '<thead><tr><th style="width: 250px;">Cleanup Action</th><th>Description</th><th style="width: 150px;">Action</th></tr></thead>';
	echo '<tbody>';
	
	// Transient cleanup
	echo '<tr>';
	echo '<td><strong>Clear Directory Size Cache</strong></td>';
	echo '<td>Removes <code>_transient_dirsize_cache</code> (' . $transient_count . ' found). WordPress will regenerate this automatically. <strong>Safe to delete.</strong></td>';
	echo '<td>';
	if ($transient_count > 0) {
		echo '<a href="?page=fix-media&cleanup_transients=1&site_id=' . $site_id . '&utm_nonce=' . wp_create_nonce('utm_fixuploadpath') . '" class="button button-secondary button-small" onclick="return confirm(\'Clear directory size cache transients?\')">Clear Cache</a>';
	} else {
		echo '<span style="color: #999;">No transients</span>';
	}
	echo '</td>';
	echo '</tr>';
	
	// Unused plugin options
	foreach ($found_unused as $option_name => $description) {
		echo '<tr>';
		echo '<td><strong>' . esc_html($description) . '</strong></td>';
		echo '<td>Option: <code>' . esc_html($option_name) . '</code><br><small>This appears to be from an unused/deactivated plugin. <strong>Safe to delete if plugin is no longer active.</strong></small></td>';
		echo '<td>';
		echo '<a href="?page=fix-media&delete_option=1&option_name=' . urlencode($option_name) . '&site_id=' . $site_id . '&utm_nonce=' . wp_create_nonce('utm_fixuploadpath') . '" class="button button-secondary button-small" onclick="return confirm(\'Delete option: ' . esc_js($option_name) . '?\')">Delete Option</a>';
		echo '</td>';
		echo '</tr>';
	}
	
	if ($transient_count == 0 && empty($found_unused)) {
		echo '<tr><td colspan="3" style="text-align: center; color: #999;"><em>No cleanup actions needed - everything looks clean!</em></td></tr>';
	}
	
	echo '</tbody>';
	echo '</table>';
	echo '</div>';
	
	// Migration recommendations
	$total_issues = ($config_status ? 0 : 1) + ($files_exist_in_blogsdir ? 1 : 0) + ($db_core_issues > 0 ? 1 : 0) + ($db_postmeta_with_files > 0 ? 1 : 0);
	
	if ($total_issues == 0 && !$msfiles_enabled) {
		echo '<div style="background: #d4edda; border: 2px solid #28a745; padding: 20px; margin: 20px 0; border-radius: 5px;">';
		echo '<h3 style="margin-top: 0; color: #155724;">✅ Migration Complete!</h3>';
		echo '<p style="color: #155724;">All migration steps have been completed successfully. Your site is now using the modern WordPress multisite upload structure.</p>';
		echo '</div>';
	} else {
		echo '<div style="background: #fff3cd; border: 2px solid #ffc107; padding: 20px; margin: 20px 0; border-radius: 5px;">';
		echo '<h3 style="margin-top: 0; color: #856404;">📋 Recommended Migration Order</h3>';
		echo '<ol style="color: #856404; margin: 0;">';
		if (!$config_status) echo '<li>Fix WordPress upload configuration first</li>';
		if ($files_exist_in_blogsdir) echo '<li>Migrate files from blogs.dir to uploads/sites (use Dry Run first!)</li>';
		if ($db_core_issues > 0) echo '<li>Migrate database core tables (posts, attachments, options)</li>';
		if ($db_postmeta_with_files > 0) echo '<li>Fix plugin metadata (postmeta)</li>';
		if ($msfiles_enabled && $total_issues <= 1) echo '<li>Finally, disable ms-files.php</li>';
		echo '</ol>';
		echo '</div>';
	}

	// ============================================================================
	// ACTION HANDLERS - Process any requested actions
	// ============================================================================
	
	// Handle WordPress upload configuration fix
	if (isset($_GET['fix_upload_config']) && $_GET['fix_upload_config'] == 1) {
		if (!isset($_GET['utm_nonce']) || !wp_verify_nonce($_GET['utm_nonce'], 'utm_fixuploadpath')) {
			echo '<div class="notice notice-error"><p><strong>Security check failed!</strong></p></div>';
		} else {
			echo '<div style="border: 2px solid #007cba; padding: 15px; margin: 10px 0; background: #f8f9fa;">';
			echo '<h2>🔧 Fixing WordPress Upload Configuration</h2>';
			
			// Clear upload_path
			if (!empty($upload_path)) {
				$wpdb->update($option_table, array('option_value' => ''), array('option_name' => 'upload_path'));
				echo '<p>✅ Cleared upload_path option</p>';
			}
			
			// Clear upload_url_path
			if (!empty($upload_url_path)) {
				$wpdb->update($option_table, array('option_value' => ''), array('option_name' => 'upload_url_path'));
				echo '<p>✅ Cleared upload_url_path option</p>';
			}
			
			echo '<p><strong>Configuration updated!</strong> <a href="?page=fix-media&site_id=' . $site_id . '">Refresh page</a> to see changes.</p>';
			echo '</div>';
			return;
		}
	}
	
	// ============================================================================
	// DETAILED DEBUG SECTION (Collapsible)
	// ============================================================================
	
	echo '<h2 style="cursor: pointer;" onclick="document.getElementById(\'debug-details\').style.display = document.getElementById(\'debug-details\').style.display === \'none\' ? \'block\' : \'none\';">🔍 Advanced Debug Information <small>(click to toggle)</small></h2>';
	echo '<div id="debug-details" style="display: none;">';
	
	echo '<h3>🗂️ Blogs.dir Status</h3>';
	echo "<div style='background: #f9fff9; padding: 10px; margin: 10px 0; border-left: 4px solid #28a745;'>";
	echo "<strong>Blogs.dir path:</strong> " . $blogsdir_parent_path . "<br>";
	echo "<strong>Path exists:</strong> " . (is_dir($blogsdir_parent_path) ? 'Yes' : 'No') . "<br>";
	echo "<strong>Path readable:</strong> " . (is_readable($blogsdir_parent_path) ? 'Yes' : 'No') . "<br>";
	echo "<strong>Path writable:</strong> " . (is_writable($blogsdir_parent_path) ? 'Yes' : 'No') . "<br>";

	if (is_dir($blogsdir_parent_path)) {
		$files_in_blogsdir = scandir($blogsdir_parent_path);
		$files_in_blogsdir = array_diff($files_in_blogsdir, array('.', '..'));
		$file_count = count($files_in_blogsdir); // Exclude . and ..
		echo "<strong>File count:</strong> $file_count<br>";

		// If blogs.dir contains only the 'files' directory and it is empty, show ready for cleanup
		if ($file_count === 1 && is_dir("{$blogsdir_parent_path}/files")) {
			$files_dir = "{$blogsdir_parent_path}/files";
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
	
	// WordPress Upload Directory Info
	echo '<h3>📁 WordPress Upload Directory Info</h3>';
	echo "<div style='background: #f8f9fa; padding: 10px; margin: 10px 0; border-left: 4px solid #007cba;'>";
	echo "<strong>Current upload path:</strong> " . htmlspecialchars($wp_upload_dir['path']) . "<br>";
	echo "<strong>Current upload URL:</strong> " . htmlspecialchars($wp_upload_dir['url']) . "<br>";
	echo "<strong>Base upload dir:</strong> " . htmlspecialchars($wp_upload_dir['basedir']) . "<br>";
	echo "<strong>Base upload URL:</strong> " . htmlspecialchars($wp_upload_dir['baseurl']) . "<br>";
	echo "<strong>Subdir:</strong> " . htmlspecialchars($wp_upload_dir['subdir']) . "<br>";
	echo "<strong>Error:</strong> " . ($wp_upload_dir['error'] ? htmlspecialchars($wp_upload_dir['error']) : 'None') . "<br>";
	echo "<strong>Contains blogs.dir:</strong> " . (strpos($wp_upload_dir['basedir'], 'blogs.dir') !== false ? 'Yes ⚠️' : 'No ✅') . "<br>";
	echo "</div>";
	
	// Database Statistics
	echo '<h3>🗄️ Database Statistics</h3>';
	echo "<div style='background: #f8f9fa; padding: 10px; margin: 10px 0; border-left: 4px solid #007cba;'>";
	echo "<strong>Attachments with legacy paths:</strong> $db_attachments_with_files<br>";
	echo "<strong>Posts with legacy paths:</strong> $db_posts_with_files<br>";
	echo "<strong>Postmeta with legacy paths:</strong> $db_postmeta_with_files<br>";
	echo "<strong>Options with legacy paths:</strong> $db_options_with_files<br>";
	echo "<strong>ms-files.php status:</strong> " . ($ms_files_rewriting == '1' ? 'Enabled ⚠️' : 'Disabled ✅') . "<br>";
	echo "</div>";
	
	echo '</div>'; // End debug-details
	
	// Auto-cleanup empty blogs.dir
	if (is_dir($blogsdir_parent_path) && count(scandir($blogsdir_parent_path)) == 2) {
		rrmdir($blogsdir_parent_path);
	}

	// ============================================================================
	// FILE MIGRATION HANDLER
	// ============================================================================
	if (isset($_GET['file_migration']) && $_GET['file_migration'] == 1){
		if (!isset($_GET['utm_nonce']) || !wp_verify_nonce($_GET['utm_nonce'], 'utm_fixuploadpath')) {
			echo '<div class="notice notice-error"><p><strong>Security check failed!</strong></p></div>';
			return;
		}
		
		if ($site_id == 1){
			echo '<div class="notice notice-info"><p>This is the main site. File migration is not needed.</p></div>';
			return;
		}
		
		echo '<div style="border: 2px solid #007cba; padding: 15px; margin: 10px 0; background: #f8f9fa;">';
		echo '<h2>📁 File Migration</h2>';
		echo '<p><strong>Source:</strong> <code>' . esc_html($blogsdir_path) . '</code></p>';
		echo '<p><strong>Destination:</strong> <code>' . esc_html($uploaddir_path) . '</code></p>';
		echo '<p><strong>Mode:</strong> ' . (!empty($_GET['dry_run']) ? '<span style="color: #856404;">DRY RUN</span>' : '<span style="color: #d63638;">LIVE MIGRATION</span>') . '</p>';
		echo '<hr>';
		
		migrate_files_contents($blogsdir_path, $uploaddir_path, !empty($_GET['dry_run']));
		
		echo '<hr>';
		echo '<p><a href="?page=fix-media&site_id=' . $site_id . '" class="button button-primary">Return to Migration Dashboard</a></p>';
		echo '</div>';
		return;
	}

	// ============================================================================
	// MS-FILES.PHP DISABLE HANDLER
	// ============================================================================
	if (isset($_GET['msfiles'])){
		if (!isset($_GET['utm_nonce']) || !wp_verify_nonce($_GET['utm_nonce'], 'utm_fixuploadpath')) {
			echo '<div class="notice notice-error"><p><strong>Security check failed!</strong></p></div>';
			return;
		}
		
		echo '<div style="border: 2px solid #007cba; padding: 15px; margin: 10px 0; background: #f8f9fa;">';
		echo '<h2>🔧 Disabling ms-files.php</h2>';
		
		$meta_key = 'ms_files_rewriting';
		$meta_value = '0';
		$meta_exists = $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM $sitemeta_table WHERE meta_key = %s",
			$meta_key
		));

		if ($meta_exists) {
			$wpdb->update($sitemeta_table, array('meta_value' => $meta_value), array('meta_key' => $meta_key));
			echo '<p>✅ Updated ms_files_rewriting to: ' . $meta_value . '</p>';
		} else {
			$wpdb->insert($sitemeta_table, array('meta_key' => $meta_key, 'meta_value' => $meta_value));
			echo '<p>✅ Inserted ms_files_rewriting with value: ' . $meta_value . '</p>';
		}
		
		echo '<p><strong>ms-files.php has been disabled!</strong> <a href="?page=fix-media&site_id=' . $site_id . '">Refresh page</a></p>';
		echo '</div>';
		return;
	}

	// ============================================================================
	// CLEANUP UTILITIES HANDLER
	// ============================================================================
	if (isset($_GET['cleanup_transients'])){
		if (!isset($_GET['utm_nonce']) || !wp_verify_nonce($_GET['utm_nonce'], 'utm_fixuploadpath')) {
			echo '<div class="notice notice-error"><p><strong>Security check failed!</strong></p></div>';
			return;
		}
		
		echo '<div style="border: 2px solid #007cba; padding: 15px; margin: 10px 0; background: #f8f9fa;">';
		echo '<h2>🧹 Cleaning Up Transients</h2>';
		
		// Delete dirsize_cache transient
		$deleted = $wpdb->query("DELETE FROM $option_table WHERE option_name LIKE '_transient_dirsize_cache' OR option_name LIKE '_transient_timeout_dirsize_cache'");
		echo '<p>✅ Deleted ' . $deleted . ' dirsize_cache transient(s)</p>';
		echo '<p><small>WordPress will automatically regenerate this cache when needed.</small></p>';
		
		echo '<p><a href="?page=fix-media&site_id=' . $site_id . '" class="button button-primary">Return to Migration Dashboard</a></p>';
		echo '</div>';
		return;
	}
	
	if (isset($_GET['delete_option'])){
		if (!isset($_GET['utm_nonce']) || !wp_verify_nonce($_GET['utm_nonce'], 'utm_fixuploadpath')) {
			echo '<div class="notice notice-error"><p><strong>Security check failed!</strong></p></div>';
			return;
		}
		
		$option_name = isset($_GET['option_name']) ? sanitize_text_field($_GET['option_name']) : '';
		if (empty($option_name)) {
			echo '<div class="notice notice-error"><p><strong>Error:</strong> No option name specified.</p></div>';
			return;
		}
		
		echo '<div style="border: 2px solid #007cba; padding: 15px; margin: 10px 0; background: #f8f9fa;">';
		echo '<h2>🗑️ Deleting Unused Option</h2>';
		
		$deleted = $wpdb->delete($option_table, array('option_name' => $option_name));
		if ($deleted) {
			echo '<p>✅ Successfully deleted option: <code>' . esc_html($option_name) . '</code></p>';
		} else {
			echo '<p>❌ Failed to delete option or option not found: <code>' . esc_html($option_name) . '</code></p>';
		}
		
		echo '<p><a href="?page=fix-media&site_id=' . $site_id . '" class="button button-primary">Return to Migration Dashboard</a></p>';
		echo '</div>';
		return;
	}

	// ============================================================================
	// DATABASE MIGRATION HANDLER
	// ============================================================================
	if (isset($_GET['migrate_files_db'])){
		if (!isset($_GET['utm_nonce']) || !wp_verify_nonce($_GET['utm_nonce'], 'utm_fixuploadpath')) {
			echo '<div class="notice notice-error"><p><strong>Security check failed!</strong></p></div>';
			return;
		}
		
		echo '<div style="border: 2px solid #007cba; padding: 15px; margin: 10px 0; background: #f8f9fa;">';
		echo '<h2>🔄 Database Migration - Core Tables</h2>';
		echo '<p><strong>Mode:</strong> ' . (!empty($_GET['dry_run']) ? '<span style="color: #856404;">DRY RUN</span>' : '<span style="color: #d63638;">LIVE MIGRATION</span>') . '</p>';
		echo '<p><strong>Site ID:</strong> ' . $site_id . '</p>';
		echo '<hr>';
		
		utm_migrate_files_database_paths($site_id, !empty($_GET['dry_run']));
		
		echo '<hr>';
		echo '<p><a href="?page=fix-media&site_id=' . $site_id . '" class="button button-primary">Return to Migration Dashboard</a></p>';
		echo '</div>';
		return;
	}

	// ============================================================================
	// ATTACHMENT METADATA FIX HANDLER
	// ============================================================================
	if (isset($_GET['fix_attachment_paths'])){
		if (!isset($_GET['utm_nonce']) || !wp_verify_nonce($_GET['utm_nonce'], 'utm_fixuploadpath')) {
			echo '<div class="notice notice-error"><p><strong>Security check failed!</strong></p></div>';
			return;
		}
		
		echo '<div style="border: 2px solid #007cba; padding: 15px; margin: 10px 0; background: #f8f9fa;">';
		echo '<h2>� Fixing Attachment Metadata</h2>';
		echo '<p><strong>Mode:</strong> ' . (!empty($_GET['dry_run']) ? '<span style="color: #856404;">DRY RUN</span>' : '<span style="color: #d63638;">LIVE FIX</span>') . '</p>';
		echo '<hr>';
		
		utm_fix_attachment_file_paths($site_id, !empty($_GET['dry_run']));
		
		echo '<hr>';
		echo '<p><a href="?page=fix-media&site_id=' . $site_id . '" class="button button-primary">Return to Migration Dashboard</a></p>';
		echo '</div>';
		return;
	}

	// ============================================================================
	// METADATA REGENERATION HANDLER
	// ============================================================================
	if (isset($_GET['regenerate_metadata'])){
		if (!isset($_GET['utm_nonce']) || !wp_verify_nonce($_GET['utm_nonce'], 'utm_fixuploadpath')) {
			echo '<div class="notice notice-error"><p><strong>Security check failed!</strong></p></div>';
			return;
		}
		
		echo '<div style="border: 2px solid #007cba; padding: 15px; margin: 10px 0; background: #f8f9fa;">';
		echo '<h2>🔄 Regenerating Attachment Metadata</h2>';
		echo '<p><strong>Mode:</strong> ' . (!empty($_GET['dry_run']) ? '<span style="color: #856404;">DRY RUN</span>' : '<span style="color: #d63638;">LIVE REGENERATION</span>') . '</p>';
		echo '<p><em>This may take a while for sites with many images...</em></p>';
		echo '<hr>';
		
		utm_regenerate_attachment_metadata($site_id, !empty($_GET['dry_run']));
		
		echo '<hr>';
		echo '<p><a href="?page=fix-media&site_id=' . $site_id . '" class="button button-primary">Return to Migration Dashboard</a></p>';
		echo '</div>';
		return;
	}
	
	echo '</div>'; // Close wrap div
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
        $subpath = $iterator->getSubPathName();
        
        // Build destination path - this will be relative to source like "2015/12/image.jpg"
        $dest_path = $destination . DIRECTORY_SEPARATOR . $subpath;
        
        if ($item->isDir()) {
            if (!is_dir($dest_path)) {
                if (!$dry_run) {
                    if (!mkdir($dest_path, 0755, true)) {
                        echo "<div class='notice notice-error'><p>Failed to create directory: $dest_path</p></div>";
                        continue;
                    }
                }
                echo "<div class='notice notice-info'><p>Created directory: $dest_path</p></div>";
            }
        } else {
			if ($dry_run) {
				// In dry run, actually copy the file (leave original in place)
				// Ensure parent directory exists
				$dest_dir = dirname($dest_path);
				if (!is_dir($dest_dir)) {
					mkdir($dest_dir, 0755, true);
				}
				if (@copy($item, $dest_path)) {
					echo "<div class='notice notice-info'><p>[Dry Run] Would copy: $item → $dest_path</p></div>";
				} else {
					echo "<div class='notice notice-error'><p>[Dry Run] Failed to copy file: $item to $dest_path</p></div>";
				}
			} else {
				// In real run, move the file (leave empty folders on origin)
				// Ensure parent directory exists
				$dest_dir = dirname($dest_path);
				if (!is_dir($dest_dir)) {
					mkdir($dest_dir, 0755, true);
				}
				if (@copy($item, $dest_path)) {
					echo "<div class='notice notice-success'><p>Copied file: $item → $dest_path</p></div>";
					// After successful copy, remove original
					@unlink($item);
				} else {
					echo "<div class='notice notice-error'><p>Failed to copy file: $item to $dest_path</p></div>";
				}
			}
        }
    }
	
	if (!$dry_run) {
		echo "<div class='notice notice-info'><p><strong>Migration complete!</strong> Cleaning up empty directories...</p></div>";
		// After migration, clean up empty directories in source
		// Use recursive function to remove empty directories from bottom up
		utm_remove_empty_dirs($source);
		
		// After cleaning up empty subdirectories, try to remove the entire site folder from blogs.dir
		// This is the parent folder containing the /files/ subdirectory
		$site_blogsdir_folder = dirname($source); // e.g., /wp-content/blogs.dir/1254
		if (is_dir($site_blogsdir_folder)) {
			// Check if it's now empty (only . and .. remain)
			$remaining_items = array_diff(scandir($site_blogsdir_folder), array('.', '..'));
			if (empty($remaining_items)) {
				if (rmdir($site_blogsdir_folder)) {
					echo "<div class='notice notice-success'><p><strong>✅ Deleted empty site folder:</strong> $site_blogsdir_folder</p></div>";
					echo "<div class='notice notice-info'><p>This site's blogs.dir folder has been completely removed. Once all sites are migrated, the parent blogs.dir folder will be empty and can be safely deleted.</p></div>";
				} else {
					echo "<div class='notice notice-warning'><p>Could not remove site folder: $site_blogsdir_folder (may have permission issues)</p></div>";
				}
			} else {
				echo "<div class='notice notice-warning'><p>Site folder still contains items: $site_blogsdir_folder</p>";
				echo "<p>Remaining items: " . implode(', ', $remaining_items) . "</p></div>";
			}
		}
	} else {
		echo "<div class='notice notice-info'><p><strong>Dry run complete!</strong> No files were moved. Files would be copied from $source to $destination</p></div>";
	}
}

/**
 * Recursively remove empty directories
 */
function utm_remove_empty_dirs($path) {
	if (!is_dir($path)) {
		return false;
	}
	
	$items = array_diff(scandir($path), array('.', '..'));
	
	// First, recursively process subdirectories
	foreach ($items as $item) {
		$item_path = $path . DIRECTORY_SEPARATOR . $item;
		if (is_dir($item_path)) {
			utm_remove_empty_dirs($item_path);
		}
	}
	
	// After processing subdirectories, check if this directory is now empty
	$items = array_diff(scandir($path), array('.', '..'));
	if (empty($items)) {
		if (rmdir($path)) {
			echo "<div class='notice notice-info'><p>Removed empty directory: $path</p></div>";
			return true;
		}
	}
	
	return false;
}

/**
 * Recursively replace strings in arrays and objects (for serialized data)
 * This handles nested arrays, objects, and maintains proper serialization
 */
function utm_recursive_str_replace_in_array($search, $replace, $data) {
    if (is_string($data)) {
        // Apply all search/replace patterns
        return str_replace($search, $replace, $data);
    } elseif (is_array($data)) {
        // Recursively process array elements
        $result = array();
        foreach ($data as $key => $value) {
            // Also replace in keys (in case paths are used as array keys)
            $new_key = is_string($key) ? str_replace($search, $replace, $key) : $key;
            $result[$new_key] = utm_recursive_str_replace_in_array($search, $replace, $value);
        }
        return $result;
    } elseif (is_object($data)) {
        // Recursively process object properties
        $result = clone $data;
        foreach ($result as $key => $value) {
            $result->$key = utm_recursive_str_replace_in_array($search, $replace, $value);
        }
        return $result;
    }
    
    // For other data types (int, bool, null, etc.), return as-is
    return $data;
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
    $site_slug = trim($site_slug, '/');

    // Get upload directory info
    $upload_dir = wp_upload_dir();
    $site_url = get_site_url($site_id);
    $network_url = network_site_url();  // Get the network's base URL (without site slug)
    
    // Build search patterns for legacy /files/ structure
    // Pattern 1: URL format - http(s)://domain.com/siteslug/files/
    $search_url_http = 'http://' . parse_url($site_url, PHP_URL_HOST) . ($site_slug ? '/' . $site_slug : '') . '/files/';
    $search_url_https = 'https://' . parse_url($site_url, PHP_URL_HOST) . ($site_slug ? '/' . $site_slug : '') . '/files/';
    
    // Pattern 2: File path format - /path/to/blogs.dir/{site_id}/files/
    $search_path = '/blogs.dir/' . $site_id . '/files/';
    
    // Pattern 3: Old server absolute paths (for server migration scenarios)
    // This handles cases where the database contains old server paths like /opt/www/...
    $current_server_root = $_SERVER['DOCUMENT_ROOT']; // e.g., /var/www/html
    $old_server_paths = array(
        '/opt/www/people.utm.my/httpdocs',  // Old server path pattern
        '/home/people/public_html',          // Alternative old path pattern
        '/usr/local/www/people.utm.my',     // Another possible old path
    );
    
    // Build replacement strings - WordPress multisite stores files at network level, not site level
    // Correct format: https://domain.com/wp-content/uploads/sites/{site_id}/ (NO site slug)
    $replace_url = str_replace('http://', 'https://', $upload_dir['baseurl']) . '/';  // Force HTTPS
    
    // Remove site slug from the URL if present (WordPress multisite doesn't use site slug in upload URLs)
    if ($site_slug && strpos($replace_url, '/' . $site_slug . '/') !== false) {
        $replace_url = str_replace('/' . $site_slug . '/', '/', $replace_url);
    }
    
    $replace_path = $upload_dir['basedir'] . '/';
    
    echo "<div class='notice notice-info'><p><strong>Migration Configuration:</strong><br>";
    echo "Search URL (HTTP): <code>{$search_url_http}</code><br>";
    echo "Search URL (HTTPS): <code>{$search_url_https}</code><br>";
    echo "Search Path: <code>{$search_path}</code><br>";
    echo "<strong>Old Server Paths to Replace:</strong><br>";
    foreach ($old_server_paths as $old_path) {
        echo "  • <code>{$old_path}</code> → <code>{$current_server_root}</code><br>";
    }
    echo "<br><strong>Replacement Values:</strong><br>";
    echo "Replace with (URLs): <code>{$replace_url}</code><br>";
    echo "Replace with (Paths): <code>{$replace_path}</code><br>";
    echo "Current Server Root: <code>{$current_server_root}</code></p></div>";

    foreach ($tables_to_search as $table) {
        // Get the primary key column name
        $primary_key = $wpdb->get_var("SHOW KEYS FROM $table WHERE Key_name = 'PRIMARY'", 4); // 5th column is Column_name
        $columns = $wpdb->get_col("DESC $table");

        foreach ($columns as $column) {
            // Build comprehensive search query including old server paths
            $search_conditions = array(
                '%' . $wpdb->esc_like('/files/') . '%',
                '%' . $wpdb->esc_like('blogs.dir') . '%',
                '%' . $wpdb->esc_like($site_slug . '/files/') . '%'
            );
            
            // Add old server path patterns to search
            foreach ($old_server_paths as $old_path) {
                $search_conditions[] = '%' . $wpdb->esc_like($old_path) . '%';
            }
            
            // Build query with dynamic number of conditions
            $placeholders = implode(' OR ', array_fill(0, count($search_conditions), "`$column` LIKE %s"));
            $query = "SELECT `$primary_key`, `$column` FROM $table WHERE $placeholders";
            
            // If it's the options table, also get the option_name for context
            if ($table === $wpdb->prefix . 'options') {
                $query = "SELECT `$primary_key`, `option_name`, `$column` FROM $table WHERE $placeholders";
            }
            
            $results = $wpdb->get_results($wpdb->prepare($query, ...$search_conditions));

            // If no results found, skip to next column
            if (empty($results)) {
                continue;
            }
            
            echo "<div class='notice notice-info'><p>Found " . count($results) . " records in $table.$column to migrate</p></div>";

            foreach ($results as $row) {
                $old_value = $row->$column;
                $new_value = $old_value;
                
                // Show what we found (before any processing)
                echo "<div class='notice notice-info' style='background: #f0f0f1; border-left: 4px solid #72aee6;'>";
                echo "<p><strong>🔍 Analyzing record:</strong> $table.$column for row {$row->$primary_key}<br>";
                
                // If this is the options table, show the option name
                if ($table === $wpdb->prefix . 'options' && isset($row->option_name)) {
                    echo "<strong>Option Name:</strong> <code style='background: #fff3cd; padding: 2px 5px;'>" . esc_html($row->option_name) . "</code><br>";
                    
                    // Provide context for common WordPress options
                    $option_descriptions = array(
                        'upload_path' => 'Legacy upload directory path (should be empty)',
                        'upload_url_path' => 'Legacy upload URL path (should be empty)',
                        'dirsize_cache' => 'Directory size cache (stores folder sizes for admin display)',
                        'active_plugins' => 'List of active plugins',
                        'cron' => 'WordPress scheduled tasks',
                        'rewrite_rules' => 'URL rewrite rules',
                    );
                    
                    if (isset($option_descriptions[$row->option_name])) {
                        echo "<em style='color: #666;'>Purpose: " . $option_descriptions[$row->option_name] . "</em><br>";
                    }
                }
                
                echo "<strong>Raw value (first 200 chars):</strong><br><code style='background: white; padding: 5px; display: block; word-break: break-all;'>" . esc_html(substr($old_value, 0, 200)) . (strlen($old_value) > 200 ? '...' : '') . "</code></p>";
                echo "</div>";
                
                // Determine if we're dealing with URLs or file paths
                $is_url = ($column === 'guid' || $column === 'post_content' || 
                          (strpos($old_value, 'http://') === 0 || strpos($old_value, 'https://') === 0));
                
                // Check what patterns match
                $matches_http = strpos($old_value, $search_url_http) !== false;
                $matches_https = strpos($old_value, $search_url_https) !== false;
                $matches_path = strpos($old_value, $search_path) !== false;
                $matches_files = strpos($old_value, '/files/') !== false;
                $matches_blogsdir = strpos($old_value, 'blogs.dir') !== false;
                
                // Check for old server paths
                $matches_old_server = array();
                foreach ($old_server_paths as $old_path) {
                    if (strpos($old_value, $old_path) !== false) {
                        $matches_old_server[] = $old_path;
                    }
                }
                
                echo "<div class='notice notice-info' style='background: #fff9e6; border-left: 4px solid #f0b849;'>";
                echo "<p><strong>🎯 Pattern Matching:</strong><br>";
                echo "• Detected as: " . ($is_url ? 'URL' : 'File Path') . "<br>";
                echo "• Matches HTTP pattern (" . esc_html($search_url_http) . "): " . ($matches_http ? '✅ YES' : '❌ NO') . "<br>";
                echo "• Matches HTTPS pattern (" . esc_html($search_url_https) . "): " . ($matches_https ? '✅ YES' : '❌ NO') . "<br>";
                echo "• Matches path pattern (" . esc_html($search_path) . "): " . ($matches_path ? '✅ YES' : '❌ NO') . "<br>";
                echo "• Contains '/files/': " . ($matches_files ? '✅ YES' : '❌ NO') . "<br>";
                echo "• Contains 'blogs.dir': " . ($matches_blogsdir ? '✅ YES' : '❌ NO') . "<br>";
                if (!empty($matches_old_server)) {
                    echo "<strong style='color: #d63638;'>⚠️ Contains OLD SERVER PATHS:</strong><br>";
                    foreach ($matches_old_server as $old_path) {
                        echo "  • <code>" . esc_html($old_path) . "</code> ✅ WILL BE REPLACED<br>";
                    }
                }
                echo "</p>";
                echo "</div>";
                
                // Check if this needs serialized data handling
                $needs_serialized_handling = ($table === $wpdb->prefix . 'postmeta' || $table === $wpdb->prefix . 'options') && 
                                            (!empty($matches_old_server) || $matches_path || $matches_http || $matches_https);
                
                if ($needs_serialized_handling) {
                    // Try to unserialize to check if it's serialized data
                    $test_unserialized = @maybe_unserialize($old_value);
                    if ($test_unserialized !== false && $test_unserialized !== $old_value) {
                        // It IS serialized - we'll handle this in the update section below
                        // Don't do simple string replacement here
                        echo "<div class='notice notice-info' style='background: #e7f5ff;'>";
                        echo "<p>📦 <strong>Serialized data detected</strong> - will use recursive replacement method</p></div>";
                        // Set new_value to old_value temporarily - we'll process it properly below
                        $new_value = $old_value;
                    } else {
                        // Not serialized, apply simple replacements
                        if ($is_url) {
                            $new_value = str_replace($search_url_http, $replace_url, $new_value);
                            $new_value = str_replace($search_url_https, $replace_url, $new_value);
                        } else {
                            $new_value = str_replace($search_path, $replace_path, $new_value);
                        }
                        // Also replace old server paths in non-serialized data
                        foreach ($old_server_paths as $old_path) {
                            $new_value = str_replace($old_path, $current_server_root, $new_value);
                        }
                    }
                } else {
                    // Apply replacements in order for non-serialized data
                    if ($is_url) {
                        // Replace HTTP version first
                        $new_value = str_replace($search_url_http, $replace_url, $new_value);
                        // Then replace HTTPS version
                        $new_value = str_replace($search_url_https, $replace_url, $new_value);
                    } else {
                        // Replace file path pattern
                        $new_value = str_replace($search_path, $replace_path, $new_value);
                    }
                }
                
                // For dry run, skip if nothing changed (but not for serialized data which we process later)
                if ($dry_run && $old_value === $new_value) {
                    echo "<div class='notice notice-warning'><p><strong>⚠️ SKIPPED (DRY RUN):</strong> No simple changes detected. May need serialized data processing.</p></div>";
                    continue;
                }

                if ($dry_run) {
                    echo "<div class='notice notice-info'><p>[Dry Run] Would update $table.$column for row {$row->$primary_key}:<br>";
                    echo "<strong>Old:</strong> " . esc_html(substr($old_value, 0, 150)) . (strlen($old_value) > 150 ? '...' : '') . "<br>";
                    echo "<strong>New:</strong> " . esc_html(substr($new_value, 0, 150)) . (strlen($new_value) > 150 ? '...' : '') . "</p></div>";
                } else {
                    // LIVE UPDATE MODE
                    if ($table === $wpdb->prefix . 'postmeta' || $table === $wpdb->prefix . 'options') {
                        // For serialized data, we need to use a special approach
                        // WordPress serialization includes string lengths that must be recalculated
                        
                        // Try to unserialize the data
                        $unserialized = @maybe_unserialize($row->$column);
                        
                        if ($unserialized !== false && $unserialized !== $row->$column) {
                            // Data was serialized - use recursive replacement
                            echo "<div class='notice notice-info' style='background: #fff3cd; border-left: 4px solid #f0b849;'>";
                            echo "<p>📦 <strong>Serialized data detected</strong> - using recursive replacement method<br>";
                            echo "Data type: " . gettype($unserialized) . "<br>";
                            if (is_array($unserialized)) {
                                echo "Array size: " . count($unserialized) . " elements<br>";
                            }
                            echo "</p></div>";
                            
                            // Build comprehensive search/replace arrays including old server paths
                            $search_patterns = array($search_url_http, $search_url_https, $search_path);
                            $replace_patterns = array($replace_url, $replace_url, $replace_path);
                            
                            // Add old server path replacements
                            foreach ($old_server_paths as $old_path) {
                                $search_patterns[] = $old_path;
                                $replace_patterns[] = $current_server_root;
                            }
                            
                            $new_value_unserialized = utm_recursive_str_replace_in_array(
                                $search_patterns,
                                $replace_patterns,
                                $unserialized
                            );
                            
                            // Re-serialize with correct string lengths
                            $new_value = maybe_serialize($new_value_unserialized);
                            
                            // Show comparison
                            echo "<div class='notice notice-info' style='background: #e7f5ff; border-left: 4px solid #2271b1;'>";
                            echo "<p>📊 <strong>Serialization comparison:</strong><br>";
                            echo "Old serialized length: " . strlen($row->$column) . " bytes<br>";
                            echo "New serialized length: " . strlen($new_value) . " bytes<br>";
                            echo "Difference: " . (strlen($new_value) - strlen($row->$column)) . " bytes<br>";
                            if ($row->$column === $new_value) {
                                echo "<strong style='color: #d63638;'>⚠️ WARNING: Values are identical - no changes made!</strong><br>";
                                echo "This may indicate the old server paths have already been replaced, or no matching patterns found.";
                            } else {
                                echo "<strong style='color: #00a32a;'>✅ Values changed - replacement successful!</strong>";
                            }
                            echo "</p></div>";
                            
                        } else {
                            // Not serialized or couldn't unserialize - use simple string replacement
                            echo "<div class='notice notice-info' style='background: #fff3cd; border-left: 4px solid #f0b849;'>";
                            echo "<p>📝 <strong>Plain text data</strong> - using simple string replacement</p></div>";
                            
                            if (is_string($row->$column)) {
                                $new_value = $row->$column;
                                $is_url = (strpos($row->$column, 'http://') === 0 || strpos($row->$column, 'https://') === 0);
                                if ($is_url) {
                                    $new_value = str_replace($search_url_http, $replace_url, $new_value);
                                    $new_value = str_replace($search_url_https, $replace_url, $new_value);
                                } else {
                                    $new_value = str_replace($search_path, $replace_path, $new_value);
                                }
                                // Also replace old server paths
                                foreach ($old_server_paths as $old_path) {
                                    $new_value = str_replace($old_path, $current_server_root, $new_value);
                                }
                            } else {
                                $new_value = $row->$column;
                            }
                        }
                    } else {
                        $is_url = ($column === 'guid' || $column === 'post_content' || 
                                  (strpos($old_value, 'http://') === 0 || strpos($old_value, 'https://') === 0));
                        if ($is_url) {
                            $new_value = str_replace($search_url_http, $replace_url, $old_value);
                            $new_value = str_replace($search_url_https, $replace_url, $new_value);
                        } else {
                            $new_value = str_replace($search_path, $replace_path, $old_value);
                        }
                        // Also replace old server paths in regular tables
                        foreach ($old_server_paths as $old_path) {
                            $new_value = str_replace($old_path, $current_server_root, $new_value);
                        }
                    }
                    // Show what we're about to update
                    echo "<div class='notice notice-info' style='background: #e7f5ff; border-left: 4px solid #2271b1;'>";
                    echo "<p><strong>🔄 Preparing Update:</strong><br>";
                    echo "<strong>Table:</strong> $table<br>";
                    echo "<strong>Column:</strong> $column<br>";
                    echo "<strong>Row ID:</strong> {$row->$primary_key}<br>";
                    echo "<strong>Old value (first 300 chars):</strong><br>";
                    echo "<code style='background: #fff3cd; padding: 5px; display: block; word-break: break-all; margin: 5px 0;'>" . esc_html(substr($row->$column, 0, 300)) . (strlen($row->$column) > 300 ? '...' : '') . "</code>";
                    echo "<strong>New value (first 300 chars):</strong><br>";
                    echo "<code style='background: #d1f0d1; padding: 5px; display: block; word-break: break-all; margin: 5px 0;'>" . esc_html(substr($new_value, 0, 300)) . (strlen($new_value) > 300 ? '...' : '') . "</code>";
                    echo "</p></div>";
                    
                    $update_result = $wpdb->update(
                        $table,
                        array($column => $new_value),
                        array($primary_key => $row->$primary_key)
                    );
                    
                    if($update_result !== false) {
                        echo "<div class='notice notice-success' style='border-left: 4px solid #00a32a;'><p><strong>✅ Successfully updated $table.$column for row {$row->$primary_key}</strong><br>";
                        echo "Rows affected: " . $update_result . "</p></div>";
                        
                        // Verify the update by reading back
                        $verify_query = $wpdb->prepare("SELECT `$column` FROM $table WHERE `$primary_key` = %d", $row->$primary_key);
                        $verify_value = $wpdb->get_var($verify_query);
                        if ($verify_value === $new_value) {
                            echo "<div class='notice notice-success'><p>✅ <strong>Verified:</strong> Database value matches expected value</p></div>";
                        } else {
                            echo "<div class='notice notice-warning'><p>⚠️ <strong>Warning:</strong> Verified value doesn't match expected value<br>";
                            echo "Expected (first 200 chars): " . esc_html(substr($new_value, 0, 200)) . "<br>";
                            echo "Got (first 200 chars): " . esc_html(substr($verify_value, 0, 200)) . "</p></div>";
                        }
                    } else {
                        echo "<div class='notice notice-error' style='border-left: 4px solid #d63638;'><p><strong>❌ Error updating $table.$column for row {$row->$primary_key}</strong><br>";
                        echo "MySQL Error: " . esc_html($wpdb->last_error) . "<br>";
                        echo "Old value length: " . strlen($row->$column) . " bytes<br>";
                        echo "New value length: " . strlen($new_value) . " bytes</p></div>";
                    }
                }
            }
        }
    }
    
    // After successful database migration, attempt to clean up the blogs.dir site folder if empty
    if (!$dry_run) {
        $blogsdir_site_folder = $_SERVER['DOCUMENT_ROOT'] . "/wp-content/blogs.dir/" . $site_id;
        if (is_dir($blogsdir_site_folder)) {
            // Recursively remove empty directories
            utm_remove_empty_dirs($blogsdir_site_folder);
            
            // Check if the site folder itself is now empty
            $remaining_items = array_diff(scandir($blogsdir_site_folder), array('.', '..'));
            if (empty($remaining_items)) {
                if (rmdir($blogsdir_site_folder)) {
                    echo "<div class='notice notice-success'><p><strong>✅ Cleaned up empty blogs.dir folder for site $site_id</strong></p></div>";
                    echo "<div class='notice notice-info'><p>The blogs.dir/$site_id folder has been removed. Once all sites are migrated, the parent blogs.dir folder can be safely deleted.</p></div>";
                }
            }
        }
    }

    restore_current_blog();
}

/**
 * Media Library Debug Page
 * Diagnoses why media files appear as empty boxes
 */
function utm_media_debug() {
    global $wpdb;
    $current_blog_id = get_current_blog_id();
    
    echo '<div class="wrap">';
    echo '<h1>🔍 Media Library Diagnostics</h1>';
    echo '<p>This tool helps diagnose why media files appear as empty boxes in the Media Library.</p>';
    
    // Get upload directory info
    $upload_dir = wp_upload_dir();
    
    // ============================================================================
    // SYSTEM CONFIGURATION
    // ============================================================================
    echo '<div style="background: #e7f3ff; border: 2px solid #0073aa; padding: 20px; margin: 20px 0; border-radius: 5px;">';
    echo '<h2 style="margin-top: 0;">📋 System Configuration</h2>';
    echo '<table class="widefat" style="background: white;">';
    echo '<tr><th style="width: 250px;">Site ID</th><td>' . $current_blog_id . '</td></tr>';
    echo '<tr><th>Site URL</th><td>' . esc_url(get_site_url()) . '</td></tr>';
    echo '<tr><th>Upload Base Directory</th><td><code>' . esc_html($upload_dir['basedir']) . '</code></td></tr>';
    echo '<tr><th>Upload Base URL</th><td><code>' . esc_html($upload_dir['baseurl']) . '</code></td></tr>';
    echo '<tr><th>Current Month Path</th><td><code>' . esc_html($upload_dir['path']) . '</code></td></tr>';
    echo '<tr><th>Current Month URL</th><td><code>' . esc_html($upload_dir['url']) . '</code></td></tr>';
    
    // Check if directory exists and is readable
    $basedir_exists = is_dir($upload_dir['basedir']);
    $basedir_readable = is_readable($upload_dir['basedir']);
    $basedir_writable = is_writable($upload_dir['basedir']);
    
    echo '<tr><th>Directory Exists</th><td>' . ($basedir_exists ? '✅ Yes' : '❌ No') . '</td></tr>';
    echo '<tr><th>Directory Readable</th><td>' . ($basedir_readable ? '✅ Yes' : '❌ No') . '</td></tr>';
    echo '<tr><th>Directory Writable</th><td>' . ($basedir_writable ? '✅ Yes' : '❌ No') . '</td></tr>';
    echo '</table>';
    echo '</div>';
    
    // ============================================================================
    // SAMPLE ATTACHMENTS ANALYSIS
    // ============================================================================
    echo '<h2>🖼️ Sample Attachments Analysis (Last 10 images)</h2>';
    
    $posts_table = $wpdb->prefix . 'posts';
    $postmeta_table = $wpdb->prefix . 'postmeta';
    
    // Get last 10 image attachments
    $attachments = $wpdb->get_results(
        "SELECT ID, post_title, post_date, guid 
        FROM $posts_table 
        WHERE post_type = 'attachment' 
        AND post_mime_type LIKE 'image/%'
        ORDER BY post_date DESC 
        LIMIT 10"
    );
    
    if (empty($attachments)) {
        echo '<div class="notice notice-warning"><p>No image attachments found in database.</p></div>';
    } else {
        echo '<div style="background: #f8f9fa; padding: 15px; margin: 10px 0;">';
        
        foreach ($attachments as $attachment) {
            $attachment_id = $attachment->ID;
            
            // Get attachment metadata
            $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
            $metadata = wp_get_attachment_metadata($attachment_id);
            
            // Construct expected file path
            $expected_file_path = $upload_dir['basedir'] . '/' . $attached_file;
            
            // Get the attachment URL from WordPress
            $wp_attachment_url = wp_get_attachment_url($attachment_id);
            
            // Check if file exists in NEW location
            $file_exists = file_exists($expected_file_path);
            
            // Also check OLD location (blogs.dir) in case file migration failed
            $old_blogsdir_path = $_SERVER['DOCUMENT_ROOT'] . '/wp-content/blogs.dir/' . $current_blog_id . '/files/' . $attached_file;
            $file_exists_in_old_location = file_exists($old_blogsdir_path);
            
            // Check even older location without year/month structure (direct in files folder)
            $attached_file_basename = basename($attached_file);
            $old_blogsdir_path_direct = $_SERVER['DOCUMENT_ROOT'] . '/wp-content/blogs.dir/' . $current_blog_id . '/files/' . $attached_file_basename;
            $file_exists_in_old_location_direct = ($old_blogsdir_path_direct !== $old_blogsdir_path) && file_exists($old_blogsdir_path_direct);
            
            echo '<div style="background: white; border: 1px solid #ddd; padding: 15px; margin: 10px 0; border-radius: 5px;">';
            echo '<h3 style="margin-top: 0;">Attachment ID: ' . $attachment_id . ' - ' . esc_html($attachment->post_title) . '</h3>';
            
            echo '<table class="widefat">';
            echo '<tr><th style="width: 250px;">Upload Date</th><td>' . esc_html($attachment->post_date) . '</td></tr>';
            echo '<tr><th>GUID (Database URL)</th><td><code style="word-break: break-all;">' . esc_html($attachment->guid) . '</code></td></tr>';
            echo '<tr><th>WP Attachment URL</th><td><code style="word-break: break-all;">' . esc_html($wp_attachment_url) . '</code></td></tr>';
            echo '<tr><th>_wp_attached_file</th><td><code>' . esc_html($attached_file) . '</code></td></tr>';
            echo '<tr><th>Expected File Path (NEW)</th><td><code style="word-break: break-all;">' . esc_html($expected_file_path) . '</code></td></tr>';
            echo '<tr><th>File Exists in NEW Location</th><td><strong>' . ($file_exists ? '✅ YES' : '❌ NO - FILE MISSING!') . '</strong></td></tr>';
            
            // Show OLD location check
            echo '<tr style="background: #f0f0f1;"><th>Old blogs.dir Path</th><td><code style="word-break: break-all;">' . esc_html($old_blogsdir_path) . '</code></td></tr>';
            echo '<tr style="background: ' . ($file_exists_in_old_location ? '#fff3cd' : '#f0f0f1') . ';"><th>File Exists in OLD Location</th><td><strong>' . ($file_exists_in_old_location ? '⚠️ YES - MIGRATION NOT COMPLETED!' : '❌ NO') . '</strong></td></tr>';
            
            // Show direct old location check (no year/month)
            if ($file_exists_in_old_location_direct) {
                echo '<tr style="background: #fff3cd;"><th>Old Direct Path</th><td><code style="word-break: break-all;">' . esc_html($old_blogsdir_path_direct) . '</code></td></tr>';
                echo '<tr style="background: #fff3cd;"><th>File in Old Direct Location</th><td><strong>⚠️ YES - File found without year/month structure!</strong></td></tr>';
            }
            
            // Check for legacy paths
            $has_legacy_path = (strpos($attachment->guid, '/files/') !== false || strpos($attachment->guid, 'blogs.dir') !== false);
            $has_legacy_attached_file = (strpos($attached_file, 'blogs.dir') !== false || strpos($attached_file, '/files/') !== false);
            
            if ($has_legacy_path || $has_legacy_attached_file) {
                echo '<tr style="background: #fff3cd;"><th>⚠️ Legacy Path Detected</th><td>';
                if ($has_legacy_path) echo 'GUID contains legacy path (/files/ or blogs.dir)<br>';
                if ($has_legacy_attached_file) echo '_wp_attached_file contains legacy path';
                echo '</td></tr>';
            }
            
            // Show metadata if available
            if ($metadata) {
                echo '<tr><th>Metadata Available</th><td>✅ Yes';
                if (isset($metadata['width']) && isset($metadata['height'])) {
                    echo ' (' . $metadata['width'] . 'x' . $metadata['height'] . ')';
                }
                echo '</td></tr>';
                
                // Check thumbnail
                if (isset($metadata['file'])) {
                    echo '<tr><th>Metadata File Path</th><td><code>' . esc_html($metadata['file']) . '</code></td></tr>';
                }
                
                // Check if thumbnail sizes exist
                if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
                    echo '<tr><th>Thumbnail Sizes</th><td>' . count($metadata['sizes']) . ' sizes generated</td></tr>';
                }
            } else {
                echo '<tr style="background: #fff3cd;"><th>⚠️ Metadata Missing</th><td>No metadata found - thumbnails may not work</td></tr>';
            }
            
            // Test if URL is accessible
            if ($file_exists) {
                $file_size = filesize($expected_file_path);
                $file_size_mb = number_format($file_size / 1024 / 1024, 2);
                echo '<tr><th>File Size</th><td>' . $file_size_mb . ' MB (' . number_format($file_size) . ' bytes)</td></tr>';
            }
            
            // Issue Summary
            $status_color = '#d4edda'; // green by default
            $status_message = '✅ OK - File exists and paths look correct';
            
            if (!$file_exists && $file_exists_in_old_location) {
                $status_color = '#fff3cd'; // yellow warning
                $status_message = '⚠️ FILE MIGRATION INCOMPLETE - File still in old blogs.dir location, not copied to new location!';
            } elseif (!$file_exists && $file_exists_in_old_location_direct) {
                $status_color = '#fff3cd'; // yellow warning
                $status_message = '⚠️ FILE MIGRATION INCOMPLETE - File in old location without year/month structure!';
            } elseif (!$file_exists && !$file_exists_in_old_location) {
                $status_color = '#f8d7da'; // red error
                $status_message = '❌ FILE COMPLETELY MISSING - Not in old or new location! May need restore from backup.';
            } elseif ($file_exists && ($has_legacy_path || $has_legacy_attached_file)) {
                $status_color = '#fff3cd'; // yellow warning
                $status_message = '⚠️ FILE EXISTS but database has legacy paths - run database migration';
            }
            
            echo '<tr style="background: ' . $status_color . ';"><th><strong>Status</strong></th><td><strong>';
            echo $status_message;
            echo '</strong></td></tr>';
            
            echo '</table>';
            echo '</div>';
        }
        
        echo '</div>';
    }
    
    // ============================================================================
    // STATISTICS & RECOMMENDATIONS
    // ============================================================================
    echo '<h2>📊 Database Statistics</h2>';
    echo '<div style="background: #f8f9fa; padding: 15px; margin: 10px 0;">';
    
    $total_attachments = $wpdb->get_var("SELECT COUNT(*) FROM $posts_table WHERE post_type = 'attachment'");
    $image_attachments = $wpdb->get_var("SELECT COUNT(*) FROM $posts_table WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'");
    $legacy_guid = $wpdb->get_var("SELECT COUNT(*) FROM $posts_table WHERE post_type = 'attachment' AND (guid LIKE '%/files/%' OR guid LIKE '%blogs.dir%')");
    $legacy_attached_file = $wpdb->get_var("SELECT COUNT(*) FROM $postmeta_table WHERE meta_key = '_wp_attached_file' AND (meta_value LIKE '%blogs.dir%' OR meta_value LIKE '%/files/%')");
    $missing_metadata = $wpdb->get_var("SELECT COUNT(p.ID) FROM $posts_table p LEFT JOIN $postmeta_table pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attachment_metadata' WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%' AND pm.meta_id IS NULL");
    
    echo '<table class="widefat" style="background: white;">';
    echo '<tr><th style="width: 300px;">Total Attachments</th><td>' . $total_attachments . '</td></tr>';
    echo '<tr><th>Image Attachments</th><td>' . $image_attachments . '</td></tr>';
    echo '<tr' . ($legacy_guid > 0 ? ' style="background: #fff3cd;"' : '') . '><th>Attachments with Legacy GUID</th><td>' . $legacy_guid . ($legacy_guid > 0 ? ' ⚠️' : ' ✅') . '</td></tr>';
    echo '<tr' . ($legacy_attached_file > 0 ? ' style="background: #fff3cd;"' : '') . '><th>Attachments with Legacy File Path</th><td>' . $legacy_attached_file . ($legacy_attached_file > 0 ? ' ⚠️' : ' ✅') . '</td></tr>';
    echo '<tr' . ($missing_metadata > 0 ? ' style="background: #fff3cd;"' : '') . '><th>Images Missing Metadata</th><td>' . $missing_metadata . ($missing_metadata > 0 ? ' ⚠️' : ' ✅') . '</td></tr>';
    echo '</table>';
    echo '</div>';
    
    // ============================================================================
    // RECOMMENDATIONS
    // ============================================================================
    echo '<h2>💡 Recommendations</h2>';
    echo '<div style="background: #fff3cd; border: 2px solid #ffc107; padding: 20px; margin: 20px 0; border-radius: 5px;">';
    
    $issues = array();
    
    if (!$basedir_exists) {
        $issues[] = '❌ <strong>Upload directory does not exist!</strong> Create directory: <code>' . esc_html($upload_dir['basedir']) . '</code>';
    } elseif (!$basedir_readable) {
        $issues[] = '❌ <strong>Upload directory is not readable!</strong> Check permissions on: <code>' . esc_html($upload_dir['basedir']) . '</code>';
    } elseif (!$basedir_writable) {
        $issues[] = '⚠️ <strong>Upload directory is not writable!</strong> You won\'t be able to upload new files. Check permissions.';
    }
    
    if ($legacy_guid > 0 || $legacy_attached_file > 0) {
        $issues[] = '⚠️ <strong>Legacy paths detected in database!</strong> Go to <a href="' . admin_url('tools.php?page=fix-media') . '">Tools → Fix Upload Path</a> to migrate database paths.';
    }
    
    if ($missing_metadata > 0) {
        $issues[] = '⚠️ <strong>' . $missing_metadata . ' images missing metadata!</strong> This can cause thumbnails to not display. Consider regenerating thumbnails with a plugin like "Regenerate Thumbnails".';
    }
    
    // Check if files are actually missing and where they are
    echo '<p><strong>Checking file locations (scanning last 50 images)...</strong></p>';
    $sample_check = $wpdb->get_results(
        "SELECT ID FROM $posts_table 
        WHERE post_type = 'attachment' 
        AND post_mime_type LIKE 'image/%'
        ORDER BY post_date DESC 
        LIMIT 50"
    );
    
    $missing_count = 0;
    $in_old_location_count = 0;
    $completely_missing_count = 0;
    
    foreach ($sample_check as $att) {
        $attached_file = get_post_meta($att->ID, '_wp_attached_file', true);
        $new_path = $upload_dir['basedir'] . '/' . $attached_file;
        $old_path = $_SERVER['DOCUMENT_ROOT'] . '/wp-content/blogs.dir/' . $current_blog_id . '/files/' . $attached_file;
        
        if (!file_exists($new_path)) {
            $missing_count++;
            if (file_exists($old_path)) {
                $in_old_location_count++;
            } else {
                $completely_missing_count++;
            }
        }
    }
    
    if ($missing_count > 0) {
        $percentage = round(($missing_count / count($sample_check)) * 100);
        
        if ($in_old_location_count > 0) {
            $issues[] = '⚠️ <strong>FILE MIGRATION INCOMPLETE!</strong> Out of last 50 images checked, ' . $in_old_location_count . ' files are still in old blogs.dir location and not copied to new location. Go to <a href="' . admin_url('tools.php?page=fix-media') . '">Tools → Fix Upload Path</a> and run <strong>File Migration</strong>.';
        }
        
        if ($completely_missing_count > 0) {
            $issues[] = '❌ <strong>FILES COMPLETELY MISSING!</strong> ' . $completely_missing_count . ' files do not exist in either old or new location. You may need to restore files from backup.';
        }
    }
    
    if (empty($issues)) {
        echo '<p style="color: #155724; font-size: 16px;">✅ <strong>Everything looks good!</strong> No issues detected.</p>';
    } else {
        echo '<ol>';
        foreach ($issues as $issue) {
            echo '<li>' . $issue . '</li>';
        }
        echo '</ol>';
    }
    
    echo '</div>';
    
    // Quick actions
    echo '<h2>🔧 Quick Actions</h2>';
    echo '<div style="background: #f8f9fa; padding: 15px; margin: 10px 0;">';
    echo '<p><a href="' . admin_url('tools.php?page=fix-media') . '" class="button button-primary">Go to Migration Tool</a> ';
    echo '<a href="' . admin_url('upload.php') . '" class="button button-secondary">Back to Media Library</a> ';
    echo '<a href="?page=media-debug" class="button button-secondary">Refresh Diagnostics</a></p>';
    echo '</div>';
    
    echo '</div>'; // Close wrap
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
