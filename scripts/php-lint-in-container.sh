#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'EOF'
Usage:
  scripts/php-lint-in-container.sh --container <container_name> <php_file> [more_files...]
  scripts/php-lint-in-container.sh --service <compose_service> <php_file> [more_files...]

Optional:
  --compose-dir <path>   Docker compose project directory (default: current directory)

Examples:
  scripts/php-lint-in-container.sh --container www-directory-php /var/www/html/api/sso.php
  scripts/php-lint-in-container.sh --service www-php index.php modules/dashboard.php
EOF
}

compose_dir="${PWD}"
container=""
service=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --container)
      container="${2:-}"
      shift 2
      ;;
    --service)
      service="${2:-}"
      shift 2
      ;;
    --compose-dir)
      compose_dir="${2:-}"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    --)
      shift
      break
      ;;
    *)
      break
      ;;
  esac
done

if [[ -z "$container" && -z "$service" ]]; then
  echo "[error] Provide either --container or --service."
  usage
  exit 1
fi

if [[ $# -lt 1 ]]; then
  echo "[error] Provide at least one PHP file path to lint."
  usage
  exit 1
fi

for file in "$@"; do
  echo "[lint] $file"
  if [[ -n "$container" ]]; then
    docker exec "$container" php -l "$file"
  else
    (cd "$compose_dir" && docker compose exec "$service" php -l "$file")
  fi
done

echo "[ok] PHP lint completed in container context."
