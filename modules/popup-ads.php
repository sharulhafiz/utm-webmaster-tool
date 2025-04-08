<?php
function enqueue_popup_script() {
    // Get the user's IP address
    $user_ip = '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $user_ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]); // Get the first IP in the list
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $user_ip = $_SERVER['REMOTE_ADDR'];
    }

    // Define UTM network IP ranges (CIDR format)
    $utm_ip_ranges = [
        '10.0.0.0/8', // Private IP range
        '161.139.0.0/16', // Server IP range
    ];

    // Check if the user's IP belongs to the UTM network
    if (is_ip_in_ranges($user_ip, $utm_ip_ranges)) {
        return; // User is on the UTM network, do not show popup
    }

    // Open this link in a new tab, once per day
    $popup_cookie_name = 'utm_popup';
    $popup_url = 'https://s.shopee.com.my/5pug3IftXz'; // Replace with your URL
    if (!isset($_COOKIE[$popup_cookie_name])) {
        echo '<script type="text/javascript">window.open("' . $popup_url . '", "_blank");</script>';
        setcookie($popup_cookie_name, '1', time() + 86400, "/");
        // register event in Google Analytics
        echo '<script type="text/javascript">
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({
                "event": "popup_opened",
                "popup_url": "' . $popup_url . '"
            });
        </script>';
    }
}
add_action('wp_footer', 'enqueue_popup_script');

/**
 * Check if an IP address is in a list of CIDR ranges.
 *
 * @param string $ip The IP address to check.
 * @param array $ranges An array of CIDR ranges.
 * @return bool True if the IP is in any of the ranges, false otherwise.
 */
function is_ip_in_ranges($ip, $ranges) {
    foreach ($ranges as $range) {
        if (ip_in_range($ip, $range)) {
            return true;
        }
    }
    return false;
}

/**
 * Check if an IP address is in a CIDR range.
 *
 * @param string $ip The IP address to check.
 * @param string $cidr The CIDR range (e.g., '192.168.0.0/24').
 * @return bool True if the IP is in the range, false otherwise.
 */
function ip_in_range($ip, $cidr) {
    list($subnet, $mask) = explode('/', $cidr);
    return (ip2long($ip) & ~((1 << (32 - $mask)) - 1)) === ip2long($subnet);
}