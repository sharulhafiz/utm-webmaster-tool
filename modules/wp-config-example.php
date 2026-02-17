<?php
/**
 * Example wp-config.php Configuration for UTM Plugin Auto-Update
 * 
 * Add these lines to your wp-config.php file to enable automatic updates
 * from a private GitHub repository.
 * 
 * IMPORTANT: Place these lines before the "That's all, stop editing!" comment
 * in your wp-config.php file.
 */

/**
 * GitHub Auto-Update Configuration
 * 
 * For private repositories, you need to provide a GitHub Personal Access Token.
 * 
 * How to generate a token:
 * 1. Go to https://github.com/settings/tokens
 * 2. Click "Generate new token (classic)"
 * 3. Give it a name like "UTM Plugin Auto-Update"
 * 4. Select the "repo" scope (Full control of private repositories)
 * 5. Click "Generate token"
 * 6. Copy the token and paste it below
 */

// Required for private repositories
define( 'UTM_GITHUB_ACCESS_TOKEN', 'your_github_personal_access_token_here' );

/**
 * Optional: Custom Repository Configuration
 * 
 * If you're using a fork or different repository, you can override
 * the default repository settings:
 */

// define( 'UTM_GITHUB_REPO_OWNER', 'your-github-username' );
// define( 'UTM_GITHUB_REPO_NAME', 'your-repo-name' );

/**
 * Security Best Practices:
 * 
 * 1. Never commit wp-config.php to version control
 * 2. Ensure wp-config.php has restricted file permissions (e.g., 0600)
 * 3. Keep your GitHub token secure and rotate it periodically
 * 4. Only grant "repo" scope to the token (minimum required permissions)
 * 5. Monitor your GitHub account for unexpected token usage
 */

/**
 * Troubleshooting:
 * 
 * If updates are not appearing:
 * - Verify the token is correctly pasted (no extra spaces)
 * - Check that the token has "repo" scope
 * - Ensure releases are published (not draft) on GitHub
 * - Check WordPress error logs for specific error messages
 * - Try clearing the update cache in WordPress admin
 * 
 * Error log location:
 * - Usually in /wp-content/debug.log (if WP_DEBUG_LOG is enabled)
 * - Or check your server's PHP error logs
 */
