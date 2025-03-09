<?php
/*
UTM Webmaster Tool - Broken Link Monitor
Monitor 404 errors and display a summary in the admin area.
*/

// Hook into 404 template
add_action('template_redirect', 'utm_log_404_errors');

function utm_log_404_errors() {
    global $wp;
    if (is_404()) {
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/404_log.txt';
        $current_url = home_url(add_query_arg(array(), $wp->request));
        $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'Direct Access';
        $log_entry = date('Y-m-d H:i:s') . " - " . $current_url . " - Referer: " . $referer . "\n";
        file_put_contents($log_file, $log_entry, FILE_APPEND);
    }
}

// Add admin menu
add_action('admin_menu', 'utm_404_log_menu');

function utm_404_log_menu() {
    add_submenu_page('tools.php', '404 Log', '404 Log', 'manage_options', 'utm-404-log', 'utm_404_log_page');
}

function utm_404_log_page() {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/404_log.txt';

    if (!file_exists($log_file)) {
        echo '<div class="wrap"><h2>404 Log</h2><p>No 404 errors logged yet.</p></div>';
        return;
    }

    $log_entries = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $log_summary = array();

    foreach ($log_entries as $entry) {
        list($timestamp, $url, $referer) = explode(' - ', $entry);
        if (!isset($log_summary[$url])) {
            $log_summary[$url] = array('count' => 0, 'referers' => array());
        }
        $log_summary[$url]['count']++;
        $log_summary[$url]['referers'][] = $referer;
    }

    arsort($log_summary);

    echo '<div class="wrap"><h2>404 Log</h2><table class="widefat"><thead><tr><th>URL</th><th>Count</th><th>Referers</th></tr></thead><tbody>';
    foreach ($log_summary as $url => $data) {
        $referers = implode(', ', array_unique($data['referers']));
        echo '<tr><td>' . esc_html($url) . '</td><td>' . esc_html($data['count']) . '</td><td>' . esc_html($referers) . '</td></tr>';
    }
    echo '</tbody></table></div>';

    echo '<div class="wrap"><h2>Download Raw Log</h2><p><a href="' . esc_url($log_file) . '">Download 404 Log</a></p></div>';

    // Clear log
    if (isset($_GET['clear']) && $_GET['clear'] == 'true') {
        unlink($log_file);
        echo '<div class="updated"><p>Log cleared.</p></div>';
    }
    echo '<div class="wrap"><h2>Clear Log</h2><p><a href="?page=utm-404-log&clear=true">Clear 404 Log</a></p></div>';
}