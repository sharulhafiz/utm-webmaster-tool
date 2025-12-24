---
applyTo: '**'
---

# Coding Preferences & Project Context

## Project Overview
UTM Webmaster Tool - WordPress multisite management plugin for Universiti Teknologi Malaysia (UTM) network.

## Architecture Patterns

### WordPress Multisite
- Main site (blog_id = 1) is administrative hub
- Subsites managed per user/department
- Users automatically get personal sites on people.utm.my
- Super admins have full network access

### Caching Strategy
- Use WordPress transients for short-term cache (5 minutes typical)
- Use user meta for persistent user-specific cache
- Always provide cache invalidation hooks
- Clear cache on role/blog membership changes

### Function Naming Convention
- Prefix all functions with `utm_` to avoid conflicts
- Use descriptive names: `utm_get_user_admin_sites()` not `get_sites()`
- Helper functions clearly marked with comments
- Group related functions in sections with clear headers

### Error Handling Pattern
```php
// Always return WP_Error for failures
if ( $error_condition ) {
    return new WP_Error( 'error_code', 'Error message' );
}

// Log errors when WP_DEBUG enabled
utm_log_site_action( $id, 'error', 'Detailed message' );

// Store user-facing errors in transients
set_transient( 'error_key_' . $user_id, $message, 60 );
```

### Security Practices
- Always escape output: `esc_html()`, `esc_url()`, `esc_attr()`
- Sanitize input: `sanitize_text_field()`, custom sanitizers
- Use prepared statements with `$wpdb`
- Check capabilities before actions
- Validate nonces for form submissions

### Performance Optimization
- Cache expensive database queries
- Use transients for temporary data
- Minimize `switch_to_blog()` calls - consolidate into single functions
- Clear only necessary caches, not everything

## WordPress Best Practices

### Hooks & Filters
- Always provide filters for customization: `apply_filters()`
- Always provide actions for extensibility: `do_action()`
- Name filters: `utm_feature_action` (e.g., `utm_should_redirect_login`)
- Name actions: `utm_past_tense_event` (e.g., `utm_user_site_created`)

### Documentation
- Use PHPDoc comments for all functions
- Include @param, @return, @since tags
- Add inline comments for complex logic
- Create separate documentation files for major features

### Internationalization
- Use `__()`, `_e()`, `_n()` for translatable strings
- Text domain: `'utm-webmaster'`
- Provide context when needed: `_x()`

## Domain-Specific Rules

### people.utm.my Module
- Only activates when domain is exactly 'people.utm.my'
- Non-super-admins cannot access main site dashboard
- Sites auto-created with pattern: `/username/` from email prefix
- Archived sites auto-reactivated on login
- Race condition prevention required for site creation

### Site Slug Sanitization
- Remove dots and special characters (nginx compatibility)
- Fallback to `user[hash]` if sanitization yields empty string
- Always lowercase
- Regex: `/[^a-zA-Z0-9_-]/`

### Redirect Flow Priority
1. Check if user is logged in
2. Check if main site (blog_id == 1)
3. Exclude super admins
4. Skip AJAX/CRON/admin-post requests
5. Check for redirect guard parameter
6. Apply filters to allow customization
7. Perform redirect

## Code Style

### PHP Standards
- Opening brace on same line for functions
- Tabs for indentation (WordPress standard)
- Spaces around operators and after commas
- Yoda conditions: `if ( 1 == $var )` not `if ( $var == 1 )`

### Comments
```php
// Single-line comment for brief explanations

/**
 * Multi-line PHPDoc comment for functions
 * 
 * @param int $param Description
 * @return string Description
 */

// ==================================================================
// SECTION HEADERS IN ALL CAPS
// ==================================================================
```

## Solutions Repository

### Site Creation Race Condition
**Problem**: Multiple concurrent login attempts could create duplicate sites
**Solution**: Transient-based locking with 30-second timeout
```php
$lock_key = 'utm_creating_site_' . $user_id;
if ( false !== get_transient( $lock_key ) ) {
    sleep(2); // Wait for other process
}
set_transient( $lock_key, time(), 30 );
// ... create site ...
delete_transient( $lock_key );
```

### Performance: Reduce switch_to_blog Calls
**Problem**: Multiple functions calling switch_to_blog repeatedly
**Solution**: Single helper function with caching
```php
function utm_get_user_admin_sites( $user_id ) {
    $cached = get_transient( 'utm_user_admin_sites_' . $user_id );
    if ( false !== $cached ) return $cached;
    // ... build array ...
    set_transient( $cache_key, $admin_sites, 5 * MINUTE_IN_SECONDS );
    return $admin_sites;
}
```

### Welcome Notice Pattern
**Problem**: How to show notice once after site creation
**Solution**: User meta flag set on creation, deleted after display
```php
// On creation:
update_user_meta( $user_id, 'utm_show_welcome_notice', '1' );

// In admin_notices:
if ( '1' === get_user_meta( $user_id, 'utm_show_welcome_notice', true ) ) {
    // Show notice
    delete_user_meta( $user_id, 'utm_show_welcome_notice' );
}
```

### Google Docs Styling Preservation (SDG Module)
**Problem**: Styles stripped when importing Google Docs to WordPress
**Root Cause**: Apps Script removed CSS class attributes but kept `<style>` tag with class definitions
**Solution**: 
1. Keep all class attributes (`c0`, `c1`, etc.) - don't strip them
2. Extract and preserve `<style>` tag content
3. Wrap content in `.google-doc-content` div for scoping
4. Prefix CSS selectors to scope them: `.c1` → `.google-doc-content .c1`
```javascript
// In parseGoogleDocHtml():
// REMOVED THIS LINE (was breaking styles):
// html.replace(/ (id|class)="c\d+"/gi, '');

// ADDED scoping:
let scopedStyles = styleContent
    .replace(/\.(c\d+)/g, '.google-doc-content .$1')
    .replace(/^(p|ol|ul|table|li|h\d|body)\s*\{/gm, '.google-doc-content $1 {');
```

### Hierarchical Menu with Dynamic Filtering
**Problem**: Need hierarchical menu showing only parents with children, but support future additions
**Solution**: Post meta flags + pattern matching + hierarchical menu building
```php
// Mark parents with meta:
update_post_meta($post_id, '_is_sdg_parent', true);
update_post_meta($post_id, '_sdg_number', $i);

// Pattern match children to parents:
if (preg_match('/^(\d+)\./', $post->post_title, $matches)) {
    $sdg_number = intval($matches[1]);
    $sdg_structure[$sdg_number]['children'][] = $post;
}

// Build menu hierarchically:
wp_update_nav_menu_item($menu->term_id, 0, [
    'menu-item-parent-id' => $parent_menu_id // Creates hierarchy
]);
```

### Dynamic Content Injection via Filters
**Problem**: Need to add navigation to content without template modification
**Solution**: Use `the_content` filter to prepend navigation
```php
add_filter('the_content', 'gdocs_add_sibling_navigation');

function gdocs_add_sibling_navigation($content) {
    if (!is_singular('the')) return $content;
    // Build navigation HTML
    return $nav_html . $content; // Prepend to content
}
```

## Failed Approaches

### ❌ Direct Database Updates Without Status Check
**What Failed**: Updating blog status directly without checking current state
**Why**: Caused unnecessary queries and didn't log changes
**Use Instead**: `utm_ensure_site_active()` which checks first

### ❌ Permanent User Meta Cache Without Validation
**What Failed**: Storing admin URL permanently without checking if site still exists
**Why**: Sites could be deleted, causing broken redirects
**Use Instead**: Validate cached URL before using, check site status

### ❌ get_posts() with 'name' Parameter for post_name Matching
**What Failed**: Using `get_posts(['name' => $slug, ...])` to check for existing posts by slug
**Why**: The 'name' parameter in WP_Query doesn't reliably match post_name field - it's meant for URL path matching
**Use Instead**: Direct $wpdb query with prepared statement:
```php
$existing = $wpdb->get_var($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} 
    WHERE post_type = %s AND post_name = %s 
    AND post_status != 'trash' LIMIT 1",
    $post_type, $slug
));
```

### ❌ Automatic Execution on save_post Hook Without Guards
**What Failed**: Running page creation function on every `save_post_the` action
**Why**: Creates duplicates due to multiple saves (autosave, revisions, manual saves, imports)
**Use Instead**: Manual URL parameter triggers with admin-only capability checks:
```php
add_action('template_redirect', function() {
    if (!current_user_can('manage_options')) return;
    if (isset($_GET['action']) && $_GET['action'] === 'create') {
        // Execute once
    }
});
```

## Conventions

### File Organization
- Main module file: `modules/modulename.php`
- Documentation: `modules/MODULENAME_IMPROVEMENTS.md`
- Quick reference: `modules/MODULENAME_QUICK_REFERENCE.md`
- Keep related files together

### Testing
- Test with WP_DEBUG enabled
- Check error logs after changes
- Test with multiple user types
- Test concurrent access scenarios
- Verify cache invalidation works

### Deployment
- Clear all related caches before deploying
- Monitor error logs after deployment
- Have rollback plan ready
- Test on staging first

## Module Improvements Pattern

When improving a module:
1. ✅ Analyze current code for duplication
2. ✅ Remove unused functions
3. ✅ Consolidate duplicate logic
4. ✅ Add caching with invalidation
5. ✅ Add error handling
6. ✅ Add logging
7. ✅ Add extensibility hooks
8. ✅ Improve user experience
9. ✅ Document everything
10. ✅ Create quick reference guide

## Notes

- Always prefer WordPress core functions over custom implementations
- Use transients for temporary data, options for permanent settings
- Clear caches when data changes
- Provide hooks for other plugins to extend functionality
- Keep functions focused and single-purpose
- Document why, not just what
