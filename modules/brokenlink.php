<?php
/*
UTM Webmaster Tool - Broken Link Monitor
Monitor 404 errors and display a summary in the admin area.
*/

// Hook into 404 template
add_action('template_redirect', 'utm_log_404_errors');

function utm_log_404_errors() {
    if (!is_404()) {
        return;
    }

    // Use REQUEST_URI for better accuracy and avoid global $wp
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    // Fast static extension check (no regex)
    $static_exts = ['jpg','jpeg','png','gif','css','js','ico','svg'];
    $ext = strtolower(pathinfo(parse_url($request_uri, PHP_URL_PATH), PATHINFO_EXTENSION));
    if (in_array($ext, $static_exts, true)) {
        return;
    }

    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/404_log.txt';
    $current_url = home_url($request_uri);
    $referer = $_SERVER['HTTP_REFERER'] ?? 'Direct Access';
    $referer = parse_url($referer, PHP_URL_PATH);
    $log_entry = date('Y-m-d H:i:s') . " - " . $current_url . " - Referer: " . $referer . "\n";
    // Only write if file is writable or doesn't exist
    if (!file_exists($log_file) || is_writable($log_file)) {
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
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

    // Keep last 7 days of logs
    if (file_exists($log_file)) {
        $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $new_lines = array();
        $seven_days_ago = strtotime('-7 days');

        foreach ($lines as $line) {
            $timestamp = strtotime(substr($line, 0, 19));
            if ($timestamp >= $seven_days_ago) {
                $new_lines[] = $line;
            }
        }

        file_put_contents($log_file, implode("\n", $new_lines));
    }

    if (!file_exists($log_file)) {
        echo '<div class="wrap"><h2>404 Log</h2><p>No 404 errors logged yet.</p></div>';
        return;
    }

    $log_entries = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $log_summary = array();

    foreach ($log_entries as $entry) {
        list($timestamp, $url, $referer) = explode(' - ', $entry);
        // if url contains a static file extension, skip it
        $static_exts = ['jpg','jpeg','png','gif','css','js','ico','svg'];
        $ext = strtolower(pathinfo($url, PATHINFO_EXTENSION));
        if (in_array($ext, $static_exts, true)) {
            continue;
        }
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
        // Strip query parameters from referers
        $referers = preg_replace('/\?.*/', '', $referers);
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