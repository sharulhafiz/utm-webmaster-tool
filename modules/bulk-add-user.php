<?php
// Path: utm-newshub/module/bulk-add-user.php

// add new menu Bulk Add User to Users menu
add_action('admin_menu', 'utm_bulk_add_user_menu');
function utm_bulk_add_user_menu() {
    add_submenu_page('users.php', 'Bulk Add User', 'Bulk Add User', 'manage_options', 'bulk-add-user', 'utm_newshub_bulk_add_user_page');
}

function utm_newshub_bulk_add_user_page() {
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        return;
    }

    echo '<div class="wrap">';

    echo '<h1>Bulk Add User</h1>';
    echo '<p>Enter a list of user emails to create/add new users to this site, new line for each user. Each user will be added as administrator</p>';

    $bulk_user = isset($_POST['bulk_user']) ? $_POST['bulk_user'] : '';

    echo '<form method="post" action="' . esc_html($_SERVER['REQUEST_URI']) . '">';
    echo '<label for="bulk_user">User Emails</label><br>';
    echo '<textarea name="bulk_user" id="bulk_user" rows="10" cols="50">' . esc_textarea($bulk_user) . '</textarea><br>';
    echo '<input type="submit" value="Submit"><br>';
    echo '</form>';

    if (isset($_POST['bulk_user'])) {
        $emails = preg_split("/[\n\s,]+/", $bulk_user);
        foreach ($emails as $email) {
            $email = trim($email); // Remove any extra whitespace
            $username = $email; // Use email as username
            $password = wp_generate_password(); // Generate a random password

            // Check if the user already exists in the network
            $user_id = email_exists($email);

            if ($user_id) {
                // User exists, check if they are added to the current site
                if (!is_user_member_of_blog($user_id)) {
                    // Add the user to the current site with the desired role
                    add_user_to_blog(get_current_blog_id(), $user_id, 'administrator');
                    echo '<p>User ' . $username . ' (' . $email . ') added to the site successfully</p>';
                    // Email the user to notify they have been added with custom message
                    wp_mail(
                        $email,
                        'You have been added as an Administrator',
                        'Hello, you have been added as an Administrator to the site ' . get_bloginfo('name') . '. Click here to log in using your email: ' . wp_login_url()
                    );
                } else {
                    echo '<p>User ' . $username . ' (' . $email . ') already exists on this site</p>';
                }
            } else {
                // User does not exist, create a new user
                $user_id = wp_create_user($username, $password, $email);
                if (!is_wp_error($user_id)) {
                    // Add the new user to the current site with the desired role
                    add_user_to_blog(get_current_blog_id(), $user_id, 'administrator');
                    echo '<p>New user ' . $username . ' (' . $email . ') created and added to the site successfully</p>';
                } else {
                    echo '<p>' . $username . ' (' . $email . '): ' . $user_id->get_error_message() . '</p>';
                }
            }
        }
    }

    echo '</div>';
}
