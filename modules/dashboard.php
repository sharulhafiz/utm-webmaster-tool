<?php
/**
 * UTM Webmaster Tool Dashboard
 * 
 * Provides a centralized dashboard showing all plugin modules
 * categorized as "Must Use" and "Optional" modules.
 */

// Exit if accessed directly for security.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Add dashboard menu to WordPress admin
 */
function utm_dashboard_admin_menu() {
    add_menu_page(
        'UTM Webmaster Dashboard',           // Page title
        'UTM Dashboard',                      // Menu title
        'manage_options',                     // Capability
        'utm-webmaster-dashboard',            // Menu slug
        'utm_dashboard_render_page',          // Callback function
        'dashicons-admin-tools',              // Icon
        3                                     // Position (top of menu)
    );
}
add_action( 'admin_menu', 'utm_dashboard_admin_menu' );

/**
 * Get module categorization
 * 
 * @return array Array with 'must_use' and 'optional' keys containing module info
 */
function utm_dashboard_get_modules() {
    return array(
        'must_use' => array(
            array(
                'name' => 'SSO (Single Sign-On)',
                'file' => 'sso.php',
                'description' => 'Provides single sign-on authentication for UTM users.',
                'features' => array( 'Authentication', 'Auto User Creation', 'Email Whitelist' )
            ),
            array(
                'name' => 'Function',
                'file' => 'function.php',
                'description' => 'Core utility functions for the plugin.',
                'features' => array( 'RSS Feed Management', 'Phone Number Formatting' )
            ),
            array(
                'name' => 'Timezone',
                'file' => 'timezone.php',
                'description' => 'Manages timezone settings for UTM sites.',
                'features' => array( 'Auto Timezone Detection', 'Post Date Formatting' )
            ),
            array(
                'name' => 'Mail',
                'file' => 'mail.php',
                'description' => 'SMTP email configuration and management.',
                'features' => array( 'SMTP Settings', 'Email Testing', 'Template Management' )
            ),
            array(
                'name' => 'Performance Patch',
                'file' => 'performance-patch.php',
                'description' => 'Performance optimizations for WordPress.',
                'features' => array( 'Query Optimization', 'Caching Improvements' )
            ),
        ),
        'optional' => array(
            array(
                'name' => 'Backup',
                'file' => 'backup.php',
                'description' => 'Database and menu backup functionality.',
                'features' => array( 'Manual Backups', 'Scheduled Backups', 'Restore', 'Version History' )
            ),
            array(
                'name' => 'Chatbot',
                'file' => 'chatbot.php',
                'description' => 'AI-powered chatbot integration for site content.',
                'features' => array( 'Content Crawling', 'AI Responses', 'Admin Dashboard' )
            ),
            array(
                'name' => 'Analytics',
                'file' => 'analytics.php',
                'description' => 'Content analytics and reporting.',
                'features' => array( 'Page Views', 'User Statistics', 'REST API' )
            ),
            array(
                'name' => 'Anti-Spam',
                'file' => 'antispam.php',
                'description' => 'Comment and form spam protection.',
                'features' => array( 'Comment Filtering', 'Blacklist Management', 'Bulk Actions' )
            ),
            array(
                'name' => 'Cache Monitor',
                'file' => 'cache-monitor.php',
                'description' => 'Real-time cache monitoring and statistics.',
                'features' => array( 'Cache Logging', 'Performance Metrics', 'Network Dashboard' )
            ),
            array(
                'name' => 'News UTM',
                'file' => 'news.utm.my.php',
                'description' => 'News management with AI summaries and audio generation.',
                'features' => array( 'AI Summaries', 'Audio Generation', 'Featured Images' )
            ),
            array(
                'name' => 'Google Docs Import',
                'file' => 'gdocsImport.php',
                'description' => 'Import content from Google Docs.',
                'features' => array( 'Document Import', 'Formatting Preservation', 'REST API' )
            ),
            array(
                'name' => 'Multisite Statistics',
                'file' => 'multisite-statistics.php',
                'description' => 'Network-wide statistics and user management.',
                'features' => array( 'Site Statistics', 'Orphan Users', 'Bulk Operations' )
            ),
            array(
                'name' => 'Bulk Add User',
                'file' => 'bulk-add-user.php',
                'description' => 'Add multiple users at once.',
                'features' => array( 'CSV Import', 'Batch Processing', 'Role Assignment' )
            ),
            array(
                'name' => 'Bulk Delete User',
                'file' => 'bulkdeleteuser.php',
                'description' => 'Delete multiple users at once.',
                'features' => array( 'Batch Deletion', 'Safety Checks' )
            ),
            array(
                'name' => 'SEO',
                'file' => 'seo.php',
                'description' => 'Search engine optimization tools.',
                'features' => array( 'Meta Tags', 'Sitemap', 'Schema Markup' )
            ),
            array(
                'name' => 'Shortcodes',
                'file' => 'shortcodes.php',
                'description' => 'Custom shortcodes for content.',
                'features' => array( 'Custom Shortcodes', 'Content Embedding' )
            ),
            array(
                'name' => 'Protected Content',
                'file' => 'protected-content.php',
                'description' => 'Content protection and access control.',
                'features' => array( 'Access Control', 'Password Protection' )
            ),
            array(
                'name' => 'Fix Upload Path',
                'file' => 'fixuploadpath.php',
                'description' => 'Fix and manage upload paths.',
                'features' => array( 'Path Management', 'Bulk Operations' )
            ),
            array(
                'name' => 'Fix User Role',
                'file' => 'fixuserrole.php',
                'description' => 'Fix and manage user roles.',
                'features' => array( 'Role Management', 'Bulk Updates' )
            ),
            array(
                'name' => 'Broken Link',
                'file' => 'brokenlink.php',
                'description' => 'Detect and fix broken links.',
                'features' => array( 'Link Checking', 'Reporting' )
            ),
            array(
                'name' => 'Events',
                'file' => 'events.php',
                'description' => 'Event management functionality.',
                'features' => array( 'Event Calendar', 'iCal Support' )
            ),
            array(
                'name' => 'UTM Lenses',
                'file' => 'utmlenses.php',
                'description' => 'UTM Lenses integration.',
                'features' => array( 'Custom Filters', 'Content Display' )
            ),
            array(
                'name' => 'Heartbeat',
                'file' => 'heartbeat.php',
                'description' => 'WordPress heartbeat API management.',
                'features' => array( 'Heartbeat Control', 'Performance Optimization' )
            ),
            array(
                'name' => 'User Meta',
                'file' => 'usermeta.php',
                'description' => 'User metadata management.',
                'features' => array( 'Custom Fields', 'User Data' )
            ),
            array(
                'name' => 'Formidable Forms',
                'file' => 'formidableforms.php',
                'description' => 'Formidable Forms integration.',
                'features' => array( 'Form Management', 'Submissions' )
            ),
            array(
                'name' => 'People UTM',
                'file' => 'people.utm.my.php',
                'description' => 'Staff directory integration.',
                'features' => array( 'Staff Profiles', 'API Integration' )
            ),
            array(
                'name' => 'Popup Ads',
                'file' => 'popup-ads.php',
                'description' => 'Popup advertisement management.',
                'features' => array( 'Ad Display', 'Scheduling' )
            ),
            array(
                'name' => 'Registrar',
                'file' => 'registrar.php',
                'description' => 'User registration management.',
                'features' => array( 'Custom Registration', 'Validation' )
            ),
            array(
                'name' => 'Migrate Upload',
                'file' => 'migrate-upload.php',
                'description' => 'Upload migration tools.',
                'features' => array( 'Bulk Migration', 'Path Updates' )
            ),
            array(
                'name' => 'Post Export',
                'file' => 'postExport.php',
                'description' => 'Export posts and content.',
                'features' => array( 'XML Export', 'Custom Formats' )
            ),
            array(
                'name' => 'UTM News Import',
                'file' => 'utm-news-import.php',
                'description' => 'Import news from external sources.',
                'features' => array( 'RSS Import', 'Auto Processing' )
            ),
            array(
                'name' => 'List Blogs',
                'file' => 'listblogs.php',
                'description' => 'List all blogs in the network.',
                'features' => array( 'Blog Listing', 'Statistics' )
            ),
            array(
                'name' => 'Multisite API',
                'file' => 'multisite-api.php',
                'description' => 'REST API for multisite operations.',
                'features' => array( 'REST Endpoints', 'Site Management' )
            ),
            array(
                'name' => 'Staff API',
                'file' => 'staffapi.php',
                'description' => 'Staff directory API.',
                'features' => array( 'REST Endpoints', 'Staff Data' )
            ),
            array(
                'name' => 'Support UTM',
                'file' => 'support.utm.my.php',
                'description' => 'Support system integration.',
                'features' => array( 'Ticket Management', 'Help Desk' )
            ),
            array(
                'name' => 'Update Network Admin Email',
                'file' => 'updatenetworkadminemail.php',
                'description' => 'Update network admin email.',
                'features' => array( 'Email Management', 'Bulk Updates' )
            ),
            array(
                'name' => 'Content Visibility Shortcodes',
                'file' => 'content-visibility-shortcodes.php',
                'description' => 'Control content visibility with shortcodes.',
                'features' => array( 'Visibility Rules', 'Role-based Access' )
            ),
            array(
                'name' => 'Delete Divi Cache',
                'file' => 'delete_et_cache_divi.php',
                'description' => 'Clear Divi builder cache.',
                'features' => array( 'Cache Clearing', 'Divi Integration' )
            ),
            array(
                'name' => 'Disable Plugin',
                'file' => 'disableplugin.php',
                'description' => 'Disable specific plugins on certain sites.',
                'features' => array( 'Plugin Control', 'Site-specific Rules' )
            ),
            array(
                'name' => 'Debug',
                'file' => 'debug.php',
                'description' => 'Debugging tools and utilities.',
                'features' => array( 'Error Logging', 'Debug Mode' )
            ),
            array(
                'name' => 'Google Analytics',
                'file' => 'googleanalytics.php',
                'description' => 'Google Analytics integration.',
                'features' => array( 'Tracking Code', 'Analytics' )
            ),
            array(
                'name' => 'Login Logger',
                'file' => 'loginlogger.php',
                'description' => 'Log user login attempts.',
                'features' => array( 'Login Tracking', 'Security Logs' )
            ),
            array(
                'name' => 'Embed Google Docs',
                'file' => 'embed_googledocs.php',
                'description' => 'Embed Google Docs in posts.',
                'features' => array( 'Document Embedding', 'Viewer Integration' )
            ),
        )
    );
}

/**
 * Render the dashboard page
 */
function utm_dashboard_render_page() {
    // Check user capabilities
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
    }

    $modules = utm_dashboard_get_modules();
    $modules_dir = UTM_WEBMASTER_PLUGIN_PATH . 'modules/';
    
    ?>
    <div class="wrap">
        <h1>
            <span class="dashicons dashicons-admin-tools" style="font-size: 30px; margin-right: 10px;"></span>
            UTM Webmaster Tool Dashboard
        </h1>
        
        <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <h2>Plugin Information</h2>
            <p><strong>Version:</strong> <?php echo esc_html( UTM_PLUGIN_VERSION ); ?></p>
            <p><strong>Author:</strong> UTM Webmaster Team</p>
            <p>This plugin provides essential tools and optional features for UTM websites.</p>
        </div>

        <!-- Must Use Modules -->
        <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <h2>
                <span class="dashicons dashicons-shield" style="color: #d63638;"></span>
                Must Use Modules (<?php echo count( $modules['must_use'] ); ?>)
            </h2>
            <p style="color: #646970;">These modules provide core functionality and are essential for the plugin to work properly.</p>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px; margin-top: 20px;">
                <?php foreach ( $modules['must_use'] as $module ) : 
                    $file_path = $modules_dir . $module['file'];
                    $is_active = file_exists( $file_path );
                ?>
                    <div style="border: 1px solid #dcdcde; padding: 15px; border-radius: 4px; <?php echo esc_attr( $is_active ? 'background: #f0f6fc;' : 'background: #fcf0f1;' ); ?>">
                        <h3 style="margin: 0 0 10px 0; font-size: 14px;">
                            <?php if ( $is_active ) : ?>
                                <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                            <?php else : ?>
                                <span class="dashicons dashicons-warning" style="color: #d63638;"></span>
                            <?php endif; ?>
                            <?php echo esc_html( $module['name'] ); ?>
                        </h3>
                        <p style="margin: 0 0 10px 0; font-size: 13px; color: #50575e;">
                            <?php echo esc_html( $module['description'] ); ?>
                        </p>
                        <div style="font-size: 12px; color: #646970;">
                            <strong>Features:</strong><br>
                            <?php foreach ( $module['features'] as $feature ) : ?>
                                <span style="display: inline-block; background: #f0f0f1; padding: 2px 6px; margin: 2px; border-radius: 3px;">
                                    <?php echo esc_html( $feature ); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <div style="margin-top: 10px; font-size: 12px;">
                            <strong>File:</strong> <code><?php echo esc_html( $module['file'] ); ?></code>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Optional Modules -->
        <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <h2>
                <span class="dashicons dashicons-star-filled" style="color: #3582c4;"></span>
                Optional Modules (<?php echo count( $modules['optional'] ); ?>)
            </h2>
            <p style="color: #646970;">These modules provide additional features and enhancements. They can be enabled or disabled as needed.</p>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px; margin-top: 20px;">
                <?php foreach ( $modules['optional'] as $module ) : 
                    $file_path = $modules_dir . $module['file'];
                    $is_active = file_exists( $file_path );
                ?>
                    <div style="border: 1px solid #dcdcde; padding: 15px; border-radius: 4px; <?php echo esc_attr( $is_active ? '' : 'background: #f6f7f7; opacity: 0.7;' ); ?>">
                        <h3 style="margin: 0 0 10px 0; font-size: 14px;">
                            <?php if ( $is_active ) : ?>
                                <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                            <?php else : ?>
                                <span class="dashicons dashicons-marker" style="color: #a7aaad;"></span>
                            <?php endif; ?>
                            <?php echo esc_html( $module['name'] ); ?>
                        </h3>
                        <p style="margin: 0 0 10px 0; font-size: 13px; color: #50575e;">
                            <?php echo esc_html( $module['description'] ); ?>
                        </p>
                        <div style="font-size: 12px; color: #646970;">
                            <strong>Features:</strong><br>
                            <?php foreach ( $module['features'] as $feature ) : ?>
                                <span style="display: inline-block; background: #f0f0f1; padding: 2px 6px; margin: 2px; border-radius: 3px;">
                                    <?php echo esc_html( $feature ); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <div style="margin-top: 10px; font-size: 12px;">
                            <strong>File:</strong> <code><?php echo esc_html( $module['file'] ); ?></code>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div style="background: #f0f6fc; border: 1px solid #c3e4f7; padding: 15px; margin: 20px 0; border-radius: 4px;">
            <h3 style="margin: 0 0 10px 0;">
                <span class="dashicons dashicons-info"></span>
                Module Status Legend
            </h3>
            <ul style="margin: 0; padding-left: 20px;">
                <li><span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span> <strong>Active:</strong> Module file exists and is loaded</li>
                <li><span class="dashicons dashicons-warning" style="color: #d63638;"></span> <strong>Missing (Must Use):</strong> Required module file is missing</li>
                <li><span class="dashicons dashicons-marker" style="color: #a7aaad;"></span> <strong>Inactive (Optional):</strong> Optional module file not present</li>
            </ul>
        </div>
    </div>
    <?php
}
