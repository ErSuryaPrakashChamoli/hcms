#!/usr/bin/env bash
# UX.18 visual regression: rebuild the disposable showcase at the frozen moment (fictional people only).
# The database must exist and its name must end in _visual_showcase (the frozen clock and the seeders insist on it).
set -euo pipefail
cd "$(dirname "$0")/../.."
export DB_DATABASE="${VISUAL_DB:-hcm_ux_visual_showcase}"
export PEOPLEOS_VISUAL_FROZEN_NOW="${PEOPLEOS_VISUAL_FROZEN_NOW:-2026-10-05 09:00:00}"
php artisan migrate:fresh --force --quiet
php artisan db:seed --class=UxShowcaseSeeder --force --quiet
echo "Visual showcase ready: ${DB_DATABASE} at ${PEOPLEOS_VISUAL_FROZEN_NOW}"
