#!/usr/bin/env bash
# Run the test suite inside the app container (uses the ottodot_test database).
# Usage: ./scripts/test.sh [options, e.g. --filter=SchemaConstraintsTest]
source "$(dirname "$0")/lib.sh"
require_docker
require_running

info "Running tests against the ottodot_test database..."
app_exec php artisan test "$@"
