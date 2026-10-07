#!/usr/bin/env bash
# Stop containers. Use --purge to also delete the MySQL data volume.
source "$(dirname "$0")/lib.sh"
require_docker

if [[ "${1:-}" == "--purge" ]]; then
  info "Stopping containers and deleting database volume..."
  docker compose down -v
else
  info "Stopping containers (database data is kept)..."
  docker compose down
fi
