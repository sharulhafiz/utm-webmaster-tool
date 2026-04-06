# SDG Initiative Branch Notes

Branch: `feature/sdg-initiative`  
Base: `refactor` (created on 2026-04-06)

## Objective
Implement SDG automation and frontend SDG presentation for `news.utm.my` with Elementor-compatible shortcode usage.

## Scope in this branch
- AI-based SDG tagging on publish (`SDG1` ... `SDG17`)
- SDG icon rendering from plugin assets
- `[sdg]` shortcode for Elementor Shortcode widget
- Remove automatic SDG injection via `the_content` hook
- SDG icon size standardized to `50x50`
- Click-through links to official UN SDG goal pages

## Notes
- This branch is intentionally isolated from broader refactor/mainline merge decisions.
- Merge strategy should be PR-based after focused validation (lint + smoke checks).
- Keep commits scoped to SDG feature files to reduce review noise.
