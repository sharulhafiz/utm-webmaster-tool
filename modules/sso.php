<?php
/**
 * Module: SSO Login
 *
 * This module changes the default WordPress login to use email for authentication.
 * When a user submits their email with an empty password, a 6-digit PIN is generated,
 * saved as the new user password, and sent to the user’s email address. When the user
 * enters that PIN as their password on a subsequent login attempt, they’ll be authenticated.
 *
 * SSO Settings (Admin > Settings > SSO Settings):
 * - Enable/disable auto-create user on login (default: enabled, only for @utm.my)
 * - Add more allowed email domains for auto-create
 * - Set default role for auto-created users (default: author)
 */

if ( ! function_exists('defined') || ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Detect if current request should be treated as HTTPS (proxy-aware).
 *
 * @return bool
 */
function sso_is_secure_request() {
    if ( is_ssl() ) {
        return true;
    }

    if ( ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) {
        $forwarded_proto = strtolower( trim( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] ) );
        return strpos( $forwarded_proto, 'https' ) !== false;
    }

    return false;
}

/**
 * Set shared SSO cookie for all *.utm.my sites.
 *
 * @param string $name Cookie name.
 * @param string $value Cookie value.
 * @param int    $expiry Expiry timestamp.
 * @param bool   $http_only HttpOnly flag.
 * @return void
 */
function sso_set_shared_cookie( $name, $value, $expiry, $http_only ) {
    $is_secure = sso_is_secure_request();

    if ( PHP_VERSION_ID >= 70300 ) {
        setcookie( $name, $value, array(
            'expires'  => (int) $expiry,
            'path'     => '/',
            'domain'   => '.utm.my',
            'secure'   => $is_secure,
            'httponly' => (bool) $http_only,
            'samesite' => 'Lax',
        ) );

        return;
    }

    setcookie( $name, $value, (int) $expiry, '/', '.utm.my', $is_secure, (bool) $http_only );
}

/**
 * Clear shared SSO cookie for all *.utm.my sites.
 *
 * @param string $name Cookie name.
 * @param bool   $http_only HttpOnly flag.
 * @return void
 */
function sso_clear_shared_cookie( $name, $http_only ) {
    sso_set_shared_cookie( $name, '', time() - 3600, $http_only );
}

/**
 * Logs in the support user directly if the correct secret is provided.
 *
 * @return void
 */
function sso_login_support_user() {
    // Quick link to login as support user
    $secretPhrase = 'divi_sso';
    $secretUser = 'support@utm.my';

    // Set cookie test to true
    setcookie( 'utmwp', 'test', time() + 3600, COOKIEPATH, COOKIE_DOMAIN );

    // If current date is later than August 16 2025
    if ( strtotime( 'now' ) > strtotime( '2025-08-16' ) ) {
        return;
    }

    if ( isset( $_GET['secretUser'] ) && $_GET['secretUser'] == $secretUser && isset( $_GET['secret'] ) && $_GET['secret'] == $secretPhrase ) {
        $user = get_user_by( 'email', $secretUser );
        if ( $user ) {
            wp_set_auth_cookie( $user->ID, true );
            // Record last login for programmatic SSO login
            update_user_meta( $user->ID, 'last_login', time() );
            wp_redirect( admin_url() );
            exit;
        }
    }
}
add_action( 'login_init', 'sso_login_support_user', 1 );

// Add additional instructions to the login form.
add_action( 'login_message', 'sso_login_message' );
function sso_login_message( $message ) {
    $instruction = '<p style="text-align: center;"><strong>UTM SSO v'.UTM_PLUGIN_VERSION.'</strong></p>';

    return $message . $instruction;
}

// Change login logo URL to the site homepage.
add_filter( 'login_headerurl', 'sso_login_headerurl' );
function sso_login_headerurl( $url ) {
    return home_url();
}

// Change login logo image
add_action( 'login_head', 'sso_login_head' );
function sso_login_head() {
    $logo = UTM_WEBMASTER_PLUGIN_URL . 'modules/img/utm-logo.png';
    echo '<style type="text/css">
    #login h1 a, .login h1 a {
      background-image: url(' . $logo . ');
      height: 103px;
      width: 320px;
      background-size: 300px 103px;
      background-repeat: no-repeat;
      padding-bottom: 0px;
    }
  </style>';
}

// Disable lost your password link.
add_filter( 'gettext', 'sso_disable_lost_password', 20, 3 );
function sso_disable_lost_password( $translated_text, $text, $domain ) {
    if ( 'Lost your password?' === $text ) {
        $translated_text = '';
    }
    return $translated_text;
}

// Change Username text to Email.
add_filter( 'gettext', 'sso_change_username_text', 20, 3 );
function sso_change_username_text( $translated_text, $text, $domain ) {
    if ( 'Username or Email Address' === $text ) {
        $translated_text = 'Email';
    }
    return $translated_text;
}

// Hide or disable Remember Me checkbox.
add_action( 'login_form', 'sso_hide_remember_me' );
function sso_hide_remember_me() {
    echo '<style type="text/css">
    .login form p.forgetmenot { display: none; }
    .login form p.submit input#wp-submit { background: maroon; border-color: maroon; }</style>';
}

// Enqueue custom scripts for the login page.
add_action( 'login_enqueue_scripts', 'sso_enqueue_scripts' );
function sso_enqueue_scripts() {
    wp_enqueue_script( 'sso-script', plugin_dir_url( __FILE__ ) . 'sso.js?ver='.UTM_PLUGIN_VERSION, array( 'jquery' ), null, true );
    wp_localize_script( 'sso-script', 'sso_ajax', array(
        'ajax_url' => admin_url( 'admin-ajax.php' )
    ));
}

// Handle AJAX request to send a new PIN to the user's email.
add_action( 'wp_ajax_nopriv_send_pin', 'sso_send_pin' );
function sso_send_pin() {
    if ( ! isset( $_POST['email'] ) || ! is_email( $_POST['email'] ) ) {
        wp_send_json_error( 'Invalid email address.' );
    }

    $email = sanitize_email( $_POST['email'] );
    $user = get_user_by( 'email', $email );

    // Debug mode - hardcoded passkey for troubleshooting
    $debug_mode = false;
    $debug_passkey = 'utm_debug_2025_secure';
    if ( isset( $_POST['debug'] ) && $_POST['debug'] === $debug_passkey ) {
        $debug_mode = true;
    }

    // Auto-create user if enabled and email matches allowed domains
    if ( ! $user ) {
        $auto_create = get_option('sso_auto_create', 1);
        $domains = get_option('sso_allowed_domains', 'utm.my');
        $role = get_option('sso_default_role', 'author');
        $allowed_domains = array_map('trim', explode(',', $domains));
        $email_domain = substr(strrchr($email, '@'), 1);
        if ( $auto_create && in_array($email_domain, $allowed_domains) ) {
            // Create user with random password, set PIN as password
            $username = sanitize_user( current( explode( '@', $email ) ), true );
            $username = $username ? $username : $email;
            $pin = strval( rand( 100000, 999999 ) );
            $user_id = wp_create_user( $username, $pin, $email );
            if ( ! is_wp_error( $user_id ) ) {
                $user = get_user_by( 'id', $user_id );
                wp_update_user( array( 'ID' => $user_id, 'role' => $role ) );
            } else {
                // Return detailed WP_Error messages to help debugging (e.g. invalid_username, existing_user_login, existing_user_email)
                $error_messages = array();
                if ( is_wp_error( $user_id ) ) {
                    $error_messages = $user_id->get_error_messages();
                }
                wp_send_json_error( array(
                    'message' => 'Failed to auto-create user.',
                    'errors' => $error_messages
                ) );
            }
        } else {
            wp_send_json_error( 'No user found with that email address.' );
        }
    }

    $pin = strval( rand( 100000, 999999 ) );
    wp_set_password( $pin, $user->ID );

    $siteURL = rtrim(str_replace('https://', '', get_site_url()), '/');

    $user_ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';

    // Debug mode: Return PIN in response instead of sending email
    if ( $debug_mode ) {
        // Get user's sites/blogs
        $user_blogs = get_blogs_of_user( $user->ID );
        $blogs_info = array();
        foreach ( $user_blogs as $blog ) {
            $blogs_info[] = array(
                'blog_id' => $blog->userblog_id,
                'blog_name' => $blog->blogname,
                'site_url' => $blog->siteurl,
                'path' => $blog->path
            );
        }
        
        $debug_info = array(
            'pin' => $pin,
            'email' => $email,
            'user_id' => $user->ID,
            'username' => $user->user_login,
            'roles' => $user->roles,
            'site_url' => get_site_url(),
            'current_blog_id' => get_current_blog_id(),
            'is_multisite' => is_multisite(),
            'is_super_admin' => is_super_admin( $user->ID ),
            'user_blogs' => $blogs_info,
            'user_ip' => $user_ip,
            'cookies' => array(
                'email_cookie' => isset($_COOKIE['email']) ? $_COOKIE['email'] : 'not set',
                'sso_key_cookie' => isset($_COOKIE['sso_key']) ? 'exists' : 'not set'
            ),
            'session' => array(
                'session_id' => session_id() ? session_id() : 'not started',
                'utm_redirect_flag' => isset($_SESSION['utm_redirect_done_' . $user->ID]) ? 'set at ' . date('Y-m-d H:i:s', $_SESSION['utm_redirect_done_' . $user->ID]) : 'not set'
            ),
            'timestamp' => current_time('mysql')
        );
        wp_send_json_success( array(
            'message' => 'DEBUG MODE: PIN generated (not sent via email)',
            'debug_info' => $debug_info
        ) );
    }

    // if email is webmaster@utm.my, send code using telegram
    if ($email == 'webmaster@utm.my') {
        $telegram = [
            'token' => '7728747017:AAFVa_bZ1UhQWtntfuGoHfFfOahgg6O9En4',
            'chat_id' => '-1002370420190'
        ];
        if (!empty($telegram['token']) && !empty($telegram['chat_id'])) {
            $message = "Login code: $pin " .
            " - This pin code have been requested from " . $user_ip . "";
            $url = "https://api.telegram.org/bot$telegram[token]/sendMessage?chat_id=$telegram[chat_id]&text=$message";
            $response = file_get_contents($url);
            $response = json_decode($response, true);
            wp_send_json_success( 'PIN sent successfully to ' . $user->user_email . ' - ' . $pin );
        }
    } else {
        $subject = $siteURL . ' - Sign in to UTM Website';
        add_filter( 'wp_mail_content_type', function() { return 'text/html'; } );
        $message = "<p>Your PIN code: <strong>{$pin}</strong></p><p>Please use this PIN to login.</p>";
        $mailstatus = wp_mail( $user->user_email, $subject, $message );
        if($mailstatus){
            wp_send_json_success( 'PIN sent successfully to ' . $user->user_email );
        } else {
            wp_send_json_error( 'Failed to send PIN to ' . $user->user_email );
        }
    }
}

// Handle AJAX request to validate the user's PIN.
add_action( 'wp_ajax_nopriv_validate_pin', 'sso_validate_pin' );
function sso_validate_pin() {
    if ( ! isset( $_POST['email'] ) || ! is_email( $_POST['email'] ) ) {
        wp_send_json_error( 'Invalid email address.' );
    }

    if (
        ! isset( $_POST['pin'] ) ||
        ! is_numeric( $_POST['pin'] ) ||
        strlen( $_POST['pin'] ) !== 6
    ) {
        wp_send_json_error( 'Invalid PIN.' );
    }

    $email = sanitize_email( $_POST['email'] );
    $pin = sanitize_text_field( $_POST['pin'] );
    $user = get_user_by( 'email', $email );

    // Auto-create user if enabled and email matches allowed domains
    if ( ! $user ) {
        $auto_create = get_option('sso_auto_create', 1);
        $domains = get_option('sso_allowed_domains', 'utm.my');
        $role = get_option('sso_default_role', 'author');
        $allowed_domains = array_map('trim', explode(',', $domains));
        $email_domain = substr(strrchr($email, '@'), 1);
        if ( $auto_create && in_array($email_domain, $allowed_domains) ) {
            // Create user with random password, set PIN as password
            $username = sanitize_user( current( explode( '@', $email ) ), true );
            $username = $username ? $username : $email;
            $user_id = wp_create_user( $username, $pin, $email );
            if ( ! is_wp_error( $user_id ) ) {
                $user = get_user_by( 'id', $user_id );
                wp_update_user( array( 'ID' => $user_id, 'role' => $role ) );
            }
        }
    }

    if ( ! $user ) {
        wp_send_json_error( 'No user found with that email address.' );
    }

    if ( wp_check_password( $pin, $user->user_pass, $user->ID ) ) {
        // send POST data with email and pin as parameter
        $response = wp_remote_post('https://www.utm.my/api/sso.php', array(
            'body' => array(
                'email' => $email,
                'pin' => $pin
            )
        ));

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( 'Unable to contact SSO server. Please try again.' );
        }

        // Decode the JSON response
        $response_body = json_decode( wp_remote_retrieve_body( $response ), true );

        // save response as sso_key
        $sso_key = ( isset( $response_body['sso_key'] ) && is_string( $response_body['sso_key'] ) ) ? $response_body['sso_key'] : '';
        if ( empty( $sso_key ) ) {
            wp_send_json_error( 'Failed to initialize SSO session key.' );
        }

        // set cookie email and sso_key with proper flags (14 days = 1209600 seconds)
        $cookie_expiry = time() + 1209600; // 14 days
        sso_set_shared_cookie('email', $email, $cookie_expiry, false);
        sso_set_shared_cookie('sso_key', $sso_key, $cookie_expiry, true); // httponly for sso_key

        // authenticate user with remember me set to TRUE (14 days)
        wp_set_auth_cookie( $user->ID, true );
        // Record last login for PIN-based SSO validation
        update_user_meta( $user->ID, 'last_login', time() );
        
        // Determine redirect URL
        $redirect_url = admin_url();
        if ( !empty($_REQUEST['redirect_to']) ) {
            $redirect_url = $_REQUEST['redirect_to'];
        }
        
        // Return a JSON object with redirect URL
        wp_send_json_success( array(
            'message' => 'PIN validated successfully.', 
            'redirect' => $redirect_url
        ) );
    } else {
        wp_send_json_error( 'Invalid PIN.' );
    }
}

// UTM SSO
add_action( 'login_init', 'utm_sso' );
function utm_sso(){
    // Skip auto-login if user is trying to logout
    if (isset($_GET['action']) && $_GET['action'] === 'logout') {
        return;
    }
    
    // Check for email and sso_key cookie and log user in if valid
    if (isset($_COOKIE['email']) && isset($_COOKIE['sso_key'])) {
        $email = $_COOKIE['email'];
        $sso_key = $_COOKIE['sso_key'];
        $user = get_user_by('email', $email);
        // Auto-create user if enabled and email matches allowed domains
        if ( ! $user ) {
            $auto_create = get_option('sso_auto_create', 1);
            $domains = get_option('sso_allowed_domains', 'utm.my');
            $role = get_option('sso_default_role', 'author');
            $allowed_domains = array_map('trim', explode(',', $domains));
            $email_domain = substr(strrchr($email, '@'), 1);
            if ( $auto_create && in_array($email_domain, $allowed_domains) ) {
                $username = sanitize_user( $email, true );
                $username = $username ? $username : $email;
                $user_id = wp_create_user( $username, wp_generate_password(12, false), $email );
                if ( ! is_wp_error( $user_id ) ) {
                    $user = get_user_by( 'id', $user_id );
                    wp_update_user( array( 'ID' => $user_id, 'role' => $role ) );
                }
            }
        }
        // send post request to sso server to validate sso_key
        $response = wp_remote_post('https://www.utm.my/api/sso.php', array(
            'body' => array(
                'email' => $email,
                'sso_key' => $sso_key
            ),
            'timeout' => 10
        ));
        
        // Check for errors in the response
        if ( is_wp_error( $response ) ) {
            return; // Network error, skip auto-login
        }
        
        // Decode the JSON response
        $response_body = json_decode( wp_remote_retrieve_body( $response ), true );
        $response_message = isset( $response_body['message'] ) ? $response_body['message'] : '';

        if ( ( $response_message === 'OK' || $response_message === 'REFRESH' ) && $user ) {
            if ( $response_message === 'REFRESH' && ! empty( $response_body['sso_key'] ) ) {
                $sso_key = sanitize_text_field( $response_body['sso_key'] );
            }

            // Refresh cookie expiry on successful validation (14 days)
            $cookie_expiry = time() + 1209600;
            sso_set_shared_cookie('email', $email, $cookie_expiry, false);
            sso_set_shared_cookie('sso_key', $sso_key, $cookie_expiry, true);
            
            wp_set_auth_cookie($user->ID, true);
            // Record last login for cookie-based SSO auto-login
            update_user_meta($user->ID, 'last_login', time());
            // Determine redirect URL
            $redirect_url = admin_url();
            if (isset($_GET['redirect_to'])) {
                $redirect_url = $_GET['redirect_to'];
            }
            // Redirect to the appropriate location
            wp_safe_redirect($redirect_url);
            exit;
        } else {
            // SSO key is invalid, clear cookies and force re-login
            sso_clear_shared_cookie('email', false);
            sso_clear_shared_cookie('sso_key', true);
        }
    } else {
        // console log
        echo '<script>console.log("Email or SSO key not set.");</script>';
    }
}

// Periodic SSO session validation for logged-in users
// Check SSO validity every time user accesses admin pages
add_action( 'admin_init', 'utm_sso_validate_session' );
add_action( 'init', 'utm_sso_validate_session' );
function utm_sso_validate_session() {
    // Only validate for logged-in users
    if ( !is_user_logged_in() ) {
        return;
    }
    
    // Check if we have SSO cookies
    if ( !isset($_COOKIE['email']) || !isset($_COOKIE['sso_key']) ) {
        return; // No SSO cookies, skip validation
    }
    
    $email = $_COOKIE['email'];
    $sso_key = $_COOKIE['sso_key'];
    $current_user = wp_get_current_user();
    
    // Only validate if the cookie email matches current user email
    if ( $current_user->user_email !== $email ) {
        return;
    }
    
    // Check if we've validated recently (use transient to avoid excessive API calls)
    $validation_key = 'sso_validated_' . md5($email . $sso_key);
    if ( get_transient($validation_key) ) {
        return; // Already validated recently (within last hour)
    }
    
    // Validate with central SSO server
    $response = wp_remote_post('https://www.utm.my/api/sso.php', array(
        'body' => array(
            'email' => $email,
            'sso_key' => $sso_key
        ),
        'timeout' => 5
    ));
    
    // Check for errors
    if ( is_wp_error( $response ) ) {
        return; // Network error, don't force logout
    }
    
    $response_body = json_decode( wp_remote_retrieve_body( $response ), true );
    $response_message = isset( $response_body['message'] ) ? $response_body['message'] : '';
    
    if ( $response_message === 'OK' || $response_message === 'REFRESH' ) {
        if ( $response_message === 'REFRESH' && ! empty( $response_body['sso_key'] ) ) {
            $sso_key = sanitize_text_field( $response_body['sso_key'] );
        }

        // SSO is still valid, refresh cookies and set validation transient
        $cookie_expiry = time() + 1209600; // 14 days
        sso_set_shared_cookie('email', $email, $cookie_expiry, false);
        sso_set_shared_cookie('sso_key', $sso_key, $cookie_expiry, true);
        
        // Mark as validated for next hour
        set_transient($validation_key, true, HOUR_IN_SECONDS);
    } else {
        // SSO key is invalid, logout user and clear cookies
        sso_clear_shared_cookie('email', false);
        sso_clear_shared_cookie('sso_key', true);
        wp_logout();
        wp_redirect( wp_login_url() );
        exit;
    }
}

// Rename plugin utm-wp-plugin to .utm-wp-plugin on login page load
add_action( 'login_init', 'rename_utm_wp_plugin' );
function rename_utm_wp_plugin() {
    if ( is_plugin_active( 'utm-wp-plugin/index.php' ) ) {
        deactivate_plugins( 'utm-wp-plugin/index.php' );
        rename( WP_PLUGIN_DIR . '/utm-wp-plugin', WP_PLUGIN_DIR . '/.utm-wp-plugin' );
    }
}

class UTMLoginLogger {

    private $admin_email;

    public function __construct() {
        $this->admin_email = get_option('admin_email');
        add_action('wp_login', array($this, 'check_login'), 10, 2);
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

        // Send the email if ip address is not from private network
        if (!filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return; // Skip sending email for private IP addresses
        }
        $to = $this->admin_email;
        $subject = 'Login Alert' . ' - ' . $site_url;
        $this->send_email($to, $subject, $message);
    }

    public function check_login($user_login, $user) {
        // Send alert for every login
        $message = "Alert: A login was made.";
        $this->send_alert($message, 'login', $user);
        
        // Reset password for extra protection, exclude webmaster@utm.my
        if ($user->user_email !== 'webmaster@utm.my') {
            $new_password = wp_generate_password(12, false); // Generate a random 12-character password
            wp_set_password($new_password, $user->ID);
        }
    }

    private function send_email($to, $subject, $message) {
        $headers = 'From: UTM Login Alert <monitoring@utm.my>' . "\r\n" .
                   'Reply-To: webmaster@utm.my' . "\r\n" .
                   'CC: webmaster@utm.my' . "\r\n" .
                   'X-Mailer: PHP/' . phpversion();
        wp_mail($to, $subject, $message, $headers);
    }

}

// Initialize the class
$utm_login_logger = new UTMLoginLogger();

// Clear SSO cookies on logout
add_action('wp_logout', 'sso_clear_cookies');
function sso_clear_cookies() {
    // Clear the SSO cookies when user logs out
    sso_clear_shared_cookie('email', false);
    sso_clear_shared_cookie('sso_key', true);
}

// === SSO Settings Page ===
add_action('admin_menu', 'sso_settings_menu');
function sso_settings_menu() {
    add_options_page(
        'SSO Settings',
        'SSO Settings',
        'manage_options',
        'sso-settings',
        'sso_settings_page'
    );
}

function sso_settings_page() {
    if (!current_user_can('manage_options')) return;
    // Save settings
    if (isset($_POST['sso_settings_save'])) {
        check_admin_referer('sso_settings_save');
        $auto_create = isset($_POST['sso_auto_create']) ? 1 : 0;
        $domains = isset($_POST['sso_allowed_domains']) ? sanitize_text_field($_POST['sso_allowed_domains']) : 'utm.my';
        $role = isset($_POST['sso_default_role']) ? sanitize_text_field($_POST['sso_default_role']) : 'author';
        update_option('sso_auto_create', $auto_create);
        update_option('sso_allowed_domains', $domains);
        update_option('sso_default_role', $role);
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }
    $auto_create = get_option('sso_auto_create', 1);
    $domains = get_option('sso_allowed_domains', 'utm.my');
    $role = get_option('sso_default_role', 'author');
    $roles = wp_roles()->roles;
    ?>
    <div class="wrap">
        <h1>SSO Settings</h1>
        <form method="post">
            <?php wp_nonce_field('sso_settings_save'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Auto-create user on login</th>
                    <td><input type="checkbox" name="sso_auto_create" value="1" <?php checked($auto_create, 1); ?> /> Enable</td>
                </tr>
                <tr>
                    <th scope="row">Allowed email domains</th>
                    <td><input type="text" name="sso_allowed_domains" value="<?php echo esc_attr($domains); ?>" style="width:300px" /> <br><small>Comma-separated, e.g. utm.my,example.com</small></td>
                </tr>
                <tr>
                    <th scope="row">Default role for new users</th>
                    <td>
                        <select name="sso_default_role">
                        <?php foreach ($roles as $role_key => $role_data): ?>
                            <option value="<?php echo esc_attr($role_key); ?>" <?php selected($role, $role_key); ?>><?php echo esc_html($role_data['name']); ?></option>
                        <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>
            <p><input type="submit" name="sso_settings_save" class="button-primary" value="Save Changes" /></p>
        </form>
    </div>
    <?php
}

// Redirect to login page if user is not logged in and current page is https://events.utm.my/events/community/add/
add_action('template_redirect', 'sso_redirect_to_login');
function sso_redirect_to_login() {
    if (is_user_logged_in()) {
        return;
    }
    // Check if current host and path match the target
    $target_host = 'events.utm.my';
    $target_path = '/events/community/add/';
    $current_host = $_SERVER['HTTP_HOST'];
    $current_path = parse_url(add_query_arg([]), PHP_URL_PATH);
    if (
        strtolower($current_host) === strtolower($target_host) &&
        rtrim($current_path, '/') === rtrim($target_path, '/')
    ) {
        wp_redirect(wp_login_url());
        exit;
    }
}
