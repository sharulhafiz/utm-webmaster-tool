<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Register settings
function dd_etcache_register_settings() {
    add_option('dd_etcache_cron_time', '00:00');
    register_setting('dd_etcache_options_group', 'dd_etcache_cron_time');
}
add_action('admin_init', 'dd_etcache_register_settings');

// Create settings page
function dd_etcache_register_options_page() {
    add_options_page('Daily Delete ETCACHE', 'Daily Delete ETCACHE', 'manage_options', 'dd-etcache', 'dd_etcache_options_page');
}
add_action('admin_menu', 'dd_etcache_register_options_page');

function dd_etcache_options_page() {
?>
    <div>
    <h2>Daily Delete of ETCACHE for DIVI</h2>
    <p>Divi utilizes and stores cache in a folder called wp-content/et-cache. For some hosts this often causes issues with inode or size limitations. This simple plugin will create daily task in wordpress to delete this folder. It will log the last 25 times the script is run. You can also run it manually. Please note that you are using this plugin at your own risk, however it has been significantly tested and not caused any issues. The plugin has been scripted to remove the daily task upon deactivation. Thanks! - Leland</p>
    <form method="post" action="options.php">
    <?php settings_fields('dd_etcache_options_group'); ?>
    <table>
    <tr valign="top">
    <th scope="row"><label for="dd_etcache_cron_time">Time to run (24-hour format)</label></th>
    <td><input type="time" id="dd_etcache_cron_time" name="dd_etcache_cron_time" value="<?php echo get_option('dd_etcache_cron_time'); ?>" /></td>
    </tr>
    </table>
    <?php submit_button(); ?>
    </form>
    <form method="post" action="">
    <?php submit_button('Run Script Now', 'primary', 'run_script_now'); ?>
    </form>
    <h3>Deletion Log</h3>
    <ul>
    <?php
    $log = get_option('dd_etcache_deletion_log', []);
    foreach ($log as $entry) {
        echo '<li>' . esc_html($entry) . '</li>';
    }
    ?>
    </ul>
    </div>
<?php
}

// Handle manual script run
if (isset($_POST['run_script_now'])) {
    dd_etcache_run_script();
}

// Function to delete the folder and log the result
function dd_etcache_run_script() {
    $dir = ABSPATH . 'wp-content/et-cache';
    $success = dd_etcache_delete_directory($dir);
    $log = get_option('dd_etcache_deletion_log', []);
    $timestamp = current_time('mysql');
    $log_entry = $timestamp . ' - ' . ($success ? 'Success' : 'Failure');
    array_unshift($log, $log_entry);
    if (count($log) > 25) {
        array_pop($log);
    }
    update_option('dd_etcache_deletion_log', $log);
}

function dd_etcache_delete_directory($dir) {
    if (!file_exists($dir)) {
        return false;
    }
    if (!is_dir($dir)) {
        return unlink($dir);
    }
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') {
            continue;
        }
        if (!dd_etcache_delete_directory($dir . DIRECTORY_SEPARATOR . $item)) {
            return false;
        }
    }
    return rmdir($dir);
}

// Schedule the cron job
function dd_etcache_schedule_cron() {
    if (!wp_next_scheduled('dd_etcache_daily_event')) {
        $time = get_option('dd_etcache_cron_time', '00:00');
        list($hour, $minute) = explode(':', $time);
        $timestamp = mktime($hour, $minute, 0);
        wp_schedule_event($timestamp, 'daily', 'dd_etcache_daily_event');
    }
}
add_action('wp', 'dd_etcache_schedule_cron');

// Handle the cron job
add_action('dd_etcache_daily_event', 'dd_etcache_run_script');

// Reschedule the cron job if the time setting is changed
function dd_etcache_reschedule_cron() {
    if (wp_next_scheduled('dd_etcache_daily_event')) {
        wp_clear_scheduled_hook('dd_etcache_daily_event');
    }
    dd_etcache_schedule_cron();
}
add_action('update_option_dd_etcache_cron_time', 'dd_etcache_reschedule_cron');

// Function to clear the scheduled cron job on deactivation
function dd_etcache_deactivate() {
    $timestamp = wp_next_scheduled('dd_etcache_daily_event');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'dd_etcache_daily_event');
    }
}
register_deactivation_hook(__FILE__, 'dd_etcache_deactivate');

// Clear the cron job on plugin activation to ensure proper scheduling
function dd_etcache_activate() {
    dd_etcache_deactivate();
    dd_etcache_schedule_cron();
}
register_activation_hook(__FILE__, 'dd_etcache_activate');

?>