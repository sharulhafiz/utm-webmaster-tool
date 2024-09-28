<?php
// Shortcode for logged-in users
function show_content_for_logged_in_users($atts, $content = null) {
    if (is_user_logged_in()) {
        return $content;
    }
    return '';
}
add_shortcode('logged_in_content', 'show_content_for_logged_in_users');

// Shortcode for logged-out users
function show_content_for_logged_out_users($atts, $content = null) {
    if (!is_user_logged_in()) {
        return $content;
    }
    return '';
}
add_shortcode('logged_out_content', 'show_content_for_logged_out_users');
