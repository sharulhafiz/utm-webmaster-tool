<?php
/**
 * Module: SSO Email Login
 *
 * This module changes the default WordPress login to use email for authentication.
 * When a user submits their email with an empty password, a 6-digit PIN is generated,
 * saved as the new user password, and sent to the user’s email address. When the user
 * enters that PIN as their password on a subsequent login attempt, they’ll be authenticated.
 */

// Check if the debug cookie is not set; otherwise, exit early.
if ( ! isset( $_COOKIE['debug'] ) ) {
    return;
}

if ( ! function_exists('defined') || ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// if isset cookie debug, return
if (isset($_COOKIE['debug'])) {
    return;
}

// Add additional instructions to the login form.
add_action( 'login_message', 'sso_login_message' );
function sso_login_message( $message ) {
    $instruction = '<p style="text-align: center;"><strong>UTM SSO</strong></p>';

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
    $logo = utm_webmaster_plugin_url . 'modules/img/utm-logo.png';
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
    echo '<style type="text/css">.login form p.forgetmenot, .login form p.submit { display: none; }</style>';
}

// Enqueue custom scripts for the login page.
add_action( 'login_enqueue_scripts', 'sso_enqueue_scripts' );
function sso_enqueue_scripts() {
    $time = time();
    wp_enqueue_script( 'sso-script', plugin_dir_url( __FILE__ ) . '/sso.js?ver='.$time, array( 'jquery' ), null, true );
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

    if ( ! $user ) {
        wp_send_json_error( 'No user found with that email address.' );
    }

    $pin = strval( rand( 100000, 999999 ) );
    wp_set_password( $pin, $user->ID );

    $siteURL = rtrim(str_replace('https://', '', get_site_url()), '/');

    $subject = $siteURL . ': Login PIN';
    $message = "Your login PIN is: {$pin}\n\nPlease use this PIN to login.";
    $mailstatus = wp_mail( $user->user_email, $subject, $message );
    if($mailstatus){
        wp_send_json_success( 'PIN sent successfully to ' . $user->user_email . ' - ' . $pin );
    } else {
        wp_send_json_error( 'Failed to send PIN to ' . $user->user_email );
    }
}

// Handle AJAX request to validate the user's PIN.
add_action( 'wp_ajax_nopriv_validate_pin', 'sso_validate_pin' );
function sso_validate_pin() {
    if ( ! isset( $_POST['email'] ) || ! is_email( $_POST['email'] ) ) {
        wp_send_json_error( 'Invalid email address.' );
    }

    if ( ! isset( $_POST['pin'] ) || ! is_numeric( $_POST['pin'] ) || strlen( $_POST['pin'] ) !== 6 ) {
        wp_send_json_error( 'Invalid PIN.' );
    }

    $email = sanitize_email( $_POST['email'] );
    $pin = sanitize_text_field( $_POST['pin'] );
    $user = get_user_by( 'email', $email );

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

        // Decode the JSON response
        $response_body = json_decode($response['body'], true);

        // save response as sso_key
        $sso_key = isset($response_body) ? $response_body['sso_key'] : '';

        // set cookie email and sso_key
        setcookie('email', $email, time() + (14 * 86400), '/', '.utm.my');
        setcookie('sso_key', $sso_key, time() + (14 * 86400), '/', '.utm.my');

        // authenticate user
        wp_set_auth_cookie( $user->ID, true );
        wp_send_json_success( 'PIN validated successfully.');
    } else {
        wp_send_json_error( 'Invalid PIN.' );
    }
}

// UTM SSO
add_action( 'login_init', 'utm_sso' );
function utm_sso(){
    // Check for utmwp cookie and log user in if valid
    if (isset($_COOKIE['email']) && isset($_COOKIE['sso_key'])) {
        $email = $_COOKIE['email'];
        $sso_key = $_COOKIE['sso_key'];
        $user = get_user_by('email', $email);
        // send post request to sso server to validate sso_key
        $response = wp_remote_post('https://www.utm.my/api/sso.php', array(
            'body' => array(
                'email' => $email,
                'sso_key' => $sso_key
            )
        ));
        // Decode the JSON response
        $response_body = json_decode($response['body'], true);

        if ($response_body['message'] == 'OK' && $user) {
            wp_set_auth_cookie($user->ID, true);
            // Redirect to GET request to redirect_to
            if (isset($_GET['redirect_to'])) {
                wp_safe_redirect($_GET['redirect_to']);
                exit;
            }
            exit;
        }
    }
}
