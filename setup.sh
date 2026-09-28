#!/usr/bin/env bash
# One-command setup: builds containers, installs dependencies, migrates and seeds demo data.
# Usage: ./setup.sh [--with-tests]
source "$(dirname "$0")/scripts/lib.sh"
trap 'fail "Setup failed. Inspect logs with: docker compose logs app mysql"' ERR

require_docker

if [ ! -f .env ]; then
  info "Creating .env from .env.example"
  cp .env.example .env
fi

info "Building and starting containers (first run can take a few minutes)..."
docker compose up -d --build --wait

info "Resetting database and seeding demo data..."
app_exec php artisan migrate:fresh --seed --force

if [[ "${1:-}" == "--with-tests" ]]; then
  info "Running test suite..."
  app_exec php artisan test
fi

cat <<MSG

Setup complete.

  App:          http://localhost:${APP_PORT:-8000}
  Run tests:    ./scripts/test.sh
  Reset demo:   ./scripts/reset.sh
  Artisan:      ./scripts/artisan.sh <command>
  Stop:         ./scripts/down.sh          (add --purge to delete DB data)

MSG
