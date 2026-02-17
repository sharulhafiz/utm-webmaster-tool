<?php
/**
 * Optional wp-config.php Configuration for UTM Plugin Auto-Update
 * 
 * NOTE: Configuration is no longer required in wp-config.php!
 * The GitHub token is now set directly in the plugin file (modules/auto-update.php).
 * 
 * However, you can still use wp-config.php to override the token if needed.
 * This is useful if you want different tokens for different installations.
 */

/**
 * Optional: Override GitHub Access Token
 * 
 * If you want to override the token set in the plugin file,
 * add this line to your wp-config.php file:
 */

// define( 'UTM_GITHUB_ACCESS_TOKEN', 'your_github_personal_access_token_here' );

/**
 * How to generate a token:
 * 1. Go to https://github.com/settings/tokens
 * 2. Click "Generate new token (classic)"
 * 3. Give it a name like "UTM Plugin Auto-Update"
 * 4. Select the "repo" scope (Full control of private repositories)
 * 5. Click "Generate token"
 * 6. Copy the token and paste it in the define above
 */

/**
 * Primary Configuration Method (Recommended):
 * 
 * Instead of using wp-config.php, you can set the token directly in:
 * modules/auto-update.php (line ~90)
 * 
 * This way, the token is committed to the repository and automatically
 * works across all your WordPress installations without manual configuration.
 */

/**
 * Security Note:
 * 
 * Since this is a private repository and the token will only be distributed
 * to your own WordPress installations, it's safe to commit the token to the
 * repository. However, ensure:
 * 
 * 1. The repository remains private
 * 2. Only trusted users have access to the repository
 * 3. Rotate the token periodically
 * 4. Only grant "repo" scope (minimum required permissions)
 * 5. Monitor your GitHub account for unexpected token usage
 */

