<?php
// Use system sendmail (msmtp) for email delivery
add_action('phpmailer_init', function($phpmailer) {
    // Don't use SMTP - use system sendmail which is configured with msmtp in the container
    $phpmailer->isMail();
    $phpmailer->Mailer = 'sendmail';
    $phpmailer->Sendmail = '/usr/bin/msmtp -t';

    // Log all outgoing emails
    add_action('wp_mail_succeeded', function() use ($phpmailer) {
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/utm_email_log.txt';
        $to_list = [];
        if (method_exists($phpmailer, 'getToAddresses')) {
            foreach ((array) $phpmailer->getToAddresses() as $addr) {
                if (is_array($addr) && isset($addr[0])) {
                    $to_list[] = $addr[0];
                } elseif (is_string($addr)) {
                    $to_list[] = $addr;
                }
            }
        } else {
            $all = $phpmailer->getAllRecipientAddresses();
            if (is_array($all)) {
                foreach ($all as $email => $name) {
                    if (is_string($email)) {
                        $to_list[] = $email;
                    } elseif (is_string($name)) {
                        $to_list[] = $name;
                    }
                }
            }
        }
        $to = $to_list ? implode(',', $to_list) : 'unknown';
        $log_message = sprintf(
            "[%s] SENT: To: %s | Subject: %s | Via: msmtp\n",
            date('Y-m-d H:i:s'),
            $to,
            $phpmailer->Subject
        );
        file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX);
    });
});

// Add UTM Email admin menu and log viewer page
add_action('admin_menu', function() {
    add_submenu_page(
        'tools.php',
        'UTM Email Log v1',
        'UTM Email',
        'manage_options',
        'utm-email-log',
        'utm_email_log_page'
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
