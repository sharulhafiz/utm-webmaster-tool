<?php
function antispam_comments_filter($commentdata)
{
	$email = $commentdata['comment_author_email'];
	$domain = substr(strrchr($email, "@"), 1);
	$allowed_domains = array('utm.my','graduate.utm.my','live.utm.my','gmail.com');

	// Mark as spam if the email is not from allowed domains
	if (!in_array($domain, $allowed_domains)) {
		$commentdata['comment_approved'] = 'spam';
		return $commentdata;
	}

	// Mark as pending if the email is from Gmail
	if (strpos($email, 'gmail.com') !== false) {
		// Mark as pending if the email is from Gmail
		$commentdata['comment_approved'] = 0;
		return $commentdata;
	}

	// Check if file blacklist.txt exists
	$blacklist_file = dirname(__FILE__) . '/blacklist.txt';
	if (!file_exists($blacklist_file)) {
		// Download from GitHub https://raw.githubusercontent.com/splorp/wordpress-comment-blacklist/master/blacklist.txt
		$blacklist_url = 'https://raw.githubusercontent.com/splorp/wordpress-comment-blacklist/master/blacklist.txt';
		$blacklist = file_get_contents($blacklist_url);

		// Create the blacklist file if it doesn't exist
		file_put_contents($blacklist_file, $blacklist);
	}

	// Get the blacklist file contents
	$blacklist = file_get_contents($blacklist_file);

	// Convert the blacklist string into an array of keywords
	$spam_keywords = explode("\n", $blacklist);

	$comment_content = strtolower($commentdata['comment_content']);

	foreach ($spam_keywords as $keyword) {
		if (strpos($comment_content, $keyword) !== false) {
			$commentdata['comment_approved'] = 'spam';
			break;
		}
	}

	return $commentdata;
}
add_filter('preprocess_comment', 'antispam_comments_filter', 9);

add_action('admin_notices', 'add_scan_button');
function add_scan_button() {
	// Only add the button on the Comments page
	$screen = get_current_screen();
	if ($screen->id !== 'edit-comments') return;

	echo '<div class="notice notice-success is-dismissible">';
	echo '<p>Scan comments for spam: ';
	echo '<a href="' . esc_url(add_query_arg('scan', 'true')) . '" class="button">Start Scanning</a>';
	echo '</p></div>';
}


function scan_comments_for_spam() {
	if (isset($_GET['scan']) && $_GET['scan'] === 'true') {
		// Get all comments that are not marked as spam
		$comments = get_comments(array('status' => 'approve'));

		foreach ($comments as $comment) {
			// Prepare comment data
			$commentdata = array(
				'comment_ID' => $comment->comment_ID,
				'comment_author_email' => $comment->comment_author_email,
				'comment_content' => $comment->comment_content,
				'comment_approved' => $comment->comment_approved,
				// link
				'comment_author_url' => $comment->comment_author_url,
			);

			// Pass comment through the antispam filter
			$filtered_commentdata = antispam_comments_filter($commentdata);

			// If the comment was marked as spam, update its status in the database
			if ($filtered_commentdata['comment_approved'] == 'spam') {
				wp_set_comment_status($comment->comment_ID, 'spam');
			} elseif ($filtered_commentdata['comment_approved'] == 0) {
				wp_set_comment_status($comment->comment_ID, '0');
			}
		}

		// Show a notice after scanning
		add_action('admin_notices', function() {
			$comment_page_url = admin_url('edit-comments.php');
			echo "<div class='notice notice-success is-dismissible'><p>Scanning comments for spam completed. <a href='$comment_page_url'>Please refresh the page</a></p></div>";
		});
	}
}
add_action('admin_head-edit-comments.php', 'scan_comments_for_spam');

// Disable comment notification emails
add_filter('wp_new_comment_notify_postauthor', '__return_false');
add_filter('wp_new_comment_notify_moderator', '__return_false');

