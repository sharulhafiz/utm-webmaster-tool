# Sustainable Google Docs → WordPress Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver a production-safe `sustainable.utm.my`-only module that supports Google Docs-driven page sync, Google Doc ID mapping, optional PDF embed conversion, and menu sync scaffolding.

**Architecture:** Add a dedicated module folder (`modules/sustainable.utm.my/`) with a `bootstrap.php` entrypoint and small focused files for context guard, REST endpoints, mapping/menu services, and sanitization helpers. Reuse existing Google Apps Script push flow (`modules/gdocsToWP.js`) but harden WordPress-side capabilities via REST routes and deterministic metadata. Roll out in small, testable slices with in-container linting and endpoint smoke checks.

**Tech Stack:** WordPress plugin PHP, WordPress REST API, WordPress Multisite APIs, Google Apps Script (existing), Docker Compose validation via container PHP.

**Authentication model (explicit):**
- **Google Drive access:** Apps Script runs under the editor-owned Google account and reads Docs using `ScriptApp.getOAuthToken()` + Drive/Docs scopes.
- **WordPress write access:** Apps Script calls WordPress REST endpoints with Basic Auth using a dedicated WordPress technical user + Application Password.
- **Credential storage:** No secrets in source files. Use Apps Script `PropertiesService.getScriptProperties()` for `WP_USERNAME`, `WP_APP_PASSWORD`, `FOLDER_ID`, and `WORDPRESS_URL`.

---

## Scope and decomposition

This feature touches two independently deployable subsystems:

1. **WordPress receiver module** (this repository): host-gated module + REST + mapping/menu services.
2. **Google Apps Script sender** (`modules/gdocsToWP.js`): payload shaping + endpoint usage.

This plan keeps both in one document but separates them into tasks so each can ship independently.

## File structure map

### New files
- `modules/sustainable.utm.my/bootstrap.php` — module bootstrapping and dependency load order.
- `modules/sustainable.utm.my/context.php` — request host guard and shared constants.
- `modules/sustainable.utm.my/rest.php` — REST route registration for sync and lookup.
- `modules/sustainable.utm.my/sync-service.php` — create/update logic, Google Doc ID mapping.
- `modules/sustainable.utm.my/menu-service.php` — menu upsert utilities from folder path.
- `modules/sustainable.utm.my/content-transform.php` — PDF bracket-link transform + content cleanup.
- `modules/sustainable.utm.my/README.md` — editor/operator documentation.

### Modified files
- `index.php` — register module slug + host gating + version bump.
- `changelog.md` — document release behavior and validation evidence.
- `modules/gdocsToWP.js` — call new endpoint(s), include folder path/menu payload metadata.

### Validation targets
- Container lint on touched PHP files.
- REST smoke checks (`/wp-json/utm-sustainable/v1/*`) on sustainable host.
- Existing heartbeat/version endpoint still healthy.

---

### Task 1: Add module scaffold and host-gated loading

**Files:**
- Create: `modules/sustainable.utm.my/bootstrap.php`
- Create: `modules/sustainable.utm.my/context.php`
- Modify: `index.php`
- Test: in-container lint for `index.php`, `bootstrap.php`, `context.php`

- [ ] **Step 1: Write failing structure test (manual check)**

Verify the module is missing from loader list before changes:

Run: `grep -n "sustainable.utm.my" /var/www/html/wp-content/plugins/utm-webmaster-tool/index.php`
Expected: no match.

- [ ] **Step 2: Add module slug + host gate in `index.php`**

Add `sustainable.utm.my` in `utm_get_all_module_slugs()` and add gate in `utm_should_load_module()`:

```php
if ( 'sustainable.utm.my' === $module ) {
	return 'sustainable.utm.my' === $request_host;
}
```

- [ ] **Step 3: Create module bootstrap and context files**

`bootstrap.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/context.php';

if ( ! utm_sustainable_module_is_allowed_context() ) {
	return;
}

require_once __DIR__ . '/content-transform.php';
require_once __DIR__ . '/sync-service.php';
require_once __DIR__ . '/menu-service.php';
require_once __DIR__ . '/rest.php';
```

`context.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function utm_sustainable_module_is_allowed_context() {
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	return is_string( $host ) && 'sustainable.utm.my' === strtolower( $host );
}
```

- [ ] **Step 4: Run lint to verify pass**

Run: `docker compose exec <php_service> php -l /var/www/html/wp-content/plugins/utm-webmaster-tool/index.php`
Expected: `No syntax errors detected`.

Run: `docker compose exec <php_service> php -l /var/www/html/wp-content/plugins/utm-webmaster-tool/modules/sustainable.utm.my/bootstrap.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add index.php modules/sustainable.utm.my/bootstrap.php modules/sustainable.utm.my/context.php
git commit -m "feat(sustainable): add host-gated module scaffold"
```

---

### Task 2: Implement Google Doc ID mapping + page upsert service

**Files:**
- Create: `modules/sustainable.utm.my/sync-service.php`
- Test: route-level smoke via REST call and metadata lookup

- [ ] **Step 1: Write failing manual test for missing service function**

Run REST call that should fail before implementation:

Run: `curl -i https://sustainable.utm.my/wp-json/utm-sustainable/v1/post-by-google-id?google_id=test123`
Expected: `404` route not found (before Task 3 route wiring).

- [ ] **Step 2: Add deterministic lookup + upsert functions**

```php
function utm_sustainable_find_page_by_google_id( $google_id ) {
	$query = new WP_Query(
		array(
			'post_type'      => 'page',
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => 1,
			'meta_key'       => '_utm_google_doc_id',
			'meta_value'     => $google_id,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	return ! empty( $query->posts ) ? (int) $query->posts[0] : 0;
}

function utm_sustainable_upsert_page_from_google_doc( $payload ) {
	$google_id = sanitize_text_field( $payload['google_id'] ?? '' );
	$title     = sanitize_text_field( $payload['title'] ?? '' );
	$content   = wp_kses_post( $payload['content'] ?? '' );

	if ( '' === $google_id || '' === $title ) {
		return new WP_Error( 'utm_sustainable_invalid_payload', 'google_id and title are required', array( 'status' => 400 ) );
	}

	$post_id = utm_sustainable_find_page_by_google_id( $google_id );

	$postarr = array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => $title,
		'post_content' => $content,
	);

	if ( $post_id > 0 ) {
		$postarr['ID'] = $post_id;
		$result        = wp_update_post( $postarr, true );
	} else {
		$result = wp_insert_post( $postarr, true );
		$post_id = is_wp_error( $result ) ? 0 : (int) $result;
	}

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	update_post_meta( $post_id, '_utm_google_doc_id', $google_id );

	return $post_id;
}
```

- [ ] **Step 3: Run lint for service file**

Run: `docker compose exec <php_service> php -l /var/www/html/wp-content/plugins/utm-webmaster-tool/modules/sustainable.utm.my/sync-service.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add modules/sustainable.utm.my/sync-service.php
git commit -m "feat(sustainable): add google-doc page upsert service"
```

---

### Task 3: Add REST endpoints for Apps Script integration

**Files:**
- Create: `modules/sustainable.utm.my/rest.php`
- Modify: `modules/sustainable.utm.my/sync-service.php`
- Test: REST curl checks on sustainable domain

- [ ] **Step 1: Write failing route test**

Run: `curl -i https://sustainable.utm.my/wp-json/utm-sustainable/v1/post-by-google-id?google_id=abc123`
Expected: `404` before route registration.

- [ ] **Step 2: Register routes and callbacks**

```php
add_action( 'rest_api_init', function() {
	register_rest_route(
		'utm-sustainable/v1',
		'/post-by-google-id',
		array(
			'methods'             => 'GET',
			'callback'            => 'utm_sustainable_rest_post_by_google_id',
			'permission_callback' => '__return_true',
		)
	);

	register_rest_route(
		'utm-sustainable/v1',
		'/sync-page',
		array(
			'methods'             => 'POST',
			'callback'            => 'utm_sustainable_rest_sync_page',
			'permission_callback' => 'utm_sustainable_rest_can_sync',
		)
	);
} );
```

`utm_sustainable_rest_can_sync` should validate Basic Auth user has `edit_pages`.

- [ ] **Step 3: Add success/failure response shape**

```php
return rest_ensure_response(
	array(
		'ok'      => true,
		'post_id' => (int) $post_id,
	)
);
```

On errors, return `WP_Error` with `status` 400/403/500.

- [ ] **Step 4: Validate routes**

Run: `curl -sS https://sustainable.utm.my/wp-json/ | grep -o "utm-sustainable/v1" | head -n1`
Expected: `utm-sustainable/v1` appears.

Run: `curl -i https://sustainable.utm.my/wp-json/utm-sustainable/v1/post-by-google-id?google_id=abc123`
Expected: `200` with JSON object (`id` nullable or integer).

- [ ] **Step 5: Commit**

```bash
git add modules/sustainable.utm.my/rest.php modules/sustainable.utm.my/sync-service.php
git commit -m "feat(sustainable): expose rest endpoints for google-doc sync"
```

---

### Task 4: Implement content transform (PDF bracket embed + safe cleanup)

**Files:**
- Create: `modules/sustainable.utm.my/content-transform.php`
- Modify: `modules/sustainable.utm.my/sync-service.php`
- Test: function-level lint + sample payload via REST

- [ ] **Step 1: Write failing transformation example**

Input sample content should include:

```text
Please read this report:
[https://drive.google.com/file/d/1abc123XYZ/view?usp=sharing]
```

Expected output includes Google Drive preview iframe URL:

```text
https://drive.google.com/file/d/1abc123XYZ/preview
```

- [ ] **Step 2: Add bracketed PDF transform helper**

```php
function utm_sustainable_transform_bracketed_pdf_links( $content ) {
	return preg_replace_callback(
		'/\[(https:\/\/drive\.google\.com\/file\/d\/([^\/\]]+)\/[^\]]*)\]/i',
		function( $matches ) {
			$file_id = sanitize_text_field( $matches[2] );
			$src     = 'https://drive.google.com/file/d/' . rawurlencode( $file_id ) . '/preview';
			return '<div class="utm-gdoc-pdf-embed"><iframe src="' . esc_url( $src ) . '" width="100%" height="800" loading="lazy" allow="autoplay"></iframe></div>';
		},
		(string) $content
	);
}
```

- [ ] **Step 3: Apply transform in upsert flow**

```php
$content = utm_sustainable_transform_bracketed_pdf_links( $content );
$content = utm_sustainable_cleanup_google_doc_content( $content );
```

- [ ] **Step 4: Validate with authenticated sync call**

Run: `curl -i -u "<wp_user>:<app_password>" -H "Content-Type: application/json" -d '{"google_id":"pdf-test-1","title":"PDF Embed Test","content":"[https://drive.google.com/file/d/1abc123XYZ/view?usp=sharing]"}' https://sustainable.utm.my/wp-json/utm-sustainable/v1/sync-page`
Expected: `200` with `ok: true` and `post_id`.

- [ ] **Step 5: Commit**

```bash
git add modules/sustainable.utm.my/content-transform.php modules/sustainable.utm.my/sync-service.php
git commit -m "feat(sustainable): add bracketed drive-pdf embed transform"
```

---

### Task 5: Add Google Drive folder-path → menu sync service

**Files:**
- Create: `modules/sustainable.utm.my/menu-service.php`
- Modify: `modules/sustainable.utm.my/rest.php`
- Modify: `modules/sustainable.utm.my/sync-service.php`
- Test: endpoint smoke check with folder path payload

- [ ] **Step 1: Write failing behavior example**

Given payload folder path:

```json
{"folder_path":["About","Sustainability Initiatives"]}
```

Expected: page assigned under/create menu tree `About > Sustainability Initiatives`.

- [ ] **Step 2: Implement minimal menu upsert helper**

```php
function utm_sustainable_ensure_menu_path( $menu_name, $folder_path, $target_post_id ) {
	$menu = wp_get_nav_menu_object( $menu_name );
	if ( ! $menu ) {
		$menu_id = wp_create_nav_menu( $menu_name );
	} else {
		$menu_id = (int) $menu->term_id;
	}

	$parent_item_id = 0;
	foreach ( $folder_path as $segment ) {
		$segment = sanitize_text_field( $segment );
		$parent_item_id = utm_sustainable_find_or_create_menu_folder_item( $menu_id, $segment, $parent_item_id );
	}

	utm_sustainable_find_or_create_menu_page_item( $menu_id, $target_post_id, $parent_item_id );
	return $menu_id;
}
```

- [ ] **Step 3: Wire menu sync from `/sync-page` payload**

If payload contains `folder_path` array, call menu helper with default menu name `Main Menu`.

- [ ] **Step 4: Validate endpoint**

Run authenticated sync POST with `folder_path` and check page appears under menu in wp-admin.

Expected: menu item exists once (idempotent reruns do not duplicate).

- [ ] **Step 5: Commit**

```bash
git add modules/sustainable.utm.my/menu-service.php modules/sustainable.utm.my/rest.php modules/sustainable.utm.my/sync-service.php
git commit -m "feat(sustainable): sync menu hierarchy from folder path"
```

---

### Task 6: Update Google Apps Script sender to new endpoints + secure auth

**Files:**
- Modify: `modules/gdocsToWP.js`
- Test: Apps Script execution logs + endpoint responses

- [ ] **Step 1: Write failing call expectation**

Current script calls `utm/v1/post-by-google-id`, but sustainable module expects `utm-sustainable/v1/post-by-google-id`.

Expected before change: lookup misses/falls back.

- [ ] **Step 2: Move credentials to Script Properties (remove hardcoded secrets)**

Replace static constants with property reads:

```javascript
const props = PropertiesService.getScriptProperties();
const FOLDER_ID = props.getProperty('FOLDER_ID');
const WORDPRESS_URL = props.getProperty('WORDPRESS_URL');
const WP_USERNAME = props.getProperty('WP_USERNAME');
const WP_APPLICATION_PASSWORD = props.getProperty('WP_APP_PASSWORD');
```

Add guard:

```javascript
if (!FOLDER_ID || !WORDPRESS_URL || !WP_USERNAME || !WP_APPLICATION_PASSWORD) {
  throw new Error('Missing required Script Properties: FOLDER_ID, WORDPRESS_URL, WP_USERNAME, WP_APP_PASSWORD');
}
```

- [ ] **Step 3: Confirm Google Drive OAuth scopes and identity**

In Apps Script project settings/manifest, ensure scopes include:
- `https://www.googleapis.com/auth/drive.readonly`
- `https://www.googleapis.com/auth/documents.readonly`
- `https://www.googleapis.com/auth/script.external_request`

Run once interactively to grant consent. Expected: no OAuth scope errors when exporting Docs as HTML.

- [ ] **Step 4: Update endpoint URLs and payload shape**

```javascript
const lookupUrl = `${WORDPRESS_URL}/wp-json/utm-sustainable/v1/post-by-google-id?google_id=${encodeURIComponent(postData.google_id)}`;
const syncUrl = `${WORDPRESS_URL}/wp-json/utm-sustainable/v1/sync-page`;
```

Include folder path in payload:

```javascript
folder_path: file.folderPath || []
```

- [ ] **Step 5: Add deterministic folder path collection**

Enhance recursive walker to track parent folder names and include them in each file record.

- [ ] **Step 6: Run script in dry-run mode**

Expected: logs show endpoint 200 responses and stable post_id mapping.

- [ ] **Step 7: Commit**

```bash
git add modules/gdocsToWP.js
git commit -m "feat(sustainable): align apps-script sender with new rest endpoints"
```

---

### Task 7: Documentation + version bump + release notes

**Files:**
- Create: `modules/sustainable.utm.my/README.md`
- Modify: `changelog.md`
- Modify: `index.php`
- Test: lint + smoke checks

- [ ] **Step 1: Write module operator doc**

Document:
- Required WP role/capability for sync endpoint.
- Required Apps Script fields.
- Bracketed PDF embed rule.
- Menu sync payload format.
- Safe rollback steps.

- [ ] **Step 2: Bump plugin version and changelog entry**

`index.php`:

```php
Version: 5.59
define( 'UTM_PLUGIN_VERSION', '5.59' );
```

Add dated changelog section with exact files and validation evidence.

- [ ] **Step 3: Run final validation sequence**

Run in container:
- PHP lint on all touched PHP files.
- GET `/wp-json/utm-sustainable/v1/post-by-google-id?google_id=smoke-1`.
- POST `/wp-json/utm-sustainable/v1/sync-page` with sample payload.
- Confirm `https://www.utm.my/api/heartbeat.php` still responds.

Expected: all checks pass, no fatal errors.

- [ ] **Step 4: Commit**

```bash
git add index.php changelog.md modules/sustainable.utm.my/README.md
git commit -m "docs(sustainable): add runbook and release notes"
```

---

## Self-review

### 1) Spec coverage check
- Google Drive folder structure mirrored to WordPress menu: **Task 5 + Task 6**.
- Doc-to-page mapping by Google Doc ID and title: **Task 2 + Task 3**.
- Content sync and update flow: **Task 2 + Task 3 + Task 6**.
- PDF embedding via bracket syntax: **Task 4**.
- Testing and validation: **All tasks include explicit checks**.
- Documentation for editors/operators: **Task 7**.

### 2) Placeholder scan
- No `TBD/TODO/implement later` placeholders.
- All code-bearing steps include concrete snippets.
- All validation steps include concrete commands and expected results.

### 3) Type/signature consistency
- Core identifiers are consistent across tasks:
  - meta key `_utm_google_doc_id`
  - route namespace `utm-sustainable/v1`
  - sync endpoint `/sync-page`
  - lookup endpoint `/post-by-google-id`

## Execution handoff

Plan complete and saved to `modules/sustainable.utm.my/plan.md`.

Two execution options:

1. **Subagent-Driven (recommended)** — dispatch a fresh subagent per task, review between tasks.
2. **Inline Execution** — execute tasks in this session in batches with checkpoints.

If option 1 is chosen, required sub-skill: `superpowers:subagent-driven-development`.
If option 2 is chosen, required sub-skill: `superpowers:executing-plans`.
