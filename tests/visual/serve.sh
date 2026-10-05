#!/usr/bin/env bash
# UX.18 visual regression: serve the frozen-clock showcase. The array cache keeps the login limiter per request
# (a frozen clock would otherwise never let its window pass).
set -euo pipefail
cd "$(dirname "$0")/../.."
export DB_DATABASE="${VISUAL_DB:-hcm_ux_visual_showcase}"
export PEOPLEOS_VISUAL_FROZEN_NOW="${PEOPLEOS_VISUAL_FROZEN_NOW:-2026-10-05 09:00:00}"
export CACHE_STORE=array
# The session cookie's expiry is stamped from the frozen clock, but browsers judge it by the real time: with the normal
# 120-minute lifetime it was already expired a few hours after the frozen moment, and every later run failed at sign-in.
# Browser-session cookies (no expiry date) and a very long server-side lifetime keep the frozen sessions valid.
export SESSION_EXPIRE_ON_CLOSE=true
export SESSION_LIFETIME=10512000
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"
exec php artisan serve --host=127.0.0.1 --port="${VISUAL_PORT:-8092}"
