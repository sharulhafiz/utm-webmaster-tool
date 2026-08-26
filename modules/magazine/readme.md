# Magazine — AI Static ZIP Publisher Module

Upload AI-generated static-site ZIP packages and publish them under `/magazine/<slug>/` on chancellery.utm.my.

## What it does

1. An admin creates a Magazine Item (slug + title).
2. Uploads a `.zip` containing a static HTML site (from ChatGPT, Gemini, Claude, etc.).
3. The module validates the ZIP, extracts it, and stores it under `wp-content/magazine-publications/<slug>/`.
4. A `[magazine_item slug="..."]` shortcode in a WordPress post renders the `index.html` inline — fully crawlable by search and AI engines.
5. A `[magazine_listing]` shortcode renders a list of published items on the `/magazine/` page.

Individual items live at `/magazine/<slug>/` via WordPress rewrite rules.

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

1. Go to **Settings → Magazine Items** in WordPress admin.
2. Enter a title and slug (lowercase letters, numbers, hyphens only).
3. Choose the ZIP file.
4. Set status to *Published* or *Draft*.
5. Click **Upload & Publish**.
6. The item appears under `/magazine/<slug>/` once published.

## Security model

Uploaded ZIP content is treated as **untrusted**:

| Threat | Protection |
|---|---|
| Path traversal (`../`) | Blocked at extraction — entries outside the target dir are rejected |
| Symlinks | Detected via entry metadata and rejected |
| Server-side files | `.php`, `.phtml`, `.cgi`, `.sh`, `.py`, `.asp`, `.jsp`, `.htaccess` etc. blocked at extraction |
| Archive bombs | Max ZIP 50 MB, max 500 files, max 200 MB uncompressed |
| No index.html | ZIP rejected before extraction |
| Unauthorised access | `manage_options` capability required for all admin operations |

**Note on inline rendering:** Static HTML is rendered inline in the WordPress post, meaning its JavaScript runs in the `chancellery.utm.my` origin. Mitigations:
- No server-side executable content (PHP etc.) is extracted
- Only static assets (CSS/JS/images/fonts) are served
- CSP header recommended: `Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'` (applied via nginx config in Phase 2)

## Storage location

`wp-content/magazine-publications/<slug>/`

On the Chancellery stack this sits on NFS and is readable by all swarm replicas and by nginx.

## URL model

| URL | Served by | Content |
|---|---|---|
| `/magazine/` | WordPress | Listing page via `[magazine_listing]` |
| `/magazine/<slug>/` | WordPress | Item page — shortcode renders index.html inline |
| `/magazine/<slug>/style.css` | nginx (Phase 2) or PHP fallback (Phase 1) | Static asset |

## Update / Unpublish / Delete

- **Update:** Re-upload a ZIP with the same slug — old content is replaced atomically.
- **Unpublish:** Edit status to *Draft* in the admin table.
- **Delete:** Click *Delete* — removes the database record and all extracted files.

## Deployment

### Phase 1 — Code merge (current)

Module is merged to `utm-webmaster-tool` main branch. No production changes.

Assets under `/magazine/<slug>/...` are served by a PHP fallback inside `shortcode.php` — functional but slower than nginx.

### Phase 2 — Production (after 2026-09-02 fleet freeze)

1. Deploy updated plugin to chancellery swarm.
2. Add nginx location block to chancellery nginx.conf:
   ```nginx
   location ~ ^/magazine/([^/]+)/(.*)$ {
       alias /var/www/html/wp-content/magazine-publications/$1/$2;
       try_files $uri =404;
   }
   ```
3. Add CSP header to the location block.
4. Create `wp-content/magazine-publications/` on NFS (writable by PHP, readable by nginx).
5. Flush rewrite rules: visit Settings → Permalinks → Save.

## Known limitations

- PHP fallback asset serving (Phase 1) is slower than direct nginx serving.
- No image preview/thumbnail in admin list — the first version shows slug/title/status only.
- CSP with `'unsafe-inline'` is required for most AI-generated HTML — tighten to `'strict-dynamic'` once inline scripts are audited.
