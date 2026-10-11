#!/usr/bin/env bash
# Downloads WordPress core + the SQLite Database Integration plugin, installs the
# drop-in, then installs and seeds the site. Safe to re-run.
#
#   bin/setup.sh            # install (idempotent)
#   bin/setup.sh --fresh    # wipe the database and reseed
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CACHE="$ROOT/.cache"
PUBLIC="$ROOT/public"
PLUGINS="$PUBLIC/wp-content/plugins"
WP_VERSION="${TT_WP_VERSION:-latest}"
SQLITE_VERSION="${TT_SQLITE_VERSION:-3.1.0}"
export TT_URL="${TT_URL:-http://localhost:8090}"
FRESH=0; [[ "${1:-}" == "--fresh" ]] && FRESH=1

step() { printf '\n\033[33m==> %s\033[0m\n' "$1"; }

step "Checking PHP"
command -v php >/dev/null || { echo "PHP 8.1+ is required on PATH."; exit 1; }
command -v unzip >/dev/null || { echo "unzip is required."; exit 1; }
for m in pdo_sqlite mbstring openssl; do
  php -m | grep -qx "$m" || { echo "PHP extension '$m' is not enabled."; exit 1; }
done
php -r "exit(version_compare(PHP_VERSION, '8.1', '>=') ? 0 : 1);" || { echo "PHP 8.1+ required."; exit 1; }

mkdir -p "$CACHE" "$ROOT/database" "$ROOT/logs" "$ROOT/config"

step "WordPress core ($WP_VERSION)"
if [[ ! -f "$PUBLIC/wordpress/wp-settings.php" ]]; then
  zip="$CACHE/wordpress-$WP_VERSION.zip"
  url="https://wordpress.org/wordpress-$WP_VERSION.zip"; [[ "$WP_VERSION" == "latest" ]] && url="https://wordpress.org/latest.zip"
  [[ -f "$zip" ]] || curl -fsSL "$url" -o "$zip"
  unzip -q -o "$zip" -d "$PUBLIC"
  rm -rf "$PUBLIC/wordpress/wp-content"   # unused: WP_CONTENT_DIR is public/wp-content
else echo "    already present"; fi

step "SQLite Database Integration ($SQLITE_VERSION)"
if [[ ! -f "$PLUGINS/sqlite-database-integration/load.php" ]]; then
  zip="$CACHE/sqlite-database-integration-$SQLITE_VERSION.zip"
  url="https://downloads.wordpress.org/plugin/sqlite-database-integration.$SQLITE_VERSION.zip"
  [[ "$SQLITE_VERSION" == "latest" ]] && url="https://downloads.wordpress.org/plugin/sqlite-database-integration.zip"
  [[ -f "$zip" ]] || curl -fsSL "$url" -o "$zip"
  unzip -q -o "$zip" -d "$PLUGINS"
else echo "    already present"; fi

step "Installing db.php drop-in"
php "$ROOT/bin/dropin.php"

if [[ $FRESH -eq 1 ]]; then
  step "Fresh install requested: removing database and uploads"
  rm -f "$ROOT"/database/*.sqlite*
  rm -rf "$PUBLIC/wp-content/uploads"
fi

step "Installing WordPress and seeding studio data"
php "$ROOT/bin/install.php"

printf '\n\033[32mDone. Start the server with:\033[0m\n    bin/serve.sh\n'
