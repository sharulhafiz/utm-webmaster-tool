<?php
/**
 * Plugin Auto-Update Module
 * 
 * This module enables automatic updates from a private GitHub repository.
 * It hooks into WordPress update mechanisms to check for and install updates.
 * 
 * Configuration:
 * - Define UTM_GITHUB_REPO_OWNER (default: 'sharulhafiz')
 * - Define UTM_GITHUB_REPO_NAME (default: 'utm-webmaster-tool')
 * - Define UTM_GITHUB_ACCESS_TOKEN for private repositories (optional)
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
        
        // Set GitHub repository information
        $this->github_owner = defined( 'UTM_GITHUB_REPO_OWNER' ) ? UTM_GITHUB_REPO_OWNER : 'sharulhafiz';
        $this->github_repo = defined( 'UTM_GITHUB_REPO_NAME' ) ? UTM_GITHUB_REPO_NAME : 'utm-webmaster-tool';
        $this->access_token = defined( 'UTM_GITHUB_ACCESS_TOKEN' ) ? UTM_GITHUB_ACCESS_TOKEN : '';
        
        // Set cache key
        $this->cache_key = 'utm_plugin_update_' . md5( $this->github_owner . '/' . $this->github_repo );
        
        // Hook into WordPress update system
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
        add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 3 );
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
        
        // Find the zipball download URL with authentication if needed
        $download_url = $release->zipball_url;
        if ( ! empty( $this->access_token ) ) {
            $download_url = add_query_arg( 'access_token', $this->access_token, $download_url );
        }
        
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
            return '<p>See the <a href="https://github.com/' . $this->github_owner . '/' . $this->github_repo . '/releases" target="_blank">release notes</a> for details.</p>';
        }
        
        // Convert markdown to HTML (basic conversion)
        $changelog = wpautop( $body );
        
        return $changelog;
    }
    
    /**
     * Clear update cache (useful for debugging)
     */
    public function clear_cache() {
        delete_transient( $this->cache_key );
    }
}

// Initialize the auto-updater
function utm_init_auto_updater() {
    new UTM_Plugin_Auto_Updater();
}
add_action( 'init', 'utm_init_auto_updater' );

/**
 * Add admin notice for auto-update configuration
 */
function utm_auto_update_admin_notice() {
    // Only show to network admins
    if ( ! is_super_admin() ) {
        return;
    }
    
    // Check if access token is configured for private repo
    if ( ! defined( 'UTM_GITHUB_ACCESS_TOKEN' ) ) {
        $screen = get_current_screen();
        if ( $screen && in_array( $screen->id, array( 'plugins', 'plugins-network' ) ) ) {
            ?>
            <div class="notice notice-info">
                <p>
                    <strong>UTM Webmaster Tool:</strong> 
                    For automatic updates from a private repository, please define <code>UTM_GITHUB_ACCESS_TOKEN</code> in your wp-config.php file.
                    <a href="https://github.com/settings/tokens" target="_blank">Generate a GitHub token</a> with <code>repo</code> access.
                </p>
            </div>
            <?php
        }
    }
}
add_action( 'admin_notices', 'utm_auto_update_admin_notice' );
add_action( 'network_admin_notices', 'utm_auto_update_admin_notice' );
