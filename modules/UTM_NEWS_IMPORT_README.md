# UTM News Import - Flexible Multi-Site Solution

## Overview
Improved news import system that fetches posts from news.utm.my and displays them on any UTM subdomain site using a flexible shortcode.

## Key Improvements

### 1. Rate Limiting with Transient Cache
- Prevents excessive API calls to news.utm.my
- Default cache duration: 1 hour (configurable)
- Each department ID has its own cache key
- Automatic refresh after cache expires

### 2. Flexible Parameters
The shortcode now accepts multiple parameters instead of being hardcoded

### 3. Multi-Site Reusability
- No hardcoded values
- Can be used across different UTM sites
- Each site can configure its own department ID and category name

### 4. Better Error Handling
- Logs errors to WordPress error log
- Handles HTTP errors gracefully
- Validates API response before processing

### 5. Enhanced Metadata
Each imported post stores department ID, original URL, and import timestamp

### 6. Improved Display Options
- Toggle date/category display
- Show post excerpts
- Configurable link target
- Responsive CSS styling

### 7. Performance Optimizations
- Added timeout to API requests (15 seconds)
- Limited meta queries
- Efficient transient caching

## Usage Examples

### Import by Department ID
Basic: [utm_news_department department_id="3058"]
Custom display: [utm_news_department department_id="3058" category_name="Faculty News" posts_per_page="5"]
Silent import: [utm_news_department department_id="3058" display="import"]

### Import by Source Category
Alumni news: [utm_news_department source_category="alumni-networking" category_name="Alumni News"]
Silent import: [utm_news_department source_category="alumni-networking" display="import"]
With display options: [utm_news_department source_category="featured" category_name="Featured News" posts_per_page="5"]

### Combined Import
Both sources: [utm_news_department department_id="3058" source_category="alumni-networking"]

### Display Options
Minimal: [utm_news_department department_id="3058" show_date="no" show_category="no"]
More posts: [utm_news_department department_id="3058" posts_per_page="10"]

## Import Methods

### Method 1: By Department ID
Imports posts from a specific department on news.utm.my
- Use parameter: department_id="3058"
- Example departments: MJIIT=3058, FKE=1234, etc.
- API: /wp-json/wp/v2/posts?department=3058

### Method 2: By Source Category
Imports posts from a specific category on news.utm.my
- Use parameter: source_category="alumni-networking"
- Common categories: alumni-networking, featured, announcements
- API: /wp-json/wp/v2/posts?categories=alumni-networking

### Method 3: Combined
Use both parameters to import posts that match EITHER condition
- Both department_id AND source_category can be used together
- Posts matching either criteria will be imported

## Display Modes

1. INLINE MODE (default): Imports and displays posts on the page
   - Use when you want to show news feed on a page
   - Example: [utm_news_department department_id="3058"]

2. IMPORT MODE: Imports posts silently without displaying anything
   - Use when you only want to sync posts to your database
   - Useful for automated imports on hidden pages
   - Posts can be displayed elsewhere using standard WordPress loops
   - Example: [utm_news_department department_id="3058" display="import"]

## Parameters

### Import Source (at least one required)
- **department_id**: Department ID from news.utm.my (optional if source_category is used)
- **source_category**: Category slug from news.utm.my (optional if department_id is used)

### Local Configuration
- **category_name**: Category name to assign imported posts locally (default: "MJIIT News")
- **posts_per_page**: Number of posts to display (default: 3)
- **cache_duration**: Cache duration in seconds (default: 3600 = 1 hour)

### Display Options
- **show_date**: Show post date - "yes" or "no" (default: "yes")
- **show_category**: Show category badge - "yes" or "no" (default: "yes")
- **target**: Link target - "_blank" or "_self" (default: "_blank")
- **display**: Display mode - "inline" (show posts) or "import" (silent import) (default: "inline")

## Comparison

Old Code:
- Hardcoded department and category
- No rate limiting
- Not reusable

New Code:
- Fully configurable via shortcode
- 1-hour cache prevents API overload
- Works on any UTM site
- Better error handling
- Richer display options

## Manual Import

Admins can force import via URL (clears cache and imports immediately):

By department:
/wp-admin/admin.php?action=utm_news_manual_import&department_id=3058&category_name=MJIIT+News

By category:
/wp-admin/admin.php?action=utm_news_manual_import&source_category=alumni-networking&category_name=Alumni+News

Both:
/wp-admin/admin.php?action=utm_news_manual_import&department_id=3058&source_category=featured


## Common Use Cases

### Use Case 1: Display Department News on Homepage
Place this shortcode on your homepage to show MJIIT news:
```
[utm_news_department department_id="3058" posts_per_page="5"]
```

### Use Case 2: Import Alumni News by Category
Import and display alumni networking news from any department:
```
[utm_news_department source_category="alumni-networking" category_name="Alumni News" posts_per_page="5"]
```

### Use Case 3: Silent Background Import
Create a hidden page (unpublished) to import posts without displaying:
```
[utm_news_department department_id="3058" display="import"]
[utm_news_department source_category="alumni-networking" display="import"]
```
This keeps your database updated. Display posts anywhere using WordPress standard post loops or widgets.

### Use Case 4: Multiple Import Sources on One Page
Import from both department and category (silent mode on hidden page):
```
[utm_news_department department_id="3058" category_name="MJIIT News" display="import"]
[utm_news_department source_category="alumni-networking" category_name="Alumni News" display="import"]
[utm_news_department source_category="featured" category_name="Featured Stories" display="import"]
```

### Use Case 5: Display Mixed Sources
Public page showing different news types:
```
<h2>MJIIT Department News</h2>
[utm_news_department department_id="3058" category_name="MJIIT News" posts_per_page="3"]

<h2>Alumni News</h2>
[utm_news_department source_category="alumni-networking" category_name="Alumni" posts_per_page="3"]
```

### Use Case 6: Sidebar Widget
Add to sidebar with minimal display:
```
[utm_news_department department_id="3058" posts_per_page="3" show_date="no" show_category="no"]
```

### Use Case 7: Automated Import via Cron
Create a draft page with multiple import sources:
```
[utm_news_department department_id="3058" display="import" cache_duration="3600"]
[utm_news_department source_category="alumni-networking" display="import" cache_duration="3600"]
```
Access this page via WP-Cron or external cron job to trigger imports.

## Tips

- Use display="import" on admin-only or hidden pages for background syncing
- Use display="inline" (default) when you want to show the news feed
- Combine import mode with WordPress custom queries for full control over display
- Set longer cache_duration for import-only shortcodes (e.g., 7200 for 2 hours)
