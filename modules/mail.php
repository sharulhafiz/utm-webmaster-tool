<?php
// Force WordPress to use Google SMTP Relay for sending emails
add_action('phpmailer_init', function($phpmailer) {
    // Configure PHPMailer to use Google SMTP Relay
    // $phpmailer->isSMTP();
    // $phpmailer->Host       = 'smtp.gmail.com'; // Google SMTP Relay host
    // $phpmailer->Port       = 587;                   // Port for TLS encryption
    // $phpmailer->SMTPSecure = 'tls';                 // Use TLS encryption
    // $phpmailer->SMTPAuth   = true;                 // Authentication required for Google SMTP Relay
    // $phpmailer->Username    = 'webmaster@utm.my'; // Your Gmail address
    // $phpmailer->Password    = 'pylb qohx anoo adwx';   // Your App Password

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
        'UTM Email Log v1',
        'UTM Email',
        'manage_options',
        'utm-email-log',
        'utm_email_log_page',
        'dashicons-email',
        101
    );
});

function utm_email_log_page() {
    echo '<div class="wrap"><h1>UTM Email Log v1.1</h1>';
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/utm_email_log.txt';

    // Handle test email action
    if (isset($_POST['utm_send_test_email']) && check_admin_referer('utm_send_test_email_action', 'utm_send_test_email_nonce')) {
        $test_email = isset($_POST['utm_test_email']) ? sanitize_email($_POST['utm_test_email']) : '';
        if (is_email($test_email)) {
            $subject = 'UTM Webmaster Tools Test Email';
            $message = 'This is a test email sent from the UTM Webmaster Tools plugin.';
            $sent = wp_mail($test_email, $subject, $message);
            if ($sent) {
                echo '<div class="updated notice"><p>Test email sent to ' . esc_html($test_email) . '.</p></div>';
            } else {
                echo '<div class="error notice"><p>Failed to send test email to ' . esc_html($test_email) . '.</p></div>';
            }
        } else {
            echo '<div class="error notice"><p>Please enter a valid email address.</p></div>';
        }
    }

    // Handle clear log action
    if (isset($_POST['utm_clear_email_log']) && check_admin_referer('utm_clear_email_log_action', 'utm_clear_email_log_nonce')) {
        if (file_exists($log_file)) {
            file_put_contents($log_file, '');
            echo '<div class="updated notice"><p>Email log cleared.</p></div>';
        }
    }

    // Test email form
    echo '<form method="post" style="margin-bottom:15px; display:flex; gap:10px; align-items:center;">';
    wp_nonce_field('utm_send_test_email_action', 'utm_send_test_email_nonce');
    echo '<input type="email" name="utm_test_email" placeholder="Enter email to test" required style="min-width:250px;">';
    echo '<input type="submit" name="utm_send_test_email" class="button button-primary" value="Send Test Email">';
    echo '</form>';

    // Clear log button form
    echo '<form method="post" style="margin-bottom:15px;">';
    wp_nonce_field('utm_clear_email_log_action', 'utm_clear_email_log_nonce');
    echo '<input type="submit" name="utm_clear_email_log" class="button button-secondary" value="Clear Log" onclick="return confirm(\'Are you sure you want to clear the email log?\');">';
    echo '</form>';

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
