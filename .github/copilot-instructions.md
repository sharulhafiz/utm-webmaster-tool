# UTM Webmaster Tool - Copilot Instructions

## Repository Overview

**UTM Webmaster Tool** is a WordPress Multisite network plugin designed for Universiti Teknologi Malaysia (UTM). It provides comprehensive tools for managing a large-scale multisite installation (3000+ sites) at people.utm.my.

**Project Type:** WordPress Network-activated Plugin  
**Primary Language:** PHP  
**Target Environment:** WordPress Multisite (Network-level plugin)  
**Version:** 5.40  
**Repository Size:** ~40 PHP modules + documentation

## High-Level Architecture

This is a **modular WordPress plugin** where each feature is implemented as a separate PHP module file in the `/modules` directory. The main plugin file (`index.php`) loads all modules during WordPress initialization.

### Tech Stack
- **Language:** PHP (WordPress-compatible, typically PHP 7.4+)
- **Framework:** WordPress Multisite APIs
- **Database:** MySQL/MariaDB (via WordPress $wpdb)
- **Frontend:** WordPress admin UI, some JavaScript for interactive features
- **Deployment:** Network-activated WordPress plugin

### Key WordPress APIs Used
- Multisite functions: `get_blogs_of_user()`, `switch_to_blog()`, `wpmu_create_blog()`
- WordPress hooks: `admin_init`, `plugins_loaded`, `template_redirect`, `wp_enqueue_scripts`
- Cron/scheduling: `wp_schedule_event()` for automated tasks
- REST API: Custom endpoints for analytics and data export

## Build and Validation

### ⚠️ CRITICAL: No Traditional Build System

This is a **PHP WordPress plugin** - there is NO npm, composer, or traditional build system in this repository.

### Installation/Deployment
1. This plugin is designed to be uploaded to a WordPress installation
2. Activate at the **Network Admin** level in WordPress Multisite
3. Configuration happens through WordPress admin UI, not config files

### Testing
The `/tests` directory contains PHP validation scripts for specific features:
- `run-phase6-tests.php` - Test runner for Phase 6 features
- `test-phase6-audio-playlist-widget.php` - Audio playlist widget tests
- `validate-phase6-implementation.php` - Phase 6 validation script

**To run tests:**
```bash
php tests/run-phase6-tests.php
```

**Note:** There is no automated test suite or CI/CD pipeline configured. Testing is primarily manual via WordPress admin interface.

### Linting
**No linters are configured.** Code follows WordPress coding standards informally.

### Validation Steps
When making changes:
1. Verify PHP syntax: `php -l file.php`
2. Test in a WordPress Multisite environment (staging recommended)
3. Check WordPress debug.log for errors: Enable `WP_DEBUG` in wp-config.php
4. For specific modules, use the test scripts in `/tests` directory

### Common Pitfalls
- **Don't run composer install or npm install** - they don't exist here
- **Opcache issues:** Some modules call `opcache_reset()` to force cache clears
- **Multisite context:** Always be aware which site context you're in (main site vs subsites)
- **Cache clearing:** WordPress object cache and transients are used heavily for performance

## Project Layout

### Root Directory Structure
```
/index.php                  # Main plugin file, defines constants, loads modules
/style.css                  # Admin UI styling
/.gitignore                 # Git ignore rules (excludes .github/, .vscode/, tests/, plans/)
/modules/                   # All feature modules (40+ PHP files)
/assets/                    # Static assets (images, CSS, JS)
/tests/                     # PHP test scripts (not PHPUnit)
/plans/                     # Planning documents (markdown files for completed phases)
/.github/instructions/      # Path-specific Copilot instructions
/.agents/                   # Agent-specific memory files
/.vscode/                   # VS Code settings (SFTP deployment config)
```

### Module Directory (`/modules`)
Each `.php` file in `/modules` is a self-contained feature module:

**Key Modules:**
- `people.utm.my.php` - User site management, auto-creates personal sites, handles redirects
- `backup.php` - Database and menu backup system with scheduled tasks
- `analytics.php` - REST API for CSV export of analytics data
- `antispam.php` - Comment spam prevention
- `news.utm.my.php` - News aggregation and management
- `utm-news-import.php` - Import news from external sources
- `sso.php` - Single Sign-On integration
- `chatbot.php` - Chatbot functionality
- `fixuploadpath.php` - Upload path fixing for multisite migrations
- `cache-monitor.php` - Cache performance monitoring
- `timezone.php` - Timezone management
- `seo.php` - SEO optimizations
- `shortcodes.php` - Custom WordPress shortcodes
- `formidableforms.php` - Formidable Forms integrations
- `multisite-api.php` - Multisite REST API endpoints
- `multisite-statistics.php` - Network statistics dashboard
- `bulk-add-user.php`, `bulkdeleteuser.php` - Bulk user operations
- `registrar.php` - User registration customizations

**Module Documentation:**
Many modules have accompanying `.md` files documenting their features:
- `PEOPLE_MODULE_QUICK_REFERENCE.md` - People module guide
- `BACKUP_MODULE_DOCUMENTATION.md` - Backup system details
- `ANALYTICS_JSON_README.md` - Analytics API documentation
- `UTM_NEWS_IMPORT_README.md` - News import guide

### Important Configuration
- **Plugin activation hook:** `utm_plugin_activation_hook()` in index.php performs one-time cleanup
- **Module loading:** `utm_load_modules()` function explicitly lists all modules to load
- **Constants defined:**
  - `UTM_PLUGIN_VERSION` - Plugin version number
  - `UTM_WEBMASTER_PLUGIN_PATH` - Absolute path to plugin directory
  - `UTM_WEBMASTER_PLUGIN_URL` - URL to plugin directory
  - `UTM_NETWORK_SITE_URL` - Dynamically set after WordPress loads

### WordPress Integration Points
- **Network activation:** Plugin requires network activation (`Network: true` in header)
- **Hooks used extensively:** `plugins_loaded`, `admin_init`, `admin_menu`, `rest_api_init`, `wp_enqueue_scripts`
- **Multisite-specific:** Code checks `is_super_admin()`, uses `switch_to_blog()`, manages network-wide settings
- **Security checks:** All modules check `ABSPATH` to prevent direct access

## Coding Guidelines

### WordPress Best Practices
1. **Always check `ABSPATH`:** Start modules with:
   ```php
   if (!defined('ABSPATH')) {
       exit;
   }
   ```

2. **Use WordPress APIs:** Prefer WordPress functions over raw PHP:
   - Database: Use `$wpdb` not direct MySQL
   - File system: Use `WP_Filesystem` API
   - HTTP requests: Use `wp_remote_get()`, `wp_remote_post()`
   - Sanitization: `sanitize_text_field()`, `esc_html()`, `esc_url()`, etc.

3. **Multisite awareness:**
   - Check `is_multisite()` when needed
   - Be aware of blog context when using `switch_to_blog()`
   - Always `restore_current_blog()` after switching

4. **Hooks over direct calls:** Use actions/filters for extensibility

5. **Transient caching:** Use WordPress transients for performance:
   ```php
   $cached = get_transient('utm_cache_key');
   if ($cached === false) {
       // Generate data
       set_transient('utm_cache_key', $data, 5 * MINUTE_IN_SECONDS);
   }
   ```

### Code Style Preferences
- **Clean, maintainable code** following WordPress conventions
- **Minimal comments** - code should be self-documenting
- **PHPDoc blocks** for functions explaining purpose, parameters, return values
- **Consistent indentation** - 4 spaces (WordPress standard)
- **Variable naming:** `$snake_case` for PHP variables

### Security Considerations
- **Nonce verification:** Always verify nonces for form submissions
- **Capability checks:** Use `current_user_can()` to check permissions
- **SQL injection prevention:** Use `$wpdb->prepare()` for queries
- **XSS prevention:** Escape all output with `esc_html()`, `esc_attr()`, etc.
- **File upload validation:** Validate file types and sizes

### Performance Tips
- **Avoid `glob()`** - Module loading in index.php moved away from glob() to explicit array
- **Cache database queries** using transients (5-minute cache is common)
- **Opcache considerations:** Some modules call `opcache_reset()` when needed
- **Minimize `switch_to_blog()` calls** - they're expensive in multisite
- **Use WP cron for heavy tasks** - don't run expensive operations on page load

## Common Development Tasks

### Adding a New Module
1. Create `modules/new-feature.php`
2. Add module header and ABSPATH check
3. Implement functionality using WordPress hooks
4. Add module name to `$modules` array in `utm_load_modules()` function in index.php
5. Create documentation file `modules/NEW_FEATURE_README.md` if complex

### Modifying Existing Modules
1. **Always backup first** - plugin has backup module for a reason
2. Test changes in staging environment, not production
3. Enable WP_DEBUG to see warnings/errors
4. Check if module has documentation in `/modules/*.md` files
5. Verify changes don't affect other sites in the network

### Debugging
1. Enable debugging in WordPress:
   ```php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('WP_DEBUG_DISPLAY', false);
   ```
2. Check `/wp-content/debug.log` for errors
3. Use `error_log()` for debugging output
4. Many modules have their own logging functions (e.g., `utm_log_site_action()`)

### Working with People Module
The people.utm.my module is critical - it:
- Auto-creates sites for users without one
- Redirects non-super-admins from main site to their own site
- Handles site activation and status management
- Fixes Divi theme onboarding redirect loops

**Key functions:**
- `utm_get_user_admin_sites($user_id)` - Get all sites where user is admin
- `utm_ensure_site_active($blog_id)` - Activate archived/suspended sites
- `utm_create_user_site($user_id)` - Create new site for user
- `utm_get_or_create_user_admin_url($user_id)` - Get admin URL, creating site if needed

**Caching:** Module uses 5-minute transient cache. Clear with:
```php
delete_transient('utm_user_admin_sites_' . $user_id);
delete_user_meta($user_id, 'utm_cached_admin_url');
```

### Working with Backup Module
- Daily automatic backups scheduled via WordPress cron
- Database backups stored in `/wp-content/uploads/utm-backups/database/`
- Menu backups stored in `/wp-content/uploads/utm-backups/menus/`
- Uses mysqldump for database exports
- 7-day retention policy by default
- Email notifications on success/failure

## Environment and Dependencies

### Required WordPress Environment
- WordPress Multisite installation (network-level)
- MySQL/MariaDB database
- PHP 7.4 or higher (recommended)
- mod_rewrite or equivalent for permalinks
- Sufficient disk space for backups

### No External Dependencies
**Important:** This plugin has no composer.json or package.json. All dependencies are:
1. WordPress core functions
2. PHP standard library
3. System utilities (`mysqldump` for backups)

### Deployment
- **Method:** SFTP (see `.vscode/sftp.json` for deployment config)
- **Target:** WordPress multisite `wp-content/plugins/` directory
- **Activation:** Network Admin > Plugins > Network Activate

## Agent Instructions

### When Making Changes
1. **Identify the module** - Find the relevant PHP file in `/modules`
2. **Read the module documentation** - Check for corresponding `.md` file
3. **Understand WordPress context** - Is this network-wide or site-specific?
4. **Test PHP syntax** - Run `php -l filename.php` before committing
5. **Verify security** - Check for proper sanitization and capability checks
6. **Update documentation** - If changing behavior significantly, update the `.md` file
7. **Don't add build tools** - No need for npm, composer, webpack, etc.

### When Debugging
1. Enable WP_DEBUG in wp-config.php
2. Check `/wp-content/debug.log`
3. Look for module-specific logging functions
4. Verify WordPress version compatibility
5. Check if caching is affecting behavior

### Search Strategy
When looking for functionality:
1. **Search module files first** - `grep -r "function_name" modules/`
2. **Check documentation** - `.md` files in `/modules`
3. **Review index.php** - For plugin initialization logic
4. **Look at hooks** - `grep -r "add_action\|add_filter" modules/`

### Testing Strategy
1. Syntax check: `php -l file.php`
2. Manual testing in WordPress admin
3. Check WordPress debug.log for errors
4. Use test scripts in `/tests` if available for the feature
5. Test on staging site first (people.utm.my is production with 3000+ sites)

### Trust These Instructions
**This information has been validated.** Only search the codebase if:
- These instructions are incomplete for your task
- You find contradictory information
- You need specific implementation details not covered here

The plugin structure is stable and well-documented. Trust the module architecture and WordPress best practices outlined above.
