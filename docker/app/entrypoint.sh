#!/usr/bin/env sh
set -e
cd /var/www/html

if [ ! -f .env ]; then
  echo "[entrypoint] Creating .env from .env.example"
  cp .env.example .env
fi

if [ ! -f vendor/autoload.php ]; then
  echo "[entrypoint] Installing Composer dependencies"
  composer install --no-interaction --prefer-dist
fi

if ! grep -q '^APP_KEY=base64:' .env; then
  echo "[entrypoint] Generating APP_KEY"
  php artisan key:generate --force
fi

exec "$@"
