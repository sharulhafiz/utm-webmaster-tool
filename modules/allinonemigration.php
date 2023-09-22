<?php
// Path: utm-webmaster-tool/modules/allinonemigration.php

// search in option table for "/opt/www/" and replace with "/var/www/"
function allinonemigration_updatepath(){
	// Ensure this code runs only for super admins in the network admin area
	if (!is_super_admin() || !is_network_admin()) {
		return;
	}

	global $wpdb;
	$option_table = $wpdb->prefix . "options";
	$query = "SELECT option_id, option_name, option_value FROM $option_table WHERE option_value LIKE '%/opt/www/%' AND (option_name NOT LIKE 'wdfc%' AND option_name NOT LIKE '_transient%')";

	if (isset($_GET['fix_path'])) {
		$results = $wpdb->get_results($query, ARRAY_A);
		if ($results) {
			foreach ($results as $row) {
				$unserialized = maybe_unserialize($row['option_value']);
				$unserialized = utm_recursive_str_replace("/opt/www/", "/var/www/", $unserialized);
				$new_value = maybe_serialize($unserialized);
				$wpdb->update($option_table, array('option_value' => $new_value), array('option_id' => $row['option_id']));
			}
			// echo the new_value
			echo "<div class='notice notice-warning is-dismissible'><pre>{$new_value}</pre></div>";
		}
	}

	// If an option named fix_path is set to 1, then do nothing, use wp get option
	$results = $wpdb->get_results($query, ARRAY_A);
	// Show notice if there is a string with "/opt/www/"
	if ($results) {
		$results_string = esc_html(print_r($results, true));
		echo "<div class='notice notice-warning is-dismissible'><p>UTM Webmaster Tool: <strong>Found</strong> string with <strong>/opt/www/</strong> in <strong>option table</strong>. Please <a href='./index.php?fix_path'><strong>fix path</strong></a></p><pre>{$results_string}</pre></div>";
	}
}
// run the function above at network admin dashboard page
add_action('network_admin_notices', 'allinonemigration_updatepath');

function utm_recursive_str_replace($search, $replace, $subject)
{
	if (is_string($subject)) {
		return str_replace($search, $replace, $subject);
	} elseif (is_array($subject)) {
		foreach ($subject as $key => $value) {
			$subject[$key] = utm_recursive_str_replace($search, $replace, $value);
		}
	} elseif (is_object($subject)) {
		foreach ($subject as $property => $value) {
			$subject->$property = utm_recursive_str_replace($search, $replace, $value);
		}
	}
	return $subject;
}