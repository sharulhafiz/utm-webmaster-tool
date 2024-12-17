<?php
function check_and_update_plugin() {

    $current_version = '5.23';
    $response = wp_remote_get('https://www.utm.my/api/webmastertool/update.php');
    
    if (is_wp_error($response)) {
        return;
    }

    $repo_data = json_decode(wp_remote_retrieve_body($response), true);
    $repo_version = $repo_data['version'];

    if (version_compare($current_version, $repo_version, '<')) {
        $update_url = $repo_data['update_url'];
        $tmp_file = download_url($update_url);

        if (is_wp_error($tmp_file)) {
            return;
        }

        $plugin_dir = WP_PLUGIN_DIR . '/utm-webmaster-tool';
        $result = unzip_file($tmp_file, $plugin_dir);

        if (is_wp_error($result)) {
            return;
        }

        unlink($tmp_file);
    }
}

function schedule_daily_plugin_update() {
    if (!wp_next_scheduled('daily_plugin_update_hook')) {
        wp_schedule_event(time(), 'daily', 'daily_plugin_update_hook');
    }
}

add_action('daily_plugin_update_hook', 'check_and_update_plugin');
register_activation_hook(__FILE__, 'schedule_daily_plugin_update');
register_deactivation_hook(__FILE__, 'deactivate_daily_plugin_update');

function deactivate_daily_plugin_update() {
    $timestamp = wp_next_scheduled('daily_plugin_update_hook');
    wp_unschedule_event($timestamp, 'daily_plugin_update_hook');
}