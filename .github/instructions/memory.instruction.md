---
applyTo: '**'
---

# User Memory

## User Preferences
- Programming languages: PHP, Python, WordPress preferred
- Code style preferences: Clean, maintainable, follows WP best practices
- Development environment: VS Code on Windows (PowerShell)
- Communication style: Concise, actionable, minimal back-and-forth

## Project Context
- Current project type: WordPress Multisite helper (UTM Webmaster Tools)
- Tech stack: WordPress Multisite, PHP
- Architecture patterns: WP plugin/module structure with hooks
- Key requirements: Redirect non-super-admin users from main-site admin to their own site dashboard; allow front-end access on main site; auto-create site if none exists.

## Coding Patterns
- Prefer WordPress core APIs (`get_blogs_of_user`, `switch_to_blog`, `wpmu_create_blog`)
- Avoid redirect loops; use guards, `wp_safe_redirect`, and admin/front-end hook correctness
- Keep logic limited to `people.utm.my` domain

## Context7 Research History
- To be updated with WP hook and multisite API confirmations (admin_init vs template_redirect, login flow)

## Conversation History
- Initial assessment: No inherent redirect loop; need to fix intended behavior to redirect from main-site admin to user site dashboard, optionally create site.

## Notes
- Implement idempotent redirects with query flag if needed to avoid loop with external plugins.
