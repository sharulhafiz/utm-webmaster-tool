<?php
function utm_add_submenu_page()
{
	add_management_page('Fix Upload Path', 'Fix Upload Path', 'manage_options', 'fix-media', 'utm_fixuploadpath');
}
add_action('admin_menu', 'utm_add_submenu_page');

function utm_fixuploadpath(){
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
	$meta_key = 'ms_files_rewriting';
	$meta_value = '0';
	if (isset($_GET['search'])) $search = $_GET['search'];
	if (isset($_GET['replace'])) $replace = $_GET['replace'];
	$upload_path = $wpdb->get_var("SELECT option_value FROM $option_table WHERE option_name = 'upload_path'");
	$output = array();

	// Starting the HTML wrapper for the admin page
	echo '<div class="wrap">';
	echo '<h1>Fix Upload Path</h1><p>This tool is for fixing multiple issues path for this site</p>';
	// Menus:
	echo '<ul>';
	echo '<li><a href="?page=fix-media&delete_option=upload_path">Delete upload_path option</a></li>';
	echo '<li><a href="?page=fix-media&delete_option=upload_url_path">Delete upload_url_path option</a></li>';
	echo '<li><a href="?page=fix-media&delete_option=ms_files_rewriting">Delete ms_files_rewriting option</a></li>';
	echo '<li><a href="?page=fix-media&delete_option=upload_path">Delete upload_path option</a></li>';
	echo '<ul>';

	echo '<form method="get" action="">';
	echo '<input type="hidden" name="page" value="fix-media">';
	echo '<label for="search">Search:</label>';
	echo '<input type="text" id="search" name="search" value="blogs.dir">';
	echo '<label for="replace">Replace:</label>';
	echo '<input type="text" id="replace" name="replace" value="uploads/sites">';
	echo '<input type="submit" value="Fix blogs.dir to uploads/sites">';
	echo '</form>';

	/*
	 * Turn off ms-files.php
	 *
	 */
	if (isset($_GET['msfiles'])){
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
	 * File Migration
	 * Move files from blogs.dir to uploads/sites
	 */
	if (isset($_GET['migration'])){
		// get blogs.dir path
		$blogsdir_path = $_SERVER['DOCUMENT_ROOT'] . "wp-content/blogs.dir/". $site_id . "/files";

		if ($site_id == 1){
			echo "<br>This is the main site. Attempting to move files to correct location";
			echo "<br>Blogs.dir path: " . $blogsdir_path;
			$uploaddir_path = $_SERVER['DOCUMENT_ROOT'] . "wp-content/uploads";
			echo "<br>Upload dir path: " . $uploaddir_path . "<br>";
		}

		if ($site_id != 1 && $site_id != 0 && $site_id != ""){
			echo "<br>This is the subsite. Attempting to move files to correct location";
			$uploaddir_path = $_SERVER['DOCUMENT_ROOT'] . "wp-content/uploads/sites/" . $site_id;
		}

		echo "<br>Blogs.dir path: " . $blogsdir_path;
		echo "<br>Upload dir path: " . $uploaddir_path . "<br>";
		merge_directories($blogsdir_path, $uploaddir_path, false);
		return;
	}

	/*
	 * Delete option from option table
	 *
	 */
	if (isset($_GET['delete_option'])){
		$wpdb->delete($option_table, array('option_name' => $_GET['delete_option']));
		echo "Deleted option: " . $_GET['delete_option'];
	}

	/*
	 * Fix Options Table
	 *
	 */
	if (isset($_GET['fixoptiontable'])){
		// Get options from the options table
		$sql = "SELECT * FROM $option_table WHERE option_value LIKE '%s' AND (option_name NOT LIKE 'wphb%' AND option_name NOT LIKE 'wpts%' AND option_name NOT LIKE 'astra%' AND option_name NOT LIKE 'elementor_log' AND option_name NOT LIKE 'fs_accounts' AND option_name NOT LIKE '_transient_%' AND option_name NOT LIKE 'wdfc%') LIMIT 10";
		$results = $wpdb->get_results($sql, ARRAY_A);
		if ($results) {
			echo "<p>Fixing option table...</p>";
			foreach ($results as $row) {
				// fix blogs.dir
				try {
					echo "Fixing row {$row['option_name']} | <a href='?page=fix-media&delete_option={$row['option_name']}'>Row error? Delete option {$row['option_name']}?</a><br>";
					$updated_value = utm_recursive_str_replace($search, $replace, $row['option_value']);

					// If there's a problem with the option, throw an exception
					if ($updated_value === false) {
						throw new Exception('Problem with option: ' . $row['option_name']);
					}
				} catch (Exception $e) {
					echo 'Caught exception: ',  $e->getMessage(), "\n";
				}
				$update_status = $wpdb->update($option_table, array('option_value' => $updated_value), array('option_id' => $row['option_id']));
				if($update_status !== false){
					// append to output
					$output[] = array(
						'options_table' => $option_table,
						'autoload' => $row['autoload'],
						'slug' => $slug,
						'update_status' => ($update_status !== false ? 'Success' : 'Failure'),
						'option_id' => $row['option_id'],
						'option_name' => $row['option_name'],
						'option_value' => $row['option_value'],
						'updated_value' => $updated_value,
					);
				}
			}
		}
		echo "<pre>" . print_r($output, true) . "</pre>";
	}

	/*
	 * Fix Post Table
	 *
	 */
	if (isset($_GET['fixposttable'])){
		// Posts Table
		$sql = "SELECT * FROM $posts_table WHERE `guid` LIKE '%s' OR `post_content` LIKE '%s' LIMIT 10";
		$results = $wpdb->get_results($wpdb->prepare($sql, '%' . $wpdb->esc_like($search) . '%', '%' . $wpdb->esc_like($search) . '%'), ARRAY_A);
		if ($results) {
			$output[] = array(
				'posts_table' => $posts_table,
				'sql' => $sql,
				'search' => $search,
				'replace' => $replace,
				// 'results' => $results,
			);
			foreach ($results as $row) {
				// if post type is attachment
				if ($row['post_type'] == 'attachment') {
					$current_guid = $row['guid'];
					$updated_guid = str_replace($search, $replace, $current_guid);
					$updated_guid = str_replace("/sites/$current_blog_id/sites/$current_blog_id/", "/sites/$current_blog_id/", $updated_guid); // remove extra nested folders
					$updated_guid = str_replace('http://', 'https://', $updated_guid); // replace http with https

					if (strlen($updated_guid) > 255) {
						$error = "Error: The new guid value is too long.";
						# truncate guid + random string
						$extension = pathinfo($updated_guid, PATHINFO_EXTENSION);
						$filename = pathinfo($updated_guid, PATHINFO_FILENAME);

						// Truncate the filename to fit within the limit, leaving room for the extension and a dash
						$filename = substr($filename, 0, 255 - 50 - strlen($extension) - 1);

						// Add a random string to the end of the filename
						$filename .= '-' . substr(md5(rand()), 0, 5);

						// Reassemble the filename with the extension
						$updated_guid = $filename . '.' . $extension;

						// Rename the file
						rename($current_guid, $updated_guid);
					} elseif (filter_var($updated_guid, FILTER_VALIDATE_URL) === false) {
						$error = "Error: The new guid value is not a valid URL.";
					}

					# update database
					$update_status = $wpdb->update(
						$posts_table,
						array('guid' => $updated_guid),
						array('ID' => $row['ID'])
					);

					$updated_content = str_replace($search, $replace, $row['post_content']);
					// Handle extra nested folders
					$updated_content = str_replace("/sites/$current_blog_id/sites/$current_blog_id/", "/sites/$current_blog_id/", $updated_content);
					$updated_content_status = $wpdb->update(
						$posts_table,
						array('post_content' => $updated_content),
						array('ID' => $row['ID'])
					);
					// Check if the updated URL is broken
					$headers = @get_headers($updated_guid);
					if ($headers && strpos($headers[0], '200') === false) {  // The image URL is broken
						// get month and year from the url
						if (preg_match('#/(\d{4})/(\d{2})/#', $updated_guid, $matches)) {
							$year = $matches[1];
							$month = $matches[2];
						}
						// The image URL is broken, try to move it from the wrong location to the correct one
						$correct_path = $_SERVER['DOCUMENT_ROOT'] . '/wp-content/uploads/sites/' . $current_blog_id . '/files/' . $year . '/' . $month . '/' . basename($current_guid);

						if (!file_exists($correct_path)) {
							// $wrong_local_path = parse_url($row['guid'], PHP_URL_PATH);
							// if (file_exists($wrong_local_path)) {
							// 	$move_status = rename($wrong_local_path, $correct_path);
							// 	$file_exist = 'Yes';
							// }

							// get site full upload path
							$upload_dir = wp_upload_dir();
							$admin_notice = "<p>Upload dir: " . print_r($upload_dir, true) . "</p>";

							// replace "uploads" with "blogs.dir"
							$old_path = str_replace('uploads/sites/'. $current_blog_id, 'blogs.dir/'. $current_blog_id . '/files', $upload_dir['basedir']);
							$new_path = '/var/www/html/wp-content/uploads/sites/' . $current_blog_id;

							// echo "<p>Old path: $old_path</p>";
							// echo "<p>New path: $new_path</p>";

							// if source folder exist
							if (file_exists($old_path)) {
								$admin_notice .= '<p>Source folder exist</p>';
								$admin_notice .= '<p>Source folder: ' . $old_path . '</p>';
								$admin_notice .= '<p>Destination folder: ' . $new_path . '</p>';
								// merge directories
								merge_directories($old_path, $new_path);
								$move_status = 'Yes';
							}
						}
					} else {
						// The image URL is not broken, do nothing
						$file_exist = 'Yes';
						// update database
						$update_status = $wpdb->update(
							$posts_table,
							array('guid' => $updated_guid, 'post_content' => $updated_content),
							array('ID' => $row['ID']),
						);
					}

					if (!isset($error)) {
						$error = $wpdb->last_error;
					}

					// append to output
					$output[] = array(
						'post_type' => $row['post_type'],
						'ID' => $row['ID'],
						'current_guid' => $row['guid'],
						'updated_guid' => $updated_guid,
						'correct_path' => $correct_path,
						'file_exist' => $file_exist ?? 'No',
						'move_status' => $move_status ?? 'No',
						'old_path' => $old_path ?? 'No',
						'new_path' => $new_path ?? 'No',
						'update_status' => ($update_status !== false ? 'Success' : "Error updating post {$row['ID']}: {$error}"),
						'post_content' => $row['post_content'],
						'updated_content_status' => $updated_content_status !== false ? 'Success' : 'Failure',
					);
				} else {
					$updated_content = str_replace($search, $replace, $row['post_content']);
					// Handle extra nested folders
					$updated_content = str_replace("/sites/$current_blog_id/sites/$current_blog_id/", "/sites/$current_blog_id/", $updated_content);

					$updated_guid = str_replace($search, $replace, $row['guid']);
					// update database
					$update_status = $wpdb->update(
						$posts_table,
						array('post_content' => $updated_content, 'guid' => $updated_guid),
						array('ID' => $row['ID'])
					);

					// append to output
					$output[] = array(
						'update_status' => ($update_status !== false ? 'Success' : 'Failure'),
						'post_type' => $row['post_type'],
						// 'post_content' => $row['post_content'],
						'updated_content' => $updated_content,
						'guid' => $row['guid'],
					);
				}
			}
		}
		if($output) echo "<meta http-equiv='refresh' content='5'><pre>" . print_r($output, true) . "</pre>";
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

	// Concluding the HTML wrapper
	echo '</div>';  // Closing wrap div
}

function merge_directories($source, $destination, $safe_delete = false){
	echo "Source: $source<br>";
	echo "Destination: $destination<br>";
	
	if (!is_dir($source)) {
        echo "Source directory does not exist: $source<br>";
        return;
    }

    $dir = opendir($source);
    if (!$dir) {
        echo "Failed to open source directory: $source<br>";
        return;
    }

    if (!is_dir($destination)) {
        mkdir($destination, 0755, true);
        echo "Created destination directory: $destination<br>";
    }

	while (($file = readdir($dir)) !== false) {
        if ($file == '.' || $file == '..') {
            continue;
        }

		$srcFile = realpath($source . '/' . $file);

		// Ensure the resolved path is within the source path
		if (strpos($srcFile, realpath($source)) !== 0) {
			echo "Skipping file outside of source path: $srcFile<br>";
			continue;
		}

		// Process the file or directory
		echo "Processing: $srcFile<br>";

        $srcFile = $source . '/' . $file;
        $destFile = $destination . '/' . $file;

        if (is_dir($srcFile)) {
            merge_directories($srcFile, $destFile, $safe_delete);
        } else {
            if (file_exists($destFile)) {
                echo "Destination file exists: $destFile<br>";
                if ($safe_delete) {
                    unlink($srcFile);
                    echo "Deleted source file: $srcFile<br>";
                }
            } else {
                if (copy($srcFile, $destFile)) {
                    echo "Copied file: $srcFile to $destFile<br>";
                    if ($safe_delete) {
                        unlink($srcFile);
                        echo "Deleted source file: $srcFile<br>";
                    }
                } else {
                    echo "Failed to copy file: $srcFile to $destFile<br>";
                }
            }
        }
    }

	closedir($dir);

    if ($safe_delete && is_dir_empty($source)) {
        rmdir($source);
        echo "Deleted empty source directory: $source<br>";
    }

	return;

	// == OLD CODE ==

	// Loop through the files in the source directory
    while (false !== ($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            // Check if it's a subdirectory
            if (is_dir($source . '/' . $file)) {
                // Recursively merge subdirectory
                merge_directories($source . '/' . $file, $destination . '/' . $file);
            } else {
                // Copy individual files
                if (!copy($source . '/' . $file, $destination . '/' . $file)) {
                    // If the copy operation fails, throw an exception
                    throw new Exception("Failed to copy $file from $source to $destination");
					$safe_delete = false;
                }
            }
        }
    }

	// Close the source directory
	closedir($dir);

	// Remove the source directory
	if ($safe_delete !== false){
		// Check if the source directory exists before renaming
		if (file_exists($source)) {
			// Rename source directory, prepend .
			rename($source, '.' . $source);
		} else {
			// Log or handle the error as needed
			error_log("Source directory not found: " . $source);
		}
	}
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
