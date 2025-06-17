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
    echo '<div class="wrap"><h1>Cache Hit Log</h1><pre>';
    if (!empty($log_files)) {
        foreach ($log_files as $log_file) {
            echo esc_html(file_get_contents($log_file));
        }
    } else {
        echo 'No data logged yet.';
    }
    echo '</pre></div>';
    $parsed_stats = parse_logs_by_site_and_status();

    ?>
    <canvas id="cacheChart" width="800" height="400"></canvas>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    const chartData = <?php echo json_encode($parsed_stats); ?>;

    const ctx = document.getElementById('cacheChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: chartData.labels,
            datasets: [
                {
                    label: 'Cache Hits',
                    data: chartData.hits,
                    backgroundColor: 'rgba(75, 192, 192, 0.6)'
                },
                {
                    label: 'Cache Misses',
                    data: chartData.misses,
                    backgroundColor: 'rgba(255, 99, 132, 0.6)'
                }
            ]
        },
        options: { responsive: true }
    });
    </script>
    <?php
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