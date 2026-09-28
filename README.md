# Ottodot Trial Booking

## Quick start

Requirements: Docker with Docker Compose v2. Nothing else (PHP, Composer, Node and MySQL run in containers).

```bash
./setup.sh              # build, start, migrate and seed demo data
./scripts/test.sh       # run the test suite
./scripts/reset.sh      # reset the demo data
./scripts/down.sh       # stop (add --purge to delete DB data)
```

App: http://localhost:8000 · React login: http://localhost:8000/login (demo: `parent@example.com` / `password`)
Vite dev server (assets + hot reload): http://localhost:5173 · MySQL (for GUI tools): localhost:3307, user `ottodot` / `secret`

Port already in use? Run `APP_PORT=8080 ./setup.sh` or `DB_HOST_PORT=3308 ./setup.sh`.
Windows: run the scripts from WSL or Git Bash.

## Running on a new machine

You need **Git** and **Docker** (Docker Desktop, or Docker Engine with the Compose v2 plugin). Check with
`docker compose version`. PHP, Composer, Node and MySQL are **not** needed on your machine. Tested on macOS; the
images are multi-arch, so Apple Silicon and Linux (x86/ARM) work too.

```bash
git clone https://github.com/hasanupin/ottodot-test-apps.git
cd ottodot-test-apps
./setup.sh
```

On the first run, `setup.sh` does everything automatically:

1. Builds the app image (PHP 8.3 CLI + `pdo_mysql`, `intl`, Composer).
2. Starts MySQL 8.4, which creates the demo database `ottodot` and the test database `ottodot_test`.
   Starts a Node 24 container that runs `npm install` and the Vite dev server for the React + TypeScript frontend.
3. Inside the app container: creates `.env` from `.env.example`, runs `composer install` and generates `APP_KEY`.
4. Waits until all containers (app, mysql, node) are healthy, then runs `migrate:fresh --seed`.

The first run takes a few minutes (image build + Composer and npm downloads). Later runs are quick. Re-running
`./setup.sh` resets the demo data.

Other commands: `./scripts/artisan.sh <command>` runs any artisan command in the container.
Frontend: `docker compose exec node npm run typecheck` (TypeScript check), `docker compose exec node npm install <pkg>`.
Do not run `npm` on the host: `node_modules` is installed inside Linux.

### Troubleshooting

| Problem | Fix |
|---|---|
| `port is already allocated` | `APP_PORT=8080 ./setup.sh` and/or `DB_HOST_PORT=3308 ./setup.sh` |
| `Unknown database 'ottodot_test'` / MySQL never healthy | The init script only runs on a fresh volume: `./scripts/down.sh --purge && ./setup.sh` |
| Port 5173 in use (another Vite app) | Stop the other dev server; the React assets are served from localhost:5173 |
| `Docker is not running` | Start Docker Desktop (or the Docker daemon) and retry |
| `Permission denied: ./setup.sh` | `chmod +x setup.sh scripts/*.sh docker/app/entrypoint.sh` |
| Anything else during setup | `docker compose logs app mysql node` |

The app container runs as your host user (UID/GID), so `vendor/` and `storage/` stay editable from your editor.
Tests always use the `ottodot_test` database (forced in `phpunit.xml`), so running them never wipes the demo data.
