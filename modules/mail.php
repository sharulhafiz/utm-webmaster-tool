<?php
// Force WordPress to use Google SMTP Relay for sending emails
add_action('phpmailer_init', function($phpmailer) {
    // Configure PHPMailer to use Google SMTP Relay
    $phpmailer->isSMTP();
    $phpmailer->Host       = 'smtp-relay.gmail.com'; // Google SMTP Relay host
    $phpmailer->Port       = 587;                   // Port for TLS encryption
    $phpmailer->SMTPSecure = 'tls';                 // Use TLS encryption
    $phpmailer->SMTPAuth   = false;                 // No authentication required for Google SMTP Relay

    // Log all outgoing emails (standard format)
    $phpmailer->action_function = function($isSent, $to, $cc, $bcc, $subject, $body, $header, $attachments) {
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/utm_email_log.txt';
        $log_message = sprintf(
            "[%s] SENT: To: %s | Subject: %s\n",
            date('Y-m-d H:i:s'),
            is_array($to) ? implode(',', $to) : $to,
            $subject
        );
        file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX);
    };
});

// Add UTM Email admin menu and log viewer page
add_action('admin_menu', function() {
    add_menu_page(
        'UTM Email Log',
        'UTM Email',
        'manage_options',
        'utm-email-log',
        'utm_email_log_page',
        'dashicons-email',
        101
    );
});

function utm_email_log_page() {
    echo '<div class="wrap"><h1>UTM Email Log</h1>';
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/utm_email_log.txt';
    if (file_exists($log_file)) {
        echo '<pre style="background:#fff; border:1px solid #ccc; padding:10px; max-height:600px; overflow:auto;">';
        echo esc_html(file_get_contents($log_file));
        echo '</pre>';
    } else {
        echo '<p>No email log found.</p>';
    }
    echo '</div>';
}

// Log email sending (standard format)
add_action('wp_mail_failed', function($error) {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/utm_email_log.txt';
    $log_message = sprintf(
        "[%s] ERROR: %s\n",
        date('Y-m-d H:i:s'),
        print_r($error, true)
    );
    file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX);
});

// // Log email errors for debugging
// add_action('wp_mail_failed', function($error) {
//     // set custom mail log in wp-content directory
//     $log_file = WP_CONTENT_DIR . '/mail_error_log.txt';
//     $log_message = sprintf("[%s] Mail Error: %s\n", date('Y-m-d H:i:s'), print_r($error, true));
//     file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX);
// });

// // Use PublishPress options for "From" email and name
// add_filter('wp_mail_from', function($email) {
//     $options = get_option('publishpress_notifications_options');
//     if (is_object($options)) {
//         $options = (array) $options;
//     }
//     return isset($options['email_from']) ? $options['email_from'] : $email;
// });

// add_filter('wp_mail_from_name', function($name) {
//     $options = get_option('publishpress_notifications_options');
//     if (is_object($options)) {
//         $options = (array) $options;
//     }
//     return isset($options['email_from_name']) ? $options['email_from_name'] : $name;
// });

// // Display the PublishPress options in the admin area
// add_action('admin_notices', function() {
//     // Show only on news.utm.my
//     if ($_SERVER['HTTP_HOST'] !== 'news.utm.my') {
//         return;
//     }
//     $options = get_option('publishpress_notifications_options');
//     if (is_object($options)) {
//         $options = (array) $options;
//     }
//     $email_from = isset($options['email_from']) ? $options['email_from'] : 'Not set';
//     // $email_from_name = isset($options['email_from_name']) ? $options['email_from_name'] : 'Not set';

//     echo '<div class="notice notice-info">';
//     echo '<p><strong>NewsHub Admin Email:</strong> ' . esc_html($email_from) . '</p>';
//     // echo '<p><strong>PublishPress Email From Name:</strong> ' . esc_html($email_from_name) . '</p>';
//     echo '</div>';
// });