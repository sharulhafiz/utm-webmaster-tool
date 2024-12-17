<?php

// This function will get results from the database and display them in a table. The table header are based on array keys while the rows are based on the values of the array.
function create_table($data) {
    // Start output buffering
    ob_start();

    // Fetch and display the results
    echo '<div class="responsive-table">';
    echo 'Sumber data: Sistem UTMHR';
    echo '<table>';
    echo '<tr>';
    foreach ($data[0] as $key => $value) {
        echo '<th>' . htmlentities($key, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    echo '</tr>';
    foreach ($data as $row) {
        echo '<tr>';
        foreach ($row as $value) {
            echo '<td>' . htmlentities($value ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
        }
        echo '</tr>';
    }
    echo '</table>';
    echo '</div>';

    // Return the buffered content
    return ob_get_clean();
}

// This function will get the value within shortcode opening and closing tags. The value is an sql query that will be executed and the results will be displayed in a table.
function query_parser($atts, $content = null) {
    // Enable error reporting
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }

    // if url contains 'frm', return
    if (strpos($_SERVER['REQUEST_URI'], 'frm') !== false) {
        // return;
    }

    // if on backend, return
    if (is_admin()) {
        return;
    }

    // Extract the email attribute
    $a = shortcode_atts(array(
        'email' => '',
    ), $atts);

    // Use the provided email or fallback to the current user's email
    $email = !empty($a['email']) ? sanitize_email($a['email']) : get_userdata(get_current_user_id())->user_email;

    // Validate the email
    if (!is_email($email)) {
        return 'Invalid email address';
    }

    if ($content == null) {
        return 'Invalid query';
    }

    // Replace HTML tags with a space
    $content = preg_replace('/<[^>]*>/', ' ', $content);

    // Replace typographical single quotes with straight quotes
    $content = str_replace(['&#8216;', '&#8217;', '‘', '’'], "'", $content);

    // Normalize whitespace to a single space
    $content = preg_replace('/\s+/', ' ', $content);

    // Trim leading and trailing whitespace
    $content = trim($content);

    $content = str_replace('{user_email}', $email, $content);

    echo '<script>console.log("Before: ' . $content . '")</script>';

    // Encode the query using rawurlencode to avoid extra encoding issues
    $encoded_query = urlencode($content);

    // Build the URL with query parameters
    $query_params = array('query' => $encoded_query);
    $url = 'https://www.utm.my/api/registrar.php?' . http_build_query($query_params);

    echo '<script>console.log("After: ' . $url . '")</script>';

    // Initialize cURL
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));

    // Execute cURL request
    $result = curl_exec($ch);

    // Check for cURL errors
    if (curl_errno($ch)) {
        return 'Error fetching data from the server: ' . curl_error($ch);
    }

    // Close cURL session
    curl_close($ch);

    // Decode the JSON result
    $data = json_decode($result, true);

    // If key 'error' is present in the result, return the error message
    if (isset($data['error'])) {
        return $data['error'];
    }

    // If $data is an array of 1 column, return the value
    if (isset($data[0]) && is_array($data[0]) && count($data[0]) == 1) {
        $value = array_values($data[0])[0];
        return $value;
    }

    // If $data[0] is undefined, return empty result
    if (!isset($data[0])) {
        return 'No results found';
    }

    // Return the results
    return create_table($data);
}


// Register the shortcode
add_shortcode('query', 'query_parser');

// If page slug contain 'career', add the stylesheet style.css
function add_stylesheet() {
    wp_enqueue_style('style', plugin_dir_url(__FILE__) . 'style.css');
    echo '<script>console.log("Custom Style added")</script>';
}
add_action('wp_enqueue_scripts', 'add_stylesheet');

// This shortcode will output an image with the given URL
function staff_image($atts, $content = null) {
    // Extract the email attribute
    $a = shortcode_atts(array(
        'email' => '',
    ), $atts);

    // Use the provided email or fallback to the current user's email
    $email = !empty($a['email']) ? sanitize_email($a['email']) : get_userdata(get_current_user_id())->user_email;

    if ($content == null) {
        return 'Invalid query';
    }

    // Remove new lines, <br>, tabs, and special characters
    $content = preg_replace('/[\r\n\t]+|<br\s*\/?>/', '', $content);

    // Ensure content only contains {user_email}
    if (trim($content) !== '{user_email}') {
        return 'Invalid content';
    }

    // Replace {user_email} with the actual user email
    $content = str_replace('{user_email}', $email, $content);

    // Construct the image URL based on the user email
    $url = "https://www.utm.my/directory/image.php?email=" . $email;

    // Return the image HTML
    return '<img src="' . esc_url($url) . '" alt="Staff Image" width="150px" />';
}
add_shortcode('staff_image', 'staff_image');

// This function will check if the URL contains 'career' and add check with JSON file. If user email does not exist in the JSON file, display notice to ask user to login view www.utm.my/login. Once login, user can refresh the page
function registrar_check_user_login($content) {
    $url = $_SERVER['REQUEST_URI'];
    if (strpos($url, 'borang-permohonan-jawatan-pendaftar') !== false) {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            if (!isset($_COOKIE['utmwp'])) {
                $content = '
                    <div style="display: flex; justify-content: center; align-items: center; height: 50vh;">
                        <div style="text-align: center; border: 1px solid grey; background-color: #f0f0f0; padding: 20px; display: inline-block;">
                            <p style="font-weight: bold; font-size: 20px;">Akses terhad. Sila log masuk terlebih dahulu</p>
                            <a href="https://www.utm.my/login" target="_blank">
                                <br>
                                <button style="background-color: maroon; color: white; border: none; padding: 10px 20px; font-size: 16px; cursor: pointer;">
                                    Log Masuk
                                </button>
                            </a>
                        </div>
                    </div>';
                    $content .= '
                    <script>
                    document.addEventListener("DOMContentLoaded", function() {
                        function getCookie(name) {
                            let cookieArr = document.cookie.split(";");
                            for (let i = 0; i < cookieArr.length; i++) {
                                let cookiePair = cookieArr[i].split("=");
                                if (name == cookiePair[0].trim()) {
                                    return decodeURIComponent(cookiePair[1]);
                                }
                            }
                            return null;
                        }

                        let utmwpCookie = getCookie("utmwp");
                        if (utmwpCookie) {
                            let xhr = new XMLHttpRequest();
                            xhr.open("POST", "' . admin_url('admin-ajax.php') . '", true);
                            xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
                            xhr.onreadystatechange = function() {
                                if (xhr.readyState === XMLHttpRequest.DONE && xhr.status === 200) {
                                    let response = JSON.parse(xhr.responseText);
                                    if (response.redirect) {
                                        window.location.href = "https://registrar.utm.my/wp-admin";
                                    }
                                }
                            };
                            xhr.send("action=check_utmwp_cookie");
                        }
                    });
                    </script>';
                return $content;
            } else {
                // Redirect to wp-admin login page
                wp_redirect(site_url('/wp-admin'));
                exit;
            }
        }
        // Get the current user's email
        $current_user = wp_get_current_user();
        if (!$current_user || !isset($current_user->user_email)) {
            return '
            <div class="notice" style="color:red">Unable to retrieve user information. Please log in.</div><br><br>';
        }

        $user_email = $current_user->user_email;
        $json = '[{"EMAIL_RASMI":"mohdsharil.kl@utm.my"},{"EMAIL_RASMI":"hanifah-a@utm.my"},{"EMAIL_RASMI":"noerwati@utm.my"},{"EMAIL_RASMI":"farahana@utm.my"},{"EMAIL_RASMI":"norazizah@utm.my"},{"EMAIL_RASMI":"subha@utm.my"},{"EMAIL_RASMI":"ajalil@utm.my"},{"EMAIL_RASMI":"rohaizamy@utm.my"},{"EMAIL_RASMI":"azri@utm.my"},{"EMAIL_RASMI":"mohdzaires@utm.my"},{"EMAIL_RASMI":"norly@utm.my"},{"EMAIL_RASMI":"norhalizaar@utm.my"},{"EMAIL_RASMI":"webmaster@utm.my"}]';
        $users = json_decode($json, true);
        $user_emails = array_column($users, 'EMAIL_RASMI');
        if (!in_array($user_email, $user_emails)) {
            return '
                <div style="display: flex; justify-content: center; align-items: center; height: 50vh;">
                    <div style="text-align: center; border: 1px solid grey; background-color: #f0f0f0; padding: 20px; display: inline-block;">
                        <p style="font-weight: bold; font-size: 20px;">Mohon maaf, anda tidak layak memohon.</p>
                    </div>
                </div>';
        }
    }
    return $content;
}
add_filter('the_content', 'registrar_check_user_login');

// This function will block access to dashboard for users with subscriber role and redirect them to the /career/borang-permohonan-jawatan-pendaftar page
function registrar_block_dashboard() {
    // Check if the user is in the admin area but not on an AJAX request
    if (is_admin() && !defined('DOING_AJAX')) {
        // Get the current user email
        $current_user = wp_get_current_user();
        if (!$current_user || !isset($current_user->user_email)) {
            return;
        }

        $user_email = $current_user->user_email;
        $json = '[{"EMAIL_RASMI":"mohdsharil.kl@utm.my"},{"EMAIL_RASMI":"hanifah-a@utm.my"},{"EMAIL_RASMI":"noerwati@utm.my"},{"EMAIL_RASMI":"norazizah@utm.my"},{"EMAIL_RASMI":"ajalil@utm.my"},{"EMAIL_RASMI":"azri@utm.my"},{"EMAIL_RASMI":"mohdzaires@utm.my"}]';

        // Decode the JSON and get the list of allowed emails
        $users = json_decode($json, true);
        $user_emails = array_column($users, 'EMAIL_RASMI');

        // If the user email is in the JSON file, redirect to the career page
        if (in_array($user_email, $user_emails)) {
            wp_redirect(site_url('/career/borang-permohonan-jawatan-pendaftar'));
            exit;
        }
    }
}
add_action('admin_init', 'registrar_block_dashboard');

// Register the AJAX action for logged-in users
add_action('wp_ajax_check_utmwp_cookie', 'check_utmwp_cookie');

// Register the AJAX action for non-logged-in users
add_action('wp_ajax_nopriv_check_utmwp_cookie', 'check_utmwp_cookie');

function check_utmwp_cookie() {
    if (isset($_COOKIE['utmwp'])) {
        wp_send_json_success(array('redirect' => true));
    } else {
        wp_send_json_success(array('redirect' => false));
    }
}