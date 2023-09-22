<?php
// Add a custom rewrite rule
function custom_rewrite_rules($rules)
{
	$new_rules = array(
		'migrate-upload/?$' => 'index.php?migrate_upload=true'
	);
	return $new_rules + $rules;
}
add_filter('rewrite_rules_array', 'custom_rewrite_rules');

// Register the custom query var
function custom_query_vars($query_vars)
{
	$query_vars[] = 'migrate_upload';
	return $query_vars;
}
add_filter('query_vars', 'custom_query_vars');

// Display the content if the query var is set
function display_migrate_upload()
{
	global $wp_query;
	if (isset($wp_query->query_vars['migrate_upload'])) {
		// Put the content you want to display here
		echo "<h1>List of Sites</h1>";
		echo list_all_sites_shortcode();
		exit;
	}
}
add_action('template_redirect', 'display_migrate_upload');

function list_all_sites_shortcode()
{
	global $wpdb;
	run_migration();
	$output = "<table>";
	$output .= "<thead><tr><th>Site ID</th><th>Upload Path</th><th>ms_files_rewriting</th><th>table_prefix</th><th>home</th></tr></thead>";
	$output .= "<tbody>";

	$sites = get_sites(array('number' => 1000));
	foreach ($sites as $site) {
		$blog_id = $site->blog_id;

		switch_to_blog($blog_id);

		$upload_path = get_option('upload_path');
		$ms_files_rewriting = get_site_option('ms_files_rewriting');
		$table_prefix = $wpdb->get_blog_prefix();
		// link to site homepage
		$home_url = get_blog_option($blog_id, 'home');

		// if site is using Divi themes
		if (!get_blog_option($blog_id, 'et_divi')) {
			$divi_logo_url = "Not using Divi";
		} else {
			$divi_logo_url = get_blog_option($blog_id, 'et_divi');
			if (isset($divi_logo_url['divi_logo'])) {
				if (is_array($divi_logo_url['divi_logo'])) {
					$divi_logo_url = 'Array Data';
				} else {
					$divi_logo_url = $divi_logo_url['divi_logo'];
				}
			} else {
				$divi_logo_url = '-';
			}
		}

		restore_current_blog();

		$output .= "<tr>";
		$output .= "<td>{$blog_id}</td>";
		$output .= "<td>{$upload_path}</td>";
		$output .= "<td>{$ms_files_rewriting}</td>";
		$output .= "<td>{$table_prefix}</td>";
		$output .= "<td><a href='?page=migrate-upload&blog_id={$blog_id}'>Migrate</a></td>";
		$output .= "<td><a href='{$home_url}'>{$home_url}</a></td>";
		$output .= "<td>" . (is_array($divi_logo_url) ? 'Array Data' : $divi_logo_url) . "</td>";
		$output .= "</tr>";
	}

	$output .= "</tbody></table>";

	return $output;
}

function run_migration($blogid = 0){
	global $wpdb;
	if (isset($_GET['blog_id']) || $blogid != 0) {
		// Get current site ID
		$current_site_id = get_current_blog_id();
		$current_site_id = $_GET['blog_id'];
		if($blogid != 0) $current_site_id = $blogid;

		if($current_site_id == 1){
			return;
		}

		// Get table prefix for the current site
		$table_prefix = $wpdb->get_blog_prefix($current_site_id);

		// Check if 'ms_files_rewriting' setting exists, and update it if needed
		$ms_files_setting = get_site_option('ms_files_rewriting');
		if ($ms_files_setting === false) {
			add_site_option('ms_files_rewriting', '0');
		} else if ($ms_files_setting == '1') {
			update_site_option('ms_files_rewriting', '0');
		}

		// Create new uploads folder if not exists
		$upload_dir = wp_upload_dir();
		$new_folder = $upload_dir['basedir'] . '/sites';
		if (!file_exists($new_folder)) {
			mkdir($new_folder, 0755, true);
		}

		// Move files from old to new location
		$old_folder = ABSPATH . 'wp-content/blogs.dir/' . $current_site_id . '/files';
		$new_folder = ABSPATH . 'wp-content/uploads/sites/' . $current_site_id;
		if (!file_exists($new_folder)) {
			mkdir($new_folder, 0755, true);
		}

		// Move files from old folder to new folder
		if ($handle = opendir($old_folder)) {
			while (false !== ($old_file = readdir($handle))) {
				if ($old_file !== '.' && $old_file !== '..') {
					$new_file = $new_folder . '/' . $old_file;
					rename($old_folder . '/' . $old_file, $new_file);
				}
			}
			closedir($handle);
		}

		// Update upload_path for the current site
		update_blog_option($current_site_id, 'upload_path', '');

		// Update file URLs in the database
		$home_url = get_blog_option($current_site_id, 'home');
		global $wpdb;
		// Add 'postmeta' to the tables array
		$tables = array(
			$table_prefix . 'posts',
			$table_prefix . 'options',
			$table_prefix . 'postmeta'
		);

		foreach ($tables as $table) {
			if ($table == $table_prefix . 'posts') {
				$wpdb->query($wpdb->prepare("UPDATE $table SET guid = REPLACE(guid, %s, %s)", $home_url . '/files/', $home_url . '/wp-content/uploads/sites/' . $current_site_id . '/'));
				// replace the content
				$wpdb->query($wpdb->prepare("UPDATE $table SET post_content = REPLACE(post_content, %s, %s)", $home_url . '/files/', $home_url . '/wp-content/uploads/sites/' . $current_site_id . '/'));

				// find string in post_content where '%Pesisir.png' and display it
				$results = $wpdb->get_results("SELECT ID, post_content FROM $table WHERE post_content LIKE '%files/%'", ARRAY_A);
				if($results){
					// var_dump($results);
				}
			}

			// For the options table
			if ($table == $table_prefix . 'options') {
				$results = $wpdb->get_results("SELECT option_id, option_value FROM $table WHERE option_name = 'upload_path' OR option_name = 'fileupload_url' OR option_name = 'et_divi'", ARRAY_A);

				foreach ($results as $row) {
					$unserialized = maybe_unserialize($row['option_value']);

					recursive_str_replace($unserialized, $home_url, $current_site_id);

					$new_value = maybe_serialize($unserialized);

					$wpdb->update($table, array('option_value' => $new_value), array('option_id' => $row['option_id']));
				}
			}

			if ($table == $table_prefix . 'postmeta') {
				$results = $wpdb->get_results("SELECT meta_id, meta_value FROM $table WHERE meta_value LIKE '%" . $home_url . "/files/%'", ARRAY_A);

				foreach ($results as $row) {
					$unserialized = maybe_unserialize($row['meta_value']);

					if (is_array($unserialized) || is_object($unserialized)) {
						array_walk_recursive($unserialized, function (&$value) use ($home_url, $current_site_id) {
							$value = str_replace($home_url . '/files/', $home_url . '/wp-content/uploads/sites/' . $current_site_id . '/', $value);
						});

						$new_value = maybe_serialize($unserialized);
					} else {
						$new_value = str_replace($home_url . '/files/', $home_url . '/wp-content/uploads/sites/' . $current_site_id . '/', $row['meta_value']);
					}

					$wpdb->update($table, array('meta_value' => $new_value), array('meta_id' => $row['meta_id']));
				}
			}
		}
	}
}

function recursive_str_replace(&$item, $home_url, $current_site_id)
{
	if (is_array($item) || is_object($item)) {
		foreach ($item as &$value) {
			recursive_str_replace($value, $home_url, $current_site_id);
		}
	} else {
		$item = str_replace($home_url . '/files/', $home_url . '/wp-content/uploads/sites/' . $current_site_id . '/', $item);
	}
}