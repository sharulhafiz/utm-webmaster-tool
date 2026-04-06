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

## Clean PR path (prepared)

### Dependency finding
- `master` still uses monolithic `modules/news.utm.my.php`.
- Current SDG implementation lives in modular files under `modules/news.utm.my/` and depends on modular bootstrap wiring.

### Recommended sequence
1. **PR A (foundation):** merge minimal modular loader changes for `news.utm.my` only.
	- Include only loader/bootstrap files needed to execute modular news files.
	- Exclude unrelated admission/refactor artifacts.
2. **PR B (feature):** merge SDG initiative changes.
	- AI SDG tagging (`ai-services.php`)
	- Admin toggle (`admin-settings.php`)
	- Frontend shortcode and rendering (`frontend-content.php`, `[sdg]`)
	- Icon assets (`assets/sdg-icons/*`)
3. Run container PHP lint + smoke checks before each PR merge.

### Alternative (faster, less clean)
- Open a single PR from `feature/sdg-initiative` and accept a larger diff.
- Not preferred for review quality/rollback granularity.
