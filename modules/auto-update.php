<?php
/**
 * Plugin Auto-Update Module
 * 
 * This module enables automatic updates from a private GitHub repository.
 * It hooks into WordPress update mechanisms to check for and install updates.
 * 
 * Configuration:
 * The GitHub repository information is hardcoded in this file:
 * - Repository Owner: 'sharulhafiz'
 * - Repository Name: 'utm-webmaster-tool'
 * - Access Token: Set in the constructor (line ~83)
 * 
 * To enable automatic updates:
 * 1. Generate a GitHub Personal Access Token at https://github.com/settings/tokens
 * 2. Give it 'repo' scope (Full control of private repositories)
 * 3. Add the token to line ~83 in the constructor: $this->access_token = 'your_token_here';
 * 
 * Optional: You can still override the token via wp-config.php if needed:
 * define('UTM_GITHUB_ACCESS_TOKEN', 'your_token');
 * 
 * @package UTM_Webmaster_Tool
 * @since 5.40
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UTM_Plugin_Auto_Updater {
    
    /**
     * Plugin slug
     * @var string
     */
    private $plugin_slug;
    
    /**
     * Plugin basename
     * @var string
     */
    private $plugin_basename;
    
    /**
     * GitHub repository owner
     * @var string
     */
    private $github_owner;
    
    /**
     * GitHub repository name
     * @var string
     */
    private $github_repo;
    
    /**
     * GitHub access token (for private repos)
     * @var string
     */
    private $access_token;
    
    /**
     * Cache key for update info
     * @var string
     */
    private $cache_key;
    
    /**
     * Cache expiration (in seconds)
     * @var int
     */
    private $cache_expiration = 43200; // 12 hours
    
    /**
     * Constructor
     */
    public function __construct() {
        // Set plugin information
        $this->plugin_slug = 'utm-webmaster-tool';
        $this->plugin_basename = plugin_basename( dirname( dirname( __FILE__ ) ) . '/index.php' );
        
        // Set GitHub repository information - hardcoded for this repository
        $this->github_owner = 'sharulhafiz';
        $this->github_repo = 'utm-webmaster-tool';
        
        // GitHub Access Token for private repository
        // Set your GitHub Personal Access Token here (with 'repo' scope)
        // Generate at: https://github.com/settings/tokens
        $this->access_token = ''; // Add your token here
        
        // Allow override via wp-config.php if needed (optional)
        if ( defined( 'UTM_GITHUB_ACCESS_TOKEN' ) && ! empty( UTM_GITHUB_ACCESS_TOKEN ) ) {
            $this->access_token = UTM_GITHUB_ACCESS_TOKEN;
        }
        
        // Set cache key
        $this->cache_key = 'utm_plugin_update_' . md5( $this->github_owner . '/' . $this->github_repo );
        
        // Hook into WordPress update system
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
        add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 3 );
        add_filter( 'http_request_args', array( $this, 'add_download_auth' ), 10, 2 );
    }
    
    /**
     * Check for plugin updates
     * 
     * @param object $transient Update transient
     * @return object Modified transient
     */
    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }
        
        // Ensure plugin version constant is defined
        if ( ! defined( 'UTM_PLUGIN_VERSION' ) ) {
            error_log( 'UTM Plugin Auto-Updater: UTM_PLUGIN_VERSION constant is not defined' );
            return $transient;
        }
        
        // Get cached update info or fetch new
        $update_info = $this->get_update_info();
        
        if ( $update_info && version_compare( UTM_PLUGIN_VERSION, $update_info->version, '<' ) ) {
            $plugin_data = array(
                'slug' => $this->plugin_slug,
                'new_version' => $update_info->version,
                'url' => $update_info->homepage,
                'package' => $update_info->download_url,
                'tested' => $update_info->tested,
                'requires_php' => $update_info->requires_php,
                'requires' => $update_info->requires,
            );
            
            $transient->response[ $this->plugin_basename ] = (object) $plugin_data;
        }
        
        return $transient;
    }
    
    /**
     * Get plugin information for the update screen
     * 
     * @param mixed $result Default result
     * @param string $action API action
     * @param object $args API arguments
     * @return mixed Plugin information or default result
     */
    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || $this->plugin_slug !== $args->slug ) {
            return $result;
        }
        
        $update_info = $this->get_update_info();
        
        if ( ! $update_info ) {
            return $result;
        }
        
        $plugin_info = array(
            'name' => $update_info->name,
            'slug' => $this->plugin_slug,
            'version' => $update_info->version,
            'author' => $update_info->author,
            'homepage' => $update_info->homepage,
            'requires' => $update_info->requires,
            'tested' => $update_info->tested,
            'requires_php' => $update_info->requires_php,
            'download_link' => $update_info->download_url,
            'sections' => array(
                'description' => $update_info->description,
                'changelog' => $update_info->changelog,
            ),
        );
        
        return (object) $plugin_info;
    }
    
    /**
     * Fix the source directory name after extraction
     * 
     * @param string $source Source directory
     * @param string $remote_source Remote source
     * @param object $upgrader Upgrader instance
     * @return string Modified source directory or WP_Error
     */
    public function fix_source_dir( $source, $remote_source, $upgrader ) {
        global $wp_filesystem;
        
        // Check if this is our plugin
        if ( ! isset( $upgrader->skin->plugin ) || $upgrader->skin->plugin !== $this->plugin_basename ) {
            return $source;
        }
        
        // Expected directory name
        $correct_dirname = $this->plugin_slug;
        $current_dirname = basename( $source );
        
        // If directory name is already correct, return
        if ( $correct_dirname === $current_dirname ) {
            return $source;
        }
        
        // Rename to correct directory name
        $new_source = trailingslashit( dirname( $source ) ) . $correct_dirname;
        
        if ( $wp_filesystem->move( $source, $new_source ) ) {
            return $new_source;
        }
        
        return new WP_Error( 'rename_failed', __( 'Could not rename plugin directory.' ) );
    }
    
    /**
     * Add authentication to download requests
     * 
     * @param array $args HTTP request arguments
     * @param string $url Request URL
     * @return array Modified arguments
     */
    public function add_download_auth( $args, $url ) {
        // Check if this is a GitHub zipball download for our plugin
        if ( strpos( $url, 'api.github.com' ) !== false && 
             strpos( $url, 'zipball' ) !== false &&
             strpos( $url, $this->github_owner . '/' . $this->github_repo ) !== false &&
             ! empty( $this->access_token ) ) {
            
            // Add authorization header (more secure than URL parameter)
            if ( ! isset( $args['headers'] ) ) {
                $args['headers'] = array();
            }
            $args['headers']['Authorization'] = 'token ' . $this->access_token;
        }
        
        return $args;
    }
    
    /**
     * Get update information from GitHub
     * 
     * @return object|false Update information or false on failure
     */
    private function get_update_info() {
        // Check cache first
        $cached = get_transient( $this->cache_key );
        if ( false !== $cached ) {
            return $cached;
        }
        
        // Fetch latest release from GitHub
        $release_info = $this->fetch_github_release();
        
        if ( ! $release_info ) {
            return false;
        }
        
        // Parse and cache the update information
        $update_info = $this->parse_release_info( $release_info );
        
        if ( $update_info ) {
            set_transient( $this->cache_key, $update_info, $this->cache_expiration );
        }
        
        return $update_info;
    }
    
    /**
     * Fetch latest release from GitHub API
     * 
     * @return object|false Release information or false on failure
     */
    private function fetch_github_release() {
        $api_url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            $this->github_owner,
            $this->github_repo
        );
        
        $args = array(
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
            ),
            'timeout' => 15,
        );
        
        // Add authentication for private repositories
        if ( ! empty( $this->access_token ) ) {
            $args['headers']['Authorization'] = 'token ' . $this->access_token;
        }
        
        $response = wp_remote_get( $api_url, $args );
        
        if ( is_wp_error( $response ) ) {
            error_log( 'UTM Plugin Auto-Updater: ' . $response->get_error_message() );
            return false;
        }
        
        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            error_log( 'UTM Plugin Auto-Updater: GitHub API returned status code ' . $code );
            return false;
        }
        
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body );
        
        if ( json_last_error() !== JSON_ERROR_NONE || ! isset( $data->tag_name ) ) {
            error_log( 'UTM Plugin Auto-Updater: Invalid JSON response from GitHub API' );
            return false;
        }
        
        return $data;
    }
    
    /**
     * Parse GitHub release information
     * 
     * @param object $release GitHub release object
     * @return object|false Parsed update information or false on failure
     */
    private function parse_release_info( $release ) {
        // Extract version from tag name (remove 'v' prefix if present)
        $version = ltrim( $release->tag_name, 'v' );
        
        // Use zipball download URL
        // Note: Authentication is handled by WordPress upgrader using http_request_args filter
        $download_url = $release->zipball_url;
        
        $update_info = (object) array(
            'name' => 'UTM Webmaster Tool',
            'slug' => $this->plugin_slug,
            'version' => $version,
            'author' => 'UTM Webmaster',
            'homepage' => sprintf( 'https://github.com/%s/%s', $this->github_owner, $this->github_repo ),
            'download_url' => $download_url,
            'requires' => '5.0',
            'tested' => '6.4',
            'requires_php' => '7.2',
            'description' => 'Tool for UTM Webmaster.',
            'changelog' => $this->format_changelog( $release->body ),
        );
        
        return $update_info;
    }
    
    /**
     * Format changelog from release notes
     * 
     * @param string $body Release body/notes
     * @return string Formatted changelog
     */
    private function format_changelog( $body ) {
        if ( empty( $body ) ) {
            return '<p>See the <a href="https://github.com/' . esc_attr( $this->github_owner ) . '/' . esc_attr( $this->github_repo ) . '/releases" target="_blank">release notes</a> for details.</p>';
        }
        
        // Convert markdown to HTML (basic conversion)
        $changelog = wpautop( $body );
        
        // Sanitize HTML to prevent XSS vulnerabilities
        $changelog = wp_kses_post( $changelog );
        
        return $changelog;
    }
    
    /**
     * Clear update cache (useful for debugging)
     */
    public function clear_cache() {
        delete_transient( $this->cache_key );
    }
    
    /**
     * Get access token status (for admin display)
     * 
     * @return string
     */
    public function get_access_token() {
        return $this->access_token;
    }
    
    /**
     * Get repository information (for admin display)
     * 
     * @return array
     */
    public function get_repo_info() {
        return array(
            'owner' => $this->github_owner,
            'repo' => $this->github_repo,
        );
    }
}

// Initialize the auto-updater
function utm_init_auto_updater() {
    new UTM_Plugin_Auto_Updater();
}
add_action( 'init', 'utm_init_auto_updater' );

/**
 * Add admin menu page for Auto-Update setup guide
 */
function utm_auto_update_admin_menu() {
    // Add as submenu under Tools
    add_submenu_page(
        'tools.php',
        'Auto-Update Setup',
        'Auto-Update Setup',
        'manage_options',
        'utm-auto-update-setup',
        'utm_auto_update_render_page'
    );
}
add_action( 'admin_menu', 'utm_auto_update_admin_menu' );

/**
 * Render the Auto-Update setup page
 */
function utm_auto_update_render_page() {
    // Check user permissions
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
    }
    
    // Get current token status
    $updater = new UTM_Plugin_Auto_Updater();
    $has_token = ! empty( $updater->get_access_token() );
    $repo_info = $updater->get_repo_info();
    
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        
        <div class="notice notice-info inline">
            <p><strong>Current Status:</strong></p>
            <ul style="list-style: disc; margin-left: 20px;">
                <li>Repository: <code><?php echo esc_html( $repo_info['owner'] . '/' . $repo_info['repo'] ); ?></code></li>
                <li>Access Token: <?php echo $has_token ? '<span style="color: green;">✓ Configured</span>' : '<span style="color: red;">✗ Not configured</span>'; ?></li>
                <li>Cache: 12 hours</li>
            </ul>
        </div>
        
        <h2>Quick Start Guide</h2>
        <div style="background: #fff; border: 1px solid #ccc; padding: 20px; margin: 20px 0;">
            <?php echo utm_auto_update_get_quick_start_html(); ?>
        </div>
        
        <h2>Implementation Details</h2>
        <details style="background: #fff; border: 1px solid #ccc; padding: 20px; margin: 20px 0;">
            <summary style="cursor: pointer; font-weight: bold; font-size: 16px;">Click to view technical implementation details</summary>
            <div style="margin-top: 15px;">
                <?php echo utm_auto_update_get_implementation_html(); ?>
            </div>
        </details>
        
        <h2>Troubleshooting</h2>
        <div style="background: #fff; border: 1px solid #ccc; padding: 20px; margin: 20px 0;">
            <h3>Updates Not Showing?</h3>
            <ol>
                <li>Verify the GitHub token is set in <code>modules/auto-update.php</code> (line ~90)</li>
                <li>Check token hasn't expired at <a href="https://github.com/settings/tokens" target="_blank">GitHub Settings</a></li>
                <li>Ensure releases are published (not draft) on GitHub</li>
                <li>Check PHP error logs for specific errors</li>
                <li>Try: Plugins → Check for updates (force refresh)</li>
            </ol>
            
            <h3>Where Are Error Logs?</h3>
            <p>Look for: <code>UTM Plugin Auto-Updater:</code> prefix in:</p>
            <ul style="list-style: disc; margin-left: 20px;">
                <li><code>/wp-content/debug.log</code> (if WP_DEBUG_LOG enabled)</li>
                <li>Server error logs (ask hosting provider)</li>
            </ul>
        </div>
    </div>
    <?php
}

/**
 * Get access token (for display purposes)
 */
function utm_auto_update_get_token_status() {
    $updater = new UTM_Plugin_Auto_Updater();
    return ! empty( $updater->get_access_token() );
}

/**
 * Convert Quick Start markdown to HTML
 */
function utm_auto_update_get_quick_start_html() {
    ob_start();
    ?>
    <h3>For Repository Administrators</h3>
    
    <h4>Step 1: Generate GitHub Token</h4>
    <ol>
        <li>Go to <a href="https://github.com/settings/tokens" target="_blank">GitHub Settings → Tokens</a></li>
        <li>Click "Generate new token (classic)"</li>
        <li>Name: <code>UTM Plugin Auto-Update</code></li>
        <li>Select scope: ✅ <code>repo</code> (Full control of private repositories)</li>
        <li>Click "Generate token"</li>
        <li><strong>Copy the token immediately</strong> (you won't see it again!)</li>
    </ol>
    
    <h4>Step 2: Add Token to Plugin</h4>
    <ol>
        <li>Open <code>modules/auto-update.php</code> in this repository</li>
        <li>Find line ~90: <code>$this->access_token = '';</code></li>
        <li>Add your token between the quotes:
            <pre style="background: #f5f5f5; padding: 10px; border-radius: 4px;"><code>$this->access_token = 'ghp_YourTokenHere123456789';</code></pre>
        </li>
        <li>Save the file and commit to the repository</li>
    </ol>
    
    <h4>Step 3: Deploy</h4>
    <p>Once you commit the token to this repository, it will automatically work on all WordPress installations that use this plugin. No need to configure each site individually!</p>
    
    <h3>To Create a New Release</h3>
    <h4>Method 1: GitHub Web Interface</h4>
    <ol>
        <li>Go to repository: <a href="https://github.com/sharulhafiz/utm-webmaster-tool" target="_blank">sharulhafiz/utm-webmaster-tool</a></li>
        <li>Click "Releases" → "Create a new release"</li>
        <li>Tag version: <code>v5.41</code> (increment from current version)</li>
        <li>Release title: "Version 5.41"</li>
        <li>Description: Add release notes</li>
        <li>Click "Publish release"</li>
    </ol>
    
    <h4>Method 2: Command Line</h4>
    <pre style="background: #f5f5f5; padding: 10px; border-radius: 4px;"><code>git tag v5.41
git push origin v5.41
# Then create release on GitHub from the tag</code></pre>
    
    <h3>Updates Will Appear In</h3>
    <ul style="list-style: disc; margin-left: 20px;">
        <li>WordPress Admin → Dashboard → Updates</li>
        <li>WordPress Admin → Plugins (update badge)</li>
        <li>Automatic checks every 12 hours</li>
    </ul>
    <?php
    return ob_get_clean();
}

/**
 * Convert Implementation Summary markdown to HTML
 */
function utm_auto_update_get_implementation_html() {
    ob_start();
    ?>
    <h3>Overview</h3>
    <p>Successfully implemented a comprehensive plugin auto-update module that enables the UTM Webmaster Tool to update automatically from a private GitHub repository.</p>
    
    <h3>Key Features Implemented</h3>
    
    <h4>Core Functionality</h4>
    <ul style="list-style: disc; margin-left: 20px;">
        <li><strong>Automatic Update Checking</strong>: Checks GitHub releases every 12 hours</li>
        <li><strong>Version Comparison</strong>: Compares current version with latest release</li>
        <li><strong>Update Installation</strong>: Downloads and installs updates through WordPress</li>
        <li><strong>Directory Naming</strong>: Fixes directory structure during extraction</li>
        <li><strong>Caching</strong>: Reduces API calls with 12-hour cache</li>
    </ul>
    
    <h4>Security Features</h4>
    <ul style="list-style: disc; margin-left: 20px;">
        <li><strong>Header-Based Authentication</strong>: Uses Authorization header instead of URL parameters</li>
        <li><strong>XSS Prevention</strong>: Sanitizes changelog HTML with <code>wp_kses_post()</code></li>
        <li><strong>Input Validation</strong>: Validates constants before use</li>
        <li><strong>URL Escaping</strong>: Proper escaping with <code>esc_attr()</code></li>
        <li><strong>Error Logging</strong>: Comprehensive error logging for debugging</li>
    </ul>
    
    <h4>WordPress Integration</h4>
    <ul style="list-style: disc; margin-left: 20px;">
        <li><code>pre_set_site_transient_update_plugins</code> - Update checking</li>
        <li><code>plugins_api</code> - Plugin information display</li>
        <li><code>upgrader_source_selection</code> - Directory naming fix</li>
        <li><code>http_request_args</code> - Authentication injection</li>
    </ul>
    
    <h3>Configuration</h3>
    <p>The GitHub repository information is hardcoded in the plugin file:</p>
    <ul style="list-style: disc; margin-left: 20px;">
        <li>Repository: <code>sharulhafiz/utm-webmaster-tool</code></li>
        <li>Access Token: Set in <code>modules/auto-update.php</code> at line ~90</li>
    </ul>
    
    <h3>API Usage</h3>
    <ul style="list-style: disc; margin-left: 20px;">
        <li><strong>Endpoint</strong>: <code>https://api.github.com/repos/{owner}/{repo}/releases/latest</code></li>
        <li><strong>Rate Limits</strong>: 5,000/hour (authenticated), 60/hour (unauthenticated)</li>
        <li><strong>Caching</strong>: 12 hours to minimize API calls</li>
        <li><strong>Timeout</strong>: 15 seconds per request</li>
    </ul>
    
    <h3>GitHub Release Requirements</h3>
    <p>For the module to work, releases must:</p>
    <ol>
        <li>Be created on GitHub (not just tags)</li>
        <li>Use version tags (e.g., <code>v5.40</code> or <code>5.40</code>)</li>
        <li>Be published (not draft)</li>
    </ol>
    <?php
    return ob_get_clean();
}

