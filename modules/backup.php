<?php
// File: /g:/My Drive/Projects/plugins/utm-webmaster-tool/modules/backup.php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class UTM_Webmaster_Tool_Backup {

    public function __construct() {
        add_action( 'init', array( $this, 'schedule_backup' ) );
        add_action( 'utm_webmaster_tool_daily_backup', array( $this, 'backup_database' ) );
        add_action( 'admin_menu', array( $this, 'add_backup_page' ) );
        add_action( 'wp_ajax_utm_initiate_backup', array( $this, 'ajax_initiate_backup' ) );
    }

    public function schedule_backup() {
        if ( ! wp_next_scheduled( 'utm_webmaster_tool_daily_backup' ) ) {
            wp_schedule_event( time(), 'daily', 'utm_webmaster_tool_daily_backup' );
        }
    }

    public function backup_database() {
        global $wpdb;

        $upload_dir = wp_upload_dir();
        $backup_file = $upload_dir['basedir'] . '/db-backup-' . time() . '.sql';
        $error_log_file = $upload_dir['basedir'] . '/db-backup-error-log.txt';

        try {
            if ( is_multisite() ) {
                $blog_id = get_current_blog_id();
                $tables = $wpdb->get_results( "SHOW TABLES LIKE '{$wpdb->base_prefix}{$blog_id}_%'", ARRAY_N );
            } else {
                $tables = $wpdb->get_results( 'SHOW TABLES', ARRAY_N );
            }

            $handle = fopen( $backup_file, 'w+' );
            if ( ! $handle ) {
                throw new Exception( 'Could not open backup file for writing.' );
            }

            foreach ( $tables as $table ) {
                $table = $table[0];
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
                $file_url = $upload_dir['baseurl'] . '/' . basename( $file );
                echo '<tr><td>' . esc_html( basename( $file ) ) . '</td><td><a href="' . esc_url( $file_url ) . '" class="button">Download</a></td></tr>';
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
}

new UTM_Webmaster_Tool_Backup();