<?php
// File: /g:/My Drive/Projects/plugins/utm-webmaster-tool/modules/backup.php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Enhanced Database and Menu Backup Class
 * 
 * Features:
 * - Daily full database backups at midnight via WordPress cron
 * - On-demand database backups through admin interface
 * - Database restore functionality with safety checks
 * - Incremental menu backups with version history
 * - Menu restore with preview capability
 * - Improved backup file detection and management
 * - Email notifications for backup operations
 * - Backup integrity verification
 */
class UTM_Webmaster_Tool_Backup {
    
    private $backup_dir;
    private $menu_backup_dir;
    private $max_menu_versions = 20; // Keep last 20 menu versions
    
    public function __construct() {
        // Initialize backup directories
        $this->init_backup_directories();
        
        // Add weekly schedule and migrate off daily
        add_filter( 'cron_schedules', array( $this, 'add_weekly_cron_schedule' ) );
        add_action( 'admin_init', array( $this, 'schedule_weekly_backup' ) );
        
        // Hook for the scheduled weekly backup
        add_action( 'utm_webmaster_weekly_backup', array( $this, 'create_database_backup' ) );
        
        // Add admin page
        add_action( 'admin_menu', array( $this, 'add_backup_page' ) );
        
        // Handle manual backup request
        add_action( 'wp_ajax_utm_manual_backup', array( $this, 'handle_manual_backup' ) );
        
        // Handle backup download
        add_action( 'admin_post_utm_download_backup', array( $this, 'download_backup_file' ) );
        
        // Handle backup restore
        add_action( 'wp_ajax_utm_restore_backup', array( $this, 'handle_restore_backup' ) );
        
        // Menu backup hooks
        add_action( 'wp_update_nav_menu', array( $this, 'backup_menu_on_save' ), 10, 2 );
        add_action( 'wp_delete_nav_menu', array( $this, 'backup_menu_before_delete' ) );
        
        // AJAX handlers for menu operations
        add_action( 'wp_ajax_utm_backup_menu', array( $this, 'handle_menu_backup' ) );
        add_action( 'wp_ajax_utm_restore_menu', array( $this, 'handle_menu_restore' ) );
        add_action( 'wp_ajax_utm_preview_menu', array( $this, 'handle_menu_preview' ) );
        add_action( 'wp_ajax_utm_delete_backup', array( $this, 'handle_delete_backup' ) );
    }
    
    /**
     * Initialize backup directories with proper permissions
     */
    private function init_backup_directories() {
        $upload_dir = wp_upload_dir();
        $base_dir = trailingslashit( $upload_dir['basedir'] ) . 'utm-backups';
        
        $this->backup_dir = $base_dir . '/database';
        $this->menu_backup_dir = $base_dir . '/menus';
        
        // Create directories if they don't exist
        if ( ! file_exists( $this->backup_dir ) ) {
            wp_mkdir_p( $this->backup_dir );
            // Add .htaccess for security
            file_put_contents( $this->backup_dir . '/.htaccess', 'Deny from all' );
        }
        
        if ( ! file_exists( $this->menu_backup_dir ) ) {
            wp_mkdir_p( $this->menu_backup_dir );
            file_put_contents( $this->menu_backup_dir . '/.htaccess', 'Deny from all' );
        }
    }
    
    /**
     * Add a weekly cron schedule if not present
     */
    public function add_weekly_cron_schedule( $schedules ) {
        if ( ! isset( $schedules['weekly'] ) ) {
            $schedules['weekly'] = array(
                'interval' => 7 * DAY_IN_SECONDS,
                'display'  => __( 'Once Weekly' ),
            );
        }
        return $schedules;
    }

    /**
     * Schedule weekly backup at a randomized per-site weekday/time (kept stable)
     * Moves off any existing daily schedule.
     */
    public function schedule_weekly_backup() {
        // Migrate: unschedule any existing daily event
        $next_daily = wp_next_scheduled( 'utm_webmaster_daily_backup' );
        if ( $next_daily ) {
            wp_unschedule_event( $next_daily, 'utm_webmaster_daily_backup' );
        }

        // Ensure we have persisted weekly day/time like 'D-HH:MM' where D=0..6 (0=Sun in PHP)
        $weekly_spec = get_option( 'utm_backup_weekly_time' );
        if ( empty( $weekly_spec ) || ! preg_match( '/^[0-6]-([01]?\d|2[0-3]):[0-5]\d$/', $weekly_spec ) ) {
            $weekly_spec = $this->generate_and_store_weekly_time();
        }

        list( $day, $hm ) = explode( '-', $weekly_spec );
        list( $hour, $minute ) = array_map( 'intval', explode( ':', $hm ) );
        $day = intval( $day );

        // Compute next occurrence of this weekday at HH:MM in WP timezone
        $now = current_time( 'timestamp' );
        $w   = (int) date( 'w', $now ); // 0 (Sun) .. 6 (Sat)

        $days_ahead = ($day - $w + 7) % 7;
        $candidate = strtotime( date( 'Y-m-d', $now ) . sprintf( ' %02d:%02d:00', $hour, $minute ), $now );
        if ( $days_ahead === 0 ) {
            // Same day; if time already passed today, schedule next week
            if ( $candidate <= $now ) {
                $days_ahead = 7;
            }
        }
        if ( $days_ahead > 0 ) {
            $candidate = strtotime( '+' . $days_ahead . ' days', $candidate );
        }

        $next = wp_next_scheduled( 'utm_webmaster_weekly_backup' );
        if ( ! $next ) {
            wp_schedule_event( $candidate, 'weekly', 'utm_webmaster_weekly_backup' );
            error_log( 'UTM Backup: Weekly backup scheduled for ' . date( 'Y-m-d H:i:s', $candidate ) . ' (spec ' . $weekly_spec . ')' );
        } else {
            // If drifted >10 minutes, reschedule
            $delta = abs( $next - $candidate );
            if ( $delta > 600 ) {
                wp_unschedule_event( $next, 'utm_webmaster_weekly_backup' );
                wp_schedule_event( $candidate, 'weekly', 'utm_webmaster_weekly_backup' );
                error_log( 'UTM Backup: Weekly backup rescheduled for ' . date( 'Y-m-d H:i:s', $candidate ) . ' (spec ' . $weekly_spec . ')' );
            }
        }
    }

    /**
     * Generate and persist deterministic per-site weekly backup spec 'D-HH:MM'
     */
    private function generate_and_store_weekly_time() {
        $site_url = get_site_url();
        $install_time = get_option( 'utmw_install_time' );
        if ( ! $install_time ) {
            $install_time = time();
            update_option( 'utmw_install_time', $install_time, true );
        }
        $hash = md5( 'weekly|' . $site_url . '|' . $install_time );
        $day    = hexdec( substr( $hash, 0, 2 ) ) % 7;    // 0-6 Sunday..Saturday
        $hour   = hexdec( substr( $hash, 2, 2 ) ) % 24;   // 0-23
        $minute = hexdec( substr( $hash, 4, 2 ) ) % 60;   // 0-59
        if ( $minute === 0 ) { $minute = 5; }
        $spec = sprintf( '%d-%02d:%02d', $day, $hour, $minute );
        update_option( 'utm_backup_weekly_time', $spec, true );
        return $spec;
    }
    
    /**
     * Create a database backup using mysqldump
     */
    public function create_database_backup() {
        $timestamp = date('Ymd-His');
        $backup_file = $this->backup_dir . '/database-backup-' . $timestamp . '.sql';
        $compressed_file = $backup_file . '.gz';
        
        try {
            error_log( 'UTM Backup: Starting database backup...' );
            
            // Create mysqldump command
            $command = $this->build_mysqldump_command( $backup_file );
            
            if ( ! $command ) {
                throw new Exception( 'mysqldump not available on this system' );
            }
            
            // Execute mysqldump
            exec( $command . ' 2>&1', $output, $return_code );
            
            if ( $return_code !== 0 ) {
                throw new Exception( 'mysqldump failed: ' . implode( "\n", $output ) );
            }
            
            if ( ! file_exists( $backup_file ) || filesize( $backup_file ) === 0 ) {
                throw new Exception( 'Backup file was not created or is empty' );
            }
            
            // Verify backup integrity
            if ( ! $this->verify_backup_integrity( $backup_file ) ) {
                throw new Exception( 'Backup integrity check failed' );
            }
            
            // Compress the backup
            $this->compress_backup( $backup_file, $compressed_file );
            
            // Remove uncompressed file
            if ( file_exists( $compressed_file ) && filesize( $compressed_file ) > 0 ) {
                unlink( $backup_file );
                $final_file = basename( $compressed_file );
                $file_size = size_format( filesize( $compressed_file ) );
            } else {
                $final_file = basename( $backup_file );
                $file_size = size_format( filesize( $backup_file ) );
            }
            
            // Clean up old backups (keep last 7 days)
            $this->cleanup_old_backups();
            
            error_log( 'UTM Backup: Database backup completed successfully: ' . $final_file . ' (' . $file_size . ')' );
            
            // Send email notification if enabled
            $this->send_backup_notification( true, $final_file, $file_size );
            
            return array(
                'success' => true,
                'file' => $final_file,
                'size' => $file_size
            );
            
        } catch ( Exception $e ) {
            $error_msg = 'UTM Backup Error: ' . $e->getMessage();
            error_log( $error_msg );
            
            // Log to custom error file
            $this->log_error( $error_msg );
            
            // Send error notification
            $this->send_backup_notification( false, null, null, $error_msg );
            
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }
    
    /**
     * Verify backup file integrity
     */
    private function verify_backup_integrity( $backup_file ) {
        $handle = fopen( $backup_file, 'r' );
        if ( ! $handle ) {
            return false;
        }
        
        $has_dump_header = false;
        $has_content = false;
        $line_count = 0;
        
        while ( ( $line = fgets( $handle ) ) !== false && $line_count < 100 ) {
            if ( strpos( $line, '-- MySQL dump' ) !== false || strpos( $line, '-- MariaDB dump' ) !== false ) {
                $has_dump_header = true;
            }
            if ( strpos( $line, 'CREATE TABLE' ) !== false || strpos( $line, 'INSERT INTO' ) !== false ) {
                $has_content = true;
            }
            $line_count++;
        }
        
        fclose( $handle );
        
        return $has_dump_header && $has_content;
    }
    
    /**
     * Send email notification about backup status
     */
    private function send_backup_notification( $success, $filename = null, $filesize = null, $error = null ) {
        // Check if notifications are enabled (can be added as an option later)
        $notify = get_option( 'utm_backup_email_notifications', false );
        if ( ! $notify ) {
            return;
        }
        
        $admin_email = get_option( 'admin_email' );
        $site_name = get_bloginfo( 'name' );
        
        if ( $success ) {
            $subject = sprintf( '[%s] Database Backup Successful', $site_name );
            $message = sprintf(
                "Database backup completed successfully.\n\nBackup File: %s\nFile Size: %s\nTimestamp: %s\n\nThis is an automated notification.",
                $filename,
                $filesize,
                current_time( 'mysql' )
            );
        } else {
            $subject = sprintf( '[%s] Database Backup FAILED', $site_name );
            $message = sprintf(
                "Database backup failed!\n\nError: %s\nTimestamp: %s\n\nPlease check your backup configuration and server logs.\n\nThis is an automated notification.",
                $error,
                current_time( 'mysql' )
            );
        }
        
        wp_mail( $admin_email, $subject, $message );
    }
    
    /**
     * Log error to custom error file
     */
    private function log_error( $error_msg ) {
        $error_file = $this->backup_dir . '/backup-errors.log';
        $log_entry = date( 'Y-m-d H:i:s' ) . ' - ' . $error_msg . "\n";
        file_put_contents( $error_file, $log_entry, FILE_APPEND );
    }
    
    /**
     * Build mysqldump command with proper escaping
     */
    private function build_mysqldump_command( $backup_file ) {
        // Get database connection details
        $db_host = DB_HOST;
        $db_name = DB_NAME;
        $db_user = DB_USER;
        $db_password = DB_PASSWORD;
        
        // Find mysqldump executable
        $mysqldump_path = $this->find_mysqldump();
        if ( ! $mysqldump_path ) {
            return false;
        }
        
        // Parse host and port
        $host_parts = explode( ':', $db_host );
        $host = $host_parts[0];
        $port = isset( $host_parts[1] ) ? $host_parts[1] : '3306';
        
        // Build command with proper escaping
        $command = sprintf(
            '%s --single-transaction --routines --triggers --lock-tables=false --host=%s --port=%s --user=%s --password=%s %s > %s',
            escapeshellarg( $mysqldump_path ),
            escapeshellarg( $host ),
            escapeshellarg( $port ),
            escapeshellarg( $db_user ),
            escapeshellarg( $db_password ),
            escapeshellarg( $db_name ),
            escapeshellarg( $backup_file )
        );
        
        return $command;
    }
    
    /**
     * Find mysqldump executable in common locations
     */
    private function find_mysqldump() {
        $common_paths = array(
            'mysqldump', // System PATH
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            '/opt/lampp/bin/mysqldump', // XAMPP Linux
            '/Applications/XAMPP/xamppfiles/bin/mysqldump', // XAMPP macOS
            'C:\\xampp\\mysql\\bin\\mysqldump.exe', // XAMPP Windows
            'C:\\wamp64\\bin\\mysql\\mysql8.0.31\\bin\\mysqldump.exe', // WAMP Windows
            'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe', // MySQL Windows
        );
        
        foreach ( $common_paths as $path ) {
            if ( $this->is_executable( $path ) ) {
                return $path;
            }
        }
        
        return false;
    }
    
    /**
     * Check if executable is available
     */
    private function is_executable( $path ) {
        if ( PHP_OS_FAMILY === 'Windows' ) {
            return file_exists( $path );
        } else {
            if ( $path === 'mysqldump' ) {
                // Check if in system PATH
                exec( 'which mysqldump', $output, $return_code );
                return $return_code === 0;
            }
            return is_executable( $path );
        }
    }
    
    /**
     * Compress backup file using gzip
     */
    private function compress_backup( $source_file, $compressed_file ) {
        if ( ! function_exists( 'gzopen' ) ) {
            return false; // Skip compression if gzip not available
        }
        
        $source = fopen( $source_file, 'rb' );
        $compressed = gzopen( $compressed_file, 'wb9' );
        
        if ( ! $source || ! $compressed ) {
            return false;
        }
        
        while ( ! feof( $source ) ) {
            gzwrite( $compressed, fread( $source, 8192 ) );
        }
        
        fclose( $source );
        gzclose( $compressed );
        
        return true;
    }
    
    /**
     * Remove backup files older than configured retention period
     */
    private function cleanup_old_backups() {
        $retention_days = get_option( 'utm_backup_retention_days', 7 );
        $cutoff_time = time() - ( $retention_days * 24 * 60 * 60 );
        
        $backup_files = glob( $this->backup_dir . '/database-backup-*.sql*' );
        
        $deleted_count = 0;
        foreach ( $backup_files as $file ) {
            if ( filemtime( $file ) < $cutoff_time ) {
                if ( unlink( $file ) ) {
                    $deleted_count++;
                    error_log( 'UTM Backup: Removed old backup file: ' . basename( $file ) );
                }
            }
        }
        
        if ( $deleted_count > 0 ) {
            error_log( sprintf( 'UTM Backup: Cleaned up %d old backup file(s)', $deleted_count ) );
        }
    }
    
    /**
     * Backup menu automatically when it's saved
     */
    public function backup_menu_on_save( $menu_id, $menu_data = null ) {
        $this->create_menu_backup( $menu_id, 'auto_save' );
    }
    
    /**
     * Backup menu before it's deleted
     */
    public function backup_menu_before_delete( $menu_id ) {
        $this->create_menu_backup( $menu_id, 'before_delete' );
    }
    
    /**
     * Create a menu backup
     */
    private function create_menu_backup( $menu_id, $trigger = 'manual' ) {
        $menu = wp_get_nav_menu_object( $menu_id );
        if ( ! $menu ) {
            return false;
        }
        
        // Get all menu items
        $menu_items = wp_get_nav_menu_items( $menu_id );
        
        // Prepare backup data
        $backup_data = array(
            'menu_id' => $menu_id,
            'menu_name' => $menu->name,
            'menu_slug' => $menu->slug,
            'menu_locations' => get_nav_menu_locations(),
            'timestamp' => current_time( 'mysql' ),
            'trigger' => $trigger,
            'items' => array()
        );
        
        // Store menu items with full data
        if ( $menu_items ) {
            foreach ( $menu_items as $item ) {
                $backup_data['items'][] = array(
                    'ID' => $item->ID,
                    'title' => $item->title,
                    'url' => $item->url,
                    'menu_item_parent' => $item->menu_item_parent,
                    'menu_order' => $item->menu_order,
                    'object' => $item->object,
                    'object_id' => $item->object_id,
                    'type' => $item->type,
                    'type_label' => $item->type_label,
                    'target' => $item->target,
                    'attr_title' => $item->attr_title,
                    'description' => $item->description,
                    'classes' => $item->classes,
                    'xfn' => $item->xfn
                );
            }
        }
        
        // Save to file
        $timestamp = date( 'Ymd-His' );
        $filename = sprintf( 'menu-%d-%s-%s.json', $menu_id, sanitize_title( $menu->name ), $timestamp );
        $filepath = $this->menu_backup_dir . '/' . $filename;
        
        $json_data = json_encode( $backup_data, JSON_PRETTY_PRINT );
        file_put_contents( $filepath, $json_data );
        
        // Clean up old menu backups
        $this->cleanup_old_menu_backups( $menu_id );
        
        error_log( sprintf( 'UTM Backup: Menu "%s" backed up successfully: %s', $menu->name, $filename ) );
        
        return $filename;
    }
    
    /**
     * Clean up old menu backups (keep last N versions per menu)
     */
    private function cleanup_old_menu_backups( $menu_id ) {
        $pattern = $this->menu_backup_dir . '/menu-' . $menu_id . '-*.json';
        $menu_backups = glob( $pattern );
        
        // Sort by modification time, newest first
        usort( $menu_backups, function( $a, $b ) {
            return filemtime( $b ) - filemtime( $a );
        });
        
        // Remove backups beyond the limit
        $backups_to_remove = array_slice( $menu_backups, $this->max_menu_versions );
        foreach ( $backups_to_remove as $file ) {
            unlink( $file );
            error_log( 'UTM Backup: Removed old menu backup: ' . basename( $file ) );
        }
    }
    
    /**
     * Get all menu backups
     */
    private function get_menu_backups() {
        $backups = glob( $this->menu_backup_dir . '/menu-*.json' );
        $menu_backups = array();
        
        foreach ( $backups as $file ) {
            $data = json_decode( file_get_contents( $file ), true );
            if ( $data ) {
                $menu_backups[] = array(
                    'file' => basename( $file ),
                    'filepath' => $file,
                    'menu_id' => $data['menu_id'],
                    'menu_name' => $data['menu_name'],
                    'timestamp' => $data['timestamp'],
                    'trigger' => $data['trigger'],
                    'item_count' => count( $data['items'] ),
                    'size' => filesize( $file )
                );
            }
        }
        
        // Sort by timestamp, newest first
        usort( $menu_backups, function( $a, $b ) {
            return strtotime( $b['timestamp'] ) - strtotime( $a['timestamp'] );
        });
        
        return $menu_backups;
    }
    
    /**
     * Restore menu from backup
     */
    private function restore_menu_from_backup( $backup_file ) {
        $filepath = $this->menu_backup_dir . '/' . sanitize_file_name( $backup_file );
        
        if ( ! file_exists( $filepath ) ) {
            throw new Exception( 'Backup file not found' );
        }
        
        $backup_data = json_decode( file_get_contents( $filepath ), true );
        if ( ! $backup_data ) {
            throw new Exception( 'Invalid backup file format' );
        }
        
        // Check if menu exists
        $existing_menu = wp_get_nav_menu_object( $backup_data['menu_id'] );
        
        if ( $existing_menu ) {
            // Delete all existing menu items
            $existing_items = wp_get_nav_menu_items( $backup_data['menu_id'] );
            if ( $existing_items ) {
                foreach ( $existing_items as $item ) {
                    wp_delete_post( $item->ID, true );
                }
            }
            $menu_id = $backup_data['menu_id'];
        } else {
            // Create new menu
            $menu_id = wp_create_nav_menu( $backup_data['menu_name'] );
            if ( is_wp_error( $menu_id ) ) {
                throw new Exception( 'Failed to create menu: ' . $menu_id->get_error_message() );
            }
        }
        
        // Restore menu items
        $item_mapping = array(); // Old ID => New ID mapping for parent relationships
        
        foreach ( $backup_data['items'] as $item ) {
            $menu_item_data = array(
                'menu-item-title' => $item['title'],
                'menu-item-url' => $item['url'],
                'menu-item-status' => 'publish',
                'menu-item-type' => $item['type'],
                'menu-item-object' => $item['object'],
                'menu-item-object-id' => $item['object_id'],
                'menu-item-target' => $item['target'],
                'menu-item-attr-title' => $item['attr_title'],
                'menu-item-description' => $item['description'],
                'menu-item-classes' => implode( ' ', $item['classes'] ),
                'menu-item-xfn' => $item['xfn'],
                'menu-item-position' => $item['menu_order']
            );
            
            // Handle parent relationship (will be updated in second pass)
            if ( $item['menu_item_parent'] != 0 ) {
                $menu_item_data['menu-item-parent-id'] = $item['menu_item_parent'];
            }
            
            $new_item_id = wp_update_nav_menu_item( $menu_id, 0, $menu_item_data );
            
            if ( ! is_wp_error( $new_item_id ) ) {
                $item_mapping[ $item['ID'] ] = $new_item_id;
            }
        }
        
        // Second pass: Update parent relationships with new IDs
        foreach ( $backup_data['items'] as $item ) {
            if ( $item['menu_item_parent'] != 0 && isset( $item_mapping[ $item['ID'] ] ) && isset( $item_mapping[ $item['menu_item_parent'] ] ) ) {
                $new_item_id = $item_mapping[ $item['ID'] ];
                $new_parent_id = $item_mapping[ $item['menu_item_parent'] ];
                
                update_post_meta( $new_item_id, '_menu_item_menu_item_parent', $new_parent_id );
            }
        }
        
        error_log( sprintf( 'UTM Backup: Menu "%s" restored successfully from %s', $backup_data['menu_name'], $backup_file ) );
        
        return array(
            'menu_id' => $menu_id,
            'menu_name' => $backup_data['menu_name'],
            'items_restored' => count( $backup_data['items'] )
        );
    }
    
    /**
     * Add backup management page to admin menu
     */
    public function add_backup_page() {
        add_submenu_page(
            'utm-webmaster-dashboard',
            'UTM Backup & Restore',
            'UTM Backup',
            'manage_options',
            'utm-database-backup',
            array( $this, 'display_backup_page' )
        );
    }
    
    /**
     * Display backup management page
     */
    public function display_backup_page() {
        // Handle tab selection
        $active_tab = isset( $_GET['tab'] ) ? $_GET['tab'] : 'database';
        
        // Get next scheduled backup (weekly)
        $next_backup = wp_next_scheduled( 'utm_webmaster_weekly_backup' );
        $next_backup_time = $next_backup ? date( 'Y-m-d H:i:s', $next_backup ) : 'Not scheduled';
        $weekly_spec = get_option( 'utm_backup_weekly_time' );
        $day_names = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
        $weekly_human = 'Not set';
        if ( $weekly_spec && preg_match( '/^([0-6])-([0-2]\d):([0-5]\d)$/', $weekly_spec, $m ) ) {
            $d = intval( $m[1] );
            $h = intval( $m[2] );
            $mm = intval( $m[3] );
            $weekly_human = $day_names[$d] . ' ' . sprintf('%02d:%02d', $h, $mm);
        }
        
        // Get existing database backup files
        $backup_files = glob( $this->backup_dir . '/database-backup-*.sql*' );
        if ( $backup_files ) {
            // Sort by modification time, newest first
            usort( $backup_files, function( $a, $b ) {
                return filemtime( $b ) - filemtime( $a );
            });
        }
        
        // Get menu backups
        $menu_backups = $this->get_menu_backups();
        
        // Get error log
        $error_file = $this->backup_dir . '/backup-errors.log';
        $errors = file_exists( $error_file ) ? file_get_contents( $error_file ) : '';
        
        // Get all menus for backup
        $menus = wp_get_nav_menus();
        
        ?>
        <div class="wrap">
            <h1>UTM Backup & Restore</h1>
            
            <h2 class="nav-tab-wrapper">
                <a href="?page=utm-database-backup&tab=database" class="nav-tab <?php echo $active_tab === 'database' ? 'nav-tab-active' : ''; ?>">Database Backup</a>
                <a href="?page=utm-database-backup&tab=menus" class="nav-tab <?php echo $active_tab === 'menus' ? 'nav-tab-active' : ''; ?>">Menu Backup</a>
                <a href="?page=utm-database-backup&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
                <a href="?page=utm-database-backup&tab=docs" class="nav-tab <?php echo $active_tab === 'docs' ? 'nav-tab-active' : ''; ?>">Docs</a>
            </h2>
            
            <?php if ( $active_tab === 'database' ) : ?>
                <!-- DATABASE BACKUP TAB -->
                <div class="card">
                    <h2>Backup Schedule</h2>
                    <p><strong>Weekly Backup:</strong> Automatically runs once a week</p>
                    <p><strong>Configured Weekly Time:</strong> <?php echo esc_html( $weekly_human ); ?> (site timezone)</p>
                    <p><strong>Next Scheduled Backup:</strong> <?php echo esc_html( $next_backup_time ); ?></p>
                </div>
                
                <div class="card">
                    <h2>Manual Database Backup</h2>
                    <p>Create a full database backup right now:</p>
                    <button id="manual-backup-btn" class="button button-primary">Create Backup Now</button>
                    <div id="backup-status" style="margin-top: 10px;"></div>
                </div>
                
                <div class="card">
                    <h2>Existing Database Backups</h2>
                    <?php if ( empty( $backup_files ) ) : ?>
                        <p>No backup files found in: <code><?php echo esc_html( $this->backup_dir ); ?></code></p>
                        <p><em>Note: Backup files are now stored in a dedicated directory for better organization.</em></p>
                    <?php else : ?>
                        <table class="widefat">
                            <thead>
                                <tr>
                                    <th>Backup File</th>
                                    <th>Size</th>
                                    <th>Date Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $backup_files as $file ) : ?>
                                    <tr>
                                        <td><code><?php echo esc_html( basename( $file ) ); ?></code></td>
                                        <td><?php echo esc_html( size_format( filesize( $file ) ) ); ?></td>
                                        <td><?php echo esc_html( date( 'Y-m-d H:i:s', filemtime( $file ) ) ); ?></td>
                                        <td>
                                            <a href="<?php echo wp_nonce_url( admin_url( 'admin-post.php?action=utm_download_backup&file=' . urlencode( basename( $file ) ) . '&type=database' ), 'utm_download_backup' ); ?>" 
                                               class="button button-small">Download</a>
                                            <button class="button button-small restore-db-btn" data-file="<?php echo esc_attr( basename( $file ) ); ?>">Restore</button>
                                            <button class="button button-small button-link-delete delete-backup-btn" data-file="<?php echo esc_attr( basename( $file ) ); ?>" data-type="database">Delete</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p><small>Backups older than <?php echo esc_html( get_option( 'utm_backup_retention_days', 7 ) ); ?> days are automatically deleted.</small></p>
                    <?php endif; ?>
                </div>
                
            <?php elseif ( $active_tab === 'menus' ) : ?>
                <!-- MENU BACKUP TAB -->
                <div class="card">
                    <h2>Automatic Menu Backup</h2>
                    <p><strong>Protection Enabled:</strong> Your menus are automatically backed up every time you save them.</p>
                    <p><strong>Retention:</strong> Last <?php echo $this->max_menu_versions; ?> versions are kept for each menu.</p>
                    <p><em>Tip: If you accidentally delete or break a menu, you can restore it from any previous version below.</em></p>
                </div>
                
                <div class="card">
                    <h2>Create Manual Menu Backup</h2>
                    <p>Backup a specific menu right now:</p>
                    <select id="menu-select" class="regular-text">
                        <option value="">-- Select a menu --</option>
                        <?php foreach ( $menus as $menu ) : ?>
                            <option value="<?php echo esc_attr( $menu->term_id ); ?>"><?php echo esc_html( $menu->name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button id="manual-menu-backup-btn" class="button button-primary">Backup Selected Menu</button>
                    <div id="menu-backup-status" style="margin-top: 10px;"></div>
                </div>
                
                <div class="card">
                    <h2>Menu Backup History</h2>
                    <?php if ( empty( $menu_backups ) ) : ?>
                        <p>No menu backups found yet. Menus will be automatically backed up when you save them.</p>
                    <?php else : ?>
                        <table class="widefat">
                            <thead>
                                <tr>
                                    <th>Menu Name</th>
                                    <th>Items</th>
                                    <th>Backup Date</th>
                                    <th>Trigger</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $menu_backups as $backup ) : ?>
                                    <tr>
                                        <td><strong><?php echo esc_html( $backup['menu_name'] ); ?></strong></td>
                                        <td><?php echo esc_html( $backup['item_count'] ); ?> items</td>
                                        <td><?php echo esc_html( $backup['timestamp'] ); ?></td>
                                        <td>
                                            <?php
                                            $trigger_labels = array(
                                                'manual' => '👤 Manual',
                                                'auto_save' => '💾 Auto Save',
                                                'before_delete' => '🗑️ Before Delete'
                                            );
                                            echo isset( $trigger_labels[ $backup['trigger'] ] ) ? $trigger_labels[ $backup['trigger'] ] : esc_html( $backup['trigger'] );
                                            ?>
                                        </td>
                                        <td>
                                            <button class="button button-small preview-menu-btn" data-file="<?php echo esc_attr( $backup['file'] ); ?>">Preview</button>
                                            <button class="button button-small button-primary restore-menu-btn" data-file="<?php echo esc_attr( $backup['file'] ); ?>">Restore</button>
                                            <a href="<?php echo wp_nonce_url( admin_url( 'admin-post.php?action=utm_download_backup&file=' . urlencode( $backup['file'] ) . '&type=menu' ), 'utm_download_backup' ); ?>" 
                                               class="button button-small">Download</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
                
            <?php elseif ( $active_tab === 'settings' ) : ?>
                <!-- SETTINGS TAB -->
                <form method="post" action="options.php">
                    <?php settings_fields( 'utm_backup_settings' ); ?>
                    <div class="card">
                        <h2>Backup Settings</h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Weekly Backup Schedule</th>
                                <td>
                                    <?php $ws = get_option( 'utm_backup_weekly_time' ); ?>
                                    <input type="text" value="<?php echo esc_attr( $weekly_human ); ?>" class="regular-text" disabled />
                                    <p class="description">Backups run once per week at the above day/time (site timezone). Each site gets a unique slot to avoid server spikes.</p>
                                    <p style="margin-top:8px;">
                                        <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=utm-database-backup&tab=settings&rerandomize_weekly=1' ), 'utm_rerandomize_weekly_time' ); ?>" class="button">Re-randomize Weekly Time</a>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Database Backup Retention</th>
                                <td>
                                    <input type="number" name="utm_backup_retention_days" value="<?php echo esc_attr( get_option( 'utm_backup_retention_days', 7 ) ); ?>" min="1" max="365" class="small-text" />
                                    <p class="description">Number of days to keep database backups (1-365 days)</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Email Notifications</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="utm_backup_email_notifications" value="1" <?php checked( get_option( 'utm_backup_email_notifications' ), 1 ); ?> />
                                        Send email notifications for backup operations
                                    </label>
                                    <p class="description">Receive email alerts when backups succeed or fail</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Notification Email</th>
                                <td>
                                    <input type="email" name="utm_backup_notification_email" value="<?php echo esc_attr( get_option( 'utm_backup_notification_email', get_option( 'admin_email' ) ) ); ?>" class="regular-text" />
                                    <p class="description">Email address for backup notifications (defaults to admin email)</p>
                                </td>
                            </tr>
                        </table>
                        <?php submit_button(); ?>
                    </div>
                </form>
                
                <div class="card">
                    <h2>Backup Information</h2>
                    <table class="widefat">
                        <tr>
                            <td><strong>Database Backup Directory:</strong></td>
                            <td><code><?php echo esc_html( $this->backup_dir ); ?></code></td>
                        </tr>
                        <tr>
                            <td><strong>Menu Backup Directory:</strong></td>
                            <td><code><?php echo esc_html( $this->menu_backup_dir ); ?></code></td>
                        </tr>
                        <tr>
                            <td><strong>Total Database Backups:</strong></td>
                            <td><?php echo count( $backup_files ); ?> files</td>
                        </tr>
                        <tr>
                            <td><strong>Total Menu Backups:</strong></td>
                            <td><?php echo count( $menu_backups ); ?> versions</td>
                        </tr>
                        <tr>
                            <td><strong>mysqldump Available:</strong></td>
                            <td><?php echo $this->find_mysqldump() ? '✅ Yes' : '❌ No'; ?></td>
                        </tr>
                    </table>
                </div>
            <?php elseif ( $active_tab === 'docs' ) : ?>
                <!-- DOCS TAB -->
                <?php
                $doc_key = isset( $_GET['doc'] ) ? sanitize_key( $_GET['doc'] ) : 'quick';
                $docs_map = array(
                    'quick'   => 'BACKUP_QUICK_REFERENCE.md',
                    'full'    => 'BACKUP_MODULE_DOCUMENTATION.md',
                    'summary' => 'BACKUP_IMPROVEMENTS_SUMMARY.md',
                );
                $doc_labels = array(
                    'quick'   => 'Quick Reference',
                    'full'    => 'Full Documentation',
                    'summary' => 'Improvements Summary',
                );
                if ( ! isset( $docs_map[ $doc_key ] ) ) {
                    $doc_key = 'quick';
                }
                $base_path = plugin_dir_path( __FILE__ );
                $doc_path = $base_path . $docs_map[ $doc_key ];
                $doc_content = file_exists( $doc_path ) ? file_get_contents( $doc_path ) : 'Documentation file not found.';
                ?>
                <div class="card">
                    <h2>Documentation</h2>
                    <p>Select a document to view:</p>
                    <p>
                        <a class="button <?php echo $doc_key==='quick'?'button-primary':''; ?>" href="?page=utm-database-backup&tab=docs&doc=quick">Quick Reference</a>
                        <a class="button <?php echo $doc_key==='full'?'button-primary':''; ?>" href="?page=utm-database-backup&tab=docs&doc=full">Full Documentation</a>
                        <a class="button <?php echo $doc_key==='summary'?'button-primary':''; ?>" href="?page=utm-database-backup&tab=docs&doc=summary">Improvements Summary</a>
                    </p>
                    <div style="border:1px solid #e5e5e5; background:#fff; padding:12px; max-height:70vh; overflow:auto;">
                        <pre style="white-space:pre-wrap; word-break:break-word; font-family: Menlo,Consolas,monospace; font-size:12px; line-height:1.5; margin:0;">
<?php echo esc_html( $doc_content ); ?>
                        </pre>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ( ! empty( $errors ) ) : ?>
                <div class="card">
                    <h2>Recent Errors</h2>
                    <pre style="background: #f1f1f1; padding: 10px; max-height: 200px; overflow-y: auto; font-size: 12px;"><?php echo esc_html( $errors ); ?></pre>
                      <p><a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=utm-database-backup&clear_errors=1&tab=' . $active_tab ), 'clear_backup_errors' ); ?>" 
                          class="button">Clear Error Log</a></p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Menu Preview Modal -->
        <div id="menu-preview-modal" style="display:none;">
            <div class="menu-preview-content">
                <h2>Menu Preview</h2>
                <div id="menu-preview-body"></div>
                <button class="button" id="close-preview">Close</button>
            </div>
        </div>
        
        <style>
        #menu-preview-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            z-index: 100000;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .menu-preview-content {
            background: white;
            padding: 20px;
            max-width: 800px;
            max-height: 80vh;
            overflow-y: auto;
            border-radius: 4px;
        }
        .menu-preview-item {
            padding: 8px 12px;
            border-left: 3px solid #0073aa;
            margin: 5px 0;
            background: #f5f5f5;
        }
        .menu-preview-item.level-1 { margin-left: 20px; }
        .menu-preview-item.level-2 { margin-left: 40px; }
        .menu-preview-item.level-3 { margin-left: 60px; }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Database backup
            $('#manual-backup-btn').on('click', function() {
                var $btn = $(this);
                var $status = $('#backup-status');
                
                $btn.prop('disabled', true).text('Creating Backup...');
                $status.html('<div class="notice notice-info"><p>🔄 Creating database backup, please wait...</p></div>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'utm_manual_backup',
                        _ajax_nonce: '<?php echo wp_create_nonce( 'utm_manual_backup' ); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $status.html('<div class="notice notice-success"><p>✅ Backup created successfully!</p></div>');
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            $status.html('<div class="notice notice-error"><p>❌ Backup failed: ' + response.data + '</p></div>');
                        }
                    },
                    error: function() {
                        $status.html('<div class="notice notice-error"><p>❌ An error occurred while creating the backup.</p></div>');
                    },
                    complete: function() {
                        $btn.prop('disabled', false).text('Create Backup Now');
                    }
                });
            });
            
            // Manual menu backup
            $('#manual-menu-backup-btn').on('click', function() {
                var $btn = $(this);
                var $status = $('#menu-backup-status');
                var menuId = $('#menu-select').val();
                
                if (!menuId) {
                    alert('Please select a menu to backup.');
                    return;
                }
                
                $btn.prop('disabled', true).text('Creating Backup...');
                $status.html('<div class="notice notice-info"><p>🔄 Backing up menu...</p></div>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'utm_backup_menu',
                        menu_id: menuId,
                        _ajax_nonce: '<?php echo wp_create_nonce( 'utm_backup_menu' ); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $status.html('<div class="notice notice-success"><p>✅ Menu backed up successfully!</p></div>');
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            $status.html('<div class="notice notice-error"><p>❌ ' + response.data + '</p></div>');
                        }
                    },
                    complete: function() {
                        $btn.prop('disabled', false).text('Backup Selected Menu');
                    }
                });
            });
            
            // Preview menu
            $('.preview-menu-btn').on('click', function() {
                var file = $(this).data('file');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'utm_preview_menu',
                        file: file,
                        _ajax_nonce: '<?php echo wp_create_nonce( 'utm_preview_menu' ); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#menu-preview-body').html(response.data.html);
                            $('#menu-preview-modal').show();
                        } else {
                            alert('Failed to load preview: ' + response.data);
                        }
                    }
                });
            });
            
            $('#close-preview').on('click', function() {
                $('#menu-preview-modal').hide();
            });
            
            // Restore menu
            $('.restore-menu-btn').on('click', function() {
                var file = $(this).data('file');
                
                if (!confirm('Are you sure you want to restore this menu? This will replace the current menu configuration.')) {
                    return;
                }
                
                var $btn = $(this);
                $btn.prop('disabled', true).text('Restoring...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'utm_restore_menu',
                        file: file,
                        _ajax_nonce: '<?php echo wp_create_nonce( 'utm_restore_menu' ); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('✅ Menu restored successfully! ' + response.data.items_restored + ' items restored.');
                            location.reload();
                        } else {
                            alert('❌ Failed to restore menu: ' + response.data);
                            $btn.prop('disabled', false).text('Restore');
                        }
                    },
                    error: function() {
                        alert('An error occurred while restoring the menu.');
                        $btn.prop('disabled', false).text('Restore');
                    }
                });
            });
            
            // Delete backup
            $('.delete-backup-btn').on('click', function() {
                var file = $(this).data('file');
                var type = $(this).data('type');
                
                if (!confirm('Are you sure you want to delete this backup file?')) {
                    return;
                }
                
                var $btn = $(this);
                $btn.prop('disabled', true);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'utm_delete_backup',
                        file: file,
                        type: type,
                        _ajax_nonce: '<?php echo wp_create_nonce( 'utm_delete_backup' ); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $btn.closest('tr').fadeOut(function() {
                                $(this).remove();
                            });
                        } else {
                            alert('Failed to delete backup: ' + response.data);
                            $btn.prop('disabled', false);
                        }
                    }
                });
            });
            
            // Restore database (placeholder - implement with caution)
            $('.restore-db-btn').on('click', function() {
                alert('⚠️ Database restore functionality requires careful implementation.\n\nFor safety, please restore database backups manually through phpMyAdmin or MySQL command line.\n\nThis prevents accidental data loss.');
            });
        });
        </script>
        <?php
        
        // Handle clear errors
        if ( isset( $_GET['clear_errors'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'clear_backup_errors' ) ) {
            if ( file_exists( $error_file ) ) {
                unlink( $error_file );
                echo '<div class="notice notice-success is-dismissible"><p>Error log cleared.</p></div>';
            }
        }
        
        // Handle weekly re-randomize request
        if ( isset( $_GET['rerandomize_weekly'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'utm_rerandomize_weekly_time' ) ) {
            $new_spec = $this->rerandomize_weekly_time_and_reschedule();
            echo '<div class="notice notice-success is-dismissible"><p>Weekly backup time updated.</p></div>';
        }

        // Register settings
        if ( isset( $_POST['utm_backup_retention_days'] ) ) {
            register_setting( 'utm_backup_settings', 'utm_backup_retention_days' );
            register_setting( 'utm_backup_settings', 'utm_backup_email_notifications' );
            register_setting( 'utm_backup_settings', 'utm_backup_notification_email' );
        }
    }

    /**
     * Force re-randomization of weekly time and reschedule
     */
    private function rerandomize_weekly_time_and_reschedule() {
        delete_option( 'utm_backup_weekly_time' );
        $spec = $this->generate_and_store_weekly_time();
        $next = wp_next_scheduled( 'utm_webmaster_weekly_backup' );
        if ( $next ) {
            wp_unschedule_event( $next, 'utm_webmaster_weekly_backup' );
        }
        $this->schedule_weekly_backup();
        return $spec;
    }
    
    /**
     * Handle AJAX request for manual backup
     */
    public function handle_manual_backup() {
        // Verify nonce and permissions
        if ( ! isset( $_POST['_ajax_nonce'] ) || ! wp_verify_nonce( $_POST['_ajax_nonce'], 'utm_manual_backup' ) ) {
            wp_send_json_error( 'Invalid nonce' );
        }
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        // Create backup
        $result = $this->create_database_backup();
        
        if ( $result['success'] ) {
            wp_send_json_success( 'Backup created successfully' );
        } else {
            wp_send_json_error( $result['error'] );
        }
    }
    
    /**
     * Handle AJAX request for menu backup
     */
    public function handle_menu_backup() {
        if ( ! isset( $_POST['_ajax_nonce'] ) || ! wp_verify_nonce( $_POST['_ajax_nonce'], 'utm_backup_menu' ) ) {
            wp_send_json_error( 'Invalid nonce' );
        }
        
        if ( ! current_user_can( 'edit_theme_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $menu_id = isset( $_POST['menu_id'] ) ? intval( $_POST['menu_id'] ) : 0;
        
        if ( ! $menu_id ) {
            wp_send_json_error( 'Invalid menu ID' );
        }
        
        $filename = $this->create_menu_backup( $menu_id, 'manual' );
        
        if ( $filename ) {
            wp_send_json_success( array( 'file' => $filename ) );
        } else {
            wp_send_json_error( 'Failed to create menu backup' );
        }
    }
    
    /**
     * Handle AJAX request for menu restore
     */
    public function handle_menu_restore() {
        if ( ! isset( $_POST['_ajax_nonce'] ) || ! wp_verify_nonce( $_POST['_ajax_nonce'], 'utm_restore_menu' ) ) {
            wp_send_json_error( 'Invalid nonce' );
        }
        
        if ( ! current_user_can( 'edit_theme_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $file = isset( $_POST['file'] ) ? sanitize_file_name( $_POST['file'] ) : '';
        
        if ( empty( $file ) ) {
            wp_send_json_error( 'No file specified' );
        }
        
        try {
            $result = $this->restore_menu_from_backup( $file );
            wp_send_json_success( $result );
        } catch ( Exception $e ) {
            wp_send_json_error( $e->getMessage() );
        }
    }
    
    /**
     * Handle AJAX request for menu preview
     */
    public function handle_menu_preview() {
        if ( ! isset( $_POST['_ajax_nonce'] ) || ! wp_verify_nonce( $_POST['_ajax_nonce'], 'utm_preview_menu' ) ) {
            wp_send_json_error( 'Invalid nonce' );
        }
        
        if ( ! current_user_can( 'edit_theme_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $file = isset( $_POST['file'] ) ? sanitize_file_name( $_POST['file'] ) : '';
        $filepath = $this->menu_backup_dir . '/' . $file;
        
        if ( ! file_exists( $filepath ) ) {
            wp_send_json_error( 'Backup file not found' );
        }
        
        $backup_data = json_decode( file_get_contents( $filepath ), true );
        
        if ( ! $backup_data ) {
            wp_send_json_error( 'Invalid backup file format' );
        }
        
        // Build preview HTML
        $html = '<div class="menu-preview">';
        $html .= '<p><strong>Menu:</strong> ' . esc_html( $backup_data['menu_name'] ) . '</p>';
        $html .= '<p><strong>Backup Date:</strong> ' . esc_html( $backup_data['timestamp'] ) . '</p>';
        $html .= '<p><strong>Total Items:</strong> ' . count( $backup_data['items'] ) . '</p>';
        $html .= '<hr>';
        $html .= '<h3>Menu Structure:</h3>';
        
        // Build hierarchical menu structure
        $menu_tree = $this->build_menu_tree( $backup_data['items'] );
        $html .= $this->render_menu_tree( $menu_tree );
        
        $html .= '</div>';
        
        wp_send_json_success( array( 'html' => $html ) );
    }
    
    /**
     * Build hierarchical menu tree
     */
    private function build_menu_tree( $items, $parent_id = 0 ) {
        $branch = array();
        
        foreach ( $items as $item ) {
            if ( $item['menu_item_parent'] == $parent_id ) {
                $children = $this->build_menu_tree( $items, $item['ID'] );
                if ( $children ) {
                    $item['children'] = $children;
                }
                $branch[] = $item;
            }
        }
        
        return $branch;
    }
    
    /**
     * Render menu tree HTML
     */
    private function render_menu_tree( $items, $level = 0 ) {
        $html = '';
        
        foreach ( $items as $item ) {
            $html .= '<div class="menu-preview-item level-' . $level . '">';
            $html .= '<strong>' . esc_html( $item['title'] ) . '</strong>';
            $html .= ' <span style="color: #666;">(' . esc_html( $item['type_label'] ) . ')</span>';
            
            if ( $item['url'] ) {
                $html .= '<br><small>🔗 ' . esc_html( $item['url'] ) . '</small>';
            }
            
            $html .= '</div>';
            
            if ( isset( $item['children'] ) ) {
                $html .= $this->render_menu_tree( $item['children'], $level + 1 );
            }
        }
        
        return $html;
    }
    
    /**
     * Handle AJAX request to delete backup
     */
    public function handle_delete_backup() {
        if ( ! isset( $_POST['_ajax_nonce'] ) || ! wp_verify_nonce( $_POST['_ajax_nonce'], 'utm_delete_backup' ) ) {
            wp_send_json_error( 'Invalid nonce' );
        }
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $file = isset( $_POST['file'] ) ? sanitize_file_name( $_POST['file'] ) : '';
        $type = isset( $_POST['type'] ) ? $_POST['type'] : 'database';
        
        if ( empty( $file ) ) {
            wp_send_json_error( 'No file specified' );
        }
        
        if ( $type === 'database' ) {
            $filepath = $this->backup_dir . '/' . $file;
            // Verify it's a database backup file
            if ( strpos( $file, 'database-backup-' ) !== 0 ) {
                wp_send_json_error( 'Invalid file type' );
            }
        } else {
            $filepath = $this->menu_backup_dir . '/' . $file;
            // Verify it's a menu backup file
            if ( strpos( $file, 'menu-' ) !== 0 ) {
                wp_send_json_error( 'Invalid file type' );
            }
        }
        
        if ( ! file_exists( $filepath ) ) {
            wp_send_json_error( 'File not found' );
        }
        
        if ( unlink( $filepath ) ) {
            wp_send_json_success( 'Backup deleted successfully' );
        } else {
            wp_send_json_error( 'Failed to delete backup file' );
        }
    }
    
    /**
     * Handle database restore (placeholder for safety)
     */
    public function handle_restore_backup() {
        if ( ! isset( $_POST['_ajax_nonce'] ) || ! wp_verify_nonce( $_POST['_ajax_nonce'], 'utm_restore_backup' ) ) {
            wp_send_json_error( 'Invalid nonce' );
        }
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        // This is intentionally not implemented for safety
        // Database restoration should be done manually with proper precautions
        wp_send_json_error( 'Database restore must be done manually for safety. Please use phpMyAdmin or MySQL CLI.' );
    }
    
    /**
     * Handle backup file downloads
     */
    public function download_backup_file() {
        // Verify nonce and permissions
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'utm_download_backup' ) ) {
            wp_die( 'Invalid nonce' );
        }
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions' );
        }
        
        if ( empty( $_GET['file'] ) ) {
            wp_die( 'No file specified' );
        }
        
        $filename = sanitize_file_name( $_GET['file'] );
        $type = isset( $_GET['type'] ) ? $_GET['type'] : 'database';
        
        // Determine file path based on type
        if ( $type === 'menu' ) {
            $file_path = $this->menu_backup_dir . '/' . $filename;
            // Validate it's a menu backup file
            if ( strpos( $filename, 'menu-' ) !== 0 ) {
                wp_die( 'Invalid file type' );
            }
        } else {
            $file_path = $this->backup_dir . '/' . $filename;
            // Validate it's a database backup file
            if ( strpos( $filename, 'database-backup-' ) !== 0 ) {
                wp_die( 'Invalid file type' );
            }
        }
        
        // Validate file exists
        if ( ! file_exists( $file_path ) ) {
            wp_die( 'File not found: ' . esc_html( $filename ) );
        }
        
        // Determine MIME type
        $mime_type = ( $type === 'menu' ) ? 'application/json' : 'application/octet-stream';
        
        // Send file
        header( 'Content-Description: File Transfer' );
        header( 'Content-Type: ' . $mime_type );
        header( 'Content-Disposition: attachment; filename=' . basename( $file_path ) );
        header( 'Expires: 0' );
        header( 'Cache-Control: must-revalidate' );
        header( 'Pragma: public' );
        header( 'Content-Length: ' . filesize( $file_path ) );
        
        // Read and output file
        readfile( $file_path );
        exit;
    }
}

// Initialize the backup system
new UTM_Webmaster_Tool_Backup();
