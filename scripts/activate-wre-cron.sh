#!/usr/bin/env bash
set -euo pipefail

if ! command -v wp >/dev/null 2>&1; then
  echo "wp command not found. Please install WP-CLI before running this script." >&2
  exit 1
fi

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")"/.. && pwd)"
cd "$ROOT_DIR"

# Remove any existing events to guarantee the new interval takes effect.
wp cron event unschedule wre_cron_run_tasks --all >/dev/null 2>&1 || true

next_run=$(($(date +%s) + 60))
wp cron event schedule "$next_run" wre_cron_run_tasks --schedule=wre_four_hours

echo "WRE cron task refreshed on the wre_four_hours schedule (next run: ${next_run})."
