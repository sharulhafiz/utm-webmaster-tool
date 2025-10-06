---
applyTo: '**'
---

# User Memory

## User Preferences
- Programming languages: PHP, WordPress
- Code style preferences: Clean, well-documented PHP code
- Development environment: WordPress/PHP development
- Communication style: Direct, action-oriented

## Project Context
- Current project type: WordPress webmaster tools module
- Tech stack: WordPress, PHP, REST API
- Architecture patterns: WordPress plugin/module architecture
- Key requirements: CSV export, Google Sheets compatibility, REST API endpoint

## Coding Patterns
- WordPress REST API implementation
- CSV generation for data export
- Google Sheets compatibility requirements
- Date-based filtering (past 1 year)

## Context7 Research History
- WordPress REST API best practices
- CSV generation patterns
- Google Sheets import compatibility

## Conversation History
- Current task: Simplified WordPress REST API endpoint for content-based media analytics (completed)
- Requirements: Count only media that appears in post content, auto-attach unattached media
- Output format: JSON with single number_of_attachments field, public access
- Endpoint URL: /wp-json/utm-webmaster/v1/analytics/data
- Authentication: Public access, no authentication required
- Key feature: Content-based counting (most accurate representation of displayed media)
- Auto-maintenance: Automatically fixes unattached media during data fetch
- Removed: Summary endpoint, fix endpoint, detailed breakdown fields

## Notes
- Working with UTM Webmaster Tools project
- File location: analytics.php (currently empty)
- Need to implement complete REST API endpoint from scratch
