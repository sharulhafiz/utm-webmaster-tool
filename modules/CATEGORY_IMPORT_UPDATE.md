# Category Import Feature - Update Log

## Overview
Extended the UTM News import functionality to support importing posts by category in addition to department ID.

## What Changed

### 1. Flexible Import Sources
**Before:** Only supported importing by department_id
**After:** Supports THREE import methods:
- By department_id (e.g., MJIIT = 3058)
- By source_category (e.g., "alumni-networking")
- By both (imports posts matching either criteria)

### 2. New Parameter: source_category
Added new shortcode parameter to specify source category from news.utm.my
- Uses WordPress REST API category filter
- Fetches from: /wp-json/wp/v2/posts?categories=CATEGORY_SLUG
- Stores metadata: utm_news_source_category

### 3. Updated Function Signature
```php
// Old
function import_utm_news_posts_flexible($department_id, $category_name, $cache_duration)

// New  
function import_utm_news_posts_flexible($department_id = '', $category_name, $cache_duration, $source_category = '')
```

### 4. Smart Cache Keys
Cache keys now adapt to import type:
- Department: utm_news_dept_3058
- Category: utm_news_cat_alumni-networking
- Prevents cache collision between different import types

### 5. Enhanced Metadata Storage
Each imported post now tracks:
- utm_news_department_id (if imported by department)
- utm_news_source_category (if imported by category)
- Allows filtering posts by their import source

### 6. Improved Post Retrieval
Meta queries now support OR logic:
- Finds posts imported by department_id OR source_category
- Displays all relevant posts regardless of import method

## Usage Examples

### Import Alumni News from All Departments
```
[utm_news_department source_category="alumni-networking" category_name="Alumni News"]
```

### Import Department-Specific News
```
[utm_news_department department_id="3058" category_name="MJIIT News"]
```

### Import Both Sources
```
[utm_news_department department_id="3058" source_category="featured" category_name="MJIIT Featured"]
```

### Silent Import for Background Sync
```
[utm_news_department source_category="alumni-networking" display="import"]
```

## API Endpoints Used

### Department Import
```
https://news.utm.my/wp-json/wp/v2/posts?department=3058&per_page=25
```

### Category Import
```
https://news.utm.my/wp-json/wp/v2/posts?categories=alumni-networking&per_page=25
```

### Combined Import
```
https://news.utm.my/wp-json/wp/v2/posts?department=3058&categories=alumni-networking&per_page=25
```

## Benefits

1. **Cross-Department Content**: Import alumni news from all departments
2. **Flexible Organization**: Mix department and category-based imports
3. **Better Taxonomy**: Organize by topic (alumni, featured) not just department
4. **Reduced Duplication**: Same post can match both department and category
5. **Scalable**: Easy to add more import sources without code changes

## Real-World Scenarios

### Scenario 1: Alumni Portal
Import all alumni-related news regardless of department:
```
[utm_news_department source_category="alumni-networking" category_name="Alumni News" posts_per_page="10"]
```

### Scenario 2: Department Site with Alumni Section
```
<h2>MJIIT News</h2>
[utm_news_department department_id="3058" category_name="MJIIT" posts_per_page="5"]

<h2>Alumni News</h2>
[utm_news_department source_category="alumni-networking" category_name="Alumni" posts_per_page="5"]
```

### Scenario 3: Background Import Hub
Hidden page that imports multiple sources:
```
[utm_news_department department_id="3058" display="import"]
[utm_news_department source_category="alumni-networking" display="import"]  
[utm_news_department source_category="featured" display="import"]
[utm_news_department source_category="announcements" display="import"]
```

## Backward Compatibility

✅ Fully backward compatible!
- Old shortcodes with just department_id still work
- Default values ensure existing implementations continue working
- No breaking changes to existing functionality

## Files Modified

1. **mjiit.utm.my.php**
   - Updated import_utm_news_posts_flexible() function
   - Added source_category parameter to shortcode
   - Enhanced metadata storage
   - Improved meta_query for post retrieval
   - Updated manual import admin action

2. **UTM_NEWS_IMPORT_README.md**
   - Added import method documentation
   - Updated usage examples
   - Added new use cases
   - Updated parameters section
   - Enhanced manual import documentation

3. **CATEGORY_IMPORT_UPDATE.md** (This file)
   - Complete changelog and documentation

## Testing Checklist

- [ ] Import by department_id only
- [ ] Import by source_category only
- [ ] Import by both department_id and source_category
- [ ] Verify cache keys are unique for each type
- [ ] Check metadata is stored correctly
- [ ] Test display=import (silent mode)
- [ ] Test display=inline (display mode)
- [ ] Verify posts display correctly
- [ ] Test manual import URL with department
- [ ] Test manual import URL with category
- [ ] Verify backward compatibility with old shortcodes

## Version

- **Previous**: v2.0 (Department import only)
- **Current**: v2.1 (Department + Category import)
- **Date**: October 6, 2025
