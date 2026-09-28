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

- PHP 8.3 (container) + Laravel 13, MySQL 8.4 (InnoDB), PHPUnit, jQuery + Blade (later).
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

`./setup.sh` = build + start + migrate + seed. `./scripts/down.sh [--purge]` = stop (purge deletes DB data).
Tests always run against the `ottodot_test` database (forced in `phpunit.xml`); the demo DB is `ottodot`.
`composer.json` pins `config.platform.php` to 8.3.0 to match the container — keep it.

## Canonical vocabulary

Booking statuses: `pending_payment`, `confirmed`, `payment_failed`, `expired`, `failed_class_full`, `cancelled`.
Payment attempt statuses: `succeeded`, `failed`, `refunded`.

`parent` is reserved in PHP → the model is **`Guardian`** with `$table = 'parents'`; FKs stay `parent_id`.

## Workflow rules

1. Step files live in `ai-steps/` (local only, gitignored). Execute **one step at a time, in order**; never implement a later step early.
2. Use the exact table/column/status/class names from the step files.
3. Write the step's check first, see it fail, then implement until it passes (TDD).
4. Run the step's verification commands and report the output.
5. **Append an entry to `notes.md`** (gitignored work log) after every step: what was done, commands, results, decisions, issues.
6. Commit with the step's commit message, then push to `origin/main`.
7. Stop and report after each step; wait for approval before the next.
8. Ask instead of guessing when something is ambiguous.
9. Synthetic data only (`@example.com` emails, invented names). Never real personal data.
