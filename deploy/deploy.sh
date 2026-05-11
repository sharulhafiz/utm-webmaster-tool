#!/usr/bin/env bash
# ============================================================================
# UTM Webmaster Tool — WSL-native Deploy Wrapper
#
# Routes deployment through SOCKS5 proxy (ssh -D 1080) + proxychains4
# so that FTP (ftplib), SFTP (rsync/SSH), and HTTP verification (urllib)
# all work from within WSL without needing PowerShell or Windows Python.
#
# Prerequisites:
#   - proxychains4 installed (sudo apt install proxychains4)
#   - SOCKS5 tunnel: ssh -D 1080 -f -N -q www2
#   - aTrust VPN connected on Windows
#   - SSH keys in ~/.ssh/ (www2.key, www5.key, etc.)
#
# Usage:
#   ./deploy/deploy.sh --wave pilot          # Deploy pilot wave
#   ./deploy/deploy.sh --target fke          # Deploy single site
#   ./deploy/deploy.sh --dry-run             # Dry run (list files)
#   ./deploy/deploy.sh --list-sites          # List all sites
#   ./deploy/deploy.sh --verify-only         # Check version endpoints
# ============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PYTHON="${PYTHON:-python3}"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

echo -e "${CYAN}╔══════════════════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║   UTM Webmaster Tool — WSL Deploy                       ║${NC}"
echo -e "${CYAN}╚══════════════════════════════════════════════════════════╝${NC}"
echo ""

# ---- Check prerequisites ------------------------------------------------

# 1. SOCKS5 tunnel (ssh -D 1080)
SOCKS_PID=$(pgrep -f "ssh.*-D 1080" 2>/dev/null || true)
if [ -z "$SOCKS_PID" ]; then
    echo -e "${YELLOW}[WARN] SOCKS5 tunnel not running. Start it:${NC}"
    echo "       ssh -D 1080 -f -N -q www2"
    echo ""
    echo -e "${YELLOW}       (Requires aTrust VPN on Windows + SSH key to www2)${NC}"
    echo ""
    read -rp "       Start tunnel now? [Y/n] " yn
    yn="${yn:-Y}"
    if [[ "$yn" =~ ^[Yy]$ ]]; then
        ssh -D 1080 -f -N -q www2 || {
            echo -e "${RED}[FAIL] Could not start SOCKS5 tunnel. Check VPN + SSH key.${NC}"
            exit 1
        }
        echo -e "${GREEN}[OK] SOCKS5 tunnel started${NC}"
    else
        echo -e "${YELLOW}[SKIP] Continuing without tunnel — deployment will fail if VPN required.${NC}"
    fi
else
    echo -e "${GREEN}[OK] SOCKS5 tunnel running (PID: $SOCKS_PID)${NC}"
fi

# 3. Verify tunnel is actually functional (quick check)
if timeout 3 bash -c "echo >/dev/tcp/127.0.0.1/1080" 2>/dev/null; then
    echo -e "${GREEN}[OK] SOCKS5 proxy listening on 127.0.0.1:1080${NC}"
else
    echo -e "${RED}[FAIL] SOCKS5 proxy not listening on 127.0.0.1:1080${NC}"
    exit 1
fi

echo ""

# ---- Run deploy.py through SOCKS5 proxy ----------------------------------

echo -e "${CYAN}[NET] SOCKS5: socks5h://127.0.0.1:1080${NC}"

# Set HTTPS_PROXY for urllib (HTTP verification) — curl handles FTP via --socks5
export HTTPS_PROXY="socks5h://127.0.0.1:1080"
export https_proxy="socks5h://127.0.0.1:1080"

echo -e "${CYAN}[RUN] $PYTHON deploy/deploy.py --socks5 socks5h://127.0.0.1:1080 $*${NC}"
echo ""

# All remaining args are passed through to deploy.py
cd "$REPO_ROOT"
exec "$PYTHON" deploy/deploy.py --socks5 socks5h://127.0.0.1:1080 "$@"
