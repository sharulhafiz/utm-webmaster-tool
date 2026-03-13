# Changelog - UTM Webmaster Tool

## [2026-03-13] - events priority-2 stability hardening

### Problem
- `events.utm.my` logs showed recurring warnings in `modules/multisite-api.php` (`Undefined variable $site_id`).
- Formidable admin flows produced repeated warning/slow-path pressure during `wp-admin` usage.

### Solution
- Hardened `modules/multisite-api.php`:
  - Added ABSPATH guard.
  - Fixed `site_id` initialization path in user-loop logic.
  - Added defensive request parsing and per-page cap.
  - Reduced exposure risk by disabling sensitive custom user/admin-email route registration and retaining only the sites route.
- Added events-admin mitigation in `modules/events.php` to reduce Formidable addon update-check chatter on `events.utm.my` admin requests.
- Version bump: `5.49` → `5.50`.

### Files Modified
- `/NFS-WWW4/wp-common-assets/plugins/utm-webmaster-tool/modules/multisite-api.php`
- `/NFS-WWW4/wp-common-assets/plugins/utm-webmaster-tool/modules/events.php`
- `/NFS-WWW4/wp-common-assets/plugins/utm-webmaster-tool/index.php`

## [2026-03-13] - heartbeat safety + analytics cache stability

### Problem
- `modules/heartbeat.php` previously used a helper that **deactivated** `utm-wp-plugin` while checking status, causing unwanted side effects.
- `modules/analytics.php` still contained a top-level OPcache reset call in local working changes and needed to remain side-effect free on normal requests.

### Solution
- Refactored heartbeat status detection to be read-only:
  - Added guarded plugin API include when needed.
  - Returns activation state without mutating plugin state.
  - Added `generated_at` timestamp to endpoint payload.
- Kept analytics module bootstrap side-effect free by removing per-request OPcache reset behavior.
- Version bump: `5.48` → `5.49`.

### Files Modified
- `/NFS-WWW4/wp-common-assets/plugins/utm-webmaster-tool/modules/heartbeat.php`
- `/NFS-WWW4/wp-common-assets/plugins/utm-webmaster-tool/modules/analytics.php`
- `/NFS-WWW4/wp-common-assets/plugins/utm-webmaster-tool/index.php`

## [2026-03-13] - people.utm.my login pressure mitigation

### Problem
- `people.utm.my` showed heavy `wp-login.php` request bursts, repeated Divi warnings/fatal traces around missing `BLOGUPLOADDIR`, and recurring Formidable collation-mismatch churn during init.

### Solution
- Added a compatibility guard in `modules/people.utm.my.php` to define `BLOGUPLOADDIR` when missing.
- Added a targeted mitigation in `modules/people.utm.my.php` to disable `FrmProCopiesController::copy_forms` on `people` requests, reducing repeated collation-heavy init queries.
- Hardened login shielding for `people` nginx:
  - Added `2345Explorer` signatures to heavy crawler map.
  - Tightened `wp-login.php` throttling burst and added hotspot limiter.
  - Return `429` for heavy crawler signatures on login endpoint.

### Files Modified
- `/NFS-WWW4/wp-common-assets/plugins/utm-webmaster-tool/modules/people.utm.my.php`
  - Added `BLOGUPLOADDIR` compatibility definition.
  - Added Formidable copy-forms mitigation hook removal.
- `/NFS-WWW4/sites/people/nginx/10-temp-botshield-http.conf`
  - Added abusive `2345Explorer` signatures to bot map.
- `/NFS-WWW4/nginx/conf/snippets/people-wordpress-php-handling.conf`
  - Tightened login rate-limiting and heavy-crawler blocking on `/wp-login.php`.
- `/NFS-WWW4/wp-common-assets/plugins/utm-webmaster-tool/index.php`
  - Version bump: `5.47` → `5.48`.

### Deployment Notes
- Test nginx config before reload/restart.
- Restart `people` nginx and affected PHP pools after applying changes.
- Re-sample `wp-login.php` traffic and response timings to confirm pressure reduction.

## [2026-03-13] - `utm_load_modules()` bootstrap optimization

### Problem
- The plugin loaded all active modules on every request via `utm_load_modules()`.
- This increased request bootstrap cost for frontend traffic, because many modules are admin-only, REST-only, or site-specific.

### Solution
- Added conservative request-aware module loading in `index.php`:
    - **Admin/network/admin-post only** modules are skipped on normal frontend requests.
    - **REST-only** modules (`analytics`, `multisite-api`, `heartbeat`) are loaded only for REST requests.
    - **Backup module** is limited to admin/admin-post/AJAX/cron contexts.
    - **Site-specific modules** (`people.utm.my`, `news.utm.my`, `support.utm.my`, `admission.utm.my-programmes-filter`, `registrar`) now include a host-level gate before load.
- Kept mixed-risk modules unchanged in this phase to minimize behavior risk.

### Files Modified
- `/NFS-WWW4/wp-common-assets/plugins/utm-webmaster-tool/index.php`
    - Added request context helpers and `utm_should_load_module()`.
    - Updated loader to skip modules that are irrelevant for the current request.
    - Version bump: `5.46` → `5.47`.

### Deployment Notes
- Validate syntax in container PHP before rollout.
- Restart active WordPress PHP-FPM containers so opcache picks up the updated shared plugin bootstrap.

## [2026-03-12] - Shared plugin OPcache reset removal

### Problem
- `modules/analytics.php` called `opcache_reset()` at file load time.
- Because the shared plugin loader includes active modules on normal requests, that reset could run during public front-end traffic and repeatedly discard PHP opcode cache, especially hurting `news.utm.my` cold/miss performance.

### Solution
- Removed the top-level `opcache_reset()` call from `modules/analytics.php`.
- Kept explicit OPcache reset mechanisms in dedicated maintenance/debug flows instead of normal page requests.

### Files Modified
- `/NFS-WWW4/wp-common-assets/plugins/utm-webmaster-tool/modules/analytics.php`
    - Removed per-request OPcache reset from module load.
- `/NFS-WWW4/wp-common-assets/plugins/utm-webmaster-tool/index.php`
    - Version bump: `5.45` → `5.46`

### Deployment Notes
- Validate syntax using container PHP.
- Restart affected PHP-FPM containers after deployment so they pick up the updated shared plugin code.

## [2026-03-12] - User profile photo enhancements

### Improvements
- Enhanced the new profile photo feature with a clearer placeholder UI on the user profile screen.
- Added a generated fallback avatar for users without a custom profile photo.
- Profile photo uploads are now center-cropped to a square and compressed automatically on save.
- Replacing or removing a managed profile photo now cleans up the old attachment files.

### Files Modified
- `/NFS-WWW4/wp-common-assets/plugins/utm-webmaster-tool/modules/profile-photo.php`
    - Added fallback avatar generation.
    - Added square crop/compression processing.
    - Added managed attachment cleanup on replace/remove.
    - Improved profile-page placeholder UI.
- `/NFS-WWW4/wp-common-assets/plugins/utm-webmaster-tool/index.php`
    - Version bump: `5.44` → `5.45`

### Deployment Notes
- Validate syntax inside representative PHP containers.
- Restart active WordPress PHP containers so FPM loads the enhanced shared plugin code.

## [2026-03-12] - User profile photo upload

### Problem
- The plugin did not provide a built-in way for users to upload and manage a custom profile photo from their WordPress profile page.
- `modules/usermeta.php` only exposed last-login information on the profile screen, while `modules/chatbot.php` handled an unrelated chatbot avatar setting.

### Solution
Added a dedicated profile photo module that integrates with the WordPress user profile screen:

1. **Profile page upload UI**
    - Adds a `Profile Photo` section to the user profile screen.
    - Shows the current photo preview.
    - Supports upload and removal actions.

2. **Secure upload handling**
    - Uses WordPress media APIs for image uploads.
    - Restricts uploads to common image formats (`jpg`, `png`, `gif`, `webp`).
    - Protects updates with nonce and capability checks.

3. **Avatar override**
    - Uses the stored attachment as the user's avatar through WordPress avatar filters.

### Files Modified
- `/NFS-WWW4/wp-common-assets/plugins/utm-webmaster-tool/modules/profile-photo.php`
  - New module for rendering, saving, and serving custom user profile photos.
- `/NFS-WWW4/wp-common-assets/plugins/utm-webmaster-tool/index.php`
  - Added the new module to the loader.
  - Version bump: `5.43` → `5.44`

### Deployment Notes
- Validated syntax inside `www-php-public` and `events-php-public`.
- Restarted active WordPress PHP containers after the change so FPM picks up the updated shared plugin code.

## [2026-03-12] - Heartbeat endpoint stabilization

### Problem
- `modules/heartbeat.php` exposed `/wp-json/utm/v1/version`, but the endpoint checked companion plugin status with side effects, including plugin deactivation logic during a read-only version request.
- `/sites/www/files/api/heartbeat.php` performed long sequential external requests on every page hit, causing timeouts and slow PHP-FPM requests, especially when one or more target sites were slow or rate-limited.

### Solution
Implemented a low-risk stabilization focused on heartbeat reads:

1. **Made the REST version endpoint side-effect free**
     - `modules/heartbeat.php` now reports companion plugin status without mutating plugin state.
     - Added an `ABSPATH` guard and a `generated_at` timestamp to the response.

2. **Made `api/heartbeat.php` bounded and cache-first**
     - Added a 5-minute cache stored under `api/cache/heartbeat-report.json`.
     - Uses parallel cURL requests instead of sequential blocking requests.
     - Reduced connection and total request timeouts per target site.
     - Added optional `?refresh=1` to force an immediate refresh.
     - Added optional `?format=json` output for lightweight machine-readable checks.

### Files Modified
- `/NFS-WWW4/wp-common-assets/plugins/utm-webmaster-tool/modules/heartbeat.php`
    - Removed side effects from the version endpoint.
    - Added safe plugin status detection.
- `/NFS-WWW4/sites/www/files/api/heartbeat.php`
    - Added cache-first response handling.
    - Replaced sequential cURL calls with parallel bounded cURL requests.
- `/NFS-WWW4/wp-common-assets/plugins/utm-webmaster-tool/index.php`
    - Version bump: `5.42` → `5.43`

### Deployment Notes
- Validate PHP syntax inside the correct Docker PHP containers.
- Request `https://www.utm.my/api/heartbeat.php?refresh=1` once after deployment to seed a fresh cache snapshot.
- If needed, restart only the affected PHP container/service to clear opcache.

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
