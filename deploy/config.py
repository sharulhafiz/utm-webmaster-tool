"""
Wave groupings for staged deployment.

Sites are organized into waves to allow controlled rollout:
  Pilot  → low-risk, testable sites (catch issues early)
  Mid    → moderate-traffic sites
  Full   → remaining production sites (after Pilot + Mid verified)

Wave assignment logic:
- Pilot: dev/test instances, low-traffic, easy to roll back
- Mid: faculty/department sites (important but not the highest-traffic)
- Full: everything else, including high-traffic institutional sites

Add new sites to the appropriate wave list below.
"""

# ── Wave A: Pilot sites (deploy first, verify, then proceed) ────────────────
PILOT_WAVE = [
    "dvcdev",       # Dev/test — lowest risk
    "persatuan",    # Low traffic
    "pesisir",      # Low traffic
]

# ── Wave B: Mid-risk sites (deploy after Pilot is verified) ──────────────────
MID_WAVE = [
    "alumni",         # Alumni relations site
    "chancellery",   # Admin hub — important but controlled audience
    "research",      # Research portal
    "fkt",           # Faculty
    "sps",           # School of Professional Studies
    "library",       # Library
    "comp",          # Faculty of Computing
    "science",       # Faculty of Science
    "mech",          # Faculty of Mechanical Engineering
    "fke",           # Faculty of Electrical Engineering
    "business",      # IBS / Business school
    "registrar",     # Registrar's office
    "humanities",    # Faculty of Humanities
]

# ── Wave C: Full rollout (everything else) ───────────────────────────────────
FULL_WAVE = [
    "kl",             # KL campus
    "photos",         # Photos site
    "international",  # International office
    "studentaffairs", # Student affairs
    "dvcai",          # DVC AI
    "utmcdex",        # CDEx
    # "mjiit" migrated to www5.utm.my — covered by www5 SFTP profile
    "apps_library",   # Apps Library
    "bursary",        # Bursary
    "conference",     # Conference
    "digital",        # Digital
    "fai",            # FAI
    "space",          # Space
    "www2",           # www2 generic (also covers support.utm.my — CNAME alias)
    "www5",           # www5 (SFTP — NFS backend)
]

# ── Map: site_name → wave_id ────────────────────────────────────────────────
WAVE_MAP = {}
for site in PILOT_WAVE:
    WAVE_MAP[site] = "pilot"
for site in MID_WAVE:
    WAVE_MAP[site] = "mid"
for site in FULL_WAVE:
    WAVE_MAP[site] = "full"

# ── Swarm shared plugin (NFS) ───────────────────────────────────────────────
# Sites that have migrated to the swarm mount the plugin read-only from
# /data/plugins/utm-webmaster-tool (NFS wwwdata, device
# :/export/pool1/wwwdata/plugins/utm-webmaster-tool). Deploying to this
# single path instantly updates ALL swarm stacks — no per-site FTP needed.
#
# SOURCE OF TRUTH: this list must match:
#   - Web-Ops/docs/domain-inventory.md (🟢 MIGRATED entries with NFS plugin)
#   - Web-Ops/configs/sites/*/docker-compose*.yml (utm-webmaster-tool-nfs-wwwdata volume)
#
# To regenerate: grep -rl "utm-webmaster-tool-nfs-wwwdata" configs/sites/*/docker-compose*.yml
#
# Excluded (NOT NFS consumers despite being in configs/sites/):
#   - bim, mjarc: no configs/sites/ directories (phantom entries)
#   - failean: uses legacy tgz config, not NFS volume
SWARM_MIGRATED = [
    "alumni",          # 🟢 swarm (www5→swarm)
    "apps.library",    # 🟢 swarm (NFS wwwdata mount)
    "builtsurvey",     # 🟢 swarm (www5→swarm)
    "chancellery",     # 🟢 swarm (redis db3)
    "comp",            # 🟢 swarm (NFS wwwdata mount)
    "civil",           # 🟢 swarm (www5→swarm)
    "dvcai",           # 🟢 swarm (NFS wwwdata mount)
    "events",          # 🟢 swarm (NFS wwwdata mount)
    "fai",             # 🟢 swarm (redis db4)
    "fest",            # 🟢 swarm (www5→swarm)
    "humanities",      # 🟢 swarm (redis db1)
    "library",         # 🟢 swarm (NFS wwwdata mount)
    "management",      # 🟢 swarm (www5→swarm, wp2_ prefix)
    "mjiit",           # 🟢 swarm (redis db5)
    "registrar",       # 🟢 swarm (NFS wwwdata mount — site of #153)
    "research",        # 🟢 swarm (redis db0)
    "space",           # 🟢 swarm (www2→swarm, REDIS_DB=6)
    "sps",             # 🟢 swarm (NFS wwwdata mount)
    "studentaffairs",  # 🟢 swarm (redis db2)
    "utmcdex",         # 🟢 swarm (NFS wwwdata mount)
    "virtualgallery",  # 🟢 swarm (www3→swarm, 2026-08-14)
]
SWARM_PLUGIN_PATH = "/data/plugins/utm-webmaster-tool"
SWARM_SSH_HOST = "www1.utm.my"
SWARM_SSH_USER = "Sysadm1n"
# Owner of the live plugin dir on www1 (devops:devops 755). The SSH login user
# (Sysadm1n) cannot write it directly; writes go through `sudo -n -u devops`.
SWARM_REMOTE_OWNER = "devops"
# Key for swarm SSH — uses default ~/.ssh/id_ed25519 (hermes www1 key)
# Falls back to ~/.ssh/www1.key / id_ed25519 per deploy.py discovery.

# ── Verification endpoint ────────────────────────────────────────────────────
VERSION_ENDPOINT = "/wp-json/utm-webmaster/v1/version"

# ── Files/dirs to exclude from upload ────────────────────────────────────────
EXCLUDE_PATTERNS = [
    ".agents",
    ".github",
    ".vscode",
    ".git",
    ".DS_Store",
    ".gitignore",
    "assets",
    "scripts",
    "vendor",
    "desktop.ini",
    "docker-compose",
    "nginx.conf",
    "*.md",
    "plans",
    "tests",
    "deploy",
    "__pycache__",
    "*.pyc",
    "*.bak*",
]
