#!/usr/bin/env bash
#
# Switch the Laravel app between PRODUCTION and TESTING environments by swapping
# the active .env file, then rebuilding the cached config/routes.
#
# Setup (one time, on the server):
#   cp .env .env.production          # current live config
#   cp .env .env.testing             # then edit the testing toggles (see ENVIRONMENTS.md)
#
# Usage:
#   ./switch-env.sh prod     # go live
#   ./switch-env.sh test     # go to testing/sandbox
#   ./switch-env.sh status   # show the active environment
#
# PHP binary: override on hosts where `php` is not 8.4, e.g.
#   PHP_BIN=/opt/alt/php84/usr/bin/php ./switch-env.sh prod
#
set -euo pipefail

PHP="${PHP_BIN:-php}"
DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$DIR"

show_status() {
    if [ -f .env ]; then
        echo "Active: $(grep -E '^APP_ENV=' .env || echo 'APP_ENV not set')"
    else
        echo "No .env present."
    fi
}

case "${1:-}" in
    prod|production) SRC=".env.production"; LABEL="PRODUCTION" ;;
    test|testing)    SRC=".env.testing";    LABEL="TESTING"    ;;
    status)          show_status; exit 0 ;;
    *) echo "Usage: $0 {prod|test|status}"; exit 1 ;;
esac

if [ ! -f "$SRC" ]; then
    echo "✗ $SRC not found. Create it first (see header / ENVIRONMENTS.md)."
    exit 1
fi

# Back up the current .env before overwriting (safety net).
[ -f .env ] && cp .env .env.backup

cp "$SRC" .env
"$PHP" artisan config:clear  >/dev/null
"$PHP" artisan config:cache  >/dev/null
"$PHP" artisan route:cache   >/dev/null

echo "✓ Switched to $LABEL"
show_status
