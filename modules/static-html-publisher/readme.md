# Static HTML Publisher

Upload AI-generated static-site ZIP packages and publish them as complete HTML documents on normal WordPress Pages.

## What it does

1. A Page editor opens any WordPress Page.
2. The **Static HTML Package** metabox appears in the Page editor.
3. The editor uploads a `.zip` containing a static HTML site (from ChatGPT, Gemini, Claude, etc.).
4. The module validates the ZIP, extracts it into `wp-content/static-packages/<page_id>/`, and associates it with the Page.
5. When the Page is published and has an active package, the package `index.html` completely replaces the normal WordPress page output — no theme wrapping, no `get_header()`, no `get_footer()`.
6. Static assets (CSS, JS, images, fonts) resolve under the same Page URL.

Individual items use the Page's own URL hierarchy — no hardcoded paths.

## Expected ZIP structure

```
my-article.zip
├── index.html          ← required
├── style.css
├── script.js
└── images/
    └── hero.jpg
```

Single top-level directory is also accepted:

```
my-article.zip
└── my-article/
    ├── index.html
    ├── style.css
    └── images/
```

## How to generate a compatible ZIP from an AI tool

1. Ask your AI tool (ChatGPT, Gemini, Claude) to generate a self-contained static HTML page.
2. Copy all files into a single folder.
3. Right-click the folder → Compress / Send to → Zip.

Keep all assets (CSS, JS, images) in the same folder or a standard sub-directory.

## Upload / Publish workflow

1. Create or edit a WordPress Page.
2. Set the title, slug, and parent hierarchy as normal.
3. In the **Static HTML Package** metabox, choose the ZIP file.
4. Click **Upload & Publish**.
5. Publish the Page normally.
6. The item appears at the Page's URL with the static content.

## Update / Unpublish

- **Update:** Re-upload a ZIP on the same Page — old content is replaced atomically.
- **Unpublish:** Click **Unpublish Package** in the metabox — the Page reverts to normal WordPress content.
- **Delete Page:** The package files remain on disk (cleanup is a future enhancement).

## Security model

Uploaded ZIP content is treated as untrusted for server-side threats:

| Threat | Protection |
|---|---|
| Path traversal (`../`) | Regex check + `realpath()` containment at extraction |
| Symlinks | ZIP mode bits check + post-extraction filesystem scan |
| Server-side files | Blocklist of 26+ extensions (.php, .sh, .py, .cgi, etc.) |
| Archive bombs | Configurable max ZIP size (50 MB), file count (500), uncompressed size (200 MB) |
| Missing index.html | ZIP rejected before extraction |
| Partial extraction | Staging-first: files only become live after full validation |
| Unauthorised upload | `edit_pages` capability + nonce check on AJAX |
| Unpublished access | Only `publish`-status Pages serve static content |

Uploaded JavaScript is treated as trusted publisher content for the MVP.

## Storage layout

```
wp-content/static-packages/
├── 42/                   ← active package for Page ID 42
│   ├── index.html
│   ├── style.css
│   └── images/
└── 42__staging_abc123/   ← temporary staging (cleaned up automatically)
```

Page ID is the stable identity — changing the Page's title, slug, or parent does not affect stored files.

## URL model

| URL | Served by | Content |
|---|---|---|
| `/magazine/edition-2026/` | WordPress template_redirect | Static package index.html (complete document) |
| `/magazine/edition-2026/style.css` | PHP asset fallback (or nginx) | CSS file from package |
| `/campaign/open-day-2026/images/logo.png` | PHP asset fallback (or nginx) | Image from package |

WordPress permalinks remain the source of truth for all URLs.

## Phase 2 — nginx optimization (not yet implemented)

Add a location block to the site's nginx config to serve static assets directly:

```nginx
location ~ ^/magazine/([^/]+)/(.+\.(?:css|js|png|jpe?g|gif|svg|webp|woff2?|ttf|eot|otf|ico|json|txt|map|pdf|avif|mp4|webm))$ {
    alias /var/www/html/wp-content/static-packages/$1/$2;
    try_files $uri =404;
}
```

The PHP fallback in `template.php` handles asset serving until this is deployed.

## Deployment

### Phase 1 — Module merge (current)

1. Merge module to `utm-webmaster-tool` main branch.
2. Sync to swarm: `rsync -av --delete /opt/apps/utm-webmaster-tool/ /data/plugins/utm-webmaster-tool/`
3. Create the `wp-content/static-packages/` directory on the site's NFS share (writable by wwwdata).
4. Visit Settings → Permalinks → Save to flush rewrite rules.
5. Test: create a Page, upload a ZIP, verify the content appears.

### Phase 2 — Production hardening

1. Add nginx location block for direct asset serving.
2. Add CSP header: `Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'`
3. Verify end-to-end on chancellery.utm.my.

## Known limitations

- PHP fallback asset serving (Phase 1) is slower than direct nginx serving.
- No admin thumbnail preview of package content.
- No automatic cleanup of files when a Page is deleted.
- CSP with `unsafe-inline` is required for most AI-generated HTML.
