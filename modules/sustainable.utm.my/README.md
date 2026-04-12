# sustainable.utm.my module

## Purpose

This module receives Google Docs sync payloads from Apps Script and upserts WordPress **pages** on `sustainable.utm.my`.

## REST endpoints

- `GET /wp-json/utm-sustainable/v1/post-by-google-id?google_id=<id>`
- `POST /wp-json/utm-sustainable/v1/sync-page`

Both endpoints require authentication and `edit_pages` capability.

## Payload (`sync-page`)

```json
{
  "google_id": "1abc...",
  "title": "Page title",
  "content": "<p>HTML from Google Docs</p>",
  "status": "publish",
  "google_modified": "2026-04-11T10:30:00Z",
  "folder_path": ["About", "Sustainability Initiatives"]
}
```

## Mapping and metadata

- Mapping key: `_utm_google_doc_id`
- Last modified mirror: `_utm_google_doc_modified_at`

## PDF bracket embedding

If content contains:

`[https://drive.google.com/file/d/<file_id>/view?usp=sharing]`

It is transformed to a Google Drive preview iframe embed.

## Menu syncing

When `folder_path` is provided, the module ensures menu hierarchy under `Main Menu` and places the page under the final folder node.

## Page hierarchy syncing

When `folder_path` is provided, the module also:

- creates/reuses folder pages as actual WordPress pages,
- sets `post_parent` for each synced doc page to the last folder page in the path,
- keeps a deterministic folder key in `_utm_sustainable_folder_key`.

This means you do **not** need to manually create parent pages first.

## Apps Script authentication model

Use Script Properties (not hardcoded secrets):

- `FOLDER_ID`
- `WORDPRESS_URL`
- `WP_USERNAME`
- `WP_APP_PASSWORD`
- `WP_POST_TYPE` (optional; defaults to `page`)

Set these in the Google Apps Script project settings, not in the `.js` file:

1. Open the Apps Script project.
2. Go to **Project Settings**.
3. Add the keys under **Script properties**.
4. Save, then run the sync function again.

The repository copy of `/NFS-WWW4/wp-common-assets/plugins/utm-webmaster-tool/modules/sustainable.utm.my/Code.js` reads these properties at runtime.

Required Apps Script scopes:

- `https://www.googleapis.com/auth/drive.readonly`
- `https://www.googleapis.com/auth/documents.readonly`
- `https://www.googleapis.com/auth/script.external_request`

Google Docs export uses `ScriptApp.getOAuthToken()`.

## Deployment steps
1. Use Google Clasp to push `Code.js` to the Apps Script project.

## Main Apps Script entrypoint

Run `pushDocsToWordPress` to execute the full sync.

A compatibility alias `pushDocsToWordpress` (lowercase `p`) is also available.

## Rollback

1. Disable module from plugin module settings (if used in your environment), or remove slug from loader.
2. Purge/refresh opcache for affected PHP containers.
3. Re-run endpoint smoke tests to confirm routes are no longer served.
