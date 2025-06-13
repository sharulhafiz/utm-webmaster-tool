<?php
return;
// Early return if not on main site
if (!is_main_site()) {
    return;
}

// Ensure the event is scheduled even after plugin updates
add_action('admin_init', function() {
    if (!wp_next_scheduled('utm_heartbeat')) {
        wp_schedule_event(time(), 'daily', 'utm_heartbeat');
    }
});

// Hook the function to the scheduled event
add_action('utm_heartbeat', function() {
    send_utm_heartbeat();
});

// Function to send the UTM Heartbeat
function send_utm_heartbeat() {
    // Send a POST request to the UTM API
    $response = wp_remote_post('https://www.utm.my/api/heartbeat.php', array(
        'method'    => 'POST',
        'body'      => array(
            'network_address' => utm_network_site_url,
            'plugin_version'  => utm_plugin_version,
            'timestamp'       => current_time('mysql'),
        ),
    ));

    if (is_wp_error($response)) {
        error_log('UTM Heartbeat Error: ' . $response->get_error_message());
        return array(
            'success' => false,
            'message' => $response->get_error_message(),
        );
    }

    return array(
        'success' => true,
        'response' => wp_remote_retrieve_body($response),
    );
}

// Check for the ?debug parameter to trigger the heartbeat manually
add_action('admin_init', function() {
    if (isset($_GET['debug']) && $_GET['debug'] === 'heartbeat') {
        $result = send_utm_heartbeat();

        // Output the result to the browser console
        echo '<script>';
        if ($result['success']) {
            echo 'console.log("Heartbeat sent successfully:", ' . json_encode($result['response']) . ');';
        } else {
            echo 'console.error("Heartbeat Error:", ' . json_encode($result['message']) . ');';
        }
        echo '</script>';
    }
});

// Add next scheduled time for heartbeat to the admin footer-left
add_filter('admin_footer_text', function($footer_text) {
    // Get the next scheduled time for the event
    $next_scheduled = wp_next_scheduled('utm_heartbeat');

    if ($next_scheduled) {
        $formatted_time = date('Y-m-d H:i:s', $next_scheduled);
        $footer_text .= ' | Next UTM Heartbeat scheduled for: <strong>' . $formatted_time . '</strong>';
    } else {
        $footer_text .= ' | No UTM Heartbeat event scheduled.';
    }

    return $footer_text;
});
