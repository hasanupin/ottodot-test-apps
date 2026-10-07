#!/usr/bin/env bash
# Reset demo data, then run the parallel last-seat race.
# Usage: ./scripts/race.sh [--parents=5] [--delay=500]
source "$(dirname "$0")/lib.sh"
require_docker
require_running

info "Resetting demo data (the race class starts with exactly one seat left)..."
app_exec php artisan migrate:fresh --seed --force
info "Running the race..."
app_exec php artisan race:simulate "$@"
