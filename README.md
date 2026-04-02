# UTM Webmaster Tool

A comprehensive WordPress Multisite network plugin designed for managing and extending the Universiti Teknologi Malaysia (UTM) web infrastructure at people.utm.my.

[![Version](https://img.shields.io/badge/version-5.40-blue.svg)](https://github.com/sharulhafiz/utm-webmaster-tool)
[![WordPress](https://img.shields.io/badge/WordPress-Multisite-green.svg)](https://wordpress.org/)
[![License](https://img.shields.io/badge/license-GPL-orange.svg)](LICENSE)

## 📋 Overview

UTM Webmaster Tool is a modular WordPress plugin that provides 40+ specialized modules for managing a large-scale multisite installation. Built specifically for UTM's organizational needs, it handles everything from automated backups to single sign-on integration, user management, and content migration.

## ✨ Key Features

### 🔐 Core Administration
- **Multisite Management** - Comprehensive tools for managing 3000+ WordPress sites
- **User Management** - Bulk user operations, role management, and user metadata handling
- **Backup & Migration** - Automated daily backups with email notifications
- **Analytics Integration** - Google Analytics and custom analytics dashboard with CSV export

### 🎯 UTM-Specific Integrations
- **people.utm.my** - Automatic site creation and user directory management
- **news.utm.my** - News aggregation and content distribution
- **support.utm.my** - Support portal integration
- **Single Sign-On (SSO)** - Unified authentication across UTM services
- **Staff API** - Integration with UTM staff directory

### 🛠️ Technical Tools
- **Performance Monitoring** - Cache monitoring, heartbeat optimization, and performance patches
- **Security** - Anti-spam, protected content, and login logging
- **SEO & Content** - SEO optimization, broken link checker, and content visibility controls
- **Import/Export** - Google Docs integration, bulk content import, and CSV export tools

### 📊 Content Management
- **Custom Shortcodes** - Reusable content blocks and dynamic elements
- **Event Management** - Event scheduling and calendar integration
- **Email Management** - Enhanced mail handling and chatbot functionality
- **Media Tools** - Upload path fixes and media migration utilities

## 📦 Installation

### Requirements
- WordPress 5.0 or higher
- WordPress Multisite enabled
- PHP 7.4 or higher
- MySQL 5.6 or higher
- `mysqldump` utility (for database backups)

### Installation Steps

1. **Download the plugin**
   ```bash
   cd wp-content/plugins/
   git clone https://github.com/sharulhafiz/utm-webmaster-tool.git
   ```

2. **Network Activate**
   - Navigate to **Network Admin** → **Plugins**
   - Find "UTM Webmaster Tool"
   - Click **Network Activate**

3. **Configure Settings**
   - Access plugin settings via **Network Admin** menu
   - Configure individual modules as needed

## 🧩 Module Reference

The plugin includes 40+ specialized modules organized by function:

### Core Modules
| Module | Description |
|--------|-------------|
| `analytics.php` | Custom analytics with REST API for CSV export |
| `backup.php` | Automated database and menu backups |
| `people.utm.my.php` | User site management with auto-creation |
| `sso.php` | Single Sign-On integration and email-domain gating |
| `multisite-api.php` | REST API endpoints for multisite operations |

### User Management
| Module | Description |
|--------|-------------|
| `bulk-add-user.php` | Bulk user import functionality |
| `bulkdeleteuser.php` | Bulk user deletion tools |
| `fixuserrole.php` | User role repair utilities |
| `usermeta.php` | User metadata management |

### Content & Import
| Module | Description |
|--------|-------------|
| `utm-news-import.php` | Import news from external sources |
| `gdocsImport.php` | Google Docs content import |
| `postExport.php` | Bulk post export functionality |
| `shortcodes.php` | Custom WordPress shortcodes |

### Monitoring & Optimization
| Module | Description |
|--------|-------------|
| `cache-monitor.php` | Cache performance monitoring |
| `conditional-redirects.php` | Per-site conditional redirects (front page/login page/login state) |
| `performance-patch.php` | WordPress performance optimizations |
| `debug.php` | Debug tools and logging |
| `loginlogger.php` | User login activity tracking |

### Access & Authentication
| Module | Description |
|--------|-------------|
| `sso.php` | Login, SSO, and email-domain gating controls |

For detailed documentation on specific modules, see the `modules/` directory documentation files:
- [People Module Guide](modules/PEOPLE_MODULE_QUICK_REFERENCE.md)
- [Backup Documentation](modules/BACKUP_MODULE_DOCUMENTATION.md)
- [Analytics API Guide](modules/ANALYTICS_JSON_README.md)
- [News Import Guide](modules/UTM_NEWS_IMPORT_README.md)

## 🚀 Usage

### Accessing Module Features

Most modules add functionality through the WordPress admin interface:

1. **Network Dashboard**
   - Navigate to **Network Admin** → **UTM Webmaster Tools**
   - Access individual module settings and controls

2. **Site Dashboard**
   - Some features are available at the individual site level
   - Look for UTM-prefixed menu items

### Common Tasks

#### Creating Database Backup
```
Network Admin → UTM Backup → Create Database Backup
```

#### Managing User Sites
```
Network Admin → UTM People → Manage User Sites
```

#### Viewing Analytics
```
Network Admin → UTM Analytics → View Reports
```

#### Importing News Content
```
Network Admin → UTM News → Import from Source
```

### Conditional Redirects (Per-Site)

Use this module when a site admin needs simple condition-based redirects without writing custom code.

**Admin path (per site):**
`Site Dashboard → Settings → UTM Redirects`

Available conditions:
- **Front page**: redirect requests for the site front page.
- **Login page**: redirect visits to `wp-login.php`.
- **Logged-in users**: redirect authenticated users on frontend requests.
- **Non-logged-in users**: redirect guest users on frontend requests.

How to configure each rule:
1. Check **Enable redirect for this condition**.
2. Set **Target URL** (absolute URL).
3. Save changes.

Example setup:
- Front page → `https://www.utm.my/welcome/`
- Login page → `https://sso.utm.my/`
- Logged-in users → `https://people.utm.my/dashboard/`
- Non-logged-in users → `https://www.utm.my/login/`

Notes:
- Rules are stored per site (not network-global).
- Redirects skip admin, AJAX, cron, REST, and XML-RPC requests.
- Loop protection is included (same current URL and target URL will not redirect).

## 🔧 Configuration

### Environment Constants

Key constants defined in `index.php`:
- `UTM_PLUGIN_VERSION` - Current plugin version
- `UTM_WEBMASTER_PLUGIN_PATH` - Absolute path to plugin directory
- `UTM_WEBMASTER_PLUGIN_URL` - URL to plugin directory

### Backup Configuration

Backups are stored in:
- Database: `wp-content/uploads/utm-backups/database/`
- Menus: `wp-content/uploads/utm-backups/menus/`

Default retention: 7 days

### Cache Settings

Many modules use 5-minute transient caching for performance. Clear cache:
```php
delete_transient('utm_cache_key');
```

## 🧪 Testing

The plugin includes validation scripts in the `/tests` directory:

```bash
# Run Phase 6 tests
php tests/run-phase6-tests.php

# Validate Phase 6 implementation
php tests/validate-phase6-implementation.php
```

### Container-based PHP syntax lint (recommended)

Do not rely on host `php` binary for syntax checks. Use container PHP:

```bash
# Lint using a running container name
scripts/php-lint-in-container.sh --container www-directory-php /var/www/html/api/sso.php

# Lint multiple plugin files via compose service
scripts/php-lint-in-container.sh --service www-php index.php modules/dashboard.php
```

## 🐛 Debugging

Enable WordPress debugging to troubleshoot issues:

```php
// Add to wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Check debug output:
```bash
tail -f wp-content/debug.log
```

## 📝 Development

### Adding a New Module

1. Create `modules/your-module.php`
2. Add ABSPATH security check
3. Implement using WordPress hooks
4. Add module name to `utm_load_modules()` in `index.php`
5. Create documentation in `modules/YOUR_MODULE_README.md`

### Code Standards
- Follow WordPress coding standards
- Use WordPress APIs (never direct database access)
- Implement proper sanitization and validation
- Add capability checks for all admin functions

## 🤝 Contributing

This plugin is maintained by the UTM Web Team. For contributions:

1. Fork the repository
2. Create a feature branch
3. Follow WordPress coding standards
4. Test in a multisite environment
5. Submit a pull request

## 📄 License

This project is licensed under the GPL license - see the LICENSE file for details.

## 👥 Support

- **Author**: UTM Webmaster Team
- **Website**: [https://osca.utm.my/webteam](https://osca.utm.my/webteam)
- **Lead Developer**: [Sharul Hafiz](https://people.utm.my/sharulhafiz)

## 🔗 Related Resources

- [WordPress Multisite Documentation](https://wordpress.org/support/article/create-a-network/)
- [WordPress Plugin Development](https://developer.wordpress.org/plugins/)
- [UTM Official Website](https://www.utm.my/)

---

**Note**: This plugin is designed specifically for UTM's infrastructure. While it can be adapted for other institutions, some modules are tightly integrated with UTM-specific services and APIs.
