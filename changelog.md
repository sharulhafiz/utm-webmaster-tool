# Changelog - UTM Webmaster Tool

## [2026-03-06] - SEO Social Meta Tags (Open Graph + Twitter Cards)

### Problem
`modules/seo.php` indicated Open Graph support in comments, but it did not actually output social metadata tags required for rich previews (WhatsApp/Facebook/X).

### Solution
Implemented real social meta tag generation in `modules/seo.php`:

1. **Open Graph output**
    - Adds `og:site_name`, `og:locale`, `og:type`, `og:title`, `og:description`, `og:url`.
    - Adds `og:image`, `og:image:width`, `og:image:height` when available.

2. **Twitter Card output**
    - Adds `twitter:card`, `twitter:title`, `twitter:description`, `twitter:image`.

3. **Fallback strategy**
    - Uses post title/permalink/excerpt/content for singular pages.
    - Falls back to site title/description/home URL/site icon for non-singular contexts.

4. **Duplicate-protection guard**
    - Skips custom OG output when major SEO plugins are active (`WPSEO`, `Rank Math`, `AIOSEO`) to avoid duplicate tags.

### Files Modified
- `/NFS-WWW4/wp-common-assets/plugins/utm-webmaster-tool/modules/seo.php`
  - Added: `utm_output_social_meta_tags()`
  - Updated: `utm_seo()` to output social metadata before analytics hooks
- `/NFS-WWW4/wp-common-assets/plugins/utm-webmaster-tool/index.php`
  - Version bump: `5.41` → `5.42`

### Deployment Notes
- Validate syntax using container PHP (not host shell).
- Refresh opcache/cache for affected site(s) after plugin update.

### Validation Targets
- `news.utm.my` homepage and content pages should expose OG/Twitter tags in `<head>`.

## [2026-02-19] - People.utm.my User Redirect Fix

### Problem
When users logged in from the WordPress network main site (people.utm.my), they received an error:
> "You attempted to access the "People@UTM" dashboard, but you do not currently have privileges on this site."

### Solution
Implemented a two-part solution to handle user access and redirection:

1. **Login Hook (`utm_auto_create_site_on_login`)**
   - Automatically assigns 'subscriber' role to users on the main site
   - Prevents "no privileges" error when accessing admin
   - Checks if user has their own site (where they are admin)

2. **Admin Access Hook (`utm_redirect_main_admin_to_own_site`)**
   - Intercepts when non-super-admin users try to access main site wp-admin
   - Finds the user's own site (where they have admin privileges)
   - Redirects them to their own site's dashboard
   - If user has no site, automatically creates one
   - Shows notification message if site creation is in progress

3. **User Notification (`utm_people_display_redirect_message`)**
   - Displays a fixed notification when site is being created
   - Prompts user to refresh after site creation completes

### User Flow
```
User Login → Assign Subscriber Role (main site) 
    ↓
User Accesses Admin Dashboard
    ↓
Check for User's Own Site
    ↓
If Found: Redirect to Own Site Dashboard
If Not Found: Create New Site → Redirect to New Site Dashboard
If Error: Show Message on Homepage
```

### Files Modified
- `/NFS-WWW4/wp-common-assets/plugins/utm-webmaster-tool/modules/people.utm.my.php`
  - Modified: `utm_auto_create_site_on_login()`
  - Modified: `utm_redirect_main_admin_to_own_site()`
  - Added: `utm_people_display_redirect_message()`

### Testing
To test this fix:
1. Log in as a non-super-admin user on people.utm.my
2. Try to access the admin dashboard (wp-admin)
3. You should be redirected to your own site's dashboard
4. If you don't have a site, one will be created automatically

### Technical Details
- Uses `switch_to_blog()` to check user capabilities across sites
- Skips super admins from redirection
- Prevents redirect loops for AJAX, CRON, and admin-post requests
- Creates sites with slug based on user email prefix
- Handles duplicate slugs by appending date and counter
