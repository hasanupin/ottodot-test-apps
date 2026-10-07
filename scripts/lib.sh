#!/usr/bin/env bash
# Shared helpers for the project scripts. Sourced, not executed directly.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

# Run the app container as the host user so files it creates (vendor/, storage/) stay editable.
HOST_UID="$(id -u)"
HOST_GID="$(id -g)"
export HOST_UID HOST_GID

info() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
fail() { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

require_docker() {
  command -v docker >/dev/null 2>&1 || fail "Docker is not installed: https://docs.docker.com/get-docker/"
  docker info >/dev/null 2>&1 || fail "Docker is not running. Start Docker and try again."
  docker compose version >/dev/null 2>&1 || fail "Docker Compose v2 is required (the 'docker compose' command)."
}

require_running() {
  docker compose ps --status running --services 2>/dev/null | grep -qx app \
    || fail "The app is not running. Run ./setup.sh first."
}

# Run a command in the app container; allocate a TTY only when interactive.
app_exec() {
  if [ -t 0 ] && [ -t 1 ]; then
    docker compose exec app "$@"
  else
    docker compose exec -T app "$@"
  fi
}
