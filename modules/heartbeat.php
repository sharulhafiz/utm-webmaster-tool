<?php
// Schedule the event on plugin activation
register_activation_hook(__FILE__, 'utm_heartbeat_schedule_event');
function utm_heartbeat_schedule_event() {
    if (is_main_site() && !wp_next_scheduled('utm_heartbeat_daily_event')) {
        wp_schedule_event(time(), 'daily', 'utm_heartbeat_daily_event');
    }
}

// Clear the scheduled event on plugin deactivation
register_deactivation_hook(__FILE__, 'utm_heartbeat_clear_scheduled_event');
function utm_heartbeat_clear_scheduled_event() {
    if (is_main_site()) {
        wp_clear_scheduled_hook('utm_heartbeat_daily_event');
    }
}

// Ensure the event is scheduled even after plugin updates
add_action('admin_init', 'utm_heartbeat_schedule_event');

// Hook the function to the scheduled event
add_action('utm_heartbeat_daily_event', 'utm_send_heartbeat');

function utm_send_heartbeat() {
    // Send a POST request to the UTM API
    $response = wp_remote_post('https://www.utm.my/api/heartbeat.php', array(
        'method'    => 'POST',
        'body'      => array(
            'network_address' => utm_network_site_url,
            'plugin_version'  => utm_plugin_version,
        ),
    ));

    if (is_wp_error($response)) {
        error_log('UTM Heartbeat Error: ' . $response->get_error_message());
    }
}

// Debugging function to manually trigger the heartbeat
function utm_manual_heartbeat() {
    utm_send_heartbeat();
    echo '<script>console.log("UTM Heartbeat Sent");</script>';
}

// // if user is webmaster, run on init
// if (is_main_site()) {
//     add_action('init', 'utm_manual_heartbeat');
// }