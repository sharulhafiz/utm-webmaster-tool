# Nginx Cache Purge — Integration Proposal

> Generated May 12, 2026 by Codex CLI (OpenAI)
> Based on audit of www2.utm.my (Plesk) and www5.utm.my (Docker) nginx cache architecture

---

## Architecture Overview

```
Visitor → www2 (Plesk Nginx Proxy, 161.139.17.183)
           │
           ├── CACHED (FastCGI, 5h TTL): 24 WordPress sites running PHP directly on www2
           │   alumni, bursary, business, chancellery, civil, comp, conference, digital,
           │   dvcdev, fai, fke, fkt, humanities, international, kl, mech, meipta,
           │   persatuan, photos, razak, science, space, studentaffairs, www2
           │
           └── NOT CACHED (pure proxy_pass): mjiit, management, fest, builtsurvey, etc.
               (proxied to www3/www5/www6 Docker backends)

                        ↓ proxied sites

    www5 (Docker Farm, 161.139.22.219)
      └── Traefik → per-site nginx (fastcgi 60m TTL) → php-fpm
```

### Cache Parameters

| Server | Type | TTL | Max/Zone | Bypass | Purge Method |
|--------|------|-----|----------|--------|-------------|
| **www2** | FastCGI | 5h (18000s) | 64MB/5MB | Auth cookies | `find /var/cache/nginx/{domain}_fastcgi/ -type f -delete` |
| **www5** | FastCGI | 60m | 1GB/100MB | POST, wpauth, query | `docker exec {site}-nginx find /var/cache/nginx -type f -delete` |

**Neither server has any auto-purge**, ngx_cache_purge module, or cron-based cleanup.

---

## Module Structure: `modules/nginx-cache-purge/`

```
modules/nginx-cache-purge/
├── bootstrap.php              # Module loader, constants, cron registration
├── settings.php               # Network/site options model
├── hooks.php                  # WordPress event → purge intent mapping
├── rest.php                   # REST endpoints (purge, status, logs, retry)
├── queue.php                  # Job dedup, persistence, cron runner
├── purge-service.php          # Business logic: site context → executor
├── admin.php                  # Dashboard UI (status, manual purge, settings, logs)
├── logger.php                 # Audit trail (custom DB table)
├── cli.php                    # WP-CLI commands
├── executors/
│   ├── interface-cache-executor.php    # Interface contract
│   ├── class-www2-plesk-executor.php   # www2 wrapper execution
│   ├── class-www5-docker-executor.php  # www5 remote/host controller execution
│   └── class-noop-executor.php         # Dry-run / disabled
└── README.md
```

---

## 1. WordPress Hook Strategy

### Trigger Matrix

| Event | WP Hook | Purge Scope | Notes |
|-------|---------|-------------|-------|
| Post publish/update | `transition_post_status` → `publish` | Full site | Ignore autosaves/revisions |
| Post delete/trash/untrash | `deleted_post`, `trashed_post`, `untrashed_post` | Full site | |
| Comment approved | `transition_comment_status` → `approve` | Full site | |
| Term created/edited/deleted | `created_term`, `edited_term`, `delete_term` | Full site | |
| Menu changed | `wp_update_nav_menu`, `wp_create_nav_menu` | Full site | |
| Theme switch | `switch_theme` | Full site | |
| Plugin activate/deactivate | `activated_plugin`, `deactivated_plugin` | Full site | |
| Widget/settings change | `update_option_sidebars_widgets`, `customize_save_after` | Full site | |
| Core/plugin/theme upgrade | `upgrader_process_complete` | Full site | |
| WP-CLI | `wp utm cache purge --reason=deploy` | Explicit | Manual deployment trigger |

### Do NOT Purge
- `wp_login` / `wp_logout` — already bypassed by auth cookie
- `profile_update` — no public content changed
- `set_current_user` — no content change

### Hook Pattern

```php
// Coalesce, don't execute immediately
add_action('transition_post_status', function($new_status, $old_status, $post) {
    if (wp_is_post_revision($post) || wp_is_post_autosave($post)) return;
    if ($new_status === 'publish' || $old_status === 'publish') {
        utm_nginx_cache_enqueue_purge('site', 'post_updated');
    }
}, 10, 3);
```

**Design decision:** All hooks enqueue a job, never purge synchronously.

---

## 2. Cache Purge Execution Layer

### Abstraction: Service + Executor Interface

```
UTM_Nginx_Cache_Purge_Service
  → enqueue($scope, $reason, $urls = [])
  → run_pending()
  → purge_now($job)

UTM_Cache_Executor_Interface
  → supports($site_context): bool
  → purge_site($site_context): array{success, message, deleted_count, duration}
  → status($site_context): array
```

### www2 (Plesk) Executor

**Do NOT run `find ... -delete` directly from PHP.** Use a root-owned wrapper:

```
sudo /usr/local/sbin/utm-nginx-cache-purge --site alumni.utm.my --json
```

Wrapper logic:
```bash
# 1. Validate site against allowlist (/etc/utm-nginx-cache-sites.json)
# 2. Verify cache dir starts with /var/cache/nginx/ and ends with _fastcgi
# 3. find /var/cache/nginx/{domain}_fastcgi -type f -delete
# 4. Output JSON result: {success, deleted_count, duration_ms, message}
```

Sudoers entry:
```
www-data ALL=(root) NOPASSWD: /usr/local/sbin/utm-nginx-cache-purge
```

**Safety rules:**
- No user-supplied shell fragments
- `escapeshellarg` even for allowlisted values
- Cache dir must match regex: `/^\/var\/cache\/nginx\/([a-z0-9.-]+)_fastcgi$/`
- Site must be in allowlist JSON
- Log every invocation

### www5 (Docker) Executor

Plugin inside container cannot run docker commands. Two options:

**Option A (Preferred):** Host-local purge agent on www5
```
POST http://127.0.0.1:PORT/purge
X-UTM-Site: mjiit.utm.my
X-UTM-Signature: hmac_sha256(...)
```

Agent runs: `docker exec {site}-nginx find /var/cache/nginx -type f -delete`

**Option B:** Plugin calls central management WordPress site via REST
- Management site has SSH access to www5
- Passes purge request via signed REST call

**Option C:** WP-Cron on www5 host polls purge queue from WordPress DB (most complex)

### Synchronous vs Async

| Aspect | Sync | Async (Recommended) |
|--------|------|-------------------|
| Post save latency | Blocks admin UX | Non-blocking |
| Stampede | Direct risk | Coalesced |
| Failure handling | Aborts save | Retry + log |
| Admin manual | OK with timeout | Also fine |

**Default: async via WP-Cron + transient queue.** Manual "Purge Now" can be sync with 30s timeout.

### Coalescing Strategy

```
Hook fires → enqueue purge intent
  → check if pending job exists for same site (within last 120s)
  → if yes, extend cooldown; if no, create new job
  → WP-Cron fires every minute → process all eligible jobs
  → dedupe by hash(site + reason + 2min window)
```

### Partial vs Full Purge

**Phase 1 & 2: Full per-site purge only.**

Rationale:
- www2 cache key includes scheme + method + host + path + args
- www5 key uses `$scheme$request_method$host$request_uri`
- nginx stores hashed cache files (MD5 of cache key) — no readable URL paths
- Without `ngx_cache_purge`, URL-level purge requires reverse-mapping cache key → filename
- Site caches are bounded (64MB-1GB) with modest TTLs

Future: `ngx_cache_purge` module would enable clean HTTP PURGE requests for specific URLs.

---

## 3. REST API Design

### Namespace: `utm/v1/nginx-cache`

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/utm/v1/nginx-cache/purge` | Enqueue/sync purge job |
| GET | `/utm/v1/nginx-cache/status` | Current site cache status |
| GET | `/utm/v1/nginx-cache/logs` | Recent purge logs |
| POST | `/utm/v1/nginx-cache/retry` | Retry failed jobs |

### POST /purge Request

```json
{
  "site": "alumni.utm.my",
  "scope": "site",
  "reason": "post_updated",
  "source": "wordpress-hook",
  "request_id": "a1b2c3d4-e5f6-...",
  "created_at": 1778570000
}
```

### Response

```json
{
  "success": true,
  "job_id": "purge_20260512_abc123",
  "status": "queued",
  "scope": "site",
  "site": "alumni.utm.my"
}
```

### Idempotency

- `request_id` required for external callers
- Dedup key = hash(site + scope + reason + 2-minute window)
- Same `request_id` returns existing job status
- Multiple hook events coalesce into one site purge

---

## 4. Security Model

### Authentication

| Caller | Method |
|--------|--------|
| WP admin (manual) | `current_user_can('manage_options')` |
| WP network admin | `current_user_can('manage_network_options')` |
| Server-to-server | HMAC-SHA256 signed requests |
| Anonymous | ❌ Never allowed |

### HMAC Headers

```
X-UTM-Site: alumni.utm.my
X-UTM-Timestamp: 1778570000
X-UTM-Nonce: random-128-bit
X-UTM-Signature: hmac_sha256(secret, method + path + body_hash + timestamp + nonce)
```

### Validation Checks

- Timestamp skew ≤ 300 seconds
- Nonce unused within last 10 minutes
- Site is in allowlist
- Scope allowed for that credential
- Strict body schema
- Rate limit passes

### Rate Limiting

- Per site: 10 purge requests per 10 minutes
- External token: 30 per hour
- Manual super-admin: bypass with warning
- Coalescing naturally reduces effective rate

### SSRF Prevention

- ❌ No arbitrary SSH hostnames
- ❌ No arbitrary filesystem paths
- ❌ No arbitrary container names
- ❌ No user-provided command fragments
- ✅ All domains, cache dirs, containers come from config allowlists

### Audit Log Schema

```sql
CREATE TABLE wp_utm_nginx_cache_purge_log (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    blog_id       BIGINT,
    site          VARCHAR(255),
    scope         VARCHAR(50),
    reason        VARCHAR(100),
    source        VARCHAR(100),
    request_id    VARCHAR(64),
    status        VARCHAR(20),     -- queued|running|success|failed|skipped
    message       TEXT,
    deleted_count INT DEFAULT 0,
    duration_ms   INT DEFAULT 0,
    created_at    DATETIME,
    completed_at  DATETIME,
    actor_type    VARCHAR(20),     -- admin|token|cli|hook
    actor_id      VARCHAR(255),
    remote_ip     VARCHAR(45)
);
```

---

## 5. Nginx Module Alternative (ngx_cache_purge)

### Pros
- Clean URL-level PURGE (no full-site delete)
- No sudo from PHP
- No docker exec for normal purges
- Faster, less destructive

### Cons
- Plesk nginx is vendor-managed — custom module may be overwritten
- Requires nginx rebuild or compatible dynamic module
- www5 requires Docker image rebuild + config rollout
- Still need config per vhost (Plesk custom template path)
- Must protect PURGE endpoint from public access

### Recommendation
**Skip Phase 1.** Use wrapper-based full-site purge first.
Re-evaluate only if full-site purge causes measurable load or freshness problems.

---

## 6. Phase Implementation Plan

### Phase 1: Foundation + www2 Local Purge
**Files:** bootstrap, settings, hooks, rest, queue, purge-service, www2-executor, logger, admin, cli
**Scope:** Cached www2 WordPress sites only. No global `/var/cache/nginx` delete. No Plesk config edits.
**Acceptance:**
- Publishing a post queues one purge
- Duplicate edits coalesce
- Manual purge deletes only configured site cache dir
- Failed command logged without breaking post save
- REST endpoint rejects unsigned requests

### Phase 2: Cross-Server www5 Docker Support
**Files:** www5-executor, host wrapper script, container allowlist
**Scope:** Docker sites behind www2 proxy. Per-container cache deletion.
**Acceptance:**
- Plugin inside Docker can request its own cache purge
- Host deletes only that site container cache
- Container name cannot be supplied arbitrarily
- Logs show remote execution result

### Phase 3: Dashboard + Monitoring
**Files:** Enhanced admin.php, cache-monitor integration
**Scope:** Network admin dashboard, site status cards, queue depth, failure count, cache hit ratio
**Also:** Fix existing cache-monitor module (currently starts with `return;` — disabled!)

### Phase 4: Security Hardening + Rate Limiting
**Scope:** HMAC key rotation, nonce replay protection, rate limiting, alerting, SIEM forwarding
**Optional:** Move secrets to wp-config.php, centralize allowlist outside DB

---

## 7. Server-Side Wrapper Scripts

### www2: `/usr/local/sbin/utm-nginx-cache-purge`

```bash
#!/bin/bash
# Root-owned wrapper. Called via sudo from WordPress.
# Usage: utm-nginx-cache-purge --site alumni.utm.my [--dry-run] [--json]

ALLOWLIST="/etc/utm-nginx-cache-sites.json"
CACHE_BASE="/var/cache/nginx"

# Validate site against allowlist
# Construct cache dir: $CACHE_BASE/{site}_fastcgi
# Safety checks: path starts with /var/cache/nginx/, ends with _fastcgi
# Run: find "$cache_dir" -type f -delete
# Output: JSON with success, deleted_count, duration_ms
```

Sites allowlist (`/etc/utm-nginx-cache-sites.json`):
```json
["alumni.utm.my", "bursary.utm.my", "business.utm.my", "chancellery.utm.my", ...]
```

### www5: `/usr/local/sbin/utm-docker-nginx-cache-purge`

```bash
#!/bin/bash
# Root-owned wrapper on www5 host.
# Usage: utm-docker-nginx-cache-purge --site mjiit.utm.my [--dry-run]

# Site → container mapping (from allowlist)
# Validate: container name is allowlisted
# Run: docker exec {container}-nginx find /var/cache/nginx -type f -delete
# Output: JSON result
```

---

## 8. WP-CLI Commands

```bash
wp utm cache status                              # Show current site cache status
wp utm cache purge --site=alumni.utm.my          # Purge specific site
wp utm cache purge --all --reason=maintenance    # Purge all managed sites
wp utm cache logs --site=alumni.utm.my --limit=20  # View purge history
wp utm cache settings                            # Show current configuration
```

---

## 9. Integration with Existing Plugin

### Registration (index.php)
```php
'nginx-cache-purge',  // Add to utm_get_all_module_slugs()
```

### Protected Modules (if critical)
Optionally add to `utm_get_protected_module_slugs()` to prevent accidental disabling.

### Existing REST Parallels
- `utm/v1/opcache-reset` currently public → **do not copy** that permission model
- New `utm/v1/nginx-cache/*` requires auth at minimum

### Existing cache-monitor
Currently disabled (`return;` at line 3). Phase 3 should fix this and link it to the purge module for complete cache lifecycle management.

---

## Key Design Decisions Summary

| Decision | Chosen | Alternative |
|----------|--------|-------------|
| Purge scope | Full per-site | URL-level (future with ngx_cache_purge) |
| Timing | Async (queued) | Sync on save_post |
| Execution | Root-owned wrapper scripts | Direct PHP exec/find |
| Cross-server | REST with HMAC | Shared SSH key |
| Plesk nginx | Leave untouched | Install ngx_cache_purge module (Phase 4+) |
| Cache dir deletion | `find -type f -delete` | `rm -rf` (more dangerous) |
| Job persistence | Transient + WP-Cron | Action Scheduler (if available) |
| Auth model | HMAC + WP capabilities | Shared secret only |
