<?php
function antispam_comments_filter($commentdata)
{
    $email = $commentdata['comment_author_email'];
    $domain = substr(strrchr($email, "@"), 1);
    $allowed_domains = array('utm.my','graduate.utm.my','live.utm.my');

    // Mark approved if the email is in allowed domains
    if (in_array($domain, $allowed_domains)) {
        return wp_set_comment_status($commentdata['comment_ID'], 'approve');
    }

    // Initial spam keywords
    $spam_keywords = array(
        'viagra', 'cialis', 'levitra', 'doxycycline', 'xanax', 'valium', 'ativan',
        'klonopin', 'ambien', 'tramadol', 'prozac', 'zoloft', 'celexa', 'lexapro',
        'effexor', 'wellbutrin', 'buspar', 'cymbalta', 'paxil', 'zyprexa', 'abilify',
        'seroquel', 'risperdal', 'geodon', 'clozaril', 'latuda', 'invega', 'fanapt',
        'saphris', 'asenapine', 'loxapine', 'lurasidone', 'olanzapine', 'quetiapine',
        'risperidone', 'ziprasidone', 'aripiprazole', 'chlorpromazine', 'fluphenazine',
        'haloperidol', 'perphenazine', 'thioridazine', 'trifluoperazine', 'casino',
		'prednisone', 'buy', 'zithromax', 'cheap', 'shop'
    );

    // Use transients to cache the blacklist for 12 hours
    $blacklist = get_transient('spam_blacklist');
    if ($blacklist === false) {
        $blacklist_file = dirname(__FILE__) . '/blacklist.txt';
        if (!file_exists($blacklist_file)) {
            $blacklist_url = 'https://raw.githubusercontent.com/splorp/wordpress-comment-blacklist/master/blacklist.txt';
            $blacklist = @file_get_contents($blacklist_url);
            if ($blacklist !== false) {
                file_put_contents($blacklist_file, $blacklist);
            }
        }

        if (file_exists($blacklist_file)) {
            $blacklist = file_get_contents($blacklist_file);
        }

        if ($blacklist !== false) {
            $blacklist_keywords = array_filter(array_map('trim', explode("\n", $blacklist)));
            set_transient('spam_blacklist', $blacklist_keywords, 12 * HOUR_IN_SECONDS);
            $spam_keywords = array_merge($spam_keywords, $blacklist_keywords);
        }
    }

    // Create a single regular expression for all spam keywords
    $spam_pattern = '/' . implode('|', array_map('preg_quote', $spam_keywords)) . '/i';

    // Convert comment content to lowercase
    $comment_content = strtolower($commentdata['comment_content']);

    // Check if the comment contains any spam keywords
    if (preg_match($spam_pattern, $comment_content)) {
		return wp_set_comment_status($commentdata['comment_ID'], 'spam');
    }
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
		// Get all pending comments
		$comments = get_comments(array(
			'status' => array('approve','0'),
			'number' => 100,
		));

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

// Disable comments for current site
add_filter('comments_open', '__return_false');
add_filter('pings_open', '__return_false');
add_filter('comment_form_defaults', 'remove_comment_form');
function remove_comment_form($defaults) {
    $defaults['comment_notes_before'] = '';
    $defaults['comment_notes_after'] = '';
    return $defaults;
}

// Disable comments for all posts
add_action('init', 'disable_comments_post_types_support');
function disable_comments_post_types_support() {
    $post_types = get_post_types();
    foreach ($post_types as $post_type) {
        if (post_type_supports($post_type, 'comments')) {
            remove_post_type_support($post_type, 'comments');
            remove_post_type_support($post_type, 'trackbacks');
        }
    }
}


