<?php
// File: /g:/My Drive/Projects/plugins/utm-webmaster-tool/modules/backup.php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Simple Database Backup Class
 * 
 * Provides basic mysqldump functionality:
 * - Daily backups at midnight via WordPress cron
 * - On-demand backups through admin interface
 * - Simple full database dumps with basic error handling
 */
class UTM_Webmaster_Tool_Backup {
    
    public function __construct() {
        // Schedule daily backup at midnight
        add_action( 'admin_init', array( $this, 'schedule_daily_backup' ) );
        
        // Hook for the scheduled backup
        add_action( 'utm_webmaster_daily_backup', array( $this, 'create_database_backup' ) );
        
        // Add admin page
        add_action( 'admin_menu', array( $this, 'add_backup_page' ) );
        
        // Handle manual backup request
        add_action( 'wp_ajax_utm_manual_backup', array( $this, 'handle_manual_backup' ) );
        
        // Handle backup download
        add_action( 'admin_post_utm_download_backup', array( $this, 'download_backup_file' ) );
    }
    
    /**
     * Schedule daily backup at midnight if not already scheduled
     */
    public function schedule_daily_backup() {
        if ( ! wp_next_scheduled( 'utm_webmaster_daily_backup' ) ) {
            // Schedule for midnight today, or tomorrow if past midnight
            $midnight_today = strtotime( 'today midnight' );
            $schedule_time = ( time() > $midnight_today ) ? strtotime( 'tomorrow midnight' ) : $midnight_today;
            
            wp_schedule_event( $schedule_time, 'daily', 'utm_webmaster_daily_backup' );
            error_log( 'UTM Backup: Daily backup scheduled for ' . date( 'Y-m-d H:i:s', $schedule_time ) );
        }
    }
    
    /**
     * Create a database backup using mysqldump
     */
    public function create_database_backup() {
        $upload_dir = wp_upload_dir();
        $backup_dir = $upload_dir['basedir'];
        $timestamp = date('Ymd-His');
        $backup_file = $backup_dir . '/database-backup-' . $timestamp . '.sql';
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
            
            // Compress the backup
            $this->compress_backup( $backup_file, $compressed_file );
            
            // Remove uncompressed file
            if ( file_exists( $compressed_file ) && filesize( $compressed_file ) > 0 ) {
                unlink( $backup_file );
                $final_file = basename( $compressed_file );
            } else {
                $final_file = basename( $backup_file );
            }
            
            // Clean up old backups (keep last 7 days)
            $this->cleanup_old_backups();
            
            error_log( 'UTM Backup: Database backup completed successfully: ' . $final_file );
            
        } catch ( Exception $e ) {
            $error_msg = 'UTM Backup Error: ' . $e->getMessage();
            error_log( $error_msg );
            
            // Log to custom error file as well
            $error_file = $backup_dir . '/backup-errors.log';
            file_put_contents( $error_file, date( 'Y-m-d H:i:s' ) . ' - ' . $error_msg . "\n", FILE_APPEND );
        }
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
     * Remove backup files older than 7 days
     */
    private function cleanup_old_backups() {
        $upload_dir = wp_upload_dir();
        $backup_dir = $upload_dir['basedir'];
        $cutoff_time = time() - ( 7 * 24 * 60 * 60 ); // 7 days ago
        
        $backup_files = glob( $backup_dir . '/database-backup-*.sql*' );
        
        foreach ( $backup_files as $file ) {
            if ( filemtime( $file ) < $cutoff_time ) {
                unlink( $file );
                error_log( 'UTM Backup: Removed old backup file: ' . basename( $file ) );
            }
        }
    }
    
    /**
     * Add backup management page to admin menu
     */
    public function add_backup_page() {
        add_submenu_page(
            'tools.php',
            'Database Backup',
            'Database Backup',
            'manage_options',
            'utm-database-backup',
            array( $this, 'display_backup_page' )
        );
    }
    
    /**
     * Display backup management page
     */
    public function display_backup_page() {
        $upload_dir = wp_upload_dir();
        $backup_dir = $upload_dir['basedir'];
        
        // Get next scheduled backup
        $next_backup = wp_next_scheduled( 'utm_webmaster_daily_backup' );
        $next_backup_time = $next_backup ? date( 'Y-m-d H:i:s', $next_backup ) : 'Not scheduled';
        
        // Get existing backup files
        $backup_files = glob( $backup_dir . '/database-backup-*.sql*' );
        rsort( $backup_files ); // Sort newest first
        
        // Get error log
        $error_file = $backup_dir . '/backup-errors.log';
        $errors = file_exists( $error_file ) ? file_get_contents( $error_file ) : '';
        
        ?>
        <div class="wrap">
            <h1>Database Backup</h1>
            
            <div class="card">
                <h2>Backup Schedule</h2>
                <p><strong>Daily Backup:</strong> Automatically runs every day at midnight</p>
                <p><strong>Next Scheduled Backup:</strong> <?php echo esc_html( $next_backup_time ); ?></p>
            </div>
            
            <div class="card">
                <h2>Manual Backup</h2>
                <p>Create a database backup right now:</p>
                <button id="manual-backup-btn" class="button button-primary">Create Backup Now</button>
                <div id="backup-status" style="margin-top: 10px;"></div>
            </div>
            
            <div class="card">
                <h2>Existing Backups</h2>
                <?php if ( empty( $backup_files ) ) : ?>
                    <p>No backup files found.</p>
                <?php else : ?>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th>Backup File</th>
                                <th>Size</th>
                                <th>Date Created</th>
                                <th>Download</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $backup_files as $file ) : ?>
                                <tr>
                                    <td><?php echo esc_html( basename( $file ) ); ?></td>
                                    <td><?php echo esc_html( size_format( filesize( $file ) ) ); ?></td>
                                    <td><?php echo esc_html( date( 'Y-m-d H:i:s', filemtime( $file ) ) ); ?></td>
                                    <td>
                                        <a href="<?php echo wp_nonce_url( admin_url( 'admin-post.php?action=utm_download_backup&file=' . urlencode( basename( $file ) ) ), 'utm_download_backup' ); ?>" 
                                           class="button button-small">Download</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
            <?php if ( ! empty( $errors ) ) : ?>
                <div class="card">
                    <h2>Recent Errors</h2>
                    <pre style="background: #f1f1f1; padding: 10px; max-height: 200px; overflow-y: auto;"><?php echo esc_html( $errors ); ?></pre>
                    <p><a href="<?php echo wp_nonce_url( admin_url( 'tools.php?page=utm-database-backup&clear_errors=1' ), 'clear_backup_errors' ); ?>" 
                          class="button">Clear Error Log</a></p>
                </div>
            <?php endif; ?>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#manual-backup-btn').on('click', function() {
                var $btn = $(this);
                var $status = $('#backup-status');
                
                $btn.prop('disabled', true).text('Creating Backup...');
                $status.html('<div class="notice notice-info"><p>Creating database backup, please wait...</p></div>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'utm_manual_backup',
                        _ajax_nonce: '<?php echo wp_create_nonce( 'utm_manual_backup' ); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $status.html('<div class="notice notice-success"><p>Backup created successfully!</p></div>');
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            $status.html('<div class="notice notice-error"><p>Backup failed: ' + response.data + '</p></div>');
                        }
                    },
                    error: function() {
                        $status.html('<div class="notice notice-error"><p>An error occurred while creating the backup.</p></div>');
                    },
                    complete: function() {
                        $btn.prop('disabled', false).text('Create Backup Now');
                    }
                });
            });
        });
        </script>
        <?php
        
        // Handle clear errors
        if ( isset( $_GET['clear_errors'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'clear_backup_errors' ) ) {
            if ( file_exists( $error_file ) ) {
                unlink( $error_file );
                echo '<div class="notice notice-success is-dismissible"><p>Error log cleared.</p></div>';
            }
        }
    }
    
    /**
     * Handle AJAX request for manual backup
     */
    public function handle_manual_backup() {
        // Verify nonce and permissions
        if ( ! wp_verify_nonce( $_POST['_ajax_nonce'], 'utm_manual_backup' ) ) {
            wp_send_json_error( 'Invalid nonce' );
        }
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        // Create backup
        $this->create_database_backup();
        
        wp_send_json_success( 'Backup created successfully' );
    }
    
    /**
     * Handle backup file downloads
     */
    public function download_backup_file() {
        // Verify nonce and permissions
        if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'utm_download_backup' ) ) {
            wp_die( 'Invalid nonce' );
        }
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions' );
        }
        
        if ( empty( $_GET['file'] ) ) {
            wp_die( 'No file specified' );
        }
        
        $filename = sanitize_file_name( $_GET['file'] );
        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['basedir'] . '/' . $filename;
        
        // Validate file exists and is a backup file
        if ( ! file_exists( $file_path ) || strpos( $filename, 'database-backup-' ) !== 0 ) {
            wp_die( 'File not found or invalid file type' );
        }
        
        // Send file
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

// Initialize the backup system
new UTM_Webmaster_Tool_Backup();
