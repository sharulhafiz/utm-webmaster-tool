<?php
/*
* Logger Endpoint via REST API
*/

add_action('wp_enqueue_scripts', function() {
    wp_enqueue_script(
        'cache-monitor-beacon',
        plugins_url('assets/js/utmbeacon.js', dirname(__FILE__)),
        [],
        utm_plugin_version,
        true
    );
});

add_action('rest_api_init', function () {
    register_rest_route('cache-monitor/v1', '/hit', [
        'methods' => 'POST',
        'callback' => 'cm_log_cache_hit',
        'permission_callback' => '__return_true'
    ]);
});

function cm_log_cache_hit($request){
    $data = $request->get_json_params();
    if (!$data || !isset($data['url'])) {
        // Try to parse raw body as JSON (for sendBeacon)
        $raw = $request->get_body();
        $data = json_decode($raw, true);
    }
    if (!$data || !isset($data['url'])) return new WP_REST_Response(null, 400);

    $log_dir = WP_CONTENT_DIR . '/cache_logs';
    if (!file_exists($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    $log_file = $log_dir . '/cache_hits_' . date('Y-m-d') . '.log';

    $site_id = get_current_blog_id();
    $dt = new DateTime('@' . ($data['timestamp'] / 1000));
    $dt->setTimezone(new DateTimeZone('Asia/Kuala_Lumpur')); // GMT+8
    $log_time = $dt->format('Y-m-d H:i:s');
    $log_entry = sprintf(
        "[%s] Site #%d %s - %s\n",
        $log_time,
        $site_id,
        $data['url'],
        $data['status']
    );

    file_put_contents($log_file, $log_entry, FILE_APPEND);
    return new WP_REST_Response(null, 204);
}

// Dashboard Page in Multisite or Single Site
if (is_multisite()) {
    add_action('network_admin_menu', function () {
        add_menu_page('Cache Monitor', 'Cache Monitor', 'manage_network', 'cache-monitor', 'cm_render_dashboard');
    });
} else {
    add_action('admin_menu', function () {
        add_management_page('Cache Monitor', 'Cache Monitor', 'manage_options', 'cache-monitor', 'cm_render_dashboard');
    });
}

function cm_render_dashboard()
{
    $log_dir = WP_CONTENT_DIR . '/cache_logs';
    $log_files = glob($log_dir . '/*.log');
    $parsed_stats = parse_logs_by_site_and_status();

    // Calculate totals
    $total_hits = array_sum($parsed_stats['hits']);
    $total_misses = array_sum($parsed_stats['misses']);
    $total_requests = $total_hits + $total_misses;
    $hit_percentage = $total_requests > 0 ? round(($total_hits / $total_requests) * 100, 2) : 0;
    $miss_percentage = $total_requests > 0 ? round(($total_misses / $total_requests) * 100, 2) : 0;

    echo '<div class="wrap"><h1>Cache Hit Log</h1>';

    // Show summary at the top
    echo '<div style="margin-bottom:2em;padding:1em;background:#f8f8f8;border:1px solid #ddd;display:inline-block;">';
    echo '<strong>Total Requests:</strong> ' . esc_html($total_requests) . '<br>';
    echo '<strong>Cache Hits:</strong> ' . esc_html($total_hits) . ' (' . esc_html($hit_percentage) . '%)<br>';
    echo '<strong>Cache Misses:</strong> ' . esc_html($total_misses) . ' (' . esc_html($miss_percentage) . '%)';
    echo '</div>';

    // List logs at the bottom
    echo '<pre style="margin-top:2em;">';
    if (!empty($log_files)) {
        foreach ($log_files as $log_file) {
            echo esc_html(file_get_contents($log_file));
        }
    } else {
        echo 'No data logged yet.';
    }
    echo '</pre></div>';
}

function parse_logs_by_site_and_status() {
    $stats = [];
    foreach (glob(WP_CONTENT_DIR . '/cache_logs/cache_hits_*.log') as $file) {
        foreach (file($file) as $line) {
            if (preg_match('/Site #(\d+)\s+([^\s]+)\s+-\s+([^\s]+)/', $line, $match)) {
                $site_id = $match[1];
                $status = strtolower(trim($match[3]));
                if (!isset($stats[$site_id])) {
                    $stats[$site_id] = ['hit' => 0, 'missed' => 0];
                }
                if ($status === 'served') {
                    $stats[$site_id]['hit']++;
                } else {
                    $stats[$site_id]['missed']++;
                }
            }
        }
    }
    // Prepare data for Chart.js
    $labels = array_keys($stats);
    $hits = [];
    $misses = [];
    foreach ($labels as $site_id) {
        $hits[] = $stats[$site_id]['hit'];
        $misses[] = $stats[$site_id]['missed'];
    }
    return [
        'labels' => $labels,
        'hits' => $hits,
        'misses' => $misses
    ];
}