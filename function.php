<?php
// callback function
function redirect_to_user_blog($user_login, WP_User $user)
{
	// check if multisite
	if (is_multisite() === false) {
		return;
	}

	// check if domain is people.utm.my
	if (strpos($_SERVER['HTTP_HOST'], 'people.utm.my') === false) {
		return;
	}

	$blog_id = get_current_blog_id();
	if (is_main_site($blog_id)) {
		$user_id = get_current_user_id(); // get ID
		$user_blogs = get_blogs_of_user($user_id);
		if (count($user_blogs) == 1) {
			foreach ($user_blogs as $blog) {
				// https://codex.wordpress.org/WPMU_Functions/get_blog_status
				$preference = 'archived';
				echo 'Blog ' . $blog_id . ' was last updated ' . get_blog_status($blog_id, $preference);
				wp_redirect($blog->siteurl);
			}
		} else if (count($user_blogs) > 0) {
			foreach ($user_blogs as $blog) {
				echo "<a href='" . $blog->siteurl . "'> $blog->siteurl </a><br>";
			}
		}
	}
}
// action hook
// add_action('wp_login', 'redirect_to_user_blog', 10, 2);

function self_unsuspended() {
	global $wpdb;
	// Get table prefix
	$table_prefix = $wpdb->get_blog_prefix();
	$wpblogs = $table_prefix . 'blogs';
	// A self-unsuspend form for users to key in their blog URL and email. This form will submit to the same page and check if the blog URL and email match the database. If it does, it will unsuspend the blog.
	$output = '<style>
		form {
			display: flex;
			flex-direction: column;
			width: 300px;
			margin: 0 auto;
			padding: 20px;
			box-shadow: 0px 0px 10px rgba(0, 0, 0, 0.1);
			border-radius: 5px;
		}
		label {
			margin-bottom: 5px;
			font-weight: 600;
		}
		input[type="text"], input[type="email"] {
			margin-bottom: 20px;
			padding: 10px;
			border: 1px solid #ccc;
			border-radius: 5px;
		}
		input[type="submit"] {
			padding: 10px;
			background-color: #4CAF50;
			color: white;
			border: none;
			border-radius: 5px;
			cursor: pointer;
		}
		input[type="submit"]:hover {
			background-color: #45a049;
		}
	</style>';

	$output .= '<form method="post">';
	$output .= '<label for="url">Blog URL</label>';
	$output .= '<input type="text" name="url" id="url" required>';
	$output .= '<label for="email">Email</label>';
	$output .= '<input type="email" name="email" id="email" required>';
	$output .= '<input type="submit" name="submit" value="Submit">';
	$output .= '</form>';

	if (isset($_POST['submit'])) {
		// ensure $url is a valid URL
		$url = rtrim(sanitize_text_field($_POST['url']), '/') . '/';
		if (!parse_url($url, PHP_URL_SCHEME)) {
			$url = 'https://' . $url;
		}
		if (filter_var($url, FILTER_VALIDATE_URL) === false) {
			// handle invalid URL
			echo '<span style="color: red; font-weight: bold;">Invalid URL</span>';
		} else {
			$path = '/' . trim(parse_url($url, PHP_URL_PATH), '/') . '/';

			$email = sanitize_email($_POST['email']);

			$blog_id = $wpdb->get_var("SELECT blog_id FROM wp_blogs WHERE path = '$path'");
			if ($blog_id) {
				$admin_email = get_blog_option($blog_id, 'admin_email');
			} else {
				$admin_email = null;
			}

			$blog = $wpdb->get_row("SELECT * FROM $wpblogs WHERE path = '$path'");
			if ($blog) {
				$blogupdated = $wpdb->update(
					$wpdb->blogs,
					array(
						'public' => 1,
						'archived' => 0
					),
					array('blog_id' => $blog->blog_id)
				);
				if ($blogupdated === false) {
					$output .= '<p><span style="color: red; font-weight: bold;">Blog ' . $url . ' could not be unsuspended.</span></p>';
					// Email to blog owner on failure
					$to = $admin_email;
					$subject = "Your blog $url could not be unsuspended";
					$body = "Your blog at $url could not be unsuspended. Please contact the UTM Webmaster by replying to this email.";
					$headers = array('From: UTM Webmaster <webmaster@utm.my>', 'Content-Type: text/html; charset=UTF-8', 'Cc: webmaster@utm.my');
					wp_mail($to, $subject, $body, $headers);
				} else {
					$output .= '<p><span style="color: green; font-weight: bold;">Blog ' . $url . ' has been unsuspended.</span></p>';
					// Email to blog owner on success
					$to = $admin_email;
					$subject = "Your blog $url has been unsuspended";
					$body = "Your blog at $url has been unsuspended. You can now access your blog at $url" . "wp-admin";
					$headers = array('From: UTM Webmaster <webmaster@utm.my>', 'Content-Type: text/html; charset=UTF-8', 'Cc: webmaster@utm.my');
					wp_mail($to, $subject, $body, $headers);
				}
			} else {
				$output .= '<p><span style="color: red; font-weight: bold;">Blog ' . $url . ' does not exist.</span></p>';
			}
		}
	}
	return $output;

	$archived = isset($_GET['archived']) ? sanitize_text_field($_GET['archived']) : 1;
	$blogs = $wpdb->get_results("SELECT * FROM $wpblogs WHERE archived = $archived LIMIT 100");
	// Check for database errors
	if ($wpdb->last_error) {
		echo "Database error: " . $wpdb->last_error;
	} else if (empty($blogs)) {
		echo "No blogs found with archived = $archived";
	} else {
		echo "Found " . count($blogs) . " blogs";
	}

	$now = time(); // or your date as well
	$output .= '<table><tr><th>ID</th><th>Blog URL</th><th>Last Updated</th><th>Days Diff</th><th>Email Reminder</th><th>Admin Email</th></tr>';
	foreach ($blogs as $blog) {
		// Get the last_email_reminder option for the current blog
		if (get_blog_option($blog->blog_id, 'last_email_reminder') !== false) {
			// Get the admin email
			$blog->adminemail = get_blog_option($blog->blog_id, 'admin_email');

			// Get the last_updated option for the current blog
			$datediff = $now - strtotime($blog->last_updated);
			$blog->url = $blog->domain . $blog->path;
			$blog->daysdiff = round($datediff / (60 * 60 * 24));

			if ($blog->daysdiff > 90) {
				if (round(($now - strtotime(get_option('last_email_reminder'))) / (60 * 60 * 24)) > 90) {
					// send email
					$to = $blog->adminemail;
					$subject = 'People@UTM Notice';
					$body = "Your website at $blog->url has not been updated for $blog->daysdiff days. Your last update was on " . date("jS M Y", strtotime($blog->last_updated)) . ". You are advised to update your website at least once a month to avoid Google from removing your website from its index.<br><a href='" . $blog->url . "/wp-admin'>Click here to login</a><br><a href='https://people.utm.my/help'>FAQ</a><br>--<br>UTM Webmaster<br>UTMDigital<br><br>Want to delete this site? Please reply to this email.";
					$headers = array('From: UTM Webmaster <webmaster@utm.my>', 'Content-Type: text/html; charset=UTF-8');
					$blog->emailbody = $body;

					// wp_mail($to, $subject, $body, $headers);

					update_blog_option($blog->blog_id, 'last_email_reminder', date("Y-m-d"));
					$blog->noofreminder = get_blog_option($blog->blog_id, 'no_of_reminder');
					update_blog_option($blog->blog_id, 'no_of_reminder', $blog->noofreminder + 1);
				}
			}
			// update blog status
			if ($archived == 0 && $blog->daysdiff < 90) {
				$blogupdated = $wpdb->update($wpdb->blogs, array('public' => 1), array('blog_id' => $blog->blog_id));
			} else if ($blog->daysdiff > 365) {
				$blogupdated = $wpdb->update($wpdb->blogs, array('public' => 0), array('blog_id' => $blog->blog_id));
			}

			// Get the last_email_reminder option for the current blog
			$blog->last_email_reminder = get_blog_option($blog->blog_id, 'last_email_reminder');
		} else {
			// The option hasn't been created yet, so add it with $autoload set to 'no'.
			$deprecated = null;
			$autoload = 'no';
			add_blog_option($blog->blog_id, 'last_email_reminder', $now, $deprecated, $autoload);
			add_blog_option($blog->blog_id, 'no_of_reminder', 0, $deprecated, $autoload);
			$blog->noofreminder = get_blog_option($blog->blog_id, 'no_of_reminder');
		}
		// set email reminder
		$now = time(); // or your date as well
		$blog->last_email_reminder = get_option('last_email_reminder');

		// Add a row to the table for each blog
        $output .= '<tr>';
		$output .= '<td>' . esc_html($blog->blog_id) . '</td>';
        $output .= '<td>' . esc_html($blog->url) . '</td>';
        $output .= '<td>' . esc_html(date("jS M Y", strtotime($blog->last_updated))) . '</td>';
        $output .= '<td>' . esc_html($blog->daysdiff) . '</td>';
        $output .= '<td>' . esc_html($blog->last_email_reminder) . '</td>';
		$output .= '<td>' . esc_html($blog->adminemail) . '</td>';
        $output .= '</tr>';
	}
	$output .= '</table>';

	return $output;
}
// add_shortcode('self_unsuspended', 'self_unsuspended');

function run_sql_once(){
	// if url parameter is set to sharul, execute this SQL
	if (isset($_GET['sharul']) && $_GET['sharul'] == 'true') {
		global $wpdb;

		$sql = "CREATE INDEX idx_meta_key_value ON news_postmeta (meta_key, meta_value(191));";
		$query_status = $wpdb->query($sql);
		if ($query_status === false) {
			echo "<script>alert('Error creating index: " . $wpdb->last_error . "');</script>";
		} else {
			echo "<script>alert('Index created successfully');</script>";
		}
	}
}
// run on init
// add_action('init', 'run_sql_once');

function stupid_ms_files_rewriting() {
	// if main site, return
	if (is_main_site()) {
		return;
	}
	$url = '/wp-content/uploads/sites/' . get_current_blog_id();
	if (!defined('BLOGUPLOADDIR')) {
		define('BLOGUPLOADDIR', $url);
	}
}
add_action('init','stupid_ms_files_rewriting');