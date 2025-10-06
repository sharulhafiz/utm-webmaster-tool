<?php
// Hook to add a custom column in the users table
add_filter('manage_users_columns', 'add_last_login_column');
function add_last_login_column($columns) {
    $columns['last_login'] = 'Last Login';
    return $columns;
}

// Hook to populate the custom column with data
add_action('manage_users_custom_column', 'populate_last_login_column', 10, 3);
function populate_last_login_column($value, $column_name, $user_id) {
    if ('last_login' === $column_name) {
        $last_login = get_user_meta($user_id, 'last_login', true);
        return $last_login ? date('Y-m-d H:i:s', $last_login) : 'Never';
    }
    return $value;
}

// Hook to track user login and save the timestamp
add_action('wp_login', 'track_user_last_login', 10, 2);
function track_user_last_login($user_login, $user) {
    update_user_meta($user->ID, 'last_login', time());
}