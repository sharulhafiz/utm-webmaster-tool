# Plugin Auto-Update Module

## Overview

The auto-update module enables automatic updates for the UTM Webmaster Tool plugin from a private GitHub repository. This allows the plugin to check for new releases and update itself automatically through the WordPress admin interface.

## Features

- Automatic update checks from GitHub releases
- Support for private repositories with access tokens
- Integration with WordPress native update system
- Caching of update information to reduce API calls
- Proper directory naming during updates
- Admin notices for configuration guidance

## Configuration

### Basic Configuration (Public Repository)

If your repository is public, the module will work out of the box with default settings:

- **Repository Owner**: `sharulhafiz`
- **Repository Name**: `utm-webmaster-tool`

### Advanced Configuration (Private Repository)

For private repositories, you need to configure a GitHub access token in your `wp-config.php` file:

```php
// GitHub Auto-Update Configuration
define( 'UTM_GITHUB_ACCESS_TOKEN', 'your_github_personal_access_token_here' );
```

#### Generating a GitHub Access Token

1. Go to [GitHub Settings > Personal Access Tokens](https://github.com/settings/tokens)
2. Click "Generate new token" (classic)
3. Give it a descriptive name (e.g., "UTM Plugin Auto-Update")
4. Select the `repo` scope (Full control of private repositories)
5. Click "Generate token"
6. Copy the token and add it to your `wp-config.php` file

### Custom Repository Configuration

If you need to use a different repository:

```php
// Custom GitHub repository
define( 'UTM_GITHUB_REPO_OWNER', 'your-github-username' );
define( 'UTM_GITHUB_REPO_NAME', 'your-repo-name' );
define( 'UTM_GITHUB_ACCESS_TOKEN', 'your_github_token' );
```

## How It Works

### Update Check Process

1. WordPress periodically checks for plugin updates (typically every 12 hours)
2. The module queries the GitHub API for the latest release
3. It compares the current version with the latest release version
4. If a newer version is available, it appears in the WordPress updates list

### Update Installation

1. When you click "Update Now" in WordPress
2. WordPress downloads the latest release ZIP from GitHub
3. The module ensures the directory structure is correct
4. WordPress extracts and installs the update
5. The plugin is reactivated if it was active before

### Caching

Update information is cached for 12 hours to minimize GitHub API calls. The cache is automatically cleared when:
- A new update check is forced
- The plugin is updated

## GitHub Release Requirements

For the auto-update to work properly, you must:

1. **Create releases** on GitHub (not just tags)
2. **Use version tags** in the format `v5.40` or `5.40`
3. **Publish releases** (draft releases are not detected)

### Creating a Release

1. Go to your GitHub repository
2. Click on "Releases" 
3. Click "Create a new release"
4. Enter a tag version (e.g., `v5.41`)
5. Enter a release title
6. Add release notes (these become the changelog)
7. Click "Publish release"

## Troubleshooting

### Updates Not Showing

1. Check that your GitHub token has `repo` scope
2. Verify the token is correctly defined in `wp-config.php`
3. Check that releases are published (not draft)
4. Clear the update cache: `delete_transient('utm_plugin_update_...')`
5. Check error logs for API errors

### Update Fails to Download

1. Ensure the access token is valid and not expired
2. Check that the repository access permissions are correct
3. Verify network connectivity to GitHub API
4. Check WordPress error logs for specific error messages

### Directory Name Issues

The module automatically handles directory naming when extracting updates. If you encounter issues:
1. Ensure the plugin directory is named `utm-webmaster-tool`
2. Check file permissions on the plugins directory
3. Review error logs for specific errors during extraction

## Security Considerations

1. **Keep your access token secure**: Store it only in `wp-config.php`, never commit it to version control
2. **Use minimum required permissions**: Only grant `repo` scope access
3. **Rotate tokens regularly**: Generate new tokens periodically and update your configuration
4. **Monitor token usage**: Check GitHub's token usage logs for unexpected activity

## API Rate Limits

GitHub API has rate limits:
- **Authenticated requests**: 5,000 per hour
- **Unauthenticated requests**: 60 per hour

The module caches update information for 12 hours to stay well within these limits.

## Code Integration

The module is automatically loaded by the main plugin file. The load order is:

1. `index.php` defines constants and registers activation hook
2. `utm_load_modules()` function loads all modules including `auto-update.php`
3. `utm_init_auto_updater()` initializes the auto-updater on the `init` hook
4. WordPress hooks are registered to integrate with the update system

## Support

For issues or questions:
- Check the plugin error logs
- Review GitHub repository settings
- Verify access token configuration
- Contact the UTM Webmaster team

## Version History

- **5.40**: Initial implementation of auto-update module
