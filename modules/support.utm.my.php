<?php
// if url is not support.utm.my, return
if (strpos($_SERVER['HTTP_HOST'], 'support.utm.my') === false) {
    return;
}

add_action('init', function () {
    // Check if the user is logged in using WordPress functions
    if (!is_user_logged_in()) {
        // Allow access to wp-admin and wp-login.php
        if (!is_admin() && !in_array($GLOBALS['pagenow'], ['wp-login.php'])) {
            // Redirect to the login page
            wp_redirect("https://utm.spab.gov.my");
            exit();
        }
    }
});
?>