#!/usr/bin/env bash
# Drop all tables, re-run migrations and re-seed the demo data.
source "$(dirname "$0")/lib.sh"
require_docker
require_running

info "Resetting database and seeding demo data..."
app_exec php artisan migrate:fresh --seed --force
info "Done."
