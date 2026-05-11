#!/usr/bin/env python3
"""
UTM Webmaster Tool -- Deployment script

Reads .vscode/sftp.json for target configuration, groups sites by wave,
and deploys the plugin files via FTP (or SFTP for www5).

Usage:
    python deploy/deploy.py                     # Deploy all waves sequentially
    python deploy/deploy.py --wave pilot        # Deploy only pilot wave
    python deploy/deploy.py --target sps        # Deploy a single site
    python deploy/deploy.py --dry-run           # Show what would be deployed
    python deploy/deploy.py --verify-only       # Only verify version endpoints
    python deploy/deploy.py --list-sites        # List all sites with wave info

Environment:
    Passwords are read from environment variables named:
        UTM_FTP_{SITE_NAME}_PASSWORD  (e.g., UTM_FTP_CHANCELLERY_PASSWORD)
    Or from a .env file in the deploy/ directory.

    For the SFTP target (www5), ensure SSH key at ~/.ssh/www5.key is configured.
"""

import ftplib
import json
import os
import re
import subprocess
import sys
import time
import urllib.request
import urllib.error
from pathlib import Path
from config import PILOT_WAVE, MID_WAVE, FULL_WAVE, WAVE_MAP, VERSION_ENDPOINT, EXCLUDE_PATTERNS

# -- Paths --------------------------------------------------------------------
REPO_ROOT = Path(__file__).resolve().parent.parent
SFTP_JSON = REPO_ROOT / ".vscode" / "sftp.json"
ENV_FILE = Path(__file__).resolve().parent / ".env"
LOCAL_PLUGIN_DIR = REPO_ROOT  # The whole repo is the plugin

# -- Helpers ------------------------------------------------------------------


def load_sftp_config():
    """Load and parse .vscode/sftp.json."""
    if not SFTP_JSON.exists():
        print(f"[FAIL] sftp.json not found at {SFTP_JSON}")
        sys.exit(1)

    with open(SFTP_JSON) as f:
        raw = f.read()
    # Replace *** with placeholder markers so json loads cleanly
    raw = raw.replace('"***"', '"__PLACEHOLDER__"')
    return json.loads(raw)


def load_passwords():
    """Load passwords from .env file (KEY=VALUE format)."""
    passwords = {}
    env_file = ENV_FILE
    if env_file.exists():
        with open(env_file) as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith("#"):
                    if "=" in line:
                        k, v = line.split("=", 1)
                        passwords[k.strip()] = v.strip()
    # Environment variables override .env
    for k, v in os.environ.items():
        passwords[k] = v
    return passwords


def get_password(site_name, passwords):
    """Get password for a site from env vars."""
    # Try UTM_FTP_<SITE>_PASSWORD first (uppercase, underscores)
    env_key = f"UTM_FTP_{site_name.upper()}_PASSWORD"
    if env_key in passwords and passwords[env_key]:
        return passwords[env_key]
    # Try just the site name as key
    if site_name in passwords:
        return passwords[site_name]
    return None


def should_exclude(path):
    """Check if path matches any exclude pattern."""
    rel = str(path.relative_to(REPO_ROOT))
    parts = rel.replace("\\", "/").split("/")
    for pat in EXCLUDE_PATTERNS:
        if pat.startswith("*.") and rel.endswith(pat[1:]):
            return True
        if pat in parts:
            return True
    return False


def get_file_list():
    """Get list of files to deploy (relative paths)."""
    files = []
    for fpath in REPO_ROOT.rglob("*"):
        if fpath.is_file() and not should_exclude(fpath):
            rel = str(fpath.relative_to(REPO_ROOT)).replace("\\", "/")
            files.append(rel)
    return sorted(files)


def ensure_remote_dir(ftp, remote_dir):
    """Ensure a remote directory exists, creating parents as needed."""
    parts = [p for p in remote_dir.split("/") if p]
    current = ""
    for part in parts:
        current += "/" + part
        try:
            ftp.cwd(current)
        except ftplib.error_perm:
            try:
                ftp.mkd(current)
                ftp.cwd(current)
            except ftplib.error_perm as e:
                print(f"  [WARN] Could not create {current}: {e}")
                return False
    return True


# -- Deployers ----------------------------------------------------------------


def deploy_ftp(profile_name, profile, passwords, dry_run):
    """Deploy via FTP using ftplib."""
    host = profile["host"]
    username = profile["username"]
    remote_path = profile["remotePath"]
    password = get_password(profile_name, passwords)

    if not password and not dry_run:
        print(f"  [WARN] No password for {profile_name} (set UTM_FTP_{profile_name.upper()}_PASSWORD)")
        return False

    if dry_run:
        files = get_file_list()
        print(f"  [FILES] {len(files)} files to deploy")
        return True

    print(f"  [CONN] Connecting to {host}...")
    try:
        ftp = ftplib.FTP(host, timeout=15)
        ftp.login(username, password)
        ftp.set_pasv(True)
        print(f"  [OK] Connected as {username}")

        # Ensure remote base path exists
        ensure_remote_dir(ftp, remote_path)
        ftp.cwd(remote_path)

        files = get_file_list()
        uploaded = 0

        for rel_file in files:
            local_file = REPO_ROOT / rel_file
            remote_file = rel_file.replace("\\", "/")

            # Create subdirectory if needed
            remote_dir = os.path.dirname(remote_file)
            if remote_dir:
                ftp.cwd(remote_path)  # Reset to base
                full_dir = remote_path + "/" + remote_dir
                if not ensure_remote_dir(ftp, full_dir):
                    continue
                ftp.cwd(full_dir)

            # Upload file
            try:
                with open(local_file, "rb") as fh:
                    ftp.storbinary(f"STOR {os.path.basename(remote_file)}", fh)
                uploaded += 1
                if uploaded % 20 == 0:
                    print(f"  [UP] {uploaded}/{len(files)} files uploaded...")
            except ftplib.error_perm as e:
                print(f"  [WARN] Failed {remote_file}: {e}")

        ftp.quit()
        print(f"  [OK] {uploaded}/{len(files)} files uploaded to {profile_name}")
        return uploaded == len(files)

    except ftplib.all_errors as e:
        print(f"  [FAIL] FTP error for {profile_name}: {e}")
        return False


def deploy_sftp(profile_name, profile, dry_run, socks5_proxy=None):
    """Deploy via SFTP (tar-over-SSH) for www5 (NFS backend)."""
    host = profile["host"]
    username = profile["username"]
    remote_path = profile.get("remotePath", "/NFS-WWW5/websites/plugins/utm-webmaster-tool")
    port = profile.get("port", 22)

    # Find SSH key
    key_file = None
    if "privateKeyPath" in profile:
        key_candidate = REPO_ROOT / profile["privateKeyPath"]
        if key_candidate.exists():
            key_file = key_candidate
    if not key_file:
        home_key = Path.home() / ".ssh" / "www5.key"
        if home_key.exists():
            key_file = home_key

    if dry_run:
        files = get_file_list()
        print(f"  [FILES] {len(files)} files to deploy via tar-over-SSH")
        return True

    # Build SSH base command
    ssh_base = [
        "ssh",
        "-o", "StrictHostKeyChecking=no",
        "-p", str(port),
    ]
    if key_file:
        ssh_base += ["-i", str(key_file)]
    if socks5_proxy:
        proxy = socks5_proxy.replace("socks5h://", "").replace("socks5://", "").replace("socks5h:", "").replace("socks5:", "")
        ssh_base += ["-o", f"ProxyCommand=nc -X 5 -x {proxy} %h %p"]
    ssh_base += [f"{username}@{host}"]

    # Build exclude patterns for tar
    exclude_patterns = [
        ".git", ".github", ".vscode", ".agents", "deploy", "plans",
        "tests", "assets", "scripts", "vendor", "__pycache__",
        ".gitignore", ".DS_Store", "desktop.ini", "docker-compose",
        "nginx.conf",
    ]

    files = get_file_list()
    print(f"  [FILES] {len(files)} files to deploy via tar-over-SSH")

    # Ensure remote directory exists
    mkdir_cmd = ssh_base + [f"mkdir -p {remote_path}"]
    subprocess.run(mkdir_cmd, capture_output=True, timeout=30)

    # Build tar-over-SSH pipeline using list form
    tar_cmd = ["tar", "cz", "-C", str(REPO_ROOT), "--exclude=*.md", "--exclude=*.pyc"]
    for pat in exclude_patterns:
        tar_cmd += [f"--exclude={pat}"]
    tar_cmd += ["."]

    # Readme needs special handling — exclude *.md but include core files
    # Actually, let's not include the readme. The plugin files are what matter.

    untar_cmd = ssh_base + [f"cd {remote_path} && tar xz"]

    print(f"  [CONN] tar-over-SSH {username}@{host}:{remote_path} (SOCKS5: {bool(socks5_proxy)}) ...")

    # Run tar | ssh pipeline
    tar_proc = subprocess.Popen(tar_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    untar_proc = subprocess.Popen(untar_cmd, stdin=tar_proc.stdout, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    tar_proc.stdout.close()
    stdout, stderr = untar_proc.communicate(timeout=120)
    tar_ret = tar_proc.wait(timeout=10)

    if untar_proc.returncode == 0:
        print(f"  [OK] {len(files)} files deployed to {host}")
        return True
    else:
        err = stderr.decode() if stderr else f"tar exit={tar_ret}, ssh exit={untar_proc.returncode}"
        print(f"  [FAIL] tar-over-SSH failed: {err[:200]}")
        return False

# -- Verification --------------------------------------------------------------


def deploy_ftp_curl(profile_name, profile, passwords, dry_run, socks5_proxy):
    """Deploy via FTP through SOCKS5 proxy using curl."""
    host = profile["host"]
    username = profile["username"]
    remote_path = profile["remotePath"]
    password = get_password(profile_name, passwords)

    if not password and not dry_run:
        print(f"  [WARN] No password for {profile_name} (set UTM_FTP_{profile_name.upper()}_PASSWORD)")
        return False

    if dry_run:
        files = get_file_list()
        print(f"  [FILES] {len(files)} files to deploy via curl (SOCKS5)")
        return True

    files = get_file_list()
    uploaded = 0

    print(f"  [CONN] {host} via SOCKS5 {socks5_proxy}...")

    for rel_file in files:
        local_file = REPO_ROOT / rel_file
        # Build FTP URL with absolute path from root
        remote_url = f"ftp://{host}{remote_path}/{rel_file}"

        cmd = [
            "curl", "--silent", "--show-error",
            "--socks5-hostname", socks5_proxy,
            "--user", f"{username}:{password}",
            "--ftp-create-dirs",
            "--upload-file", str(local_file),
            remote_url
        ]
        try:
            result = subprocess.run(cmd, capture_output=True, text=True, timeout=60)
            if result.returncode == 0:
                uploaded += 1
            else:
                # Truncate stderr to avoid huge output
                err = result.stderr.strip()[:150]
                print(f"  [WARN] Failed {rel_file}: {err}")
        except subprocess.TimeoutExpired:
            print(f"  [WARN] Timeout uploading {rel_file}")
            continue

        if uploaded % 20 == 0:
            print(f"  [UP] {uploaded}/{len(files)} files uploaded...")

    print(f"  [OK] {uploaded}/{len(files)} files uploaded to {profile_name}")
    return uploaded == len(files)




def verify_site(profile_name, profile, expected_version, socks5_proxy=None):
    """Check version endpoint for a single target site."""
    # Handle special hostname mappings for multi-site servers and IP-based hosts
    host_map = {
        "www5": "mjiit.utm.my",        # NFS backend → verify via MJIIT frontend
        "pesisir": "sps.utm.my",        # sits under www2 with sps account
        "dvcai": "dvcai.utm.my",        # www3.utm.my hosts multiple sites
    }
    # Sites that use raw IP → resolve to their subdomain
    ip_hosts = {
        "161.139.17.183": "kl.utm.my",  # fke, kl share this IP
        "161.139.22.219": "mjiit.utm.my",  # www5 NFS
    }

    host = profile.get("host", profile_name)
    if profile_name in host_map:
        host = host_map[profile_name]
    elif host in ip_hosts:
        host = ip_hosts[host]

    # Cache-busting: use a timestamp to avoid stale nginx cache
    cache_buster = int(time.time())
    url = f"https://{host}{VERSION_ENDPOINT}?_={cache_buster}"

    if socks5_proxy:
        # Use curl through SOCKS5 for verification (reliable with proxy)
        import subprocess
        # Retry up to 2 times for transient network issues
        for attempt in range(2):
            try:
                cmd = ["curl", "-sL", "--connect-timeout", "10", "--max-time", "15",
                       "--socks5-hostname", socks5_proxy.replace("socks5h://", ""),
                       url]
                result = subprocess.run(cmd, capture_output=True, text=True, timeout=20)
                if result.returncode == 0 and result.stdout.strip():
                    data = json.loads(result.stdout)
                    actual = data.get("version", "?")
                    ok = actual == expected_version
                    status = "[OK]" if ok else "[WARN]"
                    print(f"  {status} {profile_name}: v{actual} (expected v{expected_version})")
                    return ok
                if attempt == 0:
                    time.sleep(2)
            except (json.JSONDecodeError, OSError, subprocess.TimeoutExpired) as e:
                if attempt == 0:
                    time.sleep(2)
        print(f"  [?] {profile_name}: unreachable (curl)")
        return None
    else:
        # Direct HTTP request
        try:
            req = urllib.request.Request(url, headers={"User-Agent": "UTM-Deploy/1.0"})
            resp = urllib.request.urlopen(req, timeout=20)
            data = json.loads(resp.read())
            actual = data.get("version", "?")
            ok = actual == expected_version
            status = "[OK]" if ok else "[WARN]"
            print(f"  {status} {profile_name}: v{actual} (expected v{expected_version})")
            return ok
        except (urllib.error.HTTPError, urllib.error.URLError, json.JSONDecodeError, OSError) as e:
            print(f"  [?] {profile_name}: unreachable ({type(e).__name__})")
            return None


# -- Main ----------------------------------------------------------------------


def main():
    # Set UTF-8 output encoding for Windows compatibility
    import sys
    if sys.stdout.encoding and sys.stdout.encoding.upper() != "UTF-8":
        try:
            sys.stdout.reconfigure(encoding="utf-8")
        except AttributeError:
            pass

    import argparse

    parser = argparse.ArgumentParser(description="Deploy UTM Webmaster Tool to all sites")
    parser.add_argument("--wave", choices=["pilot", "mid", "full"], help="Only deploy this wave")
    parser.add_argument("--target", help="Deploy a single site by profile name")
    parser.add_argument("--dry-run", action="store_true", help="List files without deploying")
    parser.add_argument("--verify-only", action="store_true", help="Only check version endpoints")
    parser.add_argument("--list-sites", action="store_true", help="List all sites and waves")
    parser.add_argument("--socks5", help="SOCKS5 proxy for FTP (e.g., socks5h://127.0.0.1:1080). Uses curl instead of ftplib.")
    args = parser.parse_args()

    sftp_config = load_sftp_config()
    profiles = sftp_config.get("profiles", {})
    passwords = load_passwords()

    # Get expected version from index.php
    index_content = open(REPO_ROOT / "index.php").read()
    match = re.search(r"define\s*\(\s*'UTM_PLUGIN_VERSION'\s*,\s*'([^']+)'", index_content)
    expected_version = match.group(1) if match else "?"

    # Build site list
    if args.list_sites:
        print(f"\n  {'Site':<20} {'Wave':<10} {'Host':<25} {'Protocol':<8}")
        print(f"  {'-'*20} {'-'*10} {'-'*25} {'-'*8}")
        for name, prof in sorted(profiles.items()):
            wave = WAVE_MAP.get(name, "unassigned")
            protocol = prof.get("protocol", "ftp").upper()
            print(f"  {name:<20} {wave:<10} {prof['host']:<25} {protocol:<8}")
        return

    # Determine targets
    if args.target:
        targets = {args.target: profiles.get(args.target)}
        if targets[args.target] is None:
            print(f"[FAIL] Unknown target: {args.target}")
            sys.exit(1)
    elif args.wave:
        wave_map = {"pilot": PILOT_WAVE, "mid": MID_WAVE, "full": FULL_WAVE}
        target_names = wave_map[args.wave]
        targets = {n: profiles.get(n) for n in target_names if n in profiles}
    else:
        # All sites
        targets = profiles

    # Filter out missing profiles
    targets = {k: v for k, v in targets.items() if v is not None}

    if not targets:
        print("[FAIL] No targets to deploy")
        sys.exit(1)

    wave_label = args.wave or "all"
    print(f"\n  [DEPLOY] UTM Webmaster Tool v{expected_version}")
    print(f"  [NET] Deploy: {wave_label} wave ({len(targets)} sites)\n")

    # -- Verify only mode --------------------------------------------------
    if args.verify_only:
        print(f"  [CHECK] Verifying version endpoint across {len(targets)} sites...\n")
        results = {"ok": 0, "mismatch": 0, "unreachable": 0}
        for name, prof in sorted(targets.items()):
            ok = verify_site(name, prof, expected_version, args.socks5)
            if ok is True:
                results["ok"] += 1
            elif ok is False:
                results["mismatch"] += 1
            else:
                results["unreachable"] += 1
        print(f"\n  [STATS] Results: {results['ok']} ok, {results['mismatch']} mismatch, {results['unreachable']} unreachable")
        return

    # -- Deploy mode ------------------------------------------------------
    results = {"ok": 0, "fail": 0, "skipped": 0}

    for name, prof in sorted(targets.items()):
        protocol = prof.get("protocol", "ftp").lower()
        print(f"\n  -- {name} ({protocol.upper()}) --")

        if name == "www5" or protocol == "sftp":
            ok = deploy_sftp(name, prof, args.dry_run, args.socks5)
        elif args.socks5:
            ok = deploy_ftp_curl(name, prof, passwords, args.dry_run, args.socks5)
        else:
            ok = deploy_ftp(name, prof, passwords, args.dry_run)

        if ok:
            results["ok"] += 1
        else:
            results["fail"] += 1

        # Small delay between sites to avoid hammering servers
        time.sleep(1)

    # -- Summary ----------------------------------------------------------
    total = len(targets)
    print(f"\n  {'='*40}")
    print(f"  [STATS] Summary: {results['ok']}/{total} succeeded, {results['fail']} failed")

    if results["fail"] > 0 and not args.dry_run:
        sys.exit(1)


if __name__ == "__main__":
    main()
