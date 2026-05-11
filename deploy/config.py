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
]
