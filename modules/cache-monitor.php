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
    $today = date('Y-m-d');
    $today_log_file = $log_dir . '/cache_hits_' . $today . '.log';
    $log_files = file_exists($today_log_file) ? [$today_log_file] : [];
    $parsed_stats = parse_logs_by_site_and_status($today);

    // Calculate totals for today
    $total_hits = array_sum($parsed_stats['hits']);
    $total_misses = array_sum($parsed_stats['misses']);
    $total_requests = $total_hits + $total_misses;
    $hit_percentage = $total_requests > 0 ? round(($total_hits / $total_requests) * 100, 2) : 0;
    $miss_percentage = $total_requests > 0 ? round(($total_misses / $total_requests) * 100, 2) : 0;

    // --- Hourly grouping for bar graph ---
    $hourly = [];
    if (!empty($log_files)) {
        foreach ($log_files as $log_file) {
            $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (preg_match('/\[(\d{4}-\d{2}-\d{2}) (\d{2}):\d{2}:\d{2})\].*-\s+(\w+)/', $line, $m)) {
                    $hour = $m[2];
                    $status = strtolower($m[3]);
                    if (!isset($hourly[$hour])) $hourly[$hour] = ['hit' => 0, 'missed' => 0];
                    if ($status === 'served') {
                        $hourly[$hour]['hit']++;
                    } else {
                        $hourly[$hour]['missed']++;
                    }
                }
            }
        }
    }
    // Prepare data for JS
    $hours = [];
    $hits = [];
    $misses = [];
    for ($h = 0; $h < 24; $h++) {
        $label = str_pad($h, 2, '0', STR_PAD_LEFT);
        $hours[] = $label;
        $hits[] = isset($hourly[$label]['hit']) ? $hourly[$label]['hit'] : 0;
        $misses[] = isset($hourly[$label]['missed']) ? $hourly[$label]['missed'] : 0;
    }

    echo "<div class=\"wrap\"><h1>Cache Hit Log ($today)</h1>";

    // --- Bar graph container ---
    ?>
    <canvas id="cm-hourly-bar" width="800" height="300" style="max-width:100%;margin-bottom:2em;background:#fff;border:1px solid #eee"></canvas>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var ctx = document.getElementById('cm-hourly-bar').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($hours); ?>,
                datasets: [
                    {
                        label: 'Hits',
                        data: <?php echo json_encode($hits); ?>,
                        backgroundColor: 'rgba(54, 162, 235, 0.7)'
                    },
                    {
                        label: 'Misses',
                        data: <?php echo json_encode($misses); ?>,
                        backgroundColor: 'rgba(255, 99, 132, 0.7)'
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'top' },
                    title: { display: true, text: 'Cache Hits & Misses by Hour' }
                },
                scales: {
                    x: { title: { display: true, text: 'Hour' } },
                    y: { title: { display: true, text: 'Count' }, beginAtZero: true }
                }
            }
        });
    });
    </script>
    <?php

    // Show summary at the top
    echo '<div style="margin-bottom:2em;padding:1em;background:#f8f8f8;border:1px solid #ddd;display:inline-block;">';
    echo '<strong>Total Requests:</strong> ' . esc_html($total_requests) . '<br>';
    echo '<strong>Cache Hits:</strong> ' . esc_html($total_hits) . ' (' . esc_html($hit_percentage) . '%)<br>';
    echo '<strong>Cache Misses:</strong> ' . esc_html($total_misses) . ' (' . esc_html($miss_percentage) . '%)';
    echo '</div>';

    // List today's logs at the bottom
    echo '<pre style="margin-top:2em;">';
    if (!empty($log_files)) {
        foreach ($log_files as $log_file) {
            $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines) {
                $lines = array_reverse($lines); // Show latest at the top
                echo esc_html(implode("\n", $lines));
            }
        }
    } else {
        echo 'No data logged yet for today.';
    }
    echo '</pre></div>';
}

function parse_logs_by_site_and_status($date = null) {
    $stats = [];
    $pattern = WP_CONTENT_DIR . '/cache_logs/cache_hits_*.log';
    if ($date) {
        $pattern = WP_CONTENT_DIR . '/cache_logs/cache_hits_' . $date . '.log';
    }
    foreach (glob($pattern) as $file) {
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
    // Prepare data for summary
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

