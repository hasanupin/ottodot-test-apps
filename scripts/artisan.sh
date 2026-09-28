#!/usr/bin/env bash
# Run any artisan command inside the app container.
# Usage: ./scripts/artisan.sh <command> [args]   e.g. ./scripts/artisan.sh tinker
source "$(dirname "$0")/lib.sh"
require_docker
require_running

app_exec php artisan "$@"
