<?php
// Hook into the custom event to delete all comments
add_action('delete_comments_daily', 'delete_comments');

function delete_comments() {
    $args = array(
        'status' => 'any',
        'number' => 500, // batch size
        'fields' => 'ids',
        'meta_query' => array(
            array(
                'key' => '_spam_scanned',
                'compare' => 'NOT EXISTS',
            ),
        ),
    );
    $comments = get_comments($args);
    foreach ($comments as $comment_id) {
        $comment = get_comment($comment_id);
        // Mark that comment has been scanned
        update_comment_meta($comment_id, '_spam_scanned', '1');

        // 1. Check with StopForumSpam
        $email = urlencode($comment->comment_author_email);
        $response = wp_remote_get("https://api.stopforumspam.org/api?email={$email}&json");
        if (!is_wp_error($response)) {
            $data = json_decode(wp_remote_retrieve_body($response));
            if (!empty($data->email->appears) && $data->email->appears) {
                wp_spam_comment($comment_id);
                continue;
            }
        }
        // 2. Local keyword scan
        $spam_words = array('casino', 'gambling', 'poker', /* ... */);
        $content = strtolower($comment->comment_content);
        foreach ($spam_words as $word) {
            if (strpos($content, strtolower($word)) !== false) {
                wp_spam_comment($comment_id);
                break;
            }
        }
    }
}

// Helper to get next midnight timestamp
function get_next_midnight() {
    $now = current_time('timestamp');
    $midnight = strtotime('tomorrow', $now);
    return $midnight;
}

// Schedule the daily comment deletion event at midnight
if (!wp_next_scheduled('delete_comments_daily')) {
    wp_schedule_event(get_next_midnight(), 'daily', 'delete_comments_daily');
}

// Schedule the spam scan at midnight (custom hook)
if (!wp_next_scheduled('midnight_spam_scan')) {
    wp_schedule_event(get_next_midnight(), 'daily', 'midnight_spam_scan');
}

// Change the hook for spam scan to midnight
add_action('midnight_spam_scan', 'scheduled_spam_scan');

// Hook into the 'wp' action to unschedule the event on plugin deactivation
register_deactivation_hook(__FILE__, 'unschedule_daily_comment_deletion');

function unschedule_daily_comment_deletion() {
    $timestamp = wp_next_scheduled('delete_comments_daily');
    wp_unschedule_event($timestamp, 'delete_comments_daily');
}

// Show notice in the admin dashboard footer the status of scheduled event
add_action('admin_notices', 'show_scheduled_event_status');

function show_scheduled_event_status() {
    $screen = get_current_screen();
    if ($screen->id !== 'edit-comments') {
        return;
    }

    $timestamp = wp_next_scheduled('delete_comments_daily');
    if ($timestamp === false) {
        $schedule_status = wp_schedule_event(time(), 'daily', 'delete_comments_daily');
        if ($schedule_status) {
            $timestamp = wp_next_scheduled('delete_comments_daily');
        }
    }
    $status = 'Scheduled for ' . date('Y-m-d H:i:s', $timestamp);
    echo "<div class='notice notice-info is-dismissible'><p>Comment Deletion Event: {$status}</p></div>";
}

// Disable support for comments and trackbacks in post types
function disable_comments_post_types_support() {
    $post_types = get_post_types();
    foreach ($post_types as $post_type) {
        if (post_type_supports($post_type, 'comments')) {
            remove_post_type_support($post_type, 'comments');
            remove_post_type_support($post_type, 'trackbacks');
        }
    }
}
add_action('admin_init', 'disable_comments_post_types_support');

// Close comments on the front-end
function disable_comments_status() {
    return false;
}
add_filter('comments_open', 'disable_comments_status', 20, 2);
add_filter('pings_open', 'disable_comments_status', 20, 2);

// ====================


// Disable email all notification for new comments
function disable_comments_notification($notify, $comment_id) {
    return false;
}
add_filter('comment_notification_recipients', 'disable_comments_notification', 10, 2);

// Disable comment form on front-end
function disable_comment_form($open, $post_id) {
    return false;
}
add_filter('comments_open', 'disable_comment_form', 10, 2);

// Prevent comment submission
function disable_comment_submission($commentdata) {
    wp_die('Comments are closed.');
}
add_filter('preprocess_comment', 'disable_comment_submission');

// Email webmaster@utm.my when a new comment is posted
function email_webmaster_on_comment($comment_id, $comment) {
    $post = get_post($comment->comment_post_ID);
    $post_title = $post->post_title;
    $comment_author = $comment->comment_author;
    $comment_content = $comment->comment_content;
    $comment_link = get_comment_link($comment_id);
    $subject = 'New Comment Notification: ' . $post_title;
    $message = "A new comment has been posted on your site:\n\n";
    $message .= "Post Title: {$post_title}\n";
    $message .= "Comment Author: {$comment_author}\n";
    $message .= "Comment Content:\n{$comment_content}\n\n";
    $message .= "You can view the comment here: {$comment_link}\n\n";
    $message .= "Please review the comment and take necessary actions if required.";
    $header = 'From: ' . get_bloginfo('name') . ' <' . get_bloginfo('admin_email') . '>';
    wp_mail('webmaster@utm.my', $subject, $message, $header);

    // Reset the password for the user who posted the comment
    $user = get_user_by('email', $comment->comment_author_email);
    if ($user) {
        wp_set_password(wp_generate_password(), $user->ID);
    }
}
// add_action('wp_insert_comment', 'email_webmaster_on_comment', 10, 2);

/*==================================================================================
  Anti-spam scan for posts and pages
==================================================================================*/

$spam_words = array(
    'Пин Ап казино',
    'coreldraw free download',
    'Сайт',
    '1xbet',
    'Lucky Jet',
    'brillx',
    'Техподдержка',
    'casino',
    'gambling',
    'poker',
    'roulette',
    'blackjack',
    'baccarat',
    'lottery',
    'sports betting',
    'Бонусы',
    'Джекпот',
    'Ставки на спорт',
    'Онлайн-гемблинг',
    'Pin Up Casino',
    'Slot Oyna',
    'Gates Of Olympus',
    'demo',
    'oyna'
);

function scheduled_spam_scan() {
    global $spam_words;

    // Query 10 unscanned posts/pages
    $args = array(
        'post_type'      => array('post', 'page'),
        'post_status'    => 'publish',
        'meta_query'     => array(
            array(
                'key'     => '_spam_scanned',
                'compare' => 'NOT EXISTS',
            ),
        ),
        'posts_per_page' => 10,
        'fields'         => 'ids', // Only fetch post IDs to reduce memory usage
    );
    $query = new WP_Query($args);

    if (!$query->have_posts()) {
        return; // No posts to scan
    }

    foreach ($query->posts as $post_id) {
        $post = get_post($post_id);
        scan_post_for_spam($post, $spam_words);
    }
}

// Function to scan a post for spam
function scan_post_for_spam($post, $spam_words) {
    $spam_score = 0;
    $matched_words = [];
    $post_text = strtolower($post->post_title . ' ' . $post->post_content); // Convert to lowercase for uniform matching

    // Build regex patterns for spam detection
    $patterns = [];
    foreach ($spam_words as $word) {
        $patterns[] = '/\b' . preg_quote(strtolower($word), '/') . '\b/'; // Match whole words
    }

    // Add custom patterns for common spam phrases
    $patterns[] = '/\bslot.*oyna\b/'; // Match phrases like "Slot Oyna", "Gates Of Olympus Slot Oyna"
    $patterns[] = '/\bdemo.*oyna\b/'; // Match phrases like "Demo Oyna", "Gates Of Olympus Demo Oyna"

    // Check for matches
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $post_text)) {
            $spam_score++;
            $matched_words[] = $pattern; // Track matched patterns for reporting
        }

        // Stop if spam score exceeds threshold
        if ($spam_score > 2) {
            break;
        }
    }

    // Adjust threshold as needed
    if ($spam_score > 2) {
        $post_data = array(
            'ID'          => $post->ID,
            'post_status' => 'draft', // Set to custom "Draft" status
        );

        // Update post status and send email notification
        if (wp_update_post($post_data)) {
            list($subject, $message, $headers) = construct_spam_email($post, $matched_words);
            wp_mail(get_bloginfo('admin_email'), $subject, $message, $headers);
        }
    }

    // Mark post as scanned
    update_post_meta($post->ID, '_spam_scanned', '1');
}

// Construct spam email
function construct_spam_email($post, $matched_words) {
    $subject = 'Spam Post on ' . get_bloginfo('name');
    $message = "A post containing spam words has been set to draft:\n\n";
    $message .= "Post Title: {$post->post_title}\n";
    $message .= "Post URL: " . get_permalink($post->ID) . "\n";

    // Clean up matched words to remove regex delimiters and special characters
    $cleaned_words = array_map(function($word) {
        return trim($word, '/\\b'); // Remove regex delimiters and word boundaries
    }, $matched_words);

    $message .= "Matched Spam Words: " . implode(', ', $cleaned_words) . "\n";
    $author = get_userdata($post->post_author);
    $author_name = $author ? $author->display_name : 'Unknown';
    $message .= "Post Author: {$author_name}";
    $header = 'From: ' . get_bloginfo('name') . ' <' . get_bloginfo('admin_email') . '>';
    $headers = array('Cc: webmaster@utm.my');
    $headers[] = $header;
    return array($subject, $message, $headers);
}