<?php
function protected_content_shortcode($atts, $content = null) {
    // Get the full current page URL including the domain
    $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";

    // Check if user is logged in
    if (!is_user_logged_in()) {
        // Append the current page URL to the login page
        $login_page_url = wp_login_url($url);
        $content = '
            <div style="display: flex; justify-content: center; align-items: center; height: 50vh;">
                <div style="text-align: center; border: 1px solid grey; background-color: #f0f0f0; padding: 20px; display: inline-block;">
                    <p style="font-weight: bold; font-size: 20px;">Akses terhad. Sila log masuk terlebih dahulu</p>
                    <a href="' . $login_page_url . '">
                        <br>
                        <button style="background-color: maroon; color: white; border: none; padding: 10px 20px; font-size: 16px; cursor: pointer;">
                            Log Masuk
                        </button>
                    </a>
                </div>
            </div>';
    } else {
        // If the user is logged in, display the original content
        $content = do_shortcode($content);
    }

    return $content;
}
add_shortcode('protected_content', 'protected_content_shortcode');

function custom_protected_content_notice($content) {
    global $post;

    // Check if the content is password protected
    if ('private' === get_post_status($post)) {
        // Get the full current page URL including the domain
        $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        // Append the current page URL to the login page
        $login_page_url = wp_login_url($url);

        // Custom notice for protected content
        $custom_notice = '
            <div style="display: flex; justify-content: center; align-items: center; height: 50vh;">
                <div style="text-align: center; border: 1px solid grey; background-color: #f0f0f0; padding: 20px; display: inline-block;">
                    <p style="font-weight: bold; font-size: 20px;">Akses terhad. Sila log masuk terlebih dahulu</p>
                    <a href="' . $login_page_url . '">
                        <br>
                        <button style="background-color: maroon; color: white; border: none; padding: 10px 20px; font-size: 16px; cursor: pointer;">
                            Log Masuk
                        </button>
                    </a>
                </div>
            </div>';

        return $custom_notice;
    }

    return $content;
}
// add_filter('the_content', 'custom_protected_content_notice');