#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="${GRADERAI_PROJECT_DIR:-$SCRIPT_DIR}"
cd "$PROJECT_DIR"

for executable in php composer node npm; do
    command -v "$executable" >/dev/null || { echo "Missing requirement: $executable" >&2; exit 1; }
done
test -f .env || { echo 'Configure .env before deployment.' >&2; exit 1; }

# Operate only on the revision already reviewed and checked out by the operator.
npm ci
npm run build
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
php tools/check-nette-licenses.php
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
php artisan schedule:interrupt
echo 'Application prepared. Verify PHP-FPM, queue supervision, scheduler and /up.'
