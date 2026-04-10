# Merge Plan: `refactor` → `main`

## Current state (observed)
- Active branch: `refactor`
- Remote: `origin` configured
- Working tree is **not clean**:
  - Modified: `index.php`, `modules/news.utm.my.php`
  - Untracked: `modules/admission.utm.my.php`, `modules/admission.utm.my/`, `modules/news-utm-my/`, `modules/news.utm.my/`

## Goal
Merge `refactor` into `main` safely, with clear rollback points and minimal production risk.

## Proposed workflow
1. **Clean and curate changes**
   - Decide what should be included in merge vs excluded.
   - Stage only intended files; remove accidental/generated files.
2. **Commit in focused chunks**
   - Create logical commits (e.g., core loader updates vs module additions).
   - Keep commit messages clear for future rollback/audit.
3. **Sync branch with remote**
   - Update `refactor` from `origin/refactor` and resolve conflicts early.
4. **Pre-merge validation**
   - Run plugin/API smoke checks and PHP lint in container.
   - Confirm no syntax/runtime regression in touched modules.
5. **Integrate into `main` (recommended via PR)**
   - Open PR: `refactor` → `main`.
   - Prefer **squash merge** if commits are noisy; normal merge if history is clean.
6. **Post-merge verification**
   - Pull latest `main`.
   - Re-run smoke checks.
   - Confirm plugin version/changelog updates if release-worthy.
7. **Rollback readiness**
   - If regression appears, revert merge commit (or rollback squash commit) quickly.

## Merge strategy recommendation
- **Default:** PR + required checks + squash merge.
- **Why:** Cleaner history, safer review, and easier rollback.

## Ready-to-execute next step
- I can execute this workflow step-by-step and stop at each checkpoint for your approval.
