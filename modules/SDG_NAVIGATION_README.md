# SDG Navigation System - Implementation Guide

## Overview
This implementation adds automatic SDG (Sustainable Development Goals) navigation and hierarchical menu structure for THE custom post type pages, plus fixes Google Docs styling preservation.

## Features Implemented

### 1. Automatic SDG Parent Page Creation
- **Auto-creates 17 SDG parent pages** (SDG 1 through SDG 17) on plugin activation
- Each parent page has:
  - Slug: `sdg-1`, `sdg-2`, ..., `sdg-17`
  - Title: `SDG 1`, `SDG 2`, ..., `SDG 17`
  - Meta field `_is_sdg_parent` set to `true`
  - Meta field `_sdg_number` stores the SDG number (1-17)

### 2. Dynamic Child Page Listing
- **Shortcode: `[sdg_children_list sdg="X"]`** displays all child pages for SDG X
- Automatically embedded in SDG parent pages
- Shows message "No pages available yet" if no children exist (dynamic for future additions)
- Example: SDG 1 page will list all pages with titles starting with "1." (1.3.1, 1.3.2, etc.)

### 3. Sibling Navigation on Child Pages
- When viewing a child page (e.g., `/the/1-3-1/`):
  - Automatically detects parent SDG from title pattern
  - Displays navigation list at **top of content**
  - Shows all sibling pages (same SDG number)
  - Highlights current page with bold text and "(Current)" indicator
  - Sorted alphabetically by title

### 4. Hierarchical Menu Structure
- **Menu "THE Pages"** automatically updates when pages are saved
- Shows **only SDG parents that have children** (but includes empty ones for future)
- Hierarchical structure:
  ```
  SDG 1
    ├─ 1.3.1
    ├─ 1.3.2
    └─ 1.3.3
  SDG 5
    ├─ 5.3.1
    └─ 5.6.1
  ```
- Children sorted using version compare (handles 1.3.1, 1.3.10 correctly)

### 5. Google Docs Styling Preservation (FIXED)
- **Root cause identified**: Previous code stripped CSS class attributes while keeping the `<style>` tag
- **Solution implemented**:
  - Preserves `<style>` tag from Google Docs export
  - Keeps all class attributes (`.c0`, `.c1`, etc.)
  - Wraps content in `.google-doc-content` div for scoping
  - Scopes CSS selectors to prevent theme conflicts
  - Adds responsive styling for images and tables

## Files Modified

### 1. `gdocsImport.php` (WordPress Plugin)
**Changes:**
- Added `_is_sdg_parent` meta field registration
- Added `gdocs_create_sdg_parent_pages()` function with activation hook
- Added `[sdg_children_list]` shortcode
- Added `gdocs_add_sibling_navigation()` content filter
- Completely rewrote `update_the_navigation_menu()` for hierarchical structure
- Added comprehensive CSS styling for navigation and Google Docs content

### 2. `gdocsToWP.js` (Google Apps Script)
**Changes:**
- Fixed `parseGoogleDocHtml()` function:
  - **REMOVED** the line that stripped class attributes: `html.replace(/ (id|class)="c\d+"/gi, '')`
  - Added style extraction and scoping logic
  - Wraps content in `.google-doc-content` container
  - Scopes CSS selectors to prevent conflicts
  - Preserves all inline styles and formatting

## How It Works

### Title Pattern Matching
The system uses regex pattern matching to detect SDG relationships:
- Pattern: `/^(\d+)\./` extracts the SDG number from titles
- Examples:
  - `1.3.1` → SDG 1
  - `5.6.9` → SDG 5
  - `15.2.3` → SDG 15

### Activation Workflow
1. Plugin activated → `gdocs_create_sdg_parent_pages()` runs
2. Creates 17 parent pages with shortcode embedded
3. Sets meta fields for parent identification
4. Regenerates menu structure

### Content Display Workflow
1. User visits child page (e.g., `/the/1-3-1/`)
2. `gdocs_add_sibling_navigation()` filter runs
3. Extracts SDG number from title
4. Queries all posts, filters by SDG pattern
5. Builds navigation HTML and prepends to content

### Menu Update Workflow
1. Any THE page is saved
2. `update_the_navigation_menu()` runs
3. Organizes all posts by SDG number
4. Creates parent menu items for SDGs with children
5. Adds children as nested menu items

## Usage

### Manual Activation (if needed)
If SDG pages aren't auto-created, run this in WordPress:
```php
gdocs_create_sdg_parent_pages();
```

### Shortcode Usage
In any THE page, manually add:
```
[sdg_children_list sdg="3"]
```
This will display all SDG 3 pages.

### Accessing SDG Parent Pages
- Direct URLs: `https://sustainable.utm.my/the/sdg-1/` through `https://sustainable.utm.my/the/sdg-17/`
- Via menu: Navigate through "THE Pages" menu

## Styling Customization

### Navigation Styles
Located in `gdocs_sdg_navigation_styles()` function. Key classes:
- `.sdg-children-list` - Parent page child listing
- `.sdg-sibling-navigation` - Child page sibling navigation
- `.current-page` - Highlights current page
- `.current-indicator` - "(Current)" text styling

### Google Docs Content Styles
- `.google-doc-content` - Main wrapper for scoping
- `.google-doc-content img` - Image responsive styling
- `.google-doc-content table` - Table formatting

## Testing Checklist

- [ ] Verify 17 SDG parent pages created after activation
- [ ] Check SDG 1 page shows all 1.x.x child pages
- [ ] Verify empty SDG pages show placeholder message
- [ ] Test child page displays sibling navigation at top
- [ ] Confirm current page is highlighted in navigation
- [ ] Check menu shows hierarchical structure
- [ ] Verify menu only shows SDG parents (but includes empty ones)
- [ ] Test Google Docs styling preserved in WordPress
- [ ] Compare Google Docs export HTML vs WordPress display
- [ ] Verify no CSS conflicts with theme
- [ ] Test responsive image display

## Troubleshooting

### SDG Pages Not Created
Run in WordPress admin → Tools → PHP execution:
```php
gdocs_create_sdg_parent_pages();
```

### Navigation Not Showing
1. Check if page title follows pattern (e.g., `1.3.1`)
2. Verify not viewing an SDG parent page
3. Clear WordPress cache

### Menu Not Updating
1. Save any THE page to trigger menu regeneration
2. Manually run: `update_the_navigation_menu();`

### Styling Not Preserved
1. Verify Google Apps Script has latest `parseGoogleDocHtml()` version
2. Check WordPress source for `.google-doc-content` wrapper
3. Inspect browser console for CSS conflicts

## Future Enhancements

Potential improvements:
- Add AJAX loading for child page lists (performance)
- Cache child page queries in transients
- Add breadcrumb navigation
- Implement search/filter for SDG pages
- Add REST API endpoints for programmatic access
- Support custom SDG descriptions/content
- Add SDG icons/badges

## Support

For issues or questions:
1. Check error logs in WordPress Debug mode
2. Verify Google Apps Script execution logs
3. Test with default WordPress theme to isolate conflicts
4. Review pattern matching regex if titles don't follow format
