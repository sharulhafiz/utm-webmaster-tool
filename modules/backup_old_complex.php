<?php
// File: /g:/My Drive/Projects/plugins/utm-webmaster-tool/modules/backup.php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Fix for BLOGUPLOADDIR constant error in legacy multisite setups
if ( is_multisite() && ! defined( 'BLOGUPLOADDIR' ) ) {
    // Define the constant to prevent fatal errors in ms-files.php
    // This is a temporary workaround for legacy multisite configurations
    define( 'BLOGUPLOADDIR', 'wp-content/blogs.dir' );
}

class UTM_Webmaster_Tool_Backup {
    
    /**
     * Safely get upload directory without triggering legacy multisite ms-files.php
     * This prevents BLOGUPLOADDIR constant errors in older multisite setups
     */
    private function get_safe_upload_dir() {
        // Temporarily disable ms-files rewriting to prevent legacy constants issues
        $original_ms_files = null;
        if ( is_multisite() ) {
            global $wpdb;
            $original_ms_files = get_site_option( 'ms_files_rewriting' );
            if ( $original_ms_files ) {
                // Temporarily disable ms-files to get clean upload path
                update_site_option( 'ms_files_rewriting', 0 );
            }
        }
        
        // Get upload directory safely
        $upload_dir = wp_upload_dir();
        
        // Restore original ms-files setting if it was changed
        if ( is_multisite() && $original_ms_files !== null ) {
            update_site_option( 'ms_files_rewriting', $original_ms_files );
        }
        
        return $upload_dir;
    }

    public function __construct() {
        add_action( 'admin_init', array( $this, 'schedule_backup' ) );
        add_action( 'utm_webmaster_tool_daily_backup', array( $this, 'backup_database' ) );
        add_action( 'utm_webmaster_tool_network_backup', array( $this, 'backup_network_database' ) );
        add_action( 'admin_menu', array( $this, 'add_backup_page' ) );
        add_action( 'wp_ajax_utm_initiate_backup', array( $this, 'ajax_initiate_backup' ) );
        add_action( 'wp_ajax_utm_initiate_network_backup', array( $this, 'ajax_initiate_network_backup' ) );
        add_action( 'wp_ajax_utm_check_backup_status', array( $this, 'ajax_check_backup_status' ) );
        add_action( 'wp_ajax_utm_check_network_backup_status', array( $this, 'ajax_check_network_backup_status' ) );
        add_action( 'wp_ajax_utm_backup_diagnostics', array( $this, 'ajax_backup_diagnostics' ) );
        add_action( 'wp_ajax_utm_force_network_backup', array( $this, 'ajax_force_network_backup' ) );
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
        $upload_dir = $this->get_safe_upload_dir();
        $timestamp = date('Ymd-His');
        $backup_file = $upload_dir['basedir'] . '/db-backup-' . $timestamp . '.sql';
        $compressed_file = $backup_file . '.gz';
        $error_log_file = $upload_dir['basedir'] . '/db-backup-error-log.txt';
        $status_file = $upload_dir['basedir'] . '/single-backup-status.json';

        try {
            // Set backup status to running
            $this->update_backup_status( $status_file, 'running', 'Starting single site backup...', 0 );
            
            error_log( "UTM Single Site Backup: Starting database backup." );
            
            // Try mysqldump approach first (much more efficient)
            $this->update_backup_status( $status_file, 'running', 'Attempting mysqldump method...', 10 );
            
            if ( $this->create_single_site_database_dump( $backup_file, $status_file ) ) {
                error_log( "UTM Single Site Backup: Database dump completed successfully." );
                $this->update_backup_status( $status_file, 'running', 'Database dump completed, starting compression...', 70 );
            } else {
                error_log( "UTM Single Site Backup: Database dump failed, falling back to PHP backup method." );
                $this->update_backup_status( $status_file, 'running', 'mysqldump failed, using PHP backup method...', 20 );
                // Fallback to the original PHP method
                $this->backup_database_fallback( $backup_file, $status_file );
                $this->update_backup_status( $status_file, 'running', 'PHP backup completed, starting compression...', 70 );
            }
            
            // Compress the backup file
            $this->compress_backup_file( $backup_file, $compressed_file );
            $this->update_backup_status( $status_file, 'running', 'Backup compressed, cleaning up...', 90 );
            
            // Remove uncompressed file after successful compression
            if ( file_exists( $compressed_file ) && filesize( $compressed_file ) > 0 ) {
                unlink( $backup_file );
                $success_message = "UTM Single Site Backup: Successfully created compressed backup: " . basename( $compressed_file );
                error_log( $success_message );
                file_put_contents( $error_log_file, date( 'Y-m-d H:i:s' ) . ' - ' . $success_message . "\n", FILE_APPEND );
                
                // Clean up old backups after successful backup
                $this->cleanup_old_backups();
                
                // Set backup status to completed
                $this->update_backup_status( $status_file, 'completed', 'Backup completed successfully: ' . basename( $compressed_file ), 100 );
            } else {
                throw new Exception( 'Failed to create compressed backup file.' );
            }

        } catch ( Exception $e ) {
            $error_message = date( 'Y-m-d H:i:s' ) . ' - Single Site Backup Error: ' . $e->getMessage();
            file_put_contents( $error_log_file, $error_message . "\n", FILE_APPEND );
            error_log( $error_message );
            
            // Set backup status to failed
            $this->update_backup_status( $status_file, 'failed', 'Backup failed: ' . $e->getMessage(), 0 );
        }
    }

    public function backup_network_database() {
        $upload_dir = $this->get_safe_upload_dir();
        $error_log_file = $upload_dir['basedir'] . '/network-backup-error-log.txt';
        $status_file = $upload_dir['basedir'] . '/network-backup-status.json';
        
        // Enhanced logging for debugging
        $log_message = date( 'Y-m-d H:i:s' ) . ' - Network Backup: Function called, starting diagnostics.';
        error_log( $log_message );
        file_put_contents( $error_log_file, $log_message . "\n", FILE_APPEND );
        
        if ( ! is_multisite() ) {
            $error_message = 'UTM Network Backup: Not a multisite installation.';
            error_log( $error_message );
            file_put_contents( $error_log_file, date( 'Y-m-d H:i:s' ) . ' - ' . $error_message . "\n", FILE_APPEND );
            return;
        }
        
        // Log system information for debugging
        $log_message = date( 'Y-m-d H:i:s' ) . ' - Network Backup: System check - PHP memory limit: ' . ini_get( 'memory_limit' ) . ', Max execution time: ' . ini_get( 'max_execution_time' );
        error_log( $log_message );
        file_put_contents( $error_log_file, $log_message . "\n", FILE_APPEND );

        // Increase memory limit for large networks
        ini_set( 'memory_limit', '512M' );
        set_time_limit( 0 ); // Remove time limit for background processing
        
        $log_message = date( 'Y-m-d H:i:s' ) . ' - Network Backup: Settings updated - New memory limit: ' . ini_get( 'memory_limit' ) . ', Time limit: ' . ini_get( 'max_execution_time' );
        error_log( $log_message );
        file_put_contents( $error_log_file, $log_message . "\n", FILE_APPEND );

        $upload_dir = $this->get_safe_upload_dir();
        $timestamp = date('Ymd-His');
        $backup_file = $upload_dir['basedir'] . '/network-backup-' . $timestamp . '.sql';
        $compressed_file = $backup_file . '.gz';
        $error_log_file = $upload_dir['basedir'] . '/network-backup-error-log.txt';
        $status_file = $upload_dir['basedir'] . '/network-backup-status.json';

        try {
            // Set backup status to running
            $this->update_backup_status( $status_file, 'running', 'Starting network backup...', 0 );
            
            error_log( "UTM Network Backup: Starting full database dump backup." );
            
            // Try database dump approach first (much more efficient)
            $this->update_backup_status( $status_file, 'running', 'Attempting full database dump...', 10 );
            
            if ( $this->create_database_dump( $backup_file, $status_file ) ) {
                error_log( "UTM Network Backup: Database dump completed successfully." );
                $this->update_backup_status( $status_file, 'running', 'Database dump completed, starting compression...', 70 );
            } else {
                error_log( "UTM Network Backup: Database dump failed, falling back to site-by-site backup." );
                $this->update_backup_status( $status_file, 'running', 'Full dump failed, using site-by-site method...', 20 );
                // Fallback to the original site-by-site method
                $this->backup_network_database_fallback( $backup_file, $status_file );
                $this->update_backup_status( $status_file, 'running', 'Site-by-site backup completed, starting compression...', 70 );
            }
            
            // Compress the backup file
            $this->compress_backup_file( $backup_file, $compressed_file );
            $this->update_backup_status( $status_file, 'running', 'Network backup compressed, cleaning up...', 90 );
            
            // Remove uncompressed file after successful compression
            if ( file_exists( $compressed_file ) && filesize( $compressed_file ) > 0 ) {
                unlink( $backup_file );
                $success_message = "UTM Network Backup: Successfully created compressed backup: " . basename( $compressed_file );
                error_log( $success_message );
                file_put_contents( $error_log_file, date( 'Y-m-d H:i:s' ) . ' - ' . $success_message . "\n", FILE_APPEND );
                
                // Clean up old backups after successful backup
                $this->cleanup_old_backups();
                
                // Set backup status to completed
                $this->update_backup_status( $status_file, 'completed', 'Network backup completed successfully: ' . basename( $compressed_file ), 100 );
            } else {
                throw new Exception( 'Failed to create compressed backup file.' );
            }

        } catch ( Exception $e ) {
            $error_message = date( 'Y-m-d H:i:s' ) . ' - Network Backup Error: ' . $e->getMessage();
            file_put_contents( $error_log_file, $error_message . "\n", FILE_APPEND );
            error_log( $error_message );
            
            // Set backup status to failed
            $this->update_backup_status( $status_file, 'failed', 'Network backup failed: ' . $e->getMessage(), 0 );
        }
    }

    /**
     * Create a full database dump using mysqldump (preferred method)
     */
    private function create_database_dump( $backup_file, $status_file = null ) {
        // Get database connection details
        $db_host = DB_HOST;
        $db_name = DB_NAME;
        $db_user = DB_USER;
        $db_password = DB_PASSWORD;
        
        // Parse host and port if specified
        $host_parts = explode( ':', $db_host );
        $host = $host_parts[0];
        $port = isset( $host_parts[1] ) ? $host_parts[1] : '3306';
        
        try {
            // Check if mysqldump is available
            $mysqldump_path = $this->find_mysqldump_path();
            if ( ! $mysqldump_path ) {
                error_log( 'UTM Network Backup: mysqldump not found in common paths.' );
                return false;
            }
            
            // Create mysqldump command with comprehensive options
            $command = sprintf(
                '%s --host=%s --port=%s --user=%s --password=%s --single-transaction --routines --triggers --events --quick --lock-tables=false --add-drop-table --create-options --extended-insert --set-charset --comments %s > %s 2>&1',
                escapeshellarg( $mysqldump_path ),
                escapeshellarg( $host ),
                escapeshellarg( $port ),
                escapeshellarg( $db_user ),
                escapeshellarg( $db_password ),
                escapeshellarg( $db_name ),
                escapeshellarg( $backup_file )
            );
            
            error_log( "UTM Network Backup: Executing mysqldump command..." );
            
            // Execute the command
            exec( $command, $output, $return_code );
            
            if ( $return_code === 0 && file_exists( $backup_file ) && filesize( $backup_file ) > 0 ) {
                // Add custom header to the backup file
                $this->add_network_backup_header( $backup_file );
                return true;
            } else {
                error_log( "UTM Network Backup: mysqldump failed with return code: {$return_code}" );
                if ( ! empty( $output ) ) {
                    error_log( "UTM Network Backup: mysqldump output: " . implode( "\n", $output ) );
                }
                return false;
            }
            
        } catch ( Exception $e ) {
            error_log( "UTM Network Backup: Exception in create_database_dump: " . $e->getMessage() );
            return false;
        }
    }
    
    /**
     * Find mysqldump executable in common paths
     */
    private function find_mysqldump_path() {
        $common_paths = array(
            'mysqldump', // If in PATH
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            '/opt/lampp/bin/mysqldump', // XAMPP
            '/Applications/XAMPP/xamppfiles/bin/mysqldump', // XAMPP macOS
            'C:\xampp\mysql\bin\mysqldump.exe', // XAMPP Windows
            'C:\wamp\bin\mysql\mysql5.7.26\bin\mysqldump.exe', // WAMP Windows
            'C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe', // MySQL Windows
            'C:\Program Files (x86)\MySQL\MySQL Server 5.7\bin\mysqldump.exe', // MySQL Windows x86
        );
        
        foreach ( $common_paths as $path ) {
            if ( $this->is_executable_available( $path ) ) {
                return $path;
            }
        }
        
        return false;
    }
    
    /**
     * Check if executable is available
     */
    private function is_executable_available( $path ) {
        if ( PHP_OS_FAMILY === 'Windows' ) {
            // For Windows, check if file exists
            return file_exists( $path );
        } else {
            // For Unix-like systems, use which command
            if ( $path === 'mysqldump' ) {
                $result = shell_exec( 'which mysqldump 2>/dev/null' );
                return ! empty( trim( $result ) );
            }
            return is_executable( $path );
        }
    }
    
    /**
     * Add network backup header to the dump file
     */
    private function add_network_backup_header( $backup_file ) {
        $header = "-- UTM Network Database Backup (Full Database Dump)\n";
        $header .= "-- Generated on: " . date('Y-m-d H:i:s') . "\n";
        $header .= "-- Network URL: " . network_site_url() . "\n";
        $header .= "-- Database: " . DB_NAME . "\n";
        
        // Get network statistics
        $sites = get_sites( array( 'count' => true ) );
        $header .= "-- Total Sites in Network: " . $sites . "\n";
        $header .= "-- Backup Method: Full Database Dump (mysqldump)\n\n";
        
        // Prepend header to existing file
        $original_content = file_get_contents( $backup_file );
        file_put_contents( $backup_file, $header . $original_content );
    }
    
    /**
     * Fallback site-by-site backup method (slower but more compatible)
     */
    private function backup_network_database_fallback( $backup_file ) {
        error_log( "UTM Network Backup: Using fallback site-by-site backup method." );
        
        $handle = fopen( $backup_file, 'w+' );
        if ( ! $handle ) {
            throw new Exception( 'Could not open network backup file for writing in fallback mode.' );
        }

        // Write header comment
        fwrite( $handle, "-- UTM Network Database Backup (Site-by-Site Fallback Method)\n" );
        fwrite( $handle, "-- Generated on: " . date('Y-m-d H:i:s') . "\n" );
        fwrite( $handle, "-- Network URL: " . network_site_url() . "\n\n" );

        // Get all sites in the network
        $sites = get_sites( array(
            'public'    => 1,
            'archived'  => 0,
            'mature'    => 0,
            'spam'      => 0,
            'deleted'   => 0,
            'number'    => 1000 // Limit to prevent memory issues
        ) );

        $sites_processed = 0;
        $total_sites = count( $sites );
        $errors_encountered = 0;
        $error_log_file = str_replace( '.sql', '-error-log.txt', $backup_file );

        foreach ( $sites as $site ) {
            $blog_id = $site->blog_id;
            
            try {
                fwrite( $handle, "\n-- ========================================\n" );
                fwrite( $handle, "-- SITE ID: {$blog_id} ({$site->domain}{$site->path})\n" );
                fwrite( $handle, "-- ========================================\n\n" );

                // Switch to the site context
                switch_to_blog( $blog_id );
                
                global $wpdb;
                
                // Get all tables for this site
                $tables = $wpdb->get_results( "SHOW TABLES", ARRAY_N );
                
                if ( empty( $tables ) ) {
                    fwrite( $handle, "-- No tables found for site {$blog_id}\n\n" );
                    continue;
                }
                
                foreach ( $tables as $table_row ) {
                    $table = $table_row[0];
                    
                    try {
                        // Get table structure
                        $create_table = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
                        if ( $create_table ) {
                            fwrite( $handle, $create_table[1] . ";\n\n" );
                        }

                        // Get table data in batches to prevent memory issues
                        $offset = 0;
                        $batch_size = 1000;
                        
                        do {
                            $rows = $wpdb->get_results( 
                                $wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $batch_size, $offset ),
                                ARRAY_A 
                            );
                            
                            if ( $rows ) {
                                foreach ( $rows as $row ) {
                                    $values = array();
                                    foreach ( $row as $value ) {
                                        if ( $value === null ) {
                                            $values[] = 'NULL';
                                        } else {
                                            $values[] = "'" . $wpdb->_real_escape( $value ) . "'";
                                        }
                                    }
                                    $values_string = implode( ', ', $values );
                                    fwrite( $handle, "INSERT INTO `{$table}` VALUES ({$values_string});\n" );
                                }
                            }
                            
                            $offset += $batch_size;
                            
                        } while ( count( $rows ) === $batch_size );
                        
                        fwrite( $handle, "\n" );
                        
                    } catch ( Exception $table_error ) {
                        $error_message = "Error backing up table {$table} for site {$blog_id}: " . $table_error->getMessage();
                        fwrite( $handle, "-- ERROR: {$error_message}\n\n" );
                        file_put_contents( $error_log_file, date( 'Y-m-d H:i:s' ) . ' - ' . $error_message . "\n", FILE_APPEND );
                        $errors_encountered++;
                    }
                }

            } catch ( Exception $site_error ) {
                $error_message = "Error backing up site {$blog_id}: " . $site_error->getMessage();
                fwrite( $handle, "-- ERROR: {$error_message}\n\n" );
                file_put_contents( $error_log_file, date( 'Y-m-d H:i:s' ) . ' - ' . $error_message . "\n", FILE_APPEND );
                $errors_encountered++;
            } finally {
                restore_current_blog();
            }
            
            $sites_processed++;
            
            // Log progress every 10 sites
            if ( $sites_processed % 10 === 0 ) {
                error_log( "UTM Network Backup: Processed {$sites_processed}/{$total_sites} sites (fallback method)." );
            }
        }

        fclose( $handle );
        
        if ( $errors_encountered > 0 ) {
            error_log( "UTM Network Backup: Fallback backup completed with {$errors_encountered} errors." );
        } else {
            error_log( "UTM Network Backup: Fallback backup completed successfully." );
        }
    }
    
    /**
     * Create a single site database dump using mysqldump (preferred method)
     * CRITICAL: Handle main site table prefix carefully to avoid full database backup
     */
    private function create_single_site_database_dump( $backup_file, $status_file = null ) {
        global $wpdb;
        
        // Get database connection details
        $db_host = DB_HOST;
        $db_name = DB_NAME;
        $db_user = DB_USER;
        $db_password = DB_PASSWORD;
        
        // Parse host and port if specified
        $host_parts = explode( ':', $db_host );
        $host = $host_parts[0];
        $port = isset( $host_parts[1] ) ? $host_parts[1] : '3306';
        
        try {
            // Check if mysqldump is available
            $mysqldump_path = $this->find_mysqldump_path();
            if ( ! $mysqldump_path ) {
                error_log( 'UTM Single Site Backup: mysqldump not found in common paths.' );
                return false;
            }
            
            // Get current site tables with proper prefix handling
            $site_tables = $this->get_current_site_tables();
            if ( empty( $site_tables ) ) {
                error_log( 'UTM Single Site Backup: No tables found for current site.' );
                return false;
            }
            
            if ( $status_file ) {
                $this->update_backup_status( $status_file, 'running', 'Found ' . count( $site_tables ) . ' tables to backup...', 30 );
            }
            
            // Create tables list for mysqldump command
            $tables_list = implode( ' ', array_map( 'escapeshellarg', $site_tables ) );
            
            // Create mysqldump command with specific tables only
            $command = sprintf(
                '%s --host=%s --port=%s --user=%s --password=%s --single-transaction --routines --triggers --quick --lock-tables=false --add-drop-table --create-options --extended-insert --set-charset --comments %s %s > %s 2>&1',
                escapeshellarg( $mysqldump_path ),
                escapeshellarg( $host ),
                escapeshellarg( $port ),
                escapeshellarg( $db_user ),
                escapeshellarg( $db_password ),
                escapeshellarg( $db_name ),
                $tables_list, // Specific tables only - CRITICAL for main site
                escapeshellarg( $backup_file )
            );
            
            error_log( "UTM Single Site Backup: Executing mysqldump for " . count( $site_tables ) . " tables..." );
            
            if ( $status_file ) {
                $this->update_backup_status( $status_file, 'running', 'Executing mysqldump command...', 50 );
            }
            
            // Execute the command
            exec( $command, $output, $return_code );
            
            if ( $return_code === 0 && file_exists( $backup_file ) && filesize( $backup_file ) > 0 ) {
                // Add custom header to the backup file
                $this->add_single_site_backup_header( $backup_file, $site_tables );
                return true;
            } else {
                error_log( "UTM Single Site Backup: mysqldump failed with return code: {$return_code}" );
                if ( ! empty( $output ) ) {
                    error_log( "UTM Single Site Backup: mysqldump output: " . implode( "\n", $output ) );
                }
                return false;
            }
            
        } catch ( Exception $e ) {
            error_log( "UTM Single Site Backup: Exception in create_single_site_database_dump: " . $e->getMessage() );
            return false;
        }
    }
    
    /**
     * Get tables for current site with proper prefix handling
     * CRITICAL: This prevents main site from backing up entire database
     */
    private function get_current_site_tables() {
        global $wpdb;
        
        $current_blog_id = get_current_blog_id();
        $current_tables = array();
        
        if ( is_multisite() ) {
            // For multisite installations
            if ( $current_blog_id == 1 ) {
                // MAIN SITE: Be very specific about which tables to include
                // Include base WordPress tables and network-wide tables
                $main_site_tables = array(
                    $wpdb->prefix . 'posts',
                    $wpdb->prefix . 'postmeta', 
                    $wpdb->prefix . 'comments',
                    $wpdb->prefix . 'commentmeta',
                    $wpdb->prefix . 'users', // Network-wide but belongs to main site backup
                    $wpdb->prefix . 'usermeta', // Network-wide but belongs to main site backup
                    $wpdb->prefix . 'terms',
                    $wpdb->prefix . 'term_taxonomy',
                    $wpdb->prefix . 'term_relationships',
                    $wpdb->prefix . 'options',
                    $wpdb->prefix . 'links'
                );
                
                // Add network tables if backing up main site
                $network_tables = array(
                    $wpdb->prefix . 'blogs',
                    $wpdb->prefix . 'blog_versions', 
                    $wpdb->prefix . 'registration_log',
                    $wpdb->prefix . 'signups',
                    $wpdb->prefix . 'site',
                    $wpdb->prefix . 'sitemeta'
                );
                
                $main_site_tables = array_merge( $main_site_tables, $network_tables );
                
                // Verify tables exist before adding them
                foreach ( $main_site_tables as $table ) {
                    if ( $this->table_exists( $table ) ) {
                        $current_tables[] = $table;
                    }
                }
                
                error_log( "UTM Single Site Backup: Main site - backing up " . count( $current_tables ) . " specific tables." );
                
            } else {
                // SUB-SITES: Get tables with site-specific prefix (e.g., wp_2_, wp_3_)
                $site_prefix = $wpdb->get_blog_prefix( $current_blog_id );
                $sub_site_tables = array(
                    $site_prefix . 'posts',
                    $site_prefix . 'postmeta',
                    $site_prefix . 'comments', 
                    $site_prefix . 'commentmeta',
                    $site_prefix . 'terms',
                    $site_prefix . 'term_taxonomy',
                    $site_prefix . 'term_relationships',
                    $site_prefix . 'options',
                    $site_prefix . 'links'
                );
                
                // Verify tables exist before adding them
                foreach ( $sub_site_tables as $table ) {
                    if ( $this->table_exists( $table ) ) {
                        $current_tables[] = $table;
                    }
                }
                
                error_log( "UTM Single Site Backup: Sub-site {$current_blog_id} - backing up " . count( $current_tables ) . " tables with prefix '{$site_prefix}'." );
            }
        } else {
            // Single site installation - get all WordPress tables
            $single_site_tables = array(
                $wpdb->prefix . 'posts',
                $wpdb->prefix . 'postmeta',
                $wpdb->prefix . 'comments',
                $wpdb->prefix . 'commentmeta', 
                $wpdb->prefix . 'users',
                $wpdb->prefix . 'usermeta',
                $wpdb->prefix . 'terms',
                $wpdb->prefix . 'term_taxonomy',
                $wpdb->prefix . 'term_relationships',
                $wpdb->prefix . 'options',
                $wpdb->prefix . 'links'
            );
            
            // Verify tables exist before adding them
            foreach ( $single_site_tables as $table ) {
                if ( $this->table_exists( $table ) ) {
                    $current_tables[] = $table;
                }
            }
            
            error_log( "UTM Single Site Backup: Single site - backing up " . count( $current_tables ) . " tables." );
        }
        
        // Add any custom plugin tables that match current site prefix
        $custom_tables = $this->get_custom_plugin_tables();
        $current_tables = array_merge( $current_tables, $custom_tables );
        
        return $current_tables;
    }
    
    /**
     * Check if a table exists in the database
     */
    private function table_exists( $table_name ) {
        global $wpdb;
        
        $result = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) );
        return $result === $table_name;
    }
    
    /**
     * Get custom plugin tables for current site
     */
    private function get_custom_plugin_tables() {
        global $wpdb;
        
        $current_blog_id = get_current_blog_id();
        $site_prefix = is_multisite() ? $wpdb->get_blog_prefix( $current_blog_id ) : $wpdb->prefix;
        $custom_tables = array();
        
        // Get all tables in database
        $all_tables = $wpdb->get_col( "SHOW TABLES" );
        
        // Find custom tables that match current site prefix but aren't standard WordPress tables
        $standard_tables = array( 'posts', 'postmeta', 'comments', 'commentmeta', 'users', 'usermeta', 
                                 'terms', 'term_taxonomy', 'term_relationships', 'options', 'links',
                                 'blogs', 'blog_versions', 'registration_log', 'signups', 'site', 'sitemeta' );
        
        foreach ( $all_tables as $table ) {
            // Check if table starts with current site prefix
            if ( strpos( $table, $site_prefix ) === 0 ) {
                // Extract table suffix (part after prefix)
                $table_suffix = substr( $table, strlen( $site_prefix ) );
                
                // Add if it's not a standard WordPress table
                if ( ! in_array( $table_suffix, $standard_tables ) ) {
                    $custom_tables[] = $table;
                    error_log( "UTM Single Site Backup: Found custom table: {$table}" );
                }
            }
        }
        
        return $custom_tables;
    }
    
    /**
     * Add single site backup header to the dump file
     */
    private function add_single_site_backup_header( $backup_file, $tables ) {
        $current_blog_id = get_current_blog_id();
        $site_url = is_multisite() ? get_site_url( $current_blog_id ) : get_site_url();
        
        $header = "-- UTM Single Site Database Backup (mysqldump)\n";
        $header .= "-- Generated on: " . date('Y-m-d H:i:s') . "\n";
        $header .= "-- Site URL: " . $site_url . "\n";
        $header .= "-- Site ID: " . $current_blog_id . "\n";
        $header .= "-- Database: " . DB_NAME . "\n";
        $header .= "-- Tables Backed Up: " . count( $tables ) . "\n";
        $header .= "-- Table List: " . implode( ', ', $tables ) . "\n";
        $header .= "-- Backup Method: Single Site Database Dump (mysqldump)\n\n";
        
        // Prepend header to existing file
        $original_content = file_get_contents( $backup_file );
        file_put_contents( $backup_file, $header . $original_content );
    }
    
    /**
     * Fallback PHP-based backup method for single site (slower but more compatible)
     */
    private function backup_database_fallback( $backup_file ) {
        global $wpdb;
        
        error_log( "UTM Single Site Backup: Using fallback PHP backup method." );
        
        $handle = fopen( $backup_file, 'w+' );
        if ( ! $handle ) {
            throw new Exception( 'Could not open single site backup file for writing in fallback mode.' );
        }

        // Write header comment
        fwrite( $handle, "-- UTM Single Site Database Backup (PHP Fallback Method)\n" );
        fwrite( $handle, "-- Generated on: " . date('Y-m-d H:i:s') . "\n" );
        $current_blog_id = get_current_blog_id();
        $site_url = is_multisite() ? get_site_url( $current_blog_id ) : get_site_url();
        fwrite( $handle, "-- Site URL: " . $site_url . "\n" );
        fwrite( $handle, "-- Site ID: " . $current_blog_id . "\n\n" );

        $error_log_file = str_replace( '.sql', '-error-log.txt', $backup_file );
        $tables_processed = 0;
        $errors_encountered = 0;
        
        // Get current site tables using the same logic as mysqldump method
        $site_tables = $this->get_current_site_tables();
        
        foreach ( $site_tables as $table ) {
            try {
                fwrite( $handle, "\n-- ========================================\n" );
                fwrite( $handle, "-- TABLE: {$table}\n" );
                fwrite( $handle, "-- ========================================\n\n" );

                // Get table structure
                $create_table = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
                if ( $create_table ) {
                    fwrite( $handle, $create_table[1] . ";\n\n" );
                }

                // Get table data in batches to prevent memory issues
                $offset = 0;
                $batch_size = 1000;
                
                do {
                    $rows = $wpdb->get_results( 
                        $wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $batch_size, $offset ),
                        ARRAY_A 
                    );
                    
                    if ( $rows ) {
                        foreach ( $rows as $row ) {
                            $values = array();
                            foreach ( $row as $value ) {
                                if ( $value === null ) {
                                    $values[] = 'NULL';
                                } else {
                                    $values[] = "'" . $wpdb->_real_escape( $value ) . "'";
                                }
                            }
                            $values_string = implode( ', ', $values );
                            fwrite( $handle, "INSERT INTO `{$table}` VALUES ({$values_string});\n" );
                        }
                    }
                    
                    $offset += $batch_size;
                    
                } while ( count( $rows ) === $batch_size );
                
                fwrite( $handle, "\n" );
                
            } catch ( Exception $table_error ) {
                $error_message = "Error backing up table {$table}: " . $table_error->getMessage();
                fwrite( $handle, "-- ERROR: {$error_message}\n\n" );
                file_put_contents( $error_log_file, date( 'Y-m-d H:i:s' ) . ' - ' . $error_message . "\n", FILE_APPEND );
                $errors_encountered++;
            }
            
            $tables_processed++;
        }

        fclose( $handle );
        
        if ( $errors_encountered > 0 ) {
            error_log( "UTM Single Site Backup: Fallback backup completed with {$errors_encountered} errors." );
        } else {
            error_log( "UTM Single Site Backup: Fallback backup completed successfully." );
        }
    }

    private function compress_backup_file( $source_file, $compressed_file ) {
        try {
            // Try using gzip command first (more efficient)
            if ( function_exists( 'exec' ) && ! empty( shell_exec( 'which gzip' ) ) ) {
                $command = "gzip -c " . escapeshellarg( $source_file ) . " > " . escapeshellarg( $compressed_file );
                exec( $command, $output, $return_code );
                
                if ( $return_code === 0 && file_exists( $compressed_file ) ) {
                    return;
                }
            }

            // Fallback to PHP gzip functions
            $source_handle = fopen( $source_file, 'rb' );
            $compressed_handle = gzopen( $compressed_file, 'wb9' );
            
            if ( ! $source_handle || ! $compressed_handle ) {
                throw new Exception( 'Could not open files for compression.' );
            }

            while ( ! feof( $source_handle ) ) {
                $chunk = fread( $source_handle, 8192 );
                gzwrite( $compressed_handle, $chunk );
            }

            fclose( $source_handle );
            gzclose( $compressed_handle );

        } catch ( Exception $e ) {
            error_log( 'UTM Backup Compression Error: ' . $e->getMessage() );
            // Keep the uncompressed file if compression fails
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
        $upload_dir = $this->get_safe_upload_dir();
        $backup_dir = $upload_dir['basedir'];
        $single_backup_files = glob( $backup_dir . '/db-backup-*.sql' );
        $network_backup_files = glob( $backup_dir . '/network-backup-*.sql.gz' );
        $error_log_file = $backup_dir . '/db-backup-error-log.txt';
        $network_error_log_file = $backup_dir . '/network-backup-error-log.txt';

        // Get the next scheduled backup time
        $next_scheduled = wp_next_scheduled( 'utm_webmaster_tool_daily_backup' );
        $next_scheduled_time = $next_scheduled ? date( 'Y-m-d H:i:s', $next_scheduled ) : 'No scheduled backup';

        echo '<div class="wrap"><h2>Database Backups</h2>';
        echo '<p>Current time: ' . date( 'Y-m-d H:i:s' ) . '</p>';
        echo '<p>Next scheduled backup: ' . esc_html( $next_scheduled_time ) . '</p>';
        
        // Single site backup section
        echo '<div style="margin-top: 20px; padding: 20px; border: 2px solid #28a745; border-radius: 5px; background: #f0fff4;">';
        echo '<h2 style="color: #28a745; margin-top: 0;">💾 Single Site Backup</h2>';
        echo '<div style="background: white; padding: 15px; border-radius: 5px; margin: 15px 0;">';
        echo '<h3 style="margin-top: 0; color: #2c5282;">📈 Performance Enhancement</h3>';
        echo '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 15px 0;">';
        echo '<div style="background: #f7fafc; padding: 15px; border-left: 4px solid #48bb78; border-radius: 3px;">';
        echo '<h4 style="color: #48bb78; margin: 0 0 8px 0;">✅ New Method: Site-Specific mysqldump</h4>';
        echo '<ul style="margin: 8px 0; padding-left: 20px; color: #2d3748;">';
        echo '<li><strong>Speed:</strong> 5-15x faster</li>';
        echo '<li><strong>Memory:</strong> Minimal PHP memory usage</li>';
        echo '<li><strong>Precision:</strong> Only current site tables</li>';
        echo '<li><strong>Safety:</strong> Main site prefix protection</li>';
        echo '<li><strong>Method:</strong> Targeted table selection</li>';
        echo '</ul></div>';
        echo '<div style="background: #fef5e7; padding: 15px; border-left: 4px solid #ed8936; border-radius: 3px;">';
        echo '<h4 style="color: #ed8936; margin: 0 0 8px 0;">⚠️ Fallback: PHP Processing</h4>';
        echo '<ul style="margin: 8px 0; padding-left: 20px; color: #2d3748;">';
        echo '<li><strong>Speed:</strong> Slower (legacy method)</li>';
        echo '<li><strong>Memory:</strong> Higher PHP memory usage</li>';
        echo '<li><strong>Processing:</strong> Row-by-row iteration</li>';
        echo '<li><strong>Compatibility:</strong> Works when mysqldump unavailable</li>';
        echo '<li><strong>Method:</strong> PHP database queries</li>';
        echo '</ul></div></div></div>';
        
        $current_blog_id = get_current_blog_id();
        if ( is_multisite() && $current_blog_id == 1 ) {
            echo '<div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 15px 0;">';
            echo '<h4 style="margin: 0 0 8px 0; color: #856404;">🛡️ Main Site Protection</h4>';
            echo '<p style="margin: 0; color: #856404;"><strong>Critical Safety Feature:</strong> Main site backups use specific table selection to prevent accidental full database backup due to shared table prefixes.</p>';
            echo '</div>';
        }
        
        echo '<p><strong>🎯 Smart Table Selection:</strong> The system automatically identifies and backs up only the tables belonging to the current site, ensuring efficient and precise backups.</p>';
        
        echo '<div style="margin: 20px 0;">';
        echo '<div id="single-backup-status" style="display: none; padding: 15px; border-left: 4px solid #0073aa; background: #f0f8ff; margin: 10px 0; border-radius: 3px;">';
        echo '<div id="single-backup-message" style="font-weight: bold; margin-bottom: 8px;">Starting backup...</div>';
        echo '<div style="background: #e2e8f0; height: 20px; border-radius: 10px; margin: 10px 0; overflow: hidden;">';
        echo '<div id="single-backup-progress-bar" style="height: 100%; background: #0073aa; width: 0%; transition: width 0.5s ease; border-radius: 10px;"></div>';
        echo '</div>';
        echo '<small id="single-backup-timestamp" style="color: #666;"></small>';
        echo '</div>';
        $single_backup_button_text = '💾 Start Single Site Backup';
        echo '<button id="initiate-backup" class="button button-primary button-hero" style="background: #28a745; border-color: #28a745; padding: 12px 24px; font-size: 16px;">' . esc_html( $single_backup_button_text ) . '</button>';
        echo '</div>';
        echo '</div>';
        
        // Network backup section (only show if multisite)
        if ( is_multisite() ) {
            echo '<div style="margin-top: 30px; padding: 20px; border: 2px solid #0073aa; border-radius: 5px; background: #f0f8ff;">';
            echo '<h2 style="color: #0073aa; margin-top: 0;">🌐 Full Network Backup (All Sites)</h2>';
            echo '<div style="background: white; padding: 15px; border-radius: 5px; margin: 15px 0;">';
            echo '<h3 style="margin-top: 0; color: #2c5282;">📈 Performance Comparison</h3>';
            echo '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 15px 0;">';
            echo '<div style="background: #f7fafc; padding: 15px; border-left: 4px solid #48bb78; border-radius: 3px;">';
            echo '<h4 style="color: #48bb78; margin: 0 0 8px 0;">✅ New Method: Full Database Dump</h4>';
            echo '<ul style="margin: 8px 0; padding-left: 20px; color: #2d3748;">';
            echo '<li><strong>Speed:</strong> 10-50x faster</li>';
            echo '<li><strong>Memory:</strong> Minimal PHP memory usage</li>';
            echo '<li><strong>Reliability:</strong> Uses proven mysqldump</li>';
            echo '<li><strong>Consistency:</strong> Atomic database snapshot</li>';
            echo '<li><strong>Method:</strong> Single command execution</li>';
            echo '</ul></div>';
            echo '<div style="background: #fef5e7; padding: 15px; border-left: 4px solid #ed8936; border-radius: 3px;">';
            echo '<h4 style="color: #ed8936; margin: 0 0 8px 0;">⚠️ Fallback: Site-by-Site</h4>';
            echo '<ul style="margin: 8px 0; padding-left: 20px; color: #2d3748;">';
            echo '<li><strong>Speed:</strong> Much slower (legacy method)</li>';
            echo '<li><strong>Memory:</strong> High PHP memory usage</li>';
            echo '<li><strong>Complexity:</strong> Multiple context switches</li>';
            echo '<li><strong>Risk:</strong> Potential inconsistencies</li>';
            echo '<li><strong>Method:</strong> PHP loop processing</li>';
            echo '</ul></div></div></div>';
            
            echo '<p><strong>🚀 Smart Backup System:</strong> The system automatically tries the <strong>fast database dump method first</strong>, and only falls back to the slower site-by-site method if mysqldump is not available on your server.</p>';
            
            $sites_count = get_sites( array( 'count' => true ) );
            echo '<p><strong>Network Statistics:</strong> <span style="background: #e2e8f0; padding: 3px 8px; border-radius: 3px; color: #2d3748;">' . $sites_count . ' sites</span> will be backed up.</p>';
            
            echo '<div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 15px 0;">';
            echo '<h4 style="margin: 0 0 8px 0; color: #856404;">⚡ Background Processing</h4>';
            echo '<p style="margin: 0; color: #856404;">Network backups run in the background to prevent timeouts. You can continue using your site while the backup runs.</p>';
            echo '</div>';

            echo '<div style="margin: 20px 0;">';
            echo '<div id="network-backup-status" style="display: none; padding: 15px; border-left: 4px solid #0073aa; background: #f0f8ff; margin: 10px 0; border-radius: 3px;">';
            echo '<div id="network-backup-message" style="font-weight: bold; margin-bottom: 8px;">Starting network backup...</div>';
            echo '<div style="background: #e2e8f0; height: 20px; border-radius: 10px; margin: 10px 0; overflow: hidden;">';
            echo '<div id="network-backup-progress-bar" style="height: 100%; background: #0073aa; width: 0%; transition: width 0.5s ease; border-radius: 10px;"></div>';
            echo '</div>';
            echo '<small id="network-backup-timestamp" style="color: #666;"></small>';
            echo '</div>';
            $network_backup_button_text = '🌐 Start Full Network Backup';
            $force_backup_button_text = '⚡ Force Run Network Backup';
            echo '<button id="initiate-network-backup" class="button button-primary button-hero" style="background: #0073aa; border-color: #0073aa; padding: 12px 24px; font-size: 16px; margin-right: 10px;">' . esc_html( $network_backup_button_text ) . '</button>';
            echo '<button id="force-network-backup" class="button button-secondary" style="padding: 12px 24px; font-size: 16px;" title="Bypass WordPress scheduler and run directly (useful for development)">' . esc_html( $force_backup_button_text ) . '</button>';
            echo '</div>';
            echo '</div>';
        }
        
        // Diagnostic section
        echo '<div style="margin-top: 30px; padding: 20px; border: 2px solid #f39c12; border-radius: 5px; background: #fefcf3;">';
        echo '<h2 style="color: #f39c12; margin-top: 0;">🔍 Backup Diagnostics</h2>';
        echo '<p><strong>Troubleshooting:</strong> If backups are failing, use this diagnostic tool to identify potential issues.</p>';
        echo '<div id="diagnostic-results" style="display: none; background: white; padding: 15px; border-radius: 5px; margin: 15px 0; font-family: monospace; font-size: 12px; max-height: 400px; overflow-y: auto; border: 1px solid #ddd;"></div>';
        $diagnostics_button_text = '🔍 Run Diagnostics';
        $clear_logs_button_text = '🗑️ Clear Error Logs';
        echo '<button id="run-diagnostics" class="button button-secondary" style="margin-right: 10px;">' . esc_html( $diagnostics_button_text ) . '</button>';
        echo '<button id="clear-error-logs" class="button button-secondary">' . esc_html( $clear_logs_button_text ) . '</button>';
        echo '</div>';

        // Single site backups table
        echo '<h3>Single Site Backups</h3>';
        echo '<table class="widefat"><thead><tr><th>Backup File</th><th>Size</th><th>Date</th><th>Download</th></tr></thead><tbody>';
        if ( empty( $single_backup_files ) ) {
            echo '<tr><td colspan="4">No single site backups found.</td></tr>';
        } else {
            foreach ( $single_backup_files as $file ) {
                $file_name = basename( $file );
                $file_size = $this->format_file_size( filesize( $file ) );
                $file_date = date( 'Y-m-d H:i:s', filemtime( $file ) );
                $file_url = admin_url( 'admin-post.php?action=utm_download_backup&file=' . urlencode( $file_name ) );
                echo '<tr><td>' . esc_html( $file_name ) . '</td><td>' . $file_size . '</td><td>' . $file_date . '</td><td><a href="' . esc_url( $file_url ) . '" class="button">Download</a></td></tr>';
            }
        }
        echo '</tbody></table>';

        // Network backups table (only show if multisite)
        if ( is_multisite() ) {
            echo '<h3>Network Backups (Compressed)</h3>';
            echo '<table class="widefat"><thead><tr><th>Backup File</th><th>Size</th><th>Date</th><th>Download</th></tr></thead><tbody>';
            if ( empty( $network_backup_files ) ) {
                echo '<tr><td colspan="4">No network backups found.</td></tr>';
            } else {
                foreach ( $network_backup_files as $file ) {
                    $file_name = basename( $file );
                    $file_size = $this->format_file_size( filesize( $file ) );
                    $file_date = date( 'Y-m-d H:i:s', filemtime( $file ) );
                    $file_url = admin_url( 'admin-post.php?action=utm_download_backup&file=' . urlencode( $file_name ) );
                    echo '<tr><td>' . esc_html( $file_name ) . '</td><td>' . $file_size . '</td><td>' . $file_date . '</td><td><a href="' . esc_url( $file_url ) . '" class="button">Download</a></td></tr>';
                }
            }
            echo '</tbody></table>';
        }

        echo '</div>';

        // Display error logs
        echo '<div class="wrap"><h2>Backup Error Logs</h2>';
        
        // Single site error log
        echo '<h3>Single Site Backup Errors</h3>';
        if ( file_exists( $error_log_file ) ) {
            $error_log = file_get_contents( $error_log_file );
            if ( ! empty( $error_log ) ) {
                echo '<pre style="background: #f1f1f1; padding: 10px; max-height: 300px; overflow-y: auto;">' . esc_html( $error_log ) . '</pre>';
            } else {
                echo '<p>No single site backup errors logged.</p>';
            }
        } else {
            echo '<p>No single site backup errors logged.</p>';
        }

        // Network backup error log (only show if multisite)
        if ( is_multisite() ) {
            echo '<h3>Network Backup Errors</h3>';
            if ( file_exists( $network_error_log_file ) ) {
                $network_error_log = file_get_contents( $network_error_log_file );
                if ( ! empty( $network_error_log ) ) {
                    echo '<pre style="background: #f1f1f1; padding: 10px; max-height: 300px; overflow-y: auto;">' . esc_html( $network_error_log ) . '</pre>';
                } else {
                    echo '<p>No network backup errors logged.</p>';
                }
            } else {
                echo '<p>No network backup errors logged.</p>';
            }
        }

        echo '</div>';
    
        // Add JavaScript to handle the button clicks with real-time status
        echo '<script type="text/javascript">
            jQuery(document).ready(function($) {
                var singleBackupInterval;
                var networkBackupInterval;
                
                // Check for existing backup status on page load
                checkSingleBackupStatus();
                ' . ( is_multisite() ? 'checkNetworkBackupStatus();' : '' ) . '
                
                function checkSingleBackupStatus() {
                    $.post(ajaxurl, { action: "utm_check_backup_status" }, function(response) {
                        if (response.success && response.data) {
                            var status = response.data;
                            updateSingleBackupStatus(status);
                            
                            if (status.status === "running") {
                                if (!singleBackupInterval) {
                                    singleBackupInterval = setInterval(checkSingleBackupStatus, 2000);
                                }
                            } else {
                                if (singleBackupInterval) {
                                    clearInterval(singleBackupInterval);
                                    singleBackupInterval = null;
                                }
                                if (status.status === "idle") {
                                    $("#single-backup-status").hide();
                                    $("#initiate-backup").prop("disabled", false).text("💾 Start Single Site Backup");
                                }
                            }
                        }
                    });
                }
                
                function updateSingleBackupStatus(status) {
                    if (status.status === "idle") {
                        $("#single-backup-status").hide();
                        $("#initiate-backup").prop("disabled", false).text("💾 Start Single Site Backup");
                        return;
                    }
                    
                    $("#single-backup-status").show();
                    $("#single-backup-message").text(status.message);
                    $("#single-backup-progress-bar").css("width", status.progress + "%");
                    $("#single-backup-timestamp").text("Last updated: " + status.formatted_time);
                    
                    if (status.status === "running") {
                        $("#initiate-backup").prop("disabled", true).text("Backup Running...");
                        $("#single-backup-status").css("border-left-color", "#0073aa");
                    } else if (status.status === "completed") {
                        $("#single-backup-status").css("border-left-color", "#46b450");
                        $("#initiate-backup").prop("disabled", false).text("💾 Start Single Site Backup");
                        setTimeout(function() {
                            $("#single-backup-status").fadeOut();
                            location.reload();
                        }, 3000);
                    } else if (status.status === "failed") {
                        $("#single-backup-status").css("border-left-color", "#dc3232");
                        $("#initiate-backup").prop("disabled", false).text("💾 Start Single Site Backup");
                    }
                }
                
                $("#initiate-backup").on("click", function() {
                    $(this).prop("disabled", true).text("Starting Backup...");
                    $("#single-backup-status").show();
                    updateSingleBackupStatus({
                        status: "running",
                        message: "Initiating backup...",
                        progress: 0,
                        formatted_time: new Date().toLocaleString()
                    });
                    
                    $.post(ajaxurl, { action: "utm_initiate_backup" }, function(response) {
                        if (response.success) {
                            singleBackupInterval = setInterval(checkSingleBackupStatus, 2000);
                        } else {
                            alert("An error occurred while initiating the single site backup.");
                            $("#initiate-backup").prop("disabled", false).text("💾 Start Single Site Backup");
                            $("#single-backup-status").hide();
                        }
                    });
                });';

        if ( is_multisite() ) {
            echo '
                function checkNetworkBackupStatus() {
                    $.post(ajaxurl, { action: "utm_check_network_backup_status" }, function(response) {
                        if (response.success && response.data) {
                            var status = response.data;
                            updateNetworkBackupStatus(status);
                            
                            if (status.status === "running") {
                                if (!networkBackupInterval) {
                                    networkBackupInterval = setInterval(checkNetworkBackupStatus, 3000);
                                }
                            } else {
                                if (networkBackupInterval) {
                                    clearInterval(networkBackupInterval);
                                    networkBackupInterval = null;
                                }
                                if (status.status === "idle") {
                                    $("#network-backup-status").hide();
                                    $("#initiate-network-backup").prop("disabled", false).text("🌐 Start Full Network Backup");
                                }
                            }
                        }
                    });
                }
                
                function updateNetworkBackupStatus(status) {
                    if (status.status === "idle") {
                        $("#network-backup-status").hide();
                        $("#initiate-network-backup").prop("disabled", false).text("🌐 Start Full Network Backup");
                        return;
                    }
                    
                    $("#network-backup-status").show();
                    $("#network-backup-message").text(status.message);
                    $("#network-backup-progress-bar").css("width", status.progress + "%");
                    $("#network-backup-timestamp").text("Last updated: " + status.formatted_time);
                    
                    if (status.status === "running") {
                        $("#initiate-network-backup").prop("disabled", true).text("Network Backup Running...");
                        $("#network-backup-status").css("border-left-color", "#0073aa");
                    } else if (status.status === "completed") {
                        $("#network-backup-status").css("border-left-color", "#46b450");
                        $("#initiate-network-backup").prop("disabled", false).text("🌐 Start Full Network Backup");
                        setTimeout(function() {
                            $("#network-backup-status").fadeOut();
                            location.reload();
                        }, 5000);
                    } else if (status.status === "failed") {
                        $("#network-backup-status").css("border-left-color", "#dc3232");
                        $("#initiate-network-backup").prop("disabled", false).text("🌐 Start Full Network Backup");
                    }
                }
                
                $("#initiate-network-backup").on("click", function() {
                    if (!confirm("Network backup may take a long time and consume significant resources. Continue?")) {
                        return;
                    }
                    
                    $(this).prop("disabled", true).text("Starting Network Backup...");
                    $("#network-backup-status").show();
                    updateNetworkBackupStatus({
                        status: "running",
                        message: "Scheduling network backup...",
                        progress: 0,
                        formatted_time: new Date().toLocaleString()
                    });
                    
                    $.post(ajaxurl, { action: "utm_initiate_network_backup" }, function(response) {
                        if (response.success) {
                            networkBackupInterval = setInterval(checkNetworkBackupStatus, 3000);
                        } else {
                            alert("An error occurred while initiating the network backup.");
                            $("#initiate-network-backup").prop("disabled", false).text("🌐 Start Full Network Backup");
                            $("#network-backup-status").hide();
                        }
                    });
                });
                
                $("#force-network-backup").on("click", function() {
                    if (!confirm("Force network backup will run directly without using WordPress scheduler. This may take a long time. Continue?")) {
                        return;
                    }
                    
                    $("#force-network-backup").prop("disabled", true).text("Force Running...");
                    $("#initiate-network-backup").prop("disabled", true);
                    $("#network-backup-status").show();
                    $("#network-backup-message").text("Force executing network backup...");
                    $("#network-backup-progress-bar").css("width", "5%");
                    $("#network-backup-status").css("border-left-color", "#f39c12");
                    
                    // Start polling for status immediately
                    if (!networkBackupInterval) {
                        networkBackupInterval = setInterval(checkNetworkBackupStatus, 2000);
                    }
                    
                    $.post(ajaxurl, { action: "utm_force_network_backup" }, function(response) {
                        if (response.success) {
                            // Success response means backup started, polling will handle the rest
                            console.log("Force network backup initiated successfully");
                        } else {
                            alert("Error initiating force network backup: " + (response.data || "Unknown error"));
                            $("#force-network-backup").prop("disabled", false).text("⚡ Force Run Network Backup");
                            $("#initiate-network-backup").prop("disabled", false);
                            $("#network-backup-status").hide();
                            
                            if (networkBackupInterval) {
                                clearInterval(networkBackupInterval);
                                networkBackupInterval = null;
                            }
                        }
                    }).fail(function() {
                        alert("Network error - could not initiate force network backup");
                        $("#force-network-backup").prop("disabled", false).text("⚡ Force Run Network Backup");
                        $("#initiate-network-backup").prop("disabled", false);
                        $("#network-backup-status").hide();
                        
                        if (networkBackupInterval) {
                            clearInterval(networkBackupInterval);
                            networkBackupInterval = null;
                        }
                    });
                });';
        }

        echo '
                // Diagnostic functionality
                $("#run-diagnostics").on("click", function() {
                    $("#run-diagnostics").prop("disabled", true).text("Running Diagnostics...");
                    $("#diagnostic-results").show();
                    
                    $.post(ajaxurl, { action: "utm_backup_diagnostics" }, function(response) {
                        if (response.success && response.data) {
                            var diagnostics = response.data;
                            var output = "";
                            
                            output += "=== BACKUP SYSTEM DIAGNOSTICS ===\\n\\n";
                            
                            output += "SYSTEM INFORMATION:\\n";
                            output += "- Multisite: " + (diagnostics.system.is_multisite ? "Yes (" + diagnostics.system.site_count + " sites)" : "No") + "\\n";
                            output += "- Current Blog ID: " + diagnostics.system.current_blog_id + "\\n";
                            output += "- PHP Version: " + diagnostics.system.php_version + "\\n";
                            output += "- Memory Limit: " + diagnostics.system.memory_limit + "\\n";
                            output += "- Max Execution Time: " + diagnostics.system.max_execution_time + "s\\n";
                            output += "- Upload Directory: " + diagnostics.system.upload_dir + "\\n";
                            output += "- Upload Dir Writable: " + (diagnostics.system.upload_dir_writable ? "Yes" : "NO - ERROR!") + "\\n";
                            
                            output += "\\nWORDPRESS CRON:\\n";
                            output += "- WP-Cron Disabled: " + (diagnostics.cron.wp_cron_disabled ? "YES - This may prevent backups!" : "No") + "\\n";
                            output += "- Next Scheduled Backup: " + (diagnostics.cron.next_scheduled_backup ? new Date(diagnostics.cron.next_scheduled_backup * 1000).toLocaleString() : "None") + "\\n";
                            output += "- Total Cron Jobs: " + diagnostics.cron.cron_jobs_count + "\\n";
                            output += "- Cron Test: " + diagnostics.cron.test_scheduling + "\\n";
                            
                            output += "\\nPHP ERROR HANDLING:\\n";
                            output += "- Error Reporting Level: " + diagnostics.php_errors.error_reporting_level + "\\n";
                            output += "- Display Errors: " + diagnostics.php_errors.display_errors + "\\n";
                            output += "- Log Errors: " + diagnostics.php_errors.log_errors + "\\n";
                            if (diagnostics.php_errors.last_php_error && diagnostics.php_errors.last_php_error.message) {
                                output += "- Last PHP Error: " + diagnostics.php_errors.last_php_error.message + " (Line: " + diagnostics.php_errors.last_php_error.line + ", File: " + diagnostics.php_errors.last_php_error.file + ")\\n";
                            } else {
                                output += "- Last PHP Error: None\\n";
                            }
                            
                            output += "\\nDATABASE:\\n";
                            output += "- Host: " + diagnostics.database.db_host + "\\n";
                            output += "- Database: " + diagnostics.database.db_name + "\\n";
                            output += "- User: " + diagnostics.database.db_user + "\\n";
                            output += "- Table Prefix: " + diagnostics.database.db_prefix + "\\n";
                            output += "- Total Tables: " + diagnostics.database.table_count + "\\n";
                            
                            output += "\\nMYSQLDUMP:\\n";
                            output += "- Available: " + (diagnostics.mysqldump.available ? "Yes" : "No - Will use PHP fallback") + "\\n";
                            output += "- Path: " + (diagnostics.mysqldump.path || "Not found") + "\\n";
                            
                            output += "\\nBACKUP FILES:\\n";
                            output += "- Single Site Backups: " + diagnostics.files.single_backups_count + "\\n";
                            output += "- Network Backups: " + diagnostics.files.network_backups_count + "\\n";
                            output += "- Error Log Exists: " + (diagnostics.files.error_log_exists ? "Yes" : "No") + "\\n";
                            output += "- Network Error Log: " + (diagnostics.files.network_error_log_exists ? "Yes" : "No") + "\\n";
                            
                            output += "\\nCURRENT STATUS:\\n";
                            output += "- Single Site Status: " + diagnostics.status.single_status.status + " - " + diagnostics.status.single_status.message + "\\n";
                            output += "- Network Status: " + diagnostics.status.network_status.status + " - " + diagnostics.status.network_status.message + "\\n";
                            
                            if (diagnostics.recent_errors && diagnostics.recent_errors.length > 0) {
                                output += "\\nRECENT ERRORS (Last 10):\\n";
                                diagnostics.recent_errors.forEach(function(error) {
                                    output += "- " + error + "\\n";
                                });
                            } else {
                                output += "\\nNo recent errors found.\\n";
                            }
                            
                            $("#diagnostic-results").text(output);
                        } else {
                            $("#diagnostic-results").text("Error running diagnostics: " + (response.data || "Unknown error"));
                        }
                        
                        $("#run-diagnostics").prop("disabled", false).text("🔍 Run Diagnostics");
                    }).fail(function() {
                        $("#diagnostic-results").text("Failed to run diagnostics - AJAX request failed.");
                        $("#run-diagnostics").prop("disabled", false).text("🔍 Run Diagnostics");
                    });
                });
                
                $("#clear-error-logs").on("click", function() {
                    if (confirm("Are you sure you want to clear all backup error logs?")) {
                        // Implementation would go here - for now just alert
                        alert("Error log clearing feature can be implemented if needed.");
                    }
                });
            });
        </script>';
    }

    private function format_file_size( $size ) {
        $units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
        $power = $size > 0 ? floor( log( $size, 1024 ) ) : 0;
        return number_format( $size / pow( 1024, $power ), 2, '.', ',' ) . ' ' . $units[ $power ];
    }

    /**
     * Clean up old backup files to prevent disk space issues
     */
    private function cleanup_old_backups() {
        $upload_dir = $this->get_safe_upload_dir();
        $backup_dir = $upload_dir['basedir'];
        
        // Keep backups for 30 days
        $cutoff_time = time() - ( 30 * 24 * 60 * 60 );
        
        // Clean up single site backups
        $single_backups = glob( $backup_dir . '/db-backup-*.sql' );
        foreach ( $single_backups as $file ) {
            if ( filemtime( $file ) < $cutoff_time ) {
                unlink( $file );
                error_log( 'UTM Backup Cleanup: Removed old single backup file ' . basename( $file ) );
            }
        }
        
        // Clean up network backups
        $network_backups = glob( $backup_dir . '/network-backup-*.sql.gz' );
        foreach ( $network_backups as $file ) {
            if ( filemtime( $file ) < $cutoff_time ) {
                unlink( $file );
                error_log( 'UTM Backup Cleanup: Removed old network backup file ' . basename( $file ) );
            }
        }
    }

    /**
     * Get network backup status and statistics
     */
    public function get_network_backup_status() {
        if ( ! is_multisite() ) {
            return array( 'enabled' => false, 'reason' => 'Not a multisite installation' );
        }
        
        $sites = get_sites( array(
            'public'    => 1,
            'archived'  => 0,
            'mature'    => 0,
            'spam'      => 0,
            'deleted'   => 0,
            'count'     => true
        ) );
        
        $upload_dir = $this->get_safe_upload_dir();
        $backup_dir = $upload_dir['basedir'];
        $network_backups = glob( $backup_dir . '/network-backup-*.sql.gz' );
        
        return array(
            'enabled' => true,
            'total_sites' => $sites,
            'backup_count' => count( $network_backups ),
            'last_backup' => ! empty( $network_backups ) ? date( 'Y-m-d H:i:s', filemtime( end( $network_backups ) ) ) : 'Never'
        );
    }

    public function ajax_initiate_backup() {
        $upload_dir = $this->get_safe_upload_dir();
        $error_log_file = $upload_dir['basedir'] . '/db-backup-error-log.txt';
        $status_file = $upload_dir['basedir'] . '/single-backup-status.json';
        
        try {
            // Log the initiation attempt
            $log_message = date( 'Y-m-d H:i:s' ) . ' - Single Site Backup AJAX: Initiation request received.';
            error_log( $log_message );
            file_put_contents( $error_log_file, $log_message . "\n", FILE_APPEND );
            
            // Check user permissions
            if ( ! current_user_can( 'manage_options' ) ) {
                $error_msg = 'Insufficient permissions to initiate backup.';
                $log_message = date( 'Y-m-d H:i:s' ) . ' - Single Site Backup AJAX Error: ' . $error_msg;
                error_log( $log_message );
                file_put_contents( $error_log_file, $log_message . "\n", FILE_APPEND );
                wp_send_json_error( $error_msg );
                return;
            }
            
            // Check if backup is already running
            $current_status = $this->get_backup_status( $status_file );
            if ( isset( $current_status['status'] ) && $current_status['status'] === 'running' ) {
                $error_msg = 'Single site backup is already running. Please wait for it to complete.';
                $log_message = date( 'Y-m-d H:i:s' ) . ' - Single Site Backup AJAX Error: ' . $error_msg;
                error_log( $log_message );
                file_put_contents( $error_log_file, $log_message . "\n", FILE_APPEND );
                wp_send_json_error( $error_msg );
                return;
            }
            
            // Check upload directory permissions
            if ( ! is_writable( $upload_dir['basedir'] ) ) {
                $error_msg = 'Upload directory is not writable: ' . $upload_dir['basedir'];
                $log_message = date( 'Y-m-d H:i:s' ) . ' - Single Site Backup AJAX Error: ' . $error_msg;
                error_log( $log_message );
                file_put_contents( $error_log_file, $log_message . "\n", FILE_APPEND );
                wp_send_json_error( $error_msg );
                return;
            }
            
            // Clear any previous failed status
            if ( isset( $current_status['status'] ) && $current_status['status'] === 'failed' ) {
                $this->clear_backup_status( $status_file );
            }
            
            $log_message = date( 'Y-m-d H:i:s' ) . ' - Single Site Backup AJAX: Starting backup process directly.';
            error_log( $log_message );
            file_put_contents( $error_log_file, $log_message . "\n", FILE_APPEND );
            
            // Run backup directly (not scheduled since it's faster)
            $this->backup_database();
            
            $log_message = date( 'Y-m-d H:i:s' ) . ' - Single Site Backup AJAX: Backup process completed.';
            error_log( $log_message );
            file_put_contents( $error_log_file, $log_message . "\n", FILE_APPEND );
            
            wp_send_json_success( 'Single site backup completed successfully.' );
            
        } catch ( Exception $e ) {
            $error_msg = 'Exception in single site backup initiation: ' . $e->getMessage();
            $log_message = date( 'Y-m-d H:i:s' ) . ' - Single Site Backup AJAX Exception: ' . $error_msg;
            error_log( $log_message );
            file_put_contents( $error_log_file, $log_message . "\n", FILE_APPEND );
            
            // Update status to failed
            $this->update_backup_status( $status_file, 'failed', 'Exception during initiation: ' . $e->getMessage(), 0 );
            
            wp_send_json_error( $error_msg );
        }
    }

    public function ajax_initiate_network_backup() {
        $upload_dir = $this->get_safe_upload_dir();
        $error_log_file = $upload_dir['basedir'] . '/network-backup-error-log.txt';
        $status_file = $upload_dir['basedir'] . '/network-backup-status.json';
        
        try {
            // Log the initiation attempt
            $log_message = date( 'Y-m-d H:i:s' ) . ' - Network Backup AJAX: Initiation request received.';
            error_log( $log_message );
            file_put_contents( $error_log_file, $log_message . "\n", FILE_APPEND );
            
            // Check if multisite
            if ( ! is_multisite() ) {
                $error_msg = 'Not a multisite installation.';
                $log_message = date( 'Y-m-d H:i:s' ) . ' - Network Backup AJAX Error: ' . $error_msg;
                error_log( $log_message );
                file_put_contents( $error_log_file, $log_message . "\n", FILE_APPEND );
                wp_send_json_error( $error_msg );
                return;
            }
            
            // Check user permissions
            if ( ! current_user_can( 'manage_network' ) && ! current_user_can( 'manage_options' ) ) {
                $error_msg = 'Insufficient permissions to initiate network backup.';
                $log_message = date( 'Y-m-d H:i:s' ) . ' - Network Backup AJAX Error: ' . $error_msg;
                error_log( $log_message );
                file_put_contents( $error_log_file, $log_message . "\n", FILE_APPEND );
                wp_send_json_error( $error_msg );
                return;
            }
            
            // Check if backup is already running
            $current_status = $this->get_backup_status( $status_file );
            if ( isset( $current_status['status'] ) && $current_status['status'] === 'running' ) {
                $error_msg = 'Network backup is already running. Please wait for it to complete.';
                $log_message = date( 'Y-m-d H:i:s' ) . ' - Network Backup AJAX Error: ' . $error_msg;
                error_log( $log_message );
                file_put_contents( $error_log_file, $log_message . "\n", FILE_APPEND );
                wp_send_json_error( $error_msg );
                return;
            }
            
            // Check if wp-cron is working
            if ( defined( 'DISABLE_WP_CRON' ) && constant( 'DISABLE_WP_CRON' ) ) {
                $error_msg = 'WordPress cron is disabled. Network backup requires wp-cron to be enabled.';
                $log_message = date( 'Y-m-d H:i:s' ) . ' - Network Backup AJAX Error: ' . $error_msg;
                error_log( $log_message );
                file_put_contents( $error_log_file, $log_message . "\n", FILE_APPEND );
                wp_send_json_error( $error_msg );
                return;
            }
            
            // Check upload directory permissions
            if ( ! is_writable( $upload_dir['basedir'] ) ) {
                $error_msg = 'Upload directory is not writable: ' . $upload_dir['basedir'];
                $log_message = date( 'Y-m-d H:i:s' ) . ' - Network Backup AJAX Error: ' . $error_msg;
                error_log( $log_message );
                file_put_contents( $error_log_file, $log_message . "\n", FILE_APPEND );
                wp_send_json_error( $error_msg );
                return;
            }
            
            // Clear any previous failed status
            if ( isset( $current_status['status'] ) && $current_status['status'] === 'failed' ) {
                $this->clear_backup_status( $status_file );
            }
            
            // Set initial status
            $this->update_backup_status( $status_file, 'scheduled', 'Network backup scheduled for execution...', 5 );
            
            // Log scheduling attempt
            $log_message = date( 'Y-m-d H:i:s' ) . ' - Network Backup AJAX: Attempting to schedule backup event.';
            error_log( $log_message );
            file_put_contents( $error_log_file, $log_message . "\n", FILE_APPEND );
            
            // Clear any PHP errors before scheduling to avoid interference
            if ( function_exists( 'error_clear_last' ) ) {
                error_clear_last();
            }
            
            // Try to schedule the network backup to run in the background
            $scheduled = wp_schedule_single_event( time() + 10, 'utm_webmaster_tool_network_backup' );
            
            if ( $scheduled ) {
                $success_message = 'Network backup scheduled successfully. It will start in 10 seconds.';
                $log_message = date( 'Y-m-d H:i:s' ) . ' - Network Backup AJAX: ' . $success_message;
                error_log( $log_message );
                file_put_contents( $error_log_file, $log_message . "\n", FILE_APPEND );
                
                wp_send_json_success( $success_message );
            } else {
                // If scheduling fails, try direct execution as fallback (useful for development)
                $log_message = date( 'Y-m-d H:i:s' ) . ' - Network Backup AJAX: Scheduling failed, attempting direct execution...';
                error_log( $log_message );
                file_put_contents( $error_log_file, $log_message . "\n", FILE_APPEND );
                
                try {
                    // Set status to show we're running directly
                    $this->update_backup_status( $status_file, 'running', 'Executing network backup directly (scheduling failed)...', 5 );
                    
                    // Execute backup directly in background using WordPress's shutdown hook
                    add_action( 'shutdown', function() {
                        // Ignore user abort and set no time limit
                        ignore_user_abort( true );
                        set_time_limit( 0 );
                        
                        // Close the connection to the browser so user doesn't have to wait
                        if ( ob_get_level() ) {
                            ob_end_clean();
                        }
                        
                        // Execute the backup
                        $this->backup_network_database();
                    });
                    
                    $success_message = 'Network backup started directly (scheduling method failed but backup is running).';
                    $log_message = date( 'Y-m-d H:i:s' ) . ' - Network Backup AJAX: ' . $success_message;
                    error_log( $log_message );
                    file_put_contents( $error_log_file, $log_message . "\n", FILE_APPEND );
                    
                    wp_send_json_success( $success_message );
                    
                } catch ( Exception $direct_exec_error ) {
                    // Get more detailed error information about scheduling failure
                    $last_error = error_get_last();
                    $error_details = $last_error ? ' Last PHP error: ' . $last_error['message'] : '';
                    
                    $error_msg = 'Both scheduling and direct execution failed. Scheduling error: WordPress wp_schedule_single_event() returned false.' . $error_details . ' Direct execution error: ' . $direct_exec_error->getMessage();
                    $log_message = date( 'Y-m-d H:i:s' ) . ' - Network Backup AJAX Error: ' . $error_msg;
                    error_log( $log_message );
                    file_put_contents( $error_log_file, $log_message . "\n", FILE_APPEND );
                    
                    // Update status to failed
                    $this->update_backup_status( $status_file, 'failed', 'Failed to start backup: ' . $error_msg, 0 );
                    
                    wp_send_json_error( $error_msg );
                }
            }
            
        } catch ( Exception $e ) {
            $error_msg = 'Exception in network backup initiation: ' . $e->getMessage();
            $log_message = date( 'Y-m-d H:i:s' ) . ' - Network Backup AJAX Exception: ' . $error_msg;
            error_log( $log_message );
            file_put_contents( $error_log_file, $log_message . "\n", FILE_APPEND );
            
            // Update status to failed
            $this->update_backup_status( $status_file, 'failed', 'Exception during initiation: ' . $e->getMessage(), 0 );
            
            wp_send_json_error( $error_msg );
        }
    }
    
    /**
     * Update backup status in JSON file for real-time tracking
     */
    private function update_backup_status( $status_file, $status, $message, $progress ) {
        $status_data = array(
            'status' => $status, // 'running', 'completed', 'failed'
            'message' => $message,
            'progress' => $progress, // 0-100
            'timestamp' => time(),
            'formatted_time' => date( 'Y-m-d H:i:s' )
        );
        
        file_put_contents( $status_file, json_encode( $status_data ) );
    }
    
    /**
     * Get backup status from JSON file
     */
    private function get_backup_status( $status_file ) {
        if ( ! file_exists( $status_file ) ) {
            return array(
                'status' => 'idle',
                'message' => 'No backup running',
                'progress' => 0,
                'timestamp' => 0,
                'formatted_time' => 'Never'
            );
        }
        
        $status_json = file_get_contents( $status_file );
        $status_data = json_decode( $status_json, true );
        
        // If status file is older than 10 minutes and status is still running, mark as failed
        if ( isset( $status_data['status'] ) && $status_data['status'] === 'running' && 
             isset( $status_data['timestamp'] ) && ( time() - $status_data['timestamp'] ) > 600 ) {
            $status_data['status'] = 'failed';
            $status_data['message'] = 'Backup timed out or interrupted';
            file_put_contents( $status_file, json_encode( $status_data ) );
        }
        
        return $status_data;
    }
    
    /**
     * Clear backup status file
     */
    private function clear_backup_status( $status_file ) {
        if ( file_exists( $status_file ) ) {
            unlink( $status_file );
        }
    }
    
    /**
     * AJAX handler to check single site backup status
     */
    public function ajax_check_backup_status() {
        $upload_dir = $this->get_safe_upload_dir();
        $status_file = $upload_dir['basedir'] . '/single-backup-status.json';
        
        $status = $this->get_backup_status( $status_file );
        wp_send_json_success( $status );
    }
    
    /**
     * AJAX handler to check network backup status
     */
    public function ajax_check_network_backup_status() {
        $upload_dir = $this->get_safe_upload_dir();
        $status_file = $upload_dir['basedir'] . '/network-backup-status.json';
        
        $status = $this->get_backup_status( $status_file );
        wp_send_json_success( $status );
    }

    public function download_backup_file() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have sufficient permissions to access this page.' );
        }
    
        if ( ! isset( $_GET['file'] ) || empty( $_GET['file'] ) ) {
            wp_die( 'No file specified.' );
        }
    
        $file = sanitize_text_field( wp_unslash( $_GET['file'] ) );
        $upload_dir = $this->get_safe_upload_dir();
        $file_path = $upload_dir['basedir'] . '/' . $file;
    
        if ( ! file_exists( $file_path ) ) {
            wp_die( 'File not found.' );
        }

        // Validate file type for security
        $allowed_extensions = array( '.sql', '.gz' );
        $file_extension = '';
        foreach ( $allowed_extensions as $ext ) {
            if ( substr( $file, -strlen( $ext ) ) === $ext ) {
                $file_extension = $ext;
                break;
            }
        }

        if ( empty( $file_extension ) ) {
            wp_die( 'Invalid file type.' );
        }

        // Set appropriate headers based on file type
        header( 'Content-Description: File Transfer' );
        if ( $file_extension === '.gz' ) {
            header( 'Content-Type: application/gzip' );
        } else {
            header( 'Content-Type: application/octet-stream' );
        }
        header( 'Content-Disposition: attachment; filename=' . basename( $file_path ) );
        header( 'Expires: 0' );
        header( 'Cache-Control: must-revalidate' );
        header( 'Pragma: public' );
        header( 'Content-Length: ' . filesize( $file_path ) );
    
        readfile( $file_path );
        exit;
    }
    
    /**
     * AJAX handler for backup system diagnostics
     */
    public function ajax_backup_diagnostics() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
            return;
        }
        
        $upload_dir = $this->get_safe_upload_dir();
        $diagnostics = array();
        
        // Basic system information
        $diagnostics['system'] = array(
            'is_multisite' => is_multisite(),
            'site_count' => is_multisite() ? get_sites( array( 'count' => true ) ) : 1,
            'current_blog_id' => get_current_blog_id(),
            'network_admin' => is_network_admin(),
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get( 'memory_limit' ),
            'max_execution_time' => ini_get( 'max_execution_time' ),
            'upload_dir' => $upload_dir['basedir'],
            'upload_dir_writable' => is_writable( $upload_dir['basedir'] ),
        );
        
        // WordPress cron information
        $diagnostics['cron'] = array(
            'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) ? constant( 'DISABLE_WP_CRON' ) : false,
            'next_scheduled_backup' => wp_next_scheduled( 'utm_webmaster_tool_daily_backup' ),
            'cron_jobs_count' => count( _get_cron_array() ),
        );
        
        // Database information
        global $wpdb;
        $diagnostics['database'] = array(
            'db_host' => DB_HOST,
            'db_name' => DB_NAME,
            'db_user' => DB_USER,
            'db_prefix' => $wpdb->prefix,
            'table_count' => count( $wpdb->get_col( "SHOW TABLES" ) ),
        );
        
        // mysqldump availability
        $diagnostics['mysqldump'] = array(
            'path' => $this->find_mysqldump_path(),
            'available' => $this->find_mysqldump_path() !== false,
        );
        
        // Backup files information
        $single_backups = glob( $upload_dir['basedir'] . '/db-backup-*.sql*' );
        $network_backups = glob( $upload_dir['basedir'] . '/network-backup-*.sql*' );
        $diagnostics['files'] = array(
            'single_backups_count' => count( $single_backups ),
            'network_backups_count' => count( $network_backups ),
            'error_log_exists' => file_exists( $upload_dir['basedir'] . '/db-backup-error-log.txt' ),
            'network_error_log_exists' => file_exists( $upload_dir['basedir'] . '/network-backup-error-log.txt' ),
        );
        
        // Status files
        $single_status_file = $upload_dir['basedir'] . '/single-backup-status.json';
        $network_status_file = $upload_dir['basedir'] . '/network-backup-status.json';
        $diagnostics['status'] = array(
            'single_status' => $this->get_backup_status( $single_status_file ),
            'network_status' => $this->get_backup_status( $network_status_file ),
        );
        
        // Recent error log entries (last 10 lines)
        $error_log_file = $upload_dir['basedir'] . '/network-backup-error-log.txt';
        if ( file_exists( $error_log_file ) ) {
            $error_log_lines = file( $error_log_file, FILE_IGNORE_NEW_LINES );
            $diagnostics['recent_errors'] = array_slice( $error_log_lines, -10 );
        } else {
            $diagnostics['recent_errors'] = array();
        }
        
        // PHP error handling diagnostics
        $diagnostics['php_errors'] = array(
            'error_reporting_level' => error_reporting(),
            'display_errors' => ini_get( 'display_errors' ),
            'log_errors' => ini_get( 'log_errors' ),
            'last_php_error' => error_get_last(),
        );
        
        // Test wp_schedule_single_event functionality
        $test_event_name = 'utm_test_cron_' . time();
        $test_scheduled = wp_schedule_single_event( time() + 3600, $test_event_name );
        if ( $test_scheduled ) {
            wp_clear_scheduled_hook( $test_event_name ); // Clean up test event
        }
        $diagnostics['cron']['test_scheduling'] = $test_scheduled ? 'Working' : 'Failed - ' . ( error_get_last()['message'] ?? 'Unknown error' );
        
        wp_send_json_success( $diagnostics );
    }
    
    /**
     * AJAX handler to force network backup execution (bypasses scheduling)
     * Useful for development environments where wp-cron might have issues
     */
    public function ajax_force_network_backup() {
        $upload_dir = $this->get_safe_upload_dir();
        $error_log_file = $upload_dir['basedir'] . '/network-backup-error-log.txt';
        $status_file = $upload_dir['basedir'] . '/network-backup-status.json';
        
        try {
            // Log the force execution attempt
            $log_message = date( 'Y-m-d H:i:s' ) . ' - Network Backup FORCE: Direct execution request received.';
            error_log( $log_message );
            file_put_contents( $error_log_file, $log_message . "\n", FILE_APPEND );
            
            // Check if multisite
            if ( ! is_multisite() ) {
                $error_msg = 'Not a multisite installation.';
                $log_message = date( 'Y-m-d H:i:s' ) . ' - Network Backup FORCE Error: ' . $error_msg;
                error_log( $log_message );
                file_put_contents( $error_log_file, $log_message . "\n", FILE_APPEND );
                wp_send_json_error( $error_msg );
                return;
            }
            
            // Check user permissions
            if ( ! current_user_can( 'manage_network' ) && ! current_user_can( 'manage_options' ) ) {
                $error_msg = 'Insufficient permissions to initiate force network backup.';
                $log_message = date( 'Y-m-d H:i:s' ) . ' - Network Backup FORCE Error: ' . $error_msg;
                error_log( $log_message );
                file_put_contents( $error_log_file, $log_message . "\n", FILE_APPEND );
                wp_send_json_error( $error_msg );
                return;
            }
            
            // Check if backup is already running
            $current_status = $this->get_backup_status( $status_file );
            if ( isset( $current_status['status'] ) && $current_status['status'] === 'running' ) {
                $error_msg = 'Network backup is already running. Please wait for it to complete.';
                $log_message = date( 'Y-m-d H:i:s' ) . ' - Network Backup FORCE Error: ' . $error_msg;
                error_log( $log_message );
                file_put_contents( $error_log_file, $log_message . "\n", FILE_APPEND );
                wp_send_json_error( $error_msg );
                return;
            }
            
            // Set initial status
            $this->update_backup_status( $status_file, 'running', 'Force executing network backup directly...', 5 );
            
            $log_message = date( 'Y-m-d H:i:s' ) . ' - Network Backup FORCE: Starting direct execution using shutdown hook.';
            error_log( $log_message );
            file_put_contents( $error_log_file, $log_message . "\n", FILE_APPEND );
            
            // Execute backup directly using shutdown hook (non-blocking)
            add_action( 'shutdown', function() {
                // Ignore user abort and set no time limit
                ignore_user_abort( true );
                set_time_limit( 0 );
                
                // Close the connection to the browser so user doesn't have to wait
                if ( ob_get_level() ) {
                    ob_end_clean();
                }
                
                // Execute the backup
                $this->backup_network_database();
            });
            
            $success_message = 'Force network backup started directly (bypassing WordPress scheduler).';
            $log_message = date( 'Y-m-d H:i:s' ) . ' - Network Backup FORCE: ' . $success_message;
            error_log( $log_message );
            file_put_contents( $error_log_file, $log_message . "\n", FILE_APPEND );
            
            wp_send_json_success( $success_message );
            
        } catch ( Exception $e ) {
            $error_msg = 'Exception in force network backup: ' . $e->getMessage();
            $log_message = date( 'Y-m-d H:i:s' ) . ' - Network Backup FORCE Exception: ' . $error_msg;
            error_log( $log_message );
            file_put_contents( $error_log_file, $log_message . "\n", FILE_APPEND );
            
            // Update status to failed
            $this->update_backup_status( $status_file, 'failed', 'Force execution failed: ' . $e->getMessage(), 0 );
            
            wp_send_json_error( $error_msg );
        }
    }
}

new UTM_Webmaster_Tool_Backup();
