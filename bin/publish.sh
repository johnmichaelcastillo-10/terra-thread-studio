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
if [[ -f .env ]]; then
  STATIC_URL="$(grep -E '^STATIC_URL=' .env | cut -d= -f2- | tr -d '\r' || true)"
fi

[[ -f database/terra-thread.sqlite ]] || { echo "No local site yet: run bin/setup.sh first."; exit 1; }

php bin/roll-dates.php

if [[ $DEPLOY -eq 1 ]]; then
  dest="backups/auto-$(date +%Y-%m-%d_%H%M%S)"
  mkdir -p "$dest"
  cp database/terra-thread.sqlite "$dest/"
  [[ -d public/wp-content/uploads ]] && cp -R public/wp-content/uploads "$dest/"
  ls -1d backups/auto-* 2>/dev/null | sort -r | tail -n +11 | xargs -r rm -rf
  echo "Backed up to $dest"
fi

# A private server just for the export.
PORT=8091
php -S "localhost:$PORT" -t public bin/router.php >/dev/null 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT
for _ in $(seq 1 30); do curl -fs "http://localhost:$PORT/" >/dev/null && break; sleep 1; done

args=(bin/export-static.php "--source=http://localhost:$PORT")
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
