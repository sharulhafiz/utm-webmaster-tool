# WordPress Analytics JSON API - Simplified

## Overview
This module provides a public WordPress REST API endpoint that returns post analytics data in JSON format from the past year. The API automatically counts and attaches media that appears in post content, providing the most accurate representation of what's actually displayed.

## API Endpoint

### Analytics Data Endpoint (JSON)
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
      "number_of_attachments": 3,
      "post_url": "https://yoursite.com/sample-post-title/"
    },
    {
      "post_id": 124,
      "post_title": "Another Post",
      "post_publish_date": "2025-07-20T14:45:00+00:00",
      "number_of_attachments": 0,
      "post_url": "https://yoursite.com/another-post/"
    }
  ]
}
```

## Key Features

### Smart Media Detection & Auto-Attachment
The API automatically:
1. **Scans post content** for all media references
2. **Counts only media that appears** in the actual post content
3. **Auto-attaches unattached media** to the correct posts
4. **Ensures accurate counts** by ignoring unused attachments

### Content-Based Counting
Unlike traditional attachment counting that looks at the database relationship, this API:
- **Counts what's actually displayed** in the frontend
- **Ignores duplicate/unused attachments** that may exist from corrections
- **Provides the most accurate media usage statistics**

### Automatic Media Management
When fetching analytics data, the API:
- **Finds unattached media** referenced in content
- **Automatically attaches** them to the correct posts
- **Fixes orphaned media** issues in the background
- **Maintains clean media organization**

## Media Detection Patterns

The system detects media through multiple patterns:

1. **Image tags with URLs**: `<img src="/wp-content/uploads/sites/28/2025/07/image.jpg">`
2. **WordPress image classes**: `<img class="wp-image-11972">` (extracts attachment ID)
3. **Gallery shortcodes**: `[gallery ids="123,456,789"]`
4. **Video/Audio shortcodes**: `[video src="..."]`, `[audio src="..."]`
5. **Gutenberg blocks**: `<!-- wp:image {"id":123} -->`
6. **File download links**: `<a href="/wp-content/uploads/.../file.pdf">`

### Multisite Support
Fully supports WordPress multisite installations:
- **Multisite URLs**: `/wp-content/uploads/sites/28/2025/07/image.jpg`
- **Single site URLs**: `/wp-content/uploads/2025/07/image.jpg`
- **Full URLs**: `https://osca.utm.my/webteam/wp-content/uploads/sites/28/2025/07/image.jpg`

## Usage Examples

### Direct Browser Access
Navigate to: `https://yoursite.com/wp-json/utm-webmaster/v1/analytics/data`

### JavaScript/Fetch
```javascript
fetch('/wp-json/utm-webmaster/v1/analytics/data')
  .then(response => response.json())
  .then(data => {
    console.log('Total posts:', data.meta.total_posts);
    
    // Process each post
    data.data.forEach(post => {
      console.log(`${post.post_title}: ${post.number_of_attachments} attachments`);
    });
  })
  .catch(error => {
    console.error('Error fetching analytics:', error);
  });
```

### jQuery
```javascript
$.getJSON('/wp-json/utm-webmaster/v1/analytics/data', function(data) {
  console.log('Analytics data:', data);
  
  // Build table with data
  data.data.forEach(function(post) {
    $('#posts-table').append(
      '<tr>' +
        '<td>' + post.post_title + '</td>' +
        '<td>' + post.post_publish_date + '</td>' +
        '<td>' + post.number_of_attachments + '</td>' +
        '<td><a href="' + post.post_url + '">View</a></td>' +
      '</tr>'
    );
  });
});
```

### cURL
```bash
curl -X GET "https://yoursite.com/wp-json/utm-webmaster/v1/analytics/data" \
     -H "Accept: application/json"
```

## Data Fields

- **post_id**: Unique WordPress post ID
- **post_title**: The post title
- **post_publish_date**: ISO 8601 formatted publish date with timezone
- **number_of_attachments**: Count of media that actually appears in post content
- **post_url**: Full permalink to the post

## Google Sheets Integration

### Google Apps Script
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

### CSV Export
```javascript
function convertToCSV(data) {
  const headers = ['Post Title', 'Publish Date', 'Attachments', 'URL'];
  const csvRows = [headers.join(',')];
  
  data.data.forEach(post => {
    const row = [
      `"${post.post_title.replace(/"/g, '""')}"`,
      post.post_publish_date,
      post.number_of_attachments,
      post.post_url
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

## Advantages

### Accuracy
- **Content-based counting**: Only counts media that actually appears
- **No duplicate counting**: Ignores unused/duplicate attachments
- **Real usage statistics**: Reflects what visitors actually see

### Maintenance
- **Auto-fixing**: Automatically attaches orphaned media
- **Clean organization**: Maintains proper media-post relationships
- **Background processing**: Fixes issues without manual intervention

### Performance  
- **Efficient scanning**: Uses optimized regex patterns
- **Multisite optimized**: Handles complex URL structures
- **Single endpoint**: One call provides data and maintenance

## Technical Details

### Supported File Types
- **Images**: JPG, JPEG, PNG, GIF, WebP, SVG, BMP, TIFF
- **Documents**: PDF, DOC, DOCX, TXT
- **Archives**: ZIP, RAR, 7Z
- **Spreadsheets**: XLSX, PPTX
- **Media**: MP4, MP3

### Error Handling
Returns proper HTTP status codes and error messages:
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

### Performance Considerations
- **Memory efficient**: Processes posts in batches
- **Database optimized**: Uses efficient WordPress queries
- **Background attachment**: Media attachment happens during data fetch
- **Caching friendly**: Response structure supports caching

## Installation

1. Upload `analytics.php` to your WordPress `modules` directory
2. Include in your main plugin file:
   ```php
   require_once 'path/to/modules/analytics.php';
   ```
3. Access the endpoint: `/wp-json/utm-webmaster/v1/analytics/data`

No configuration required - works immediately with public access.
