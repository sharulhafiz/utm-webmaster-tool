<?php
// File: /g:/My Drive/Projects/plugins/utm-webmaster-tool/modules/backup.php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class UTM_Webmaster_Tool_Backup {

    public function __construct() {
        add_action( 'admin_init', array( $this, 'schedule_backup' ) );
        add_action( 'utm_webmaster_tool_daily_backup', array( $this, 'backup_database' ) );
        add_action( 'admin_menu', array( $this, 'add_backup_page' ) );
        add_action( 'wp_ajax_utm_initiate_backup', array( $this, 'ajax_initiate_backup' ) );
        add_action( 'admin_post_utm_download_backup', array( $this, 'download_backup_file' ) );
    }

    public function schedule_backup() {
        $next_scheduled = wp_next_scheduled( 'utm_webmaster_tool_daily_backup' );
        if ( $next_scheduled ) {
            $scheduled_hour = date( 'H', $next_scheduled );
            // Only allow backups between 22:00 and 06:00
            if ( $scheduled_hour < 22 && $scheduled_hour >= 6 ) {
                wp_clear_scheduled_hook( 'utm_webmaster_tool_daily_backup' );
                $this->schedule_new_backup();
            }
        } else {
            $this->schedule_new_backup();
        }
    }

    private function schedule_new_backup() {
        $random_time = $this->get_random_time_in_range(22, 6);
        wp_schedule_event( $random_time, 'daily', 'utm_webmaster_tool_daily_backup' );
    }

    private function get_random_time_in_range($start_hour, $end_hour) {
        $now = time();
        $today_start = strtotime(date('Y-m-d 00:00:00', $now));
    
        $start_time = $today_start + ($start_hour * 3600); // Start hour of today
        $end_time = $today_start + ($end_hour * 3600); // End hour of today
    
        if ($end_time <= $start_time) {
            $end_time += 86400; // Add 24 hours if end time is earlier than start time (crosses midnight)
        }
    
        $random_timestamp = rand($start_time, $end_time);
    
        // If the random time is in the past, schedule it for tomorrow
        if ($random_timestamp < $now) {
            $random_timestamp += 86400; // Add 24 hours
        }
    
        return $random_timestamp;
    }

    public function backup_database() {
        global $wpdb;
    
        $upload_dir = wp_upload_dir();
        $timestamp = date('Ymd'); // Changed to format 20250320
        $backup_file = $upload_dir['basedir'] . '/db-backup-' . $timestamp . '.sql';
        $error_log_file = $upload_dir['basedir'] . '/db-backup-error-log.txt';
    
        try {
            $handle = fopen( $backup_file, 'w+' );
            if ( ! $handle ) {
                throw new Exception( 'Could not open backup file for writing.' );
            }
    
            foreach ( $wpdb->tables as $table ) {
                $table = $wpdb->prefix . $table; // Add the prefix to the table name
                $create_table = $wpdb->get_row( "SHOW CREATE TABLE $table", ARRAY_N );
                fwrite( $handle, $create_table[1] . ";\n\n" );
    
                $rows = $wpdb->get_results( "SELECT * FROM $table", ARRAY_N );
                foreach ( $rows as $row ) {
                    $values = array_map( array( $wpdb, 'escape' ), $row );
                    $values = implode( "', '", $values );
                    fwrite( $handle, "INSERT INTO $table VALUES ('$values');\n" );
                }
                fwrite( $handle, "\n\n" );
            }
    
            fclose( $handle );
        } catch ( Exception $e ) {
            file_put_contents( $error_log_file, date( 'Y-m-d H:i:s' ) . ' - ' . $e->getMessage() . "\n", FILE_APPEND );
        }
    }

    public function add_backup_page() {
        add_submenu_page(
            'tools.php',
            'Database Backups',
            'Database Backups',
            'manage_options',
            'utm-database-backups',
            array( $this, 'display_backup_page' )
        );
    }

    public function display_backup_page() {
        $upload_dir = wp_upload_dir();
        $backup_dir = $upload_dir['basedir'];
        $backup_files = glob( $backup_dir . '/db-backup-*.sql' );
        $error_log_file = $backup_dir . '/db-backup-error-log.txt';

        // Get the next scheduled backup time
        $next_scheduled = wp_next_scheduled( 'utm_webmaster_tool_daily_backup' );
        $next_scheduled_time = $next_scheduled ? date( 'Y-m-d H:i:s', $next_scheduled ) : 'No scheduled backup';

        echo '<div class="wrap"><h2>Database Backups</h2>';
        echo '<p>Current time: ' . date( 'Y-m-d H:i:s' ) . '</p>';
        echo '<p>Next scheduled backup: ' . esc_html( $next_scheduled_time ) . '</p>';
        echo '<button id="initiate-backup" class="button button-primary">Initiate Backup Now</button>';
        echo '<table class="widefat"><thead><tr><th>Backup File</th><th>Download</th></tr></thead><tbody>';
        if ( empty( $backup_files ) ) {
            echo '<tr><td colspan="2">No backups found.</td></tr>';
        } else {
            foreach ( $backup_files as $file ) {
                $file_name = basename( $file );
                $file_url = admin_url( 'admin-post.php?action=utm_download_backup&file=' . urlencode( $file_name ) );
                echo '<tr><td>' . esc_html( $file_name ) . '</td><td><a href="' . esc_url( $file_url ) . '" class="button">Download</a></td></tr>';
            }
        }
        echo '</tbody></table></div>';

        // Display error log
        echo '<div class="wrap"><h2>Backup Error Log</h2>';
        if ( file_exists( $error_log_file ) ) {
            $error_log = file_get_contents( $error_log_file );
            if ( ! empty( $error_log ) ) {
                echo '<pre>' . esc_html( $error_log ) . '</pre>';
            } else {
                echo '<p>No errors logged.</p>';
            }
        } else {
            echo '<p>No errors logged.</p>';
        }
        echo '</div>';
    
        // Add JavaScript to handle the button click
        echo '<script type="text/javascript">
            jQuery(document).ready(function($) {
                $("#initiate-backup").on("click", function() {
                    $.post(ajaxurl, { action: "utm_initiate_backup" }, function(response) {
                        if (response.success) {
                            alert("Backup initiated successfully.");
                            location.reload();
                        } else {
                            alert("An error occurred while initiating the backup.");
                        }
                    });
                });
            });
        </script>';
    }

    public function ajax_initiate_backup() {
        $this->backup_database();
        wp_send_json_success();
    }

    public function download_backup_file() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have sufficient permissions to access this page.' );
        }
    
        if ( ! isset( $_GET['file'] ) || empty( $_GET['file'] ) ) {
            wp_die( 'No file specified.' );
        }
    
        $file = sanitize_text_field( wp_unslash( $_GET['file'] ) );
        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['basedir'] . '/' . $file;
    
        if ( ! file_exists( $file_path ) ) {
            wp_die( 'File not found.' );
        }
    
        header( 'Content-Description: File Transfer' );
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename=' . basename( $file_path ) );
        header( 'Expires: 0' );
        header( 'Cache-Control: must-revalidate' );
        header( 'Pragma: public' );
        header( 'Content-Length: ' . filesize( $file_path ) );
    
        readfile( $file_path );
        exit;
    }
}

new UTM_Webmaster_Tool_Backup();