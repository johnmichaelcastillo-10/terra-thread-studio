#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${PORT:-8090}"
HOST="${HOST:-localhost}"
printf '\033[33mTerra & Thread Studio -> http://%s:%s  (admin: http://%s:%s/wordpress/wp-admin/)\033[0m\n' "$HOST" "$PORT" "$HOST" "$PORT"
exec php -S "$HOST:$PORT" -t "$ROOT/public" "$ROOT/bin/router.php"
