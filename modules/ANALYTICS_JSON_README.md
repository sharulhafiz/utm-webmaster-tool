# WordPress Analytics JSON API

## Overview
This module provides a public WordPress REST API endpoint that returns post analytics data in JSON format from the past year. The endpoint is publicly accessible and includes post title, publish date, and number of attachments for each post.

## API Endpoints

### 1. Analytics Data Endpoint (JSON)
**URL:** `/wp-json/utm-webmaster/v1/analytics/data`
**Method:** GET
**Authentication:** None required (Public access)

**Response:** JSON object with posts data:
```json
{
  "status": "success",
  "meta": {
    "total_posts": 45,
    "date_range": {
      "from": "2024-08-28",
      "to": "2025-08-28"
    },
    "generated_at": "2025-08-28T10:30:00+00:00",
    "api_version": "1.0"
  },
  "data": [
    {
      "post_id": 123,
      "post_title": "Sample Post Title",
      "post_publish_date": "2025-08-15T10:30:00+00:00",
      "number_of_attachments": 5,
      "attached_media": 2,
      "unattached_media": 3,
      "post_url": "https://yoursite.com/sample-post-title/"
    },
    {
      "post_id": 124,
      "post_title": "Another Post",
      "post_publish_date": "2025-07-20T14:45:00+00:00",
      "number_of_attachments": 0,
      "attached_media": 0,
      "unattached_media": 0,
      "post_url": "https://yoursite.com/another-post/"
    }
  ]
}
```

### 3. Fix Unattached Media Endpoint (Admin Only)
**URL:** `/wp-json/utm-webmaster/v1/analytics/fix-attachments`
**Method:** POST
**Authentication:** WordPress admin login required

**Parameters:**
- `post_id` (optional): Fix attachments for specific post only
- `dry_run` (optional): Set to `false` to actually fix attachments (default: `true`)

**Response:** JSON object with fix results:
```json
{
  "status": "success",
  "dry_run": true,
  "results": {
    "processed_posts": 45,
    "attached_media": 12,
    "errors": []
  },
  "message": "Dry run completed - no changes made",
  "meta": {
    "generated_at": "2025-08-28T10:30:00+00:00",
    "api_version": "1.0"
  }
}
```

### 2. Analytics Summary Endpoint
**URL:** `/wp-json/utm-webmaster/v1/analytics/summary`
**Method:** GET
**Authentication:** None required (Public access)

**Response:** JSON object with summary statistics:
```json
{
  "total_posts": 150,
  "posts_past_year": 45,
  "date_range": {
    "from": "2024-08-28",
    "to": "2025-08-28"
  }
}
```

## Usage Instructions

### 1. Installation
1. Upload the `analytics.php` file to your WordPress `modules` directory
2. Include the module in your main plugin file or theme functions.php:
   ```php
   require_once 'path/to/modules/analytics.php';
   ```

### 2. Accessing the JSON Data

**Direct Browser Access**
- Navigate to: `https://yoursite.com/wp-json/utm-webmaster/v1/analytics/data`
- View JSON response directly in browser

**Using JavaScript/Fetch**
```javascript
fetch('/wp-json/utm-webmaster/v1/analytics/data')
  .then(response => response.json())
  .then(data => {
    console.log('Total posts:', data.meta.total_posts);
    console.log('Posts data:', data.data);
    
    // Process each post
    data.data.forEach(post => {
      console.log(`${post.post_title}: ${post.number_of_attachments} attachments`);
    });
  })
  .catch(error => {
    console.error('Error fetching analytics:', error);
  });
```

**Using cURL**
```bash
curl -X GET "https://yoursite.com/wp-json/utm-webmaster/v1/analytics/data" \
     -H "Accept: application/json"
```

**Using jQuery**
```javascript
$.getJSON('/wp-json/utm-webmaster/v1/analytics/data', function(data) {
  console.log('Analytics data:', data);
  $('#post-count').text(data.meta.total_posts);
  
  // Build table or chart with data.data array
  data.data.forEach(function(post) {
    $('#posts-table').append(
      '<tr>' +
        '<td>' + post.post_title + '</td>' +
        '<td>' + post.post_publish_date + '</td>' +
        '<td>' + post.number_of_attachments + '</td>' +
      '</tr>'
    );
  });
});
```

### 3. Converting to CSV (if needed)
```javascript
function convertToCSV(data) {
  const headers = ['Post Title', 'Publish Date', 'Attachments'];
  const csvRows = [headers.join(',')];
  
  data.data.forEach(post => {
    const row = [
      `"${post.post_title.replace(/"/g, '""')}"`,
      post.post_publish_date,
      post.number_of_attachments
    ];
    csvRows.push(row.join(','));
  });
  
  return csvRows.join('\n');
}

// Usage
fetch('/wp-json/utm-webmaster/v1/analytics/data')
  .then(response => response.json())
  .then(data => {
    const csv = convertToCSV(data);
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'analytics.csv';
    a.click();
  });
```

## Features

### Data Filtering
- **Date Range:** Automatically filters posts from the past 365 days
- **Post Status:** Only includes published posts
- **Post Type:** Only includes standard posts (not pages or custom post types)

### Data Fields
- **post_id:** Unique WordPress post ID
- **post_title:** The post title
- **post_publish_date:** ISO 8601 formatted publish date with timezone
- **number_of_attachments:** Total count of all media (attached + unattached)
- **attached_media:** Count of properly attached media files
- **unattached_media:** Count of media files found in content but not attached to post
- **post_url:** Full permalink to the post

### Unattached Media Detection
The API automatically scans post content to find media files that aren't properly attached to posts. This is common when using visual editors, page builders, or direct HTML insertion. The system detects:

1. **Image tags** with upload URLs: `<img src="/wp-content/uploads/sites/28/2025/07/image.jpg">` 
2. **WordPress image classes**: `<img class="wp-image-11972">` (extracts attachment ID)
3. **Direct upload paths** in content: `/wp-content/uploads/sites/28/2025/08/file.jpg`
4. **Gallery shortcodes** with specific IDs: `[gallery ids="123,456"]`
5. **Video/Audio shortcodes**: `[video src="..."]`, `[audio src="..."]`
6. **Gutenberg blocks** with media IDs: `<!-- wp:image {"id":123} -->`
7. **File download links**: `<a href="/wp-content/uploads/.../file.pdf">`

### Multisite Support
The detection system automatically handles WordPress multisite installations:
- **Multisite URLs**: `/wp-content/uploads/sites/28/2025/07/image.jpg`
- **Single site URLs**: `/wp-content/uploads/2025/07/image.jpg`
- **Full URLs**: `https://osca.utm.my/webteam/wp-content/uploads/sites/28/2025/07/image.jpg`
- **Complex img tags**: Handles srcset, sizes, and multiple CSS classes

### Advanced Image Detection
The system can identify WordPress images through multiple methods:
- **src attribute**: Direct URL parsing in img tags
- **wp-image-ID class**: Extracts attachment ID from CSS classes like `wp-image-11972`
- **Complex attributes**: Handles fetchpriority, decoding, width, height, srcset, sizes
- **Mixed content**: Works with inline styles and multiple CSS classes

### Fixing Unattached Media
Use the fix endpoint to properly attach unattached media to their respective posts:

```javascript
// Dry run first (see what would be fixed)
fetch('/wp-json/utm-webmaster/v1/analytics/fix-attachments', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-WP-Nonce': wpApiSettings.nonce
  },
  body: JSON.stringify({
    dry_run: true
  })
})
.then(response => response.json())
.then(data => {
  console.log('Would attach', data.results.attached_media, 'media files');
  
  // Actually fix if results look good
  if (confirm('Fix ' + data.results.attached_media + ' attachments?')) {
    return fetch('/wp-json/utm-webmaster/v1/analytics/fix-attachments', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': wpApiSettings.nonce
      },
      body: JSON.stringify({
        dry_run: false
      })
    });
  }
})
.then(response => response ? response.json() : null)
.then(data => {
  if (data) {
    console.log('Fixed', data.results.attached_media, 'attachments');
  }
});
```

### Response Format
- **JSON Structure:** Well-structured with meta information and data array
- **ISO 8601 Dates:** Standard international date format with timezone
- **Error Handling:** Proper error responses with status codes
- **Metadata:** Includes generation timestamp and API version

### Public Access
- **No Authentication:** Publicly accessible endpoint
- **CORS Friendly:** Works with cross-origin requests
- **RESTful:** Follows REST API conventions

## Customization Options

### Modify Date Range
```php
// For past 6 months instead of 1 year
$one_year_ago = date('Y-m-d', strtotime('-6 months'));
```

### Include Additional Post Types
```php
$posts = get_posts(array(
    'post_type' => array('post', 'page', 'your_custom_type'),
    // ... other parameters
));
```

### Add More Data Fields
```php
$posts_data[] = array(
    'post_id' => $post->ID,
    'post_title' => $post->post_title,
    'post_publish_date' => $publish_date,
    'number_of_attachments' => $attachment_count,
    'post_url' => get_permalink($post->ID),
    'post_author' => get_the_author_meta('display_name', $post->post_author),
    'post_excerpt' => get_the_excerpt($post->ID),
    'post_status' => $post->post_status
);
```

## Error Responses

### Server Error (500)
```json
{
  "status": "error",
  "message": "Failed to generate analytics data: [error details]",
  "meta": {
    "generated_at": "2025-08-28T10:30:00+00:00",
    "api_version": "1.0"
  }
}
```

## Integration Examples

### Google Sheets (using Google Apps Script)
```javascript
function importAnalyticsData() {
  const url = 'https://yoursite.com/wp-json/utm-webmaster/v1/analytics/data';
  const response = UrlFetchApp.fetch(url);
  const data = JSON.parse(response.getContentText());
  
  const sheet = SpreadsheetApp.getActiveSheet();
  sheet.clear();
  
  // Add headers
  sheet.getRange(1, 1, 1, 4).setValues([
    ['Post Title', 'Publish Date', 'Attachments', 'URL']
  ]);
  
  // Add data
  const rows = data.data.map(post => [
    post.post_title,
    post.post_publish_date,
    post.number_of_attachments,
    post.post_url
  ]);
  
  if (rows.length > 0) {
    sheet.getRange(2, 1, rows.length, 4).setValues(rows);
  }
}
```

### Dashboard Widget
```javascript
// Create a simple dashboard showing post statistics
fetch('/wp-json/utm-webmaster/v1/analytics/data')
  .then(response => response.json())
  .then(data => {
    document.getElementById('total-posts').textContent = data.meta.total_posts;
    
    const totalAttachments = data.data.reduce((sum, post) => 
      sum + post.number_of_attachments, 0
    );
    document.getElementById('total-attachments').textContent = totalAttachments;
    
    const avgAttachments = (totalAttachments / data.meta.total_posts).toFixed(1);
    document.getElementById('avg-attachments').textContent = avgAttachments;
  });
```

## Performance Considerations

- **Caching:** Consider implementing caching for high-traffic sites
- **Pagination:** For sites with >1000 posts, consider adding pagination parameters
- **Rate Limiting:** May want to implement rate limiting for public endpoints

## File Structure
```
modules/
├── analytics.php              # Main implementation
└── ANALYTICS_JSON_README.md   # This documentation
```
