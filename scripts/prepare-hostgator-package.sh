#!/usr/bin/env bash
# Builds the two HostGator deployment archives.
#
# Usage: prepare-hostgator-package.sh <api-url> [output-dir]
#   <api-url>     public origin of the deployed API, e.g. https://api.yourdomain.com (no /api, no trailing slash)
#   [output-dir]  where the zips and checklist go (default: <repo>/artifacts)
#
# Environment:
#   SKIP_WEB_BUILD=1          reuse web/dist (it must already be built for <api-url>)
#   COMPOSER=/path/composer   composer executable or composer.phar (default: composer on PATH)
#   IGNORE_PLATFORM_REQS=1    pass --ignore-platform-reqs to composer
#
# Output:
#   nexus-api.zip  Laravel API, `composer install --no-dev --optimize-autoloader`, built from an allow-list.
#                  Extract OUTSIDE the web root; the API subdomain's document root is its public/ folder.
#   nexus-web.zip  Static React build for the website's document root.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND_DIR="$ROOT_DIR/backend"
WEB_DIR="$ROOT_DIR/web"

API_URL="${1:-}"
OUTPUT_DIR="${2:-$ROOT_DIR/artifacts}"

if [ -z "$API_URL" ]; then
  echo "Usage: $0 <api-url> [output-dir]  (for example https://api.yourdomain.com)" >&2
  exit 1
fi
API_URL="${API_URL%/}"
if ! printf '%s' "$API_URL" | grep -Eiq '^https://[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:[0-9]+)?$'; then
  echo "The API URL must be an https:// origin without a path (got '$API_URL'). Do not add /api." >&2
  exit 1
fi
if printf '%s' "$API_URL" | grep -Eiq '://(localhost|127\.0\.0\.1|([^/]*\.)?example\.(com|org|net))(:[0-9]+)?$'; then
  echo "The API URL '$API_URL' is a local or placeholder address and cannot be used for a HostGator build." >&2
  exit 1
fi
[ -d "$BACKEND_DIR" ] || { echo "Backend directory not found: $BACKEND_DIR" >&2; exit 1; }
[ -d "$WEB_DIR" ] || { echo "Web directory not found: $WEB_DIR" >&2; exit 1; }

mkdir -p "$OUTPUT_DIR"
OUTPUT_DIR="$(cd "$OUTPUT_DIR" && pwd)"
STAGING="$OUTPUT_DIR/staging"
API_STAGE="$STAGING/api"
WEB_STAGE="$STAGING/web"
API_ZIP="$OUTPUT_DIR/nexus-api.zip"
WEB_ZIP="$OUTPUT_DIR/nexus-web.zip"
CHECKLIST="$OUTPUT_DIR/PACKAGE-CHECKLIST.txt"

# ---- tool checks (fail before any slow work) -------------------------------------------------
command -v php >/dev/null 2>&1 || { echo "php was not found on PATH. Composer needs PHP 8.2+." >&2; exit 1; }
command -v zip >/dev/null 2>&1 || { echo "zip was not found on PATH." >&2; exit 1; }
COMPOSER_CMD="${COMPOSER:-composer}"
if [ "${COMPOSER_CMD##*.}" = "phar" ]; then
  COMPOSER_RUN=(php "$COMPOSER_CMD")
else
  command -v "$COMPOSER_CMD" >/dev/null 2>&1 || {
    echo "Composer was not found. Install it from https://getcomposer.org or set COMPOSER=/path/to/composer(.phar)." >&2
    exit 1
  }
  COMPOSER_RUN=("$COMPOSER_CMD")
fi

# A failed run must never leave stale archives behind.
rm -rf "$STAGING" "$API_ZIP" "$WEB_ZIP" "$CHECKLIST"
mkdir -p "$API_STAGE" "$WEB_STAGE"

REPORT="$(mktemp)"
trap 'rm -f "$REPORT"' EXIT
say() { printf '%s\n' "$*" | tee -a "$REPORT"; }

# ---- 1. API: allow-list copy ----------------------------------------------------------------
echo "Staging the API from the allow-list..."
for dir in app config database/migrations public routes; do
  [ -d "$BACKEND_DIR/$dir" ] || { echo "Allow-listed backend folder is missing: backend/$dir" >&2; exit 1; }
  mkdir -p "$API_STAGE/$(dirname "$dir")"
  cp -R "$BACKEND_DIR/$dir" "$API_STAGE/$(dirname "$dir")/"
done
for file in artisan composer.json composer.lock .env.example bootstrap/app.php bootstrap/providers.php; do
  [ -f "$BACKEND_DIR/$file" ] || { echo "Allow-listed backend file is missing: backend/$file" >&2; exit 1; }
  mkdir -p "$API_STAGE/$(dirname "$file")"
  cp "$BACKEND_DIR/$file" "$API_STAGE/$file"
done
rm -rf "$API_STAGE/public/hot" "$API_STAGE/public/storage"
# Empty folders Laravel writes to at runtime (contents are never shipped).
for dir in bootstrap/cache storage/app storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs; do
  mkdir -p "$API_STAGE/$dir"
  printf '*\n!.gitignore\n' > "$API_STAGE/$dir/.gitignore"
done

# ---- 2. API: production-only dependencies and a fresh bootstrap cache -------------------------
echo "Installing production dependencies (composer install --no-dev --optimize-autoloader)..."
# The local cache can reference packages that will not exist on the server. Start from nothing.
rm -f "$API_STAGE"/bootstrap/cache/*.php
INSTALL_ARGS=(install --no-dev --optimize-autoloader --no-interaction --prefer-dist)
[ "${IGNORE_PLATFORM_REQS:-0}" = "1" ] && INSTALL_ARGS+=(--ignore-platform-reqs)
(
  cd "$API_STAGE"
  "${COMPOSER_RUN[@]}" "${INSTALL_ARGS[@]}"
  if [ ! -f bootstrap/cache/packages.php ] || [ ! -f bootstrap/cache/services.php ]; then
    php artisan package:discover --ansi
  fi
)
for cache_file in packages.php services.php; do
  [ -f "$API_STAGE/bootstrap/cache/$cache_file" ] || { echo "bootstrap/cache/$cache_file was not regenerated." >&2; exit 1; }
  if grep -Eiq 'phpunit|mockery|collision|laravel/pail|laravel/sail' "$API_STAGE/bootstrap/cache/$cache_file"; then
    echo "bootstrap/cache/$cache_file still references a dev-only package." >&2
    exit 1
  fi
done

# ---- 3. Web: build against the real API URL and verify the bundle ---------------------------
if [ "${SKIP_WEB_BUILD:-0}" != "1" ]; then
  echo "Building the static web app against $API_URL ..."
  (cd "$WEB_DIR" && npm ci && VITE_API_BASE_URL="$API_URL" npm run build)
fi
[ -d "$WEB_DIR/dist/assets" ] || { echo "Static web build not found at $WEB_DIR/dist; build the front end first." >&2; exit 1; }
if grep -rqE '127\.0\.0\.1:8000|localhost:8000|api\.example\.com' --include='*.js' "$WEB_DIR/dist/assets"; then
  echo "The web build contains a local or placeholder API address. Rebuild without SKIP_WEB_BUILD." >&2
  exit 1
fi
if ! grep -rqF --include='*.js' "$API_URL" "$WEB_DIR/dist/assets"; then
  echo "The web build does not contain $API_URL. It was built for a different API; rebuild without SKIP_WEB_BUILD." >&2
  exit 1
fi

cp -R "$WEB_DIR/dist"/. "$WEB_STAGE/"
# Netlify-only redirect file and source maps are of no use on Apache.
find "$WEB_STAGE" -type f \( -name '_redirects' -o -name '*.map' \) -delete

HTACCESS="$WEB_STAGE/.htaccess"
[ -f "$HTACCESS" ] || { echo "web/dist/.htaccess is missing; the SPA fallback, HTTPS redirect and security headers would not apply." >&2; exit 1; }
# Narrow the Content-Security-Policy to the API origin this build talks to.
if grep -qF "connect-src 'self' https:" "$HTACCESS"; then
  ESCAPED_URL="$(printf '%s' "$API_URL" | sed -e 's/[&|\\]/\\&/g')"
  if [ -n "${AI_WORKER_URL:-}" ]; then
    ESCAPED_WORKER="$(printf '%s' "$AI_WORKER_URL" | sed -e 's/[&|\\]/\\&/g')"
    sed -i.bak "s|connect-src 'self' https:|connect-src 'self' $ESCAPED_URL $ESCAPED_WORKER|" "$HTACCESS"
  else
    sed -i.bak "s|connect-src 'self' https:|connect-src 'self' $ESCAPED_URL|" "$HTACCESS"
  fi
  rm -f "$HTACCESS.bak"
else
  echo "Warning: could not narrow connect-src in .htaccess; it keeps the generic https: rule." >&2
fi

# ---- 4. Verify exactly what will be zipped, then zip ----------------------------------------
API_LIST="$STAGING/api.list"
WEB_LIST="$STAGING/web.list"
(cd "$API_STAGE" && find . -type f | sed 's|^\./||' | LC_ALL=C sort) > "$API_LIST"
(cd "$WEB_STAGE" && find . -type f | sed 's|^\./||' | LC_ALL=C sort) > "$WEB_LIST"

PROBLEMS=0
# forbid <list> <label> <ERE> [<ERE of allowed exceptions>]
forbid() {
  local list="$1" label="$2" pattern="$3" allow="${4:-}" hits
  hits="$(grep -E -- "$pattern" "$list" || true)"
  if [ -n "$allow" ] && [ -n "$hits" ]; then hits="$(printf '%s\n' "$hits" | grep -Ev -- "$allow" || true)"; fi
  if [ -z "$hits" ]; then
    say "  [OK]   absent: $label"
  else
    PROBLEMS=$((PROBLEMS + 1))
    say "  [FAIL] FOUND:  $label"
    printf '%s\n' "$hits" | head -5 | sed 's/^/           /' | tee -a "$REPORT"
  fi
}
require() {
  local list="$1" item="$2"
  if grep -qxF -- "$item" "$list"; then say "  [OK]   present: $item"
  else PROBLEMS=$((PROBLEMS + 1)); say "  [FAIL] MISSING: $item"; fi
}

say ""
say "==================== PACKAGE CHECKLIST ===================="
say "Built $(date '+%Y-%m-%d %H:%M') for API $API_URL"
say ""
say "API archive - files that must NOT be in the archive:"
forbid "$API_LIST" "backend/.env (real keys and passwords)" '(^|/)\.env$' '^vendor/'
forbid "$API_LIST" "other .env.* variants (only .env.example is allowed)" '(^|/)\.env\.' '^vendor/|(^|/)\.env\.example$'
forbid "$API_LIST" "storage/private/* (student papers and uploads)" '^storage/private/'
forbid "$API_LIST" "storage/logs/*" '^storage/logs/' '^storage/logs/\.gitignore$'
forbid "$API_LIST" "storage/framework/testing" '^storage/framework/testing/'
forbid "$API_LIST" "storage/framework cache, sessions and compiled views" '^storage/framework/(cache/data|sessions|views)/' '/\.gitignore$'
forbid "$API_LIST" ".phpunit.result.cache" '(^|/)\.phpunit\.result\.cache$'
forbid "$API_LIST" "route-list-folders.json" '(^|/)route-list-folders\.json$'
forbid "$API_LIST" ".freebuff" '(^|/)\.freebuff(/|$)'
forbid "$API_LIST" ".git" '(^|/)\.git(/|$)'
forbid "$API_LIST" "tests/" '^tests/'
forbid "$API_LIST" "phpunit.xml" '(^|/)phpunit\.xml(\.dist)?$'
forbid "$API_LIST" "dev packages (vendor/phpunit, vendor/mockery, vendor/hamcrest)" '^vendor/(phpunit|mockery|hamcrest)/'
forbid "$API_LIST" "public/hot and public/storage" '^public/(hot|storage)(/|$)'
say ""
say "API archive - files that MUST be in the archive:"
for item in artisan composer.json composer.lock .env.example public/index.php public/.htaccess \
            bootstrap/app.php bootstrap/providers.php bootstrap/cache/packages.php bootstrap/cache/services.php \
            vendor/autoload.php vendor/laravel/framework/composer.json; do
  require "$API_LIST" "$item"
done
say ""
say "Web archive - files that must NOT be in the archive:"
forbid "$WEB_LIST" "source maps (*.map)" '\.map$'
forbid "$WEB_LIST" "_redirects (Netlify only)" '(^|/)_redirects$'
forbid "$WEB_LIST" ".env files" '(^|/)\.env'
forbid "$WEB_LIST" "node_modules" '(^|/)node_modules/'
say ""
say "Web archive - files that MUST be in the archive:"
require "$WEB_LIST" "index.html"
require "$WEB_LIST" ".htaccess"

if [ "$PROBLEMS" -gt 0 ]; then
  cp "$REPORT" "$STAGING/FAILED-CHECKLIST.txt"
  echo "$PROBLEMS packaging check(s) failed (see above). No archives were created." >&2
  exit 1
fi

# Zip exactly the files that were checked (entry names use "/").
(cd "$API_STAGE" && zip -q -X "$API_ZIP" -@ < "$API_LIST")
(cd "$WEB_STAGE" && zip -q -X "$WEB_ZIP" -@ < "$WEB_LIST")

say ""
say "Created:"
say "  $API_ZIP ($(du -h "$API_ZIP" | cut -f1), $(wc -l < "$API_LIST" | tr -d ' ') files)"
say "  $WEB_ZIP ($(du -h "$WEB_ZIP" | cut -f1), $(wc -l < "$WEB_LIST" | tr -d ' ') files)"
say ""
say "Next steps:"
say "  1. Extract nexus-api.zip OUTSIDE any web root, for example /home/ACCOUNT/nexus-api, and point the API subdomain document root at nexus-api/public."
say "  2. Extract nexus-web.zip into the static site document root (for example public_html)."
say "  3. Create the production .env on the server from .env.example (never reuse the local one): APP_ENV=production, APP_DEBUG=false, new APP_KEY,"
say "     new JWT_* and STORAGE_SIGNING_SECRET, real DB_*, PRIVATE_STORAGE_PATH=/home/ACCOUNT/private/nexus and CORS_ALLOWED_ORIGINS set to the static site's exact https origin."
say "  4. Follow docs/HOSTGATOR-DEPLOYMENT.md for the database import, admin creation and the 15-minute cron."

cp "$REPORT" "$CHECKLIST"
rm -rf "$STAGING"
printf '\nChecklist saved to %s\n' "$CHECKLIST"
