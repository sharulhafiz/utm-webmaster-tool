<?php
// Hook into the custom event to delete all comments
add_action('delete_comments_daily', 'delete_comments');

function delete_comments() {
    global $wpdb;

    // Change pending to spam to prevent accidental deletion of legitimate comments
    $wpdb->query("UPDATE {$wpdb->comments} SET comment_approved = 'spam' WHERE comment_approved = '0'");

    // Delete only comments marked as spam
    $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'");

    // Clean up comment meta for deleted comments
    $wpdb->query("DELETE FROM {$wpdb->commentmeta} WHERE comment_id NOT IN (SELECT comment_id FROM {$wpdb->comments})");

    // Email to webmaster if number of comments approved is more than 100
    $comment_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = '1'");
    if ($comment_count > 100) {
        $subject = 'Comment Cleanup on ' . get_bloginfo('name');
        $message = "The number of comments has exceeded 100. Cleanup is required.\n\n";
        $message .= "Current Comment Count: {$comment_count}";
        $header = 'From: ' . get_bloginfo('name') . ' <' . get_bloginfo('admin_email') . '>';
        wp_mail(get_bloginfo('admin_email'), $subject, $message, $header);
    }
}

// Schedule the daily event
if (!wp_next_scheduled('delete_comments_daily')) {
    wp_schedule_event(time(), 'daily', 'delete_comments_daily');
}

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
    $subject = 'New Comment on ' . $post_title;
    $message = "A new comment on the post \"{$post_title}\" has been posted by {$comment_author}:\n\n";
    $message .= $comment_content . "\n\n";
    $message .= "View Comment: {$comment_link}";
    $header = 'From: ' . get_bloginfo('name') . ' <' . get_bloginfo('admin_email') . '>';
    wp_mail('webmaster@utm.my', $subject, $message, $header);
}
add_action('wp_insert_comment', 'email_webmaster_on_comment', 10, 2);


$spam_words = array('Сайт', '1xbet', 'Lucky Jet', 'brillx', 'Техподдержка', 'casino', 'gambling', 'poker', 'roulette', 'blackjack', 'baccarat', 'lottery', 'sports betting');

// Function to trash posts or pages containing spam words in the title or content on page load
function trash_spam_posts_on_load() {
    global $spam_words;
    $post = get_post();

    // Early return for logged-in users
    if (is_user_logged_in()) {
        return;
    }

    // if page is not published, return
    if ($post->post_status !== 'publish') {
        return;
    }

    if (is_page() || is_singular()) {
        $spam_score = 0; // Initialize spam score
        foreach ($spam_words as $word) {
            $post_text = $post->post_title . ' ' . $post->post_content;
            if (stripos($post_text, $word) !== false) {
                $spam_score++; // Increment spam score for each spam word found
            }
        }

        // Adjust threshold as needed
        if ($spam_score > 2) { // Example: Flag if more than 2 spam words are found
            $post_data = array(
                'ID' => $post->ID,
                'post_status' => 'pending' // Set to pending review
            );
            if (wp_update_post($post_data)) {
                list($subject, $message, $headers) = construct_spam_email($post, $word);
                wp_mail(get_bloginfo('admin_email'), $subject, $message, $headers);
                // Remove redirect
                wp_redirect(home_url());
                exit;
            }
        }
    }
}
add_action('template_redirect', 'trash_spam_posts_on_load');

function construct_spam_email($post, $word) {
    $subject = 'Spam Post on ' . get_bloginfo('name');
    $message = "A post containing the word '{$word}' has been set to draft:\n\n";
    $message .= "Post Title: {$post->post_title}\n";
    $message .= "Post URL: " . get_permalink($post->ID) . "\n";
    $message .= "Post Author: {$post->post_author}";
    $header = 'From: ' . get_bloginfo('name') . ' <' . get_bloginfo('admin_email') . '>';
    $headers = array('Cc: webmaster@utm.my');
    $headers[] = $header;
    return array($subject, $message, $headers);
}

add_action('template_redirect', 'trash_spam_posts_on_load');