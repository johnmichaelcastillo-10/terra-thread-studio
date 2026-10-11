#!/usr/bin/env bash
# Linux/macOS twin of bin/publish.ps1: build dist/ from the local WordPress, optionally deploy.
#
#   bin/publish.sh            # export only; preview: node bin/serve-dist.js
#   bin/publish.sh --deploy   # back up, export, deploy to Vercel (production)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
DEPLOY=0; [[ "${1:-}" == "--deploy" ]] && DEPLOY=1

STATIC_URL=""
TT_PORT=""
if [[ -f .env ]]; then
  STATIC_URL="$(grep -E '^STATIC_URL=' .env | cut -d= -f2- | tr -d '\r' || true)"
  TT_PORT="$(grep -E '^TT_PORT=' .env | cut -d= -f2- | tr -d '\r' || true)"
fi

[[ -f database/terra-thread.sqlite ]] || { echo "No local site yet: run 'docker compose up -d' (or bin/setup.sh) first."; exit 1; }

# If the Docker site is running, use it (same database and uploads); otherwise start a
# private host PHP server below.
DOCKER_UP=0
if command -v docker >/dev/null && docker compose ps --status running --services 2>/dev/null | grep -qx web; then
  DOCKER_UP=1
fi

if [[ $DOCKER_UP -eq 1 ]]; then
  docker compose exec -T -u www-data web php bin/roll-dates.php
else
  php bin/roll-dates.php
fi

if [[ $DEPLOY -eq 1 ]]; then
  dest="backups/auto-$(date +%Y-%m-%d_%H%M%S)"
  mkdir -p "$dest"
  cp database/terra-thread.sqlite "$dest/"
  [[ -d public/wp-content/uploads ]] && cp -R public/wp-content/uploads "$dest/"
  ls -1d backups/auto-* 2>/dev/null | sort -r | tail -n +11 | xargs -r rm -rf
  echo "Backed up to $dest"
fi

# Export source: the running Docker site, or a private host server just for the export.
if [[ $DOCKER_UP -eq 1 ]]; then
  SOURCE="http://localhost:${TT_PORT:-8090}"
  echo "Exporting from the Docker site at $SOURCE"
else
  SOURCE="http://localhost:8091"
  php -S "localhost:8091" -t public bin/router.php >/dev/null 2>&1 &
  SERVER=$!
  trap 'kill $SERVER 2>/dev/null || true' EXIT
fi
for _ in $(seq 1 30); do curl -fs "$SOURCE/" >/dev/null && break; sleep 1; done

args=(bin/export-static.php "--source=$SOURCE")
if [[ -n "$STATIC_URL" ]]; then
  args+=("--url=$STATIC_URL")
else
  echo "STATIC_URL is not set in .env: links will be root-relative and canonical tags relative."
fi
php "${args[@]}" || { echo "Static export failed; nothing was deployed."; exit 1; }

if [[ $DEPLOY -eq 1 ]]; then
  [[ -f .vercel/project.json ]] || { echo 'Not linked to Vercel: run `vercel link` in this folder first.'; exit 1; }
  cp -R .vercel dist/
  vercel deploy dist --prod --yes
  printf '\n\033[32mDeployed: %s\033[0m\n' "$STATIC_URL"
fi
