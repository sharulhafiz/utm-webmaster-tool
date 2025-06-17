<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// If domain is not "news.utm.my", return
if ($_SERVER['HTTP_HOST'] !== 'news.utm.my') {
    return;
}

// Only run in admin area
add_action('admin_init', function() {
    // Only target the specific user
    $current_user = wp_get_current_user();
    if ($current_user && $current_user->user_email === 'officevc@utm.my') {
        // Grant capabilities to the user to publish and edit posts
        $current_user->add_cap('edit_posts');
        $current_user->add_cap('publish_posts');
        // Optionally, ensure the user has the author role
        if (!in_array('author', $current_user->roles)) {
            $current_user->add_role('author');
        }
    }
});

// Append notice after posts by officevc@utm.my
add_filter('the_content', function($content) {
    global $post;
    if (is_admin() || !is_singular() || !isset($post->post_author)) {
        return $content;
    }
    $author = get_userdata($post->post_author);
    if ($author && $author->user_email === 'officevc@utm.my') {
        $notice = '<div style="margin-top:2em;font-style:italic;color:#555;">Berita ini dikendalikan sepenuhnya oleh Pejabat Naib Canselor</div>';
        return $content . $notice;
    }
    return $content;
});

