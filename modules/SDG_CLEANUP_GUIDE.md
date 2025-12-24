# SDG Pages Cleanup & Management Guide

## Problem Identified
The previous implementation created **thousands of duplicate SDG pages** because:
1. The `get_posts()` query with `'name' => $slug` parameter doesn't work correctly for post_name matching
2. The function was triggered automatically on every page save
3. No duplicate prevention mechanism was in place

## Solution Implemented

### 1. Fixed Duplicate Detection
Changed from:
```php
$existing = get_posts(['name' => $slug, ...]); // DOESN'T WORK
```

To:
```php
$existing = $wpdb->get_var($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} 
    WHERE post_type = 'the' 
    AND post_name = %s 
    AND post_status != 'trash'
    LIMIT 1",
    $slug
)); // WORKS CORRECTLY
```

### 2. Manual URL Triggers (Admin Only)
Removed automatic execution. Now use URL parameters:

**Create SDG Parent Pages:**
```
https://sustainable.utm.my/?the=createparent
```

**Regenerate Menu:**
```
https://sustainable.utm.my/?the=createmenu
```

**Cleanup Duplicates:**
```
https://sustainable.utm.my/?the=cleanup
```

### 3. Added Cleanup Function
New function `gdocs_cleanup_duplicate_sdg_pages()`:
- Finds all duplicate SDG pages (sdg-1 through sdg-17)
- Keeps the oldest (first created) page
- Ensures it has proper meta fields set
- Permanently deletes all duplicates
- Returns count of kept and deleted pages

## Usage Instructions

### Step 1: Cleanup Existing Duplicates
Visit as admin:
```
https://sustainable.utm.my/?the=cleanup
```

This will:
- ✅ Keep 17 pages (one per SDG)
- ❌ Delete all duplicates (could be hundreds/thousands)
- ✅ Set proper meta fields on kept pages

### Step 2: Create Missing Parents (if needed)
If any SDG pages are missing after cleanup:
```
https://sustainable.utm.my/?the=createparent
```

### Step 3: Regenerate Menu
After cleanup and creation:
```
https://sustainable.utm.my/?the=createmenu
```

## Verification

### Check Total THE Posts
```powershell
curl -s "https://sustainable.utm.my/wp-json/wp/v2/the?per_page=1" -I | Select-String "X-WP-Total:"
```

### Check SDG Pages Only
```powershell
curl -s "https://sustainable.utm.my/wp-json/wp/v2/the?per_page=100&search=SDG" | ConvertFrom-Json | Select-Object id, title | Format-Table -AutoSize
```

Should show only 17 entries (SDG 1 through SDG 17), one of each.

### Verify Specific SDG Page
```powershell
curl -s "https://sustainable.utm.my/wp-json/wp/v2/the?slug=sdg-1" | ConvertFrom-Json | Select-Object id, title, link
```

Should return only ONE result.

## Security
- All triggers require `manage_options` capability (admin only)
- URL parameters are sanitized
- SQL queries use prepared statements
- No unauthorized access possible

## What Changed in Code

### Before (Broken):
```php
// Ran automatically on every save
add_action('save_post_the', 'gdocs_create_sdg_parent_pages');

// Broken duplicate check
$existing = get_posts(['name' => $slug, ...]);
if (empty($existing)) {
    // Would create duplicate every time
}
```

### After (Fixed):
```php
// Manual trigger only via URL
add_action('template_redirect', 'gdocs_handle_url_triggers');

// Correct duplicate check using direct SQL
$existing = $wpdb->get_var($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} 
    WHERE post_name = %s...",
    $slug
));

if (!$existing) {
    // Only creates if truly doesn't exist
}
```

## Expected Results After Cleanup

### Before Cleanup:
```
SDG 1: 100+ duplicate pages
SDG 2: 100+ duplicate pages
SDG 3: 100+ duplicate pages
... (thousands total)
```

### After Cleanup:
```
SDG 1: 1 page (ID: lowest)
SDG 2: 1 page (ID: lowest)
SDG 3: 1 page (ID: lowest)
...
SDG 17: 1 page (ID: lowest)
Total: 17 pages
```

## Troubleshooting

### Q: Cleanup says "0 deleted"?
A: Duplicates might already be cleaned, or post_name differs from expected. Check with REST API.

### Q: Some SDG numbers missing after cleanup?
A: Run `?the=createparent` to create missing ones.

### Q: Menu still showing old structure?
A: Run `?the=createmenu` to regenerate.

### Q: Getting "Permission denied" on URL triggers?
A: Must be logged in as administrator with `manage_options` capability.

## Maintenance

**Normal workflow:**
1. Google Apps Script syncs docs → creates/updates child pages (1.3.1, 5.6.2, etc.)
2. When new child pages added: Run `?the=createmenu` to update navigation
3. SDG parent pages rarely change (only 17 of them)

**Never run:**
- `?the=cleanup` on production unless you verified duplicates exist
- `?the=createparent` repeatedly (will skip existing, but still processes)

**Safe to run anytime:**
- `?the=createmenu` - Just regenerates menu structure

## Notes
- The cleanup function permanently deletes posts (not trash)
- Always keep the oldest post ID (most stable for external links)
- Meta fields `_is_sdg_parent` and `_sdg_number` are set automatically
- Future saves won't trigger automatic menu updates (commented out)
