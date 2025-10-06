# WordPress Analytics CSV Export API

## Overview
This module provides a custom WordPress REST API endpoint that generates a CSV file containing post analytics data from the past year. The CSV is compatible with Google Sheets import and includes post title, publish date, and number of attachments for each post.

## API Endpoints

### 1. CSV Export Endpoint
**URL:** `/wp-json/utm-webmaster/v1/analytics/csv`
**Method:** GET
**Authentication:** Multiple methods supported (see Authentication section below)

**Response:** CSV file download with the following columns:
- Post Title
- Post Publish Date (YYYY-MM-DD HH:MM:SS format)
- Number of Attachments

### 2. Analytics Summary Endpoint (Optional)
**URL:** `/wp-json/utm-webmaster/v1/analytics/summary`
**Method:** GET
**Authentication:** Same as CSV export endpoint

**Response:** JSON object with summary statistics:
```json
{
  "total_posts": 150,
  "posts_past_year": 45,
  "date_range": {
    "from": "2024-08-27",
    "to": "2025-08-27"
  }
}
```

## Authentication Methods

The API supports multiple authentication methods to provide flexibility for different use cases:

### Method 1: WordPress Admin Authentication
- **Best for:** Manual downloads by site administrators
- **Requirements:** User must be logged into WordPress with `manage_options` capability
- **Usage:** Simply visit the URL while logged in as an administrator

### Method 2: API Key Authentication
- **Best for:** Automated scripts, external applications, scheduled exports
- **Setup:** Add to your `wp-config.php`: `define('UTM_ANALYTICS_API_KEY', 'your-secret-key-here');`
- **Usage:** Add `?api_key=your-secret-key-here` to the URL
- **Example:** `https://yoursite.com/wp-json/utm-webmaster/v1/analytics/csv?api_key=your-secret-key-here`

### Method 3: IP Whitelist
- **Best for:** Trusted internal servers, localhost development
- **Allowed IPs by default:**
  - `127.0.0.1` (localhost IPv4)
  - `::1` (localhost IPv6)
  - `10.0.0.0/8` (Private network Class A)
  - `172.16.0.0/12` (Private network Class B)
  - `192.168.0.0/16` (Private network Class C)
- **Usage:** Access from allowed IP addresses without additional authentication

### Method 4: Development Mode
- **Best for:** Initial testing and development
- **Automatic:** If no API key is defined and user is not logged in, allows access for testing
- **Security:** Logs all unauthenticated attempts for monitoring

## Usage Instructions

### 1. Installation
1. Upload the `analytics.php` file to your WordPress `modules` directory
2. Include the module in your main plugin file or theme functions.php:
   ```php
   require_once 'path/to/modules/analytics.php';
   ```

### 2. Accessing the CSV Export

**Option A: Direct Browser Access (Admin Login)**
1. Log in to your WordPress admin area
2. Navigate to: `https://yoursite.com/wp-json/utm-webmaster/v1/analytics/csv`
3. The CSV file will automatically download

**Option B: Direct Browser Access (API Key)**
1. Add API key to wp-config.php: `define('UTM_ANALYTICS_API_KEY', 'your-secret-key');`
2. Navigate to: `https://yoursite.com/wp-json/utm-webmaster/v1/analytics/csv?api_key=your-secret-key`
3. The CSV file will automatically download

**Option C: From Localhost/Private Network**
1. Access from allowed IP (localhost, private network ranges)
2. Navigate to: `https://yoursite.com/wp-json/utm-webmaster/v1/analytics/csv`
3. No additional authentication required

**Option D: Using JavaScript/AJAX**
```javascript
// For authenticated admin users
fetch('/wp-json/utm-webmaster/v1/analytics/csv', {
    method: 'GET',
    headers: {
        'X-WP-Nonce': wpApiSettings.nonce
    }
})
.then(response => response.blob())
.then(blob => {
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'wordpress-analytics.csv';
    a.click();
});

// For API key authentication
fetch('/wp-json/utm-webmaster/v1/analytics/csv?api_key=your-secret-key', {
    method: 'GET'
})
.then(response => response.blob())
.then(blob => {
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'wordpress-analytics.csv';
    a.click();
});
```

**Option E: Using cURL**
```bash
# With API key
curl -X GET "https://yoursite.com/wp-json/utm-webmaster/v1/analytics/csv?api_key=your-secret-key" \
     -o "analytics-export.csv"

# With WordPress authentication cookie
curl -X GET "https://yoursite.com/wp-json/utm-webmaster/v1/analytics/csv" \
     -H "Cookie: wordpress_logged_in_xxxxx=your_auth_cookie" \
     -o "analytics-export.csv"
```

### 3. Importing to Google Sheets
1. Open Google Sheets
2. Click "File" → "Import"
3. Upload the downloaded CSV file
4. Choose "Replace current sheet" or "Insert new sheet(s)"
5. Select "Comma" as separator
6. Click "Import data"

The CSV includes UTF-8 BOM for proper character encoding in Google Sheets.

## Features

### Data Filtering
- **Date Range:** Automatically filters posts from the past 365 days
- **Post Status:** Only includes published posts
- **Post Type:** Only includes standard posts (not pages or custom post types)

### Attachment Counting
- Counts all media attachments (images, documents, etc.) associated with each post
- Includes both inserted and uploaded-but-not-inserted attachments

### Google Sheets Compatibility
- UTF-8 BOM encoding for proper character display
- Proper CSV escaping for fields containing commas, quotes, or newlines
- Standardized date format (YYYY-MM-DD HH:MM:SS)

### Security
- Requires administrator privileges (`manage_options` capability)
- Prevents direct file access when WordPress is not loaded
- Proper error handling and validation

## Customization Options

### Modify Date Range
To change the date range, edit the `analytics_csv_export_handler` function:
```php
// For past 6 months instead of 1 year
$one_year_ago = date('Y-m-d', strtotime('-6 months'));
```

### Include Additional Post Types
To include pages or custom post types:
```php
$posts = get_posts(array(
    'post_type' => array('post', 'page', 'your_custom_type'),
    // ... other parameters
));
```

### Add More Columns
To add additional data columns, modify the CSV header and data rows:
```php
// Add to header
$csv_data[] = array(
    'Post Title',
    'Post Publish Date',
    'Number of Attachments',
    'Post Author',
    'Post Status'
);

// Add to data rows
$csv_data[] = array(
    $post->post_title,
    $publish_date,
    $attachment_count,
    get_the_author_meta('display_name', $post->post_author),
    $post->post_status
);
```

## Troubleshooting

### Common Issues

**1. Permission Denied (401 Error)**
- Ensure you're logged in as an Administrator
- Check that your user has the `manage_options` capability

**2. Empty CSV File**
- Verify you have published posts in the past year
- Check that WordPress `get_posts()` is working correctly

**3. Character Encoding Issues in Google Sheets**
- The CSV includes UTF-8 BOM, but if issues persist, try saving the file as UTF-8 in a text editor first

**4. Large Number of Posts (Performance)**
- For sites with thousands of posts, consider adding pagination or caching
- Monitor server memory usage during export

### Error Messages

**"csv_generation_failed"**
- Check PHP error logs for detailed error information
- Ensure adequate server memory and execution time limits

## Performance Considerations

- **Memory Usage:** Each post and its attachments are loaded into memory
- **Execution Time:** Large sites may need increased PHP execution time
- **Database Load:** Query optimization is included, but very large datasets may require additional optimization

For sites with >1000 posts, consider implementing pagination or background processing.

## File Structure
```
modules/
├── analytics.php          # Main implementation
├── ANALYTICS_README.md    # This documentation
└── .github/
    └── instructions/
        └── memory.instruction.md  # Development memory/context
```
