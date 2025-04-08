<?php
// filepath: g:\My Drive\Projects\plugins\utm-webmaster-tool\modules\loginlogger.php
if (!defined('UTM_ALLOWED_DOMAIN')) {
    define('UTM_ALLOWED_DOMAIN', 'utm.my');
}

class UTMLoginLogger {

    private $admin_email;
    private $allowed_domain;

    public function __construct() {
        $this->admin_email = get_option('admin_email');
        $this->allowed_domain = $this->get_allowed_domain();
        add_action('wp_login', array($this, 'check_login'), 10, 2);
        add_action('wp_login_failed', array($this, 'utm_check_failed_logins'));
        add_action('admin_init', array($this, 'utm_prepend_htaccess_block_wp_login_post'));
    }

    private function send_alert($message, $type, $user = null) {
        // Extend user session to 14 days
        wp_set_auth_cookie($user->ID, true, is_ssl());
        // Append multisite url to the message
        $site_url = get_site_url();
        $message .= "\n\nSite URL: " . $site_url;
        // Get login page URL
        $message .= "\n\nLogin Page URL: " . wp_login_url();
        // Get the user email
        $message .= "\n\nUser Email: " . $user->user_email;
        // Get user IP address
        $message .= "\n\nUser IP Address: " . $_SERVER['REMOTE_ADDR'];
        // Extend user session to 14 days
        $message .= "\n\nSession valid until: " . date('Y-m-d H:i:s', time() + 1209600);

        // Send the email
        $to = $this->admin_email;
        $subject = 'Login Alert' . ' - ' . $site_url;
        $headers = 'From: UTM Login Alert <monitoring@utm.my>' . "\r\n" .
            'Reply-To: webmaster@utm.my' . "\r\n" .
            'CC: webmaster@utm.my' . "\r\n" .
            'X-Mailer: PHP/' . phpversion();
        wp_mail($to, $subject, $message, $headers);
    }

    public function check_login($user_login, $user) {
        // Send alert for every login
        $message = "Alert: A login was made.";
        $this->send_alert($message, 'login', $user);

        $this->utm_log_successful_login($user->ID);
        $this->utm_check_multiple_logins($user->ID);
    }

    private function utm_log_failed_login($username) {
        $ip = $_SERVER['REMOTE_ADDR'];
        $transient_key = 'failed_login_' . $ip . '_' . $username;
        $failed_attempts = get_transient($transient_key);

        if ($failed_attempts === false) {
            $failed_attempts = 1;
        } else {
            $failed_attempts++;
        }

        set_transient($transient_key, $failed_attempts, 3600); // Store for 1 hour
        return $failed_attempts;
    }

    public function utm_check_failed_logins($username) {
        $max_attempts = 3; // Set the maximum number of failed attempts
        $failed_attempts = $this->utm_log_failed_login($username);

        if ($failed_attempts >= $max_attempts) {
            $this->utm_reset_password($username);
        }
    }

    private function utm_reset_password($username) {
        $user = get_user_by('login', $username);

        if ($user) {
            $new_password = wp_generate_password(8, false); // Generate a random 8-character password
            wp_set_password($new_password, $user->ID);

            // Send email to admin
            $subject = 'Password Reset Alert';
            $message = "User {$username} password has been reset due to multiple failed login attempts.\n\nNew Password: {$new_password}";
            wp_mail($this->admin_email, $subject, $message);

            // Send email to user (optional)
            $user_email = $user->user_email;
            $subject = 'Your Password Has Been Reset';
            $message = "Your password has been reset due to multiple failed login attempts.\n\nNew Password: {$new_password}";
            wp_mail($user_email, $subject, $message);
        }
    }

    private function utm_log_successful_login($user_id) {
        $login_times = get_user_meta($user_id, 'utm_login_times', true);

        if (empty($login_times)) {
            $login_times = array();
        }

        $login_times[] = time();

        update_user_meta($user_id, 'utm_login_times', $login_times);
    }

    private function utm_check_multiple_logins($user_id) {
        $login_times = get_user_meta($user_id, 'utm_login_times', true);
        $time_window = 600; // 10 minutes in seconds
        $max_logins = 2; // Maximum number of logins allowed within the time window

        if (empty($login_times)) {
            return;
        }

        $recent_logins = array_filter($login_times, function ($time) use ($time_window) {
            return ($time > (time() - $time_window));
        });

        if (count($recent_logins) >= $max_logins) {
            $this->utm_reset_password_compromised($user_id);
        }
    }

    private function utm_reset_password_compromised($user_id) {
        $user = get_user_by('ID', $user_id);

        if ($user) {
            $new_password = wp_generate_password(8, false); // Generate a random 8-character password
            wp_set_password($new_password, $user->ID);

            // Send email to admin
            $subject = 'Compromised Account Alert';
            $message = "User {$user->user_login} password has been reset due to multiple logins within a short period.\n\nNew Password: {$new_password}";
            wp_mail($this->admin_email, $subject, $message);

            // Send email to user (optional)
            $user_email = $user->user_email;
            $subject = 'Your Password Has Been Reset';
            $message = "Your password has been reset due to multiple logins within a short period. This may indicate that your account has been compromised.\n\nNew Password: {$new_password}";
            wp_mail($user_email, $subject, $message);

            // Clear login times
            delete_user_meta($user_id, 'utm_login_times');
        }
    }

    public function utm_prepend_htaccess_block_wp_login_post() {
        // Login logger version 1.0
        $loginlogger_version = '1.0';

        // check in option table 'utm_logginlogger_version', if version is less than plugin version
        $current_version = get_option('utm_logginlogger_version', '0.0');
        if (version_compare($current_version, $loginlogger_version, '=')) {
            return;
        }

        $htaccess_file = ABSPATH . '.htaccess';

        if (!file_exists($htaccess_file) || !is_writable($htaccess_file)) {
            // Email to webmaster@utm.my
            $site_url = $_SERVER['HTTP_HOST'];
            $subject = 'UTM Login Logger Error '. $site_url;
            $message = "The .htaccess file does not exist or is not writable. Please check the file permissions.";
            $this->send_email($this->admin_email, $subject, $message);
            return false; // .htaccess file does not exist or is not writable
        }

        $block = <<<HTACCESS
        # BEGIN Block wp-login.php POST requests
        <Files wp-login.php>
            <IfModule mod_rewrite.c>
                RewriteEngine On
                RewriteCond %{REQUEST_METHOD} POST
                RewriteCond %{HTTP_REFERER} !^https?://(.*)?utm\.my [NC]
                RewriteRule ^(.*)$ - [F,L]
            </IfModule>
        </Files>
        # END Block wp-login.php POST requests

        HTACCESS;

        $contents = file_get_contents($htaccess_file);

        if (strpos($contents, '# BEGIN Block wp-login.php POST requests') === false) {
            $contents = $block . $contents;
            file_put_contents($htaccess_file, $contents);
        }

        update_option('utm_logginlogger_version', $loginlogger_version);

        return true;
    }

    private function send_email($to, $subject, $message) {
        $headers = 'From: UTM Login Alert <monitoring@utm.my>' . "\r\n" .
                   'Reply-To: webmaster@utm.my' . "\r\n" .
                   'CC: webmaster@utm.my' . "\r\n" .
                   'X-Mailer: PHP/' . phpversion();
        wp_mail($to, $subject, $message, $headers);
    }

    private function get_allowed_domain() {
        return defined('UTM_ALLOWED_DOMAIN') ? UTM_ALLOWED_DOMAIN : 'utm.my';
    }

}

// Initialize the class
$utm_login_logger = new UTMLoginLogger();
?>