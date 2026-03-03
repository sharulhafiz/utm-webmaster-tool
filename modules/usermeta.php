<?php
// Add "Last Login" column to single-site users list and Network Admin users list
add_filter('manage_users_columns', 'add_last_login_column');
add_filter('wpmu_users_columns', 'add_last_login_column');
function add_last_login_column($columns) {
    $columns['last_login'] = __('Last Login', 'utm-webmaster');
    return $columns;
}

// Populate the "Last Login" column for single-site and network list views
add_filter('manage_users_custom_column', 'populate_last_login_column', 10, 3);
add_filter('manage_users-network_custom_column', 'populate_last_login_column', 10, 3);
function populate_last_login_column($value, $column_name, $user_id) {
    if ('last_login' !== $column_name) {
        return $value;
    }

    // Capability check: require appropriate capability based on context
    if (is_network_admin()) {
        if (! current_user_can('manage_network')) {
            return $value;
        }
    } else {
        if (! current_user_can('list_users')) {
            return $value;
        }
    }

    $last = get_user_meta($user_id, 'last_login', true);
    if (empty($last)) {
        return __('Never', 'utm-webmaster');
    }

    // Display mode: default to relative time. Allow override via filter.
    $display = apply_filters('utm_last_login_display', 'relative'); // 'relative' or 'datetime'

    if ($display === 'datetime') {
        $format = apply_filters('utm_last_login_datetime_format', 'Y-m-d H:i:s');
        return date_i18n($format, (int) $last);
    }

    // Relative time (e.g., "3 days ago"). Fallback to datetime if helper missing.
    if (function_exists('human_time_diff')) {
        return human_time_diff((int) $last, time()) . ' ' . __('ago', 'utm-webmaster');
    }

    return date_i18n('Y-m-d H:i:s', (int) $last);
}

// Track user login and save the timestamp (existing behavior)
add_action('wp_login', 'track_user_last_login', 10, 2);
function track_user_last_login($user_login, $user) {
    update_user_meta($user->ID, 'last_login', time());
}

// Make the Last Login column sortable and handle sorting by meta value
add_filter('manage_users_sortable_columns', 'utm_make_last_login_sortable');
// Also make sortable in Network Admin users list
add_filter('manage_users-network_sortable_columns', 'utm_make_last_login_sortable');
function utm_make_last_login_sortable($columns) {
    $columns['last_login'] = 'last_login';
    return $columns;
}

// Adjust the users query when ordering by last_login
add_action('pre_get_users', 'utm_last_login_orderby');
function utm_last_login_orderby($query) {
    // Only modify admin user lists
    if (! is_admin()) {
        return;
    }

    // Determine requested orderby (WP passes via query var or GET)
    $orderby = isset($_GET['orderby']) ? $_GET['orderby'] : $query->get('orderby');
    if ($orderby !== 'last_login') {
        return;
    }

    // Ensure we sort by numeric meta value
    $order = (isset($_GET['order']) && strtoupper($_GET['order']) === 'ASC') ? 'ASC' : 'DESC';
    $query->set('meta_key', 'last_login');
    $query->set('orderby', 'meta_value_num');
    $query->set('order', $order);
}

// Show Last Login on the user profile page (view and edit screens)
add_action('show_user_profile', 'utm_show_last_login_profile');
add_action('edit_user_profile', 'utm_show_last_login_profile');
function utm_show_last_login_profile($user) {
    // Only show when a valid WP_User object is provided
    if (empty($user->ID)) {
        return;
    }

    $last = get_user_meta($user->ID, 'last_login', true);
    $display = apply_filters('utm_last_login_display', 'relative');

    if (empty($last)) {
        $output = __('Never', 'utm-webmaster');
    } else {
        if ($display === 'datetime') {
            $format = apply_filters('utm_last_login_datetime_format', 'Y-m-d H:i:s');
            $output = date_i18n($format, (int) $last);
        } else {
            if (function_exists('human_time_diff')) {
                $output = human_time_diff((int) $last, time()) . ' ' . __('ago', 'utm-webmaster');
            } else {
                $output = date_i18n('Y-m-d H:i:s', (int) $last);
            }
        }
    }

    ?>
    <h2><?php esc_html_e('Last Login', 'utm-webmaster'); ?></h2>
    <table class="form-table">
        <tr>
            <th><label><?php esc_html_e('Last Login', 'utm-webmaster'); ?></label></th>
            <td><?php echo esc_html($output); ?></td>
        </tr>
    </table>
    <?php
}
