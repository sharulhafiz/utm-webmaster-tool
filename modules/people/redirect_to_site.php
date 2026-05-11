<?php
// Redirect users to their own blog, only for non superadmin
add_action('admin_init',  function () {
	// Only execute for people.utm.my on the main site
	if ($_SERVER['HTTP_HOST'] != 'people.utm.my' || get_current_blog_id() != 1) {
		return;
	}

	// Check if the user is a super admin
	if (is_super_admin()) {
		return;
	}

	$user_id = get_current_user_id(); // Get current user ID

	// Get all blogs of the user, excluding the main site
	$user_blogs = array_filter(get_blogs_of_user($user_id), function ($blog) {
		return $blog->path != '/';
	});

	$txt = "";
	// Unarchive archived blogs
	foreach ($user_blogs as $blog) {
		switch_to_blog($blog->userblog_id);
		if (get_blog_status($blog->userblog_id, 'archived') == '1') {
			update_blog_status($blog->userblog_id, 'archived', '0');
			// Optionally, notify the user or perform additional actions here
			$txt .= "Blog " . $blog->domain . $blog->path . " unarchived\n";
		}
		restore_current_blog();
	}
	if ($txt != "") {
		send_email_to_webmaster($user_id, $txt);
	}

	if (!empty($user_blogs)) {
		// Redirect to the first blog
		wp_redirect(reset($user_blogs)->siteurl);
		exit;
	} else {
		// If user has no blog, create and redirect to a new one
		$new_blog_id = create_new_user_blog($user_id);
		if ($new_blog_id) {
			wp_redirect(get_blog_details($new_blog_id)->siteurl);
			exit;
		}
	}
});

/**
 * Create a new blog for a user.
 * 
 * @param int $user_id User ID.
 * @return int|false New blog ID or false on failure.
 */
function create_new_user_blog($user_id)
{
	$email = wp_get_current_user()->user_email;
	$username = strtok($email, '@');
	$userpath = str_replace('.', '-', $username);

	$newdomain = 'people.utm.my';
	$title = "$username Blog";

	$unique = '';
	do {
		$site_path = "/{$userpath}{$unique}/";
		$unique = is_numeric($unique) ? $unique + 1 : 1;
	} while (get_blog_id_from_url($newdomain, $site_path));

	return wpmu_create_blog($newdomain, $site_path, $title, $user_id, array('public' => 1));
}

/**
 * Send email to webmaster when user unarchived their blog
 *
 * @param int $user_id User ID.
 * @param string $txt Email body.
 * @return true|false True on Success or False on failure.
 */
function send_email_to_webmaster($user_id, $txt)
{
	$to = "webmaster@utm.my";  // webmaster email
	$user = get_userdata($user_id);
	$from = $user->user_email;
	$subject = "{$user->username} Sites Unarchived";
	$body = "Hello Webmaster, \n\n";
	$body .= "{$user->username} has unarchived their sites. \n\n";
	$body .= "Here are the sites: \n";
	$body .= $txt . "\n\n";
	$body .= "Regards, \n";
	$body .= "UTM Webmaster";
	$headers = "From: " . $from . "\r\n";
	return mail(
		$to,
		$subject,
		$body,
		$headers
	);
}