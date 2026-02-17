# UTM Webmaster Tool Dashboard

## Overview

The Dashboard module provides a centralized interface for viewing all UTM Webmaster Tool plugin modules, categorized into **Must Use** and **Optional** modules.

## Features

- **Module Categorization**: Clearly separates essential modules from optional enhancements
- **Status Indicators**: Visual indicators showing which modules are active
- **Module Information**: Displays description and key features for each module
- **Responsive Design**: Uses WordPress admin styles and dashicons for consistency
- **Plugin Information**: Shows current plugin version and author information

## Access

The dashboard is accessible from the WordPress admin menu:

**Location**: `UTM Dashboard` (top-level menu item)  
**Required Capability**: `manage_options`  
**Icon**: Admin tools dashicon

## Module Categories

### Must Use Modules (5)

These modules provide core functionality and are essential for the plugin to work properly:

1. **SSO (Single Sign-On)** - Authentication system
2. **Function** - Core utility functions
3. **Timezone** - Timezone management
4. **Mail** - SMTP configuration
5. **Performance Patch** - Performance optimizations

### Optional Modules (39)

These modules provide additional features and enhancements that can be enabled or disabled as needed. Notable modules include:

- **Backup** - Database and menu backups
- **Chatbot** - AI-powered chatbot integration
- **Analytics** - Content analytics and reporting
- **Anti-Spam** - Comment spam protection
- **Cache Monitor** - Real-time cache monitoring
- **News UTM** - News management with AI features
- **Google Docs Import** - Content import functionality
- **Multisite Statistics** - Network-wide statistics
- And many more...

## Status Indicators

The dashboard uses the following visual indicators:

- ✅ **Green checkmark** - Module is active (file exists)
- ⚠️ **Red warning** - Must-use module file is missing
- 🔘 **Gray marker** - Optional module is not present

## Implementation Details

### File Location

`/modules/dashboard.php`

### Functions

- `utm_dashboard_admin_menu()` - Registers the admin menu
- `utm_dashboard_get_modules()` - Returns module categorization data
- `utm_dashboard_render_page()` - Renders the dashboard HTML

### Hooks Used

- `admin_menu` - Adds the dashboard to WordPress admin menu

### WordPress Integration

The dashboard integrates seamlessly with WordPress admin:

- Uses WordPress admin styles and components
- Follows WordPress coding standards
- Implements proper capability checks
- Uses WordPress dashicons for visual elements

## Module Data Structure

Each module in the dashboard contains:

```php
array(
    'name' => 'Module Name',
    'file' => 'module-file.php',
    'description' => 'Module description',
    'features' => array( 'Feature 1', 'Feature 2', ... )
)
```

## Extending the Dashboard

To add new modules to the dashboard, edit the `utm_dashboard_get_modules()` function in `dashboard.php` and add the module information to either the `must_use` or `optional` array.

## Security

- Access is restricted to users with `manage_options` capability
- All output is properly escaped using `esc_html()`
- Checks for direct access and exits if `ABSPATH` is not defined

## Styling

The dashboard uses inline styles for simplicity and to avoid dependencies:

- CSS Grid for responsive layout
- WordPress color palette for consistency
- Box shadows and borders matching WordPress admin style
- Responsive design that adapts to different screen sizes

## Future Enhancements

Potential future improvements:

- Enable/disable functionality for optional modules
- Module search and filtering
- Module dependency tracking
- Usage statistics for each module
- Configuration links for modules with settings pages
