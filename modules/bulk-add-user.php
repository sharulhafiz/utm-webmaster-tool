<?php
// Path: utm-newshub/module/bulk-add-user.php

// add new menu Bulk Add User to Users menu
add_action('admin_menu', 'utm_newshub_bulk_add_user_menu');
function utm_newshub_bulk_add_user_menu() {
    add_submenu_page('users.php', 'Bulk Add User', 'Bulk Add User', 'manage_options', 'bulk-add-user', 'utm_newshub_bulk_add_user_page');
}

function utm_newshub_bulk_add_user_page() {
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        return;
    }

    echo '<div class="wrap">';

    echo '<h1>Bulk Add User</h1>';

    $bulk_user = isset($_POST['bulk_user']) ? $_POST['bulk_user'] : '';

    echo '<form method="post" action="' . esc_html($_SERVER['REQUEST_URI']) . '">';
    echo '<label for="bulk_user">User Emails</label><br>';
    echo '<textarea name="bulk_user" id="bulk_user" rows="10" cols="50">' . esc_textarea($bulk_user) . '</textarea><br>';
    echo '<input type="submit" value="Submit"><br>';
    echo '</form>';

    if (isset($_POST['bulk_user'])) {
        $emails = preg_split("/[\n\s,]+/", $bulk_user);
        // var_dump($emails);
        // return;
        foreach ($emails as $email) {
            $email = trim($email); // Remove any extra whitespace
            $username = substr($email, 0, strpos($email, '@')); // Get the part of the email before the @
            $password = wp_generate_password(); // Generate a random password
            $user_id = wp_create_user($username, $password, $email);
            if (!is_wp_error($user_id)) {
                // Set the new user's role
                wp_update_user(array('ID' => $user_id, 'role' => 'author'));
                echo '<p>User ' . $username .' ('.$email.') created successfully</p>';
            } else {
                echo '<p>'.$username.' ('.$email.'): ' . $user_id->get_error_message() . '</p>';
            }
        }
    }

    echo '</div>';
}