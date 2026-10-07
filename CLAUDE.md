# CLAUDE.md — Ottodot Trial Booking

Take-home (timebox 4h): smallest working slice of a **trial class booking** system for Ottodot
(live online science/math classes for kids). Correct backend behaviour, invariants and tests
matter more than UI polish or feature breadth. Trial booking only — no regular enrollment.

## Invariants (must always hold)

- Max **4 confirmed** students per trial class.
- At most **one confirmed booking** per student per class.
- A failed payment never puts a child on the confirmed roster.
- Last-seat race (two parents competing for seat 4) ends with **at most one** confirmed booking.

## Stack (fixed — do not substitute)

- PHP 8.3 (container) + Laravel 13, MySQL 8.4 (InnoDB), PHPUnit.
- Frontend: **React 19 + TypeScript** (the only frontend), built by Vite in the `node` container (port 5173).
  Laravel serves one HTML shell (`resources/views/app.blade.php`) that mounts `resources/js/app.tsx`.
  No router or state library; pages are picked by pathname. No server-rendered pages.
- **Never SQLite** — it ignores `lockForUpdate()`, so locking would go untested.
- Everything runs in Docker. Host PHP/Composer are **not** used. Do not install Laravel Boost
  or host PHP (Laravel's template AGENTS.md suggesting that was deliberately removed).

## Commands

| Instead of | Use |
|---|---|
| `php artisan <cmd>` | `./scripts/artisan.sh <cmd>` |
| `php artisan test` | `./scripts/test.sh` |
| `php artisan migrate:fresh --seed` | `./scripts/reset.sh` |
| `composer <cmd>` | `docker compose exec app composer <cmd>` |
| `npm <cmd>` | `docker compose exec node npm <cmd>` (never host npm: `node_modules` has Linux binaries) |
| type-check TS | `docker compose exec node npm run typecheck` |

`./setup.sh` = build + start + migrate + seed. `./scripts/down.sh [--purge]` = stop (purge deletes DB data).
Tests always run against the `ottodot_test` database (forced in `phpunit.xml`); the demo DB is `ottodot`.
`composer.json` pins `config.platform.php` to 8.3.0 to match the container — keep it.

Auth: logins are `users` rows with `role` (`parent` | `teacher` | `admin`) plus a nullable FK `parent_id` / `teacher_id`
(`chk_users_role` keeps them consistent; no `admins` table). `POST /api/login` (throttled 5/min) starts a **Sanctum SPA
cookie session** on the `web` guard — no API tokens. `/` needs auth (guests → `/login`), `/login` is guest-only (→ `/`).
Booking API routes sit behind `auth:sanctum` (+ `role:` middleware); the parent comes from the session user, never from the
request. No "Acting as parent" switcher: log in as another parent (second browser / incognito window for the race demo).
Seeded accounts use password `password`.

API code structure: FormRequest validates → Service does the work → thin controller returns the base `Controller`'s
`success()` (`{success, message, data}`) or `failed()` (`{success: false, message, errors}`). API exceptions render through
`Controller::failed()` in `bootstrap/app.php`.

## Booking design (two modes, `BOOKING_MODE` in `.env`, `config/booking.php`)

The payment gateway is an external vendor: it reports success/decline; the app decides what that means per mode.

- **`hold` (default).** Creating a booking holds the seat for `BOOKING_HOLD_MINUTES` (default 1). Seats in use =
  `CONFIRMED` + unexpired `PENDING_PAYMENT`, so the race is decided at booking time (loser gets `class_full`, never
  charged). Unpaid holds become `EXPIRED` lazily under the class lock (no scheduler); hold end = `created_at + minutes`.
- **`first_to_pay`.** Creating a booking reserves nothing; seats in use = `CONFIRMED` only.

`pay()` is the same three phases in both modes: (1) locked pre-check: expired hold / class full → reject without
charging; (2) charge outside any DB lock; (3) locked final check: seat free → confirm, else refund + `FAILED_CLASS_FULL`
(in hold mode only reachable when the charge outlasted the hold). No seat counter column.
Tests run `first_to_pay` (forced in `phpunit.xml`); `SeatHoldTest` switches to hold itself.

## Canonical vocabulary

Status values are **UPPER_SNAKE_CASE** (enum case names stay PascalCase). `status` columns use `utf8mb4_bin` so
the CHECK constraints are case-sensitive (MySQL's default collation would accept `'confirmed'`).

Booking statuses: `PENDING_PAYMENT`, `CONFIRMED`, `PAYMENT_FAILED`, `FAILED_CLASS_FULL`, `CANCELLED`, `EXPIRED`
(hold mode only). Only `PENDING_PAYMENT` can change (plus `EXPIRED` → confirmed/refunded when its own payment was
already in flight); the rest are final.
Payment attempt statuses: `PENDING`, `SUCCEEDED`, `FAILED`, `REFUNDED` (refund = own row, key `<original_key>:refund`).
Stay lowercase: log event names (`booking.refunded`), `failure_reason` values (`card_declined`, `class_full`).

`parent` is reserved in PHP → the model is **`Guardian`** with `$table = 'parents'`; FKs stay `parent_id`.

## Workflow rules

1. Work on one task at a time; never start the next one early.
2. Use the exact table/column/status/class names already in the code.
3. Write the check first, see it fail, then implement until it passes (TDD).
4. Run the verification commands and report the output.
5. Commit with a clear message, then push to `origin/main`.
6. Stop and report after each task; wait for approval before the next.
7. Ask instead of guessing when something is ambiguous.
8. Never weaken a test or constraint to make something pass; fix the code or report the problem.
9. Synthetic data only (`@example.com` emails, invented names). Never real personal data.
