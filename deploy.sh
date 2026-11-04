#!/usr/bin/env bash
# Production deploy (docs/operations/production-configuration.md §9). Run it on the server, from the application root,
# after every merge into main.
#
# git carries only source code. public/build (the compiled theme: calendars, Home, launcher, every .pos-* screen),
# vendor/, node_modules/ and the caches in bootstrap/cache are built here, so a `git pull` alone leaves production
# showing the old screens, and pages added since the last `php artisan optimize` stay missing.
#
# Usage: ./deploy.sh [branch, default main] [--yes]
#   --yes  skip the backup question (only when a fresh database backup was taken: §9 step 2)
# On a failure after maintenance mode is on, the application stays down: fix the cause and run the script again.
set -euo pipefail
cd "$(dirname "$0")"

BRANCH=main
CONFIRMED=0
for arg in "$@"; do
  case "$arg" in
    --yes) CONFIRMED=1 ;;
    -*) echo "Unknown option: $arg" >&2; exit 2 ;;
    *) BRANCH="$arg" ;;
  esac
done

step() { printf '\n==> %s\n' "$*"; }

# Nothing is touched until these pass.
[ -f .env ] || { echo "No .env here: run this from the application root on the server." >&2; exit 2; }
if ! git diff --quiet || ! git diff --cached --quiet; then
  echo "The server has edits to tracked files. Make the change in the repository and merge it; discard these first:" >&2
  git status --short --untracked-files=no >&2
  exit 2
fi
command -v node > /dev/null && command -v npm > /dev/null ||
  { echo "Node.js and npm are needed to build public/build (Node 20.19+ or 22.12+)." >&2; exit 2; }
node -e 'const [a, b] = process.versions.node.split(".").map(Number); process.exit((a === 20 && b >= 19) || (a === 22 && b >= 12) || a >= 23 ? 0 : 1)' ||
  { echo "Node.js $(node -v) is too old for Vite 8: install 20.19+ or 22.12+." >&2; exit 2; }
if [ "$CONFIRMED" -ne 1 ]; then
  read -r -p "Fresh database backup taken (§9 step 2)? [y/N] " answer
  [[ "$answer" =~ ^[Yy]$ ]] || { echo "Stopped: take the backup first." >&2; exit 2; }
fi

step "Fetching origin/$BRANCH"
git fetch origin "$BRANCH"
if git rev-parse --verify --quiet "refs/heads/$BRANCH" > /dev/null &&
  ! git merge-base --is-ancestor "$BRANCH" "origin/$BRANCH"; then
  echo "The server's $BRANCH has commits that origin/$BRANCH does not: merge them in the repository first." >&2
  exit 2
fi

step "Maintenance mode"
php artisan down --retry=60
trap 'echo >&2; echo "DEPLOY FAILED at the step above. The application is still in maintenance mode." >&2; echo "Fix the cause and run ./deploy.sh again; run php artisan up only once code, assets and database match." >&2' ERR

step "Code: $BRANCH at origin/$BRANCH"
git checkout "$BRANCH"
git merge --ff-only "origin/$BRANCH"
git log -1 --format='%h %s (%cr)'

step "PHP dependencies (also publishes Filament's own assets)"
composer install --no-dev --optimize-autoloader --no-interaction

step "Front-end build: public/build"
npm ci --include=dev --ignore-scripts --no-audit --no-fund
npm run build

step "Database: migrations, then the production baseline (permissions, statutory packs)"
php artisan migrate --force --isolated
php artisan db:seed --force

step "Caches: clear the stale ones, rebuild config, routes, events, views, Filament components and icons"
php artisan optimize:clear --except=cache
php artisan optimize
php artisan queue:restart

step "Readiness (reported, does not keep the application down)"
php artisan peopleos:readiness || echo "Readiness reported FAIL lines above: review them (§9 step 8)." >&2

trap - ERR
step "Back online"
php artisan up
