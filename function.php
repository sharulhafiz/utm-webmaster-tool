<?php
// callback function
function redirect_to_user_blog($user_login, WP_User $user)
{
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
				echo "<a href='" . $blog->siteurl . "'> . $blog->siteurl . </a><br>";
			}
		} else {
			// echo "<a href='".site_url('/wp-login.php?action=register')."'>Click here to register a site</a>";
			echo "You have no registered website. ";
			echo "<a href='" . site_url('/register') . "'>Click here to register a site</a>";
		}
	}
}
// action hook
add_action('wp_login', 'redirect_to_user_blog', 10, 2);

function send_email_reminder()
{
	global $wpdb;
	$blogs = $wpdb->get_results("SELECT * FROM wp_blogs WHERE public = 1");
	$now = time(); // or your date as well
	foreach ($blogs as $blog) {
		if (get_option('last_email_reminder') !== false) {
			$datediff = $now - strtotime($blog->last_updated);

			$blog->daysdiff = round($datediff / (60 * 60 * 24));
			if ($blog->daysdiff > 90) {
				if (round(($now - strtotime(get_option('last_email_reminder'))) / (60 * 60 * 24)) > 90) {
					// send email
					$to = $blog->adminemail;
					$subject = 'People@UTM Notice';
					$body = 'Your website at ' . $blog->url . ' has not been updated for more than 30 days. Your last update was on ' . date("jS M Y", strtotime($blog->last_updated)) . '. You are advised to update your website at least once a month.<br><a href="' . $blog->url . '/wp-admin">Click here to login</a><br><a href="https://people.utm.my/wp-login.php?action=lostpassword">Click here to reset your password</a><br><a href="https://people.utm.my/faq">FAQ</a><br>--<br>UTM Webmaster<br>UTM Office of Corporate Affairs<br><br>No longer an administrator for this site? Please reply to this email.';
					$headers = array('From: UTM Webmaster <webmaster@utm.my>', 'Content-Type: text/html; charset=UTF-8');
					$blog->emailbody = $body;

					//   wp_mail($to, $subject, $body, $headers);

					update_option('last_email_reminder', date("Y-m-d"));
					$blog->noofreminder = get_option('no_of_reminder');
					update_option('no_of_reminder', $blog->noofreminder + 1);
				}
			} else if ($_GET['public'] == 0 && $blog->daysdiff < 90) {
				$blogupdated = $wpdb->update($wpdb->blogs, array('public' => 1), array('blog_id' => $blog->blog_id));
			} else if ($blog->daysdiff > 365) {
				$blogupdated = $wpdb->update($wpdb->blogs, array('public' => 0), array('blog_id' => $blog->blog_id));
			}
		} else {
			// The option hasn't been created yet, so add it with $autoload set to 'no'.
			$deprecated = null;
			$autoload = 'no';
			add_option('last_email_reminder', $now, $deprecated, $autoload);
			add_option('no_of_reminder', 0, $deprecated, $autoload);
			$blog->noofreminder = get_option('no_of_reminder');
		}
		// set email reminder
		$now = time(); // or your date as well
		$blog->last_email_reminder = get_option('last_email_reminder');
	}
}

// create a shortcode to display [registration_form] if not logged in
function registration_form_shortcode()
{
	if (is_user_logged_in()) {
		return '';
	} else {
		// login url
		$loginurl = wp_login_url();
		return do_shortcode('[formidable id=24]');
	}
}
add_shortcode('registration_form', 'registration_form_shortcode');
