# Ottodot Trial Booking

A small, working slice of a **trial class booking** system for live online science and math classes for kids.
Parents log in, pick a child and a trial class, pay through a mock gateway and see the outcome; teachers and admins
see the confirmed roster; admins manage classes, teachers, parents and students. The focus is correct backend
behaviour under concurrency: a class never gets more than 4 confirmed students, a child is never confirmed twice in
the same class, a failed payment never reaches the roster, and two parents racing for the last seat end with at most
one confirmed booking. Laravel 13 (PHP 8.3) + MySQL 8.4 + React 19/TypeScript, all in Docker.

> **For reviewers, the fastest path (about 3 minutes):** run `./setup.sh`, then open
> http://localhost:8000 in a normal window (log in as `maya.tan@example.com`) and an incognito window
> (`priya.nair@example.com`), password `password`. Both pick **Math Explorers: Fractions**, which has one
> seat left. With the default `BOOKING_MODE=hold`, whoever clicks **Book** first holds the seat for a minute and the
> other parent sees **Full** (see [Booking mode](#booking-mode-env) for `first_to_pay`). Then run
> `./scripts/race.sh` to see five parents race for that seat in parallel; it resets the demo data first, so any
> bookings made in the browser are wiped.

## Quick start

Requirements: Docker with Docker Compose v2. Nothing else (PHP, Composer, Node and MySQL run in containers).

```bash
./setup.sh              # build, start, migrate and seed demo data
./scripts/test.sh       # run the test suite
./scripts/reset.sh      # reset the demo data
./scripts/race.sh       # reset demo data, then run the parallel last-seat race
./scripts/down.sh       # stop (add --purge to delete DB data)
```

App: http://localhost:8000 (guests are sent to `/login`). Log in with any account from [Demo data](#demo-data)
(password `password` for all).
Vite dev server (assets + hot reload): http://localhost:5173 · MySQL (for GUI tools): localhost:3307, user `ottodot` / `secret`

Port already in use? Run `APP_PORT=8080 ./setup.sh` or `DB_HOST_PORT=3308 ./setup.sh`.
Windows: run the scripts from WSL or Git Bash.

### Running on a new machine

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

### Booking mode (`.env`)

The last-seat behaviour is configurable (see [Last-seat race](#last-seat-race-approach-why-trade-offs)).

| Setting | Values | Effect |
|---|---|---|
| `BOOKING_MODE` | `hold` (default) | Creating a booking holds the seat for `BOOKING_HOLD_MINUTES`. Other parents see "Full" until the hold is paid or expires. |
| | `first_to_pay` | Creating a booking holds nothing; the first successful payment wins, a late payer is refunded. |
| `BOOKING_HOLD_MINUTES` | integer, default `1` | Hold length (hold mode only). |

After editing `.env` no restart is needed (config is not cached in development).

### Troubleshooting

| Problem | Fix |
|---|---|
| `port is already allocated` | `APP_PORT=8080 ./setup.sh` and/or `DB_HOST_PORT=3308 ./setup.sh` |
| `Unknown database 'ottodot_test'` / MySQL never healthy | The init script only runs on a fresh volume: `./scripts/down.sh --purge && ./setup.sh` |
| Port 5173 in use (another Vite app) | Stop the other dev server; the React assets are served from localhost:5173 |
| `Docker is not running` | Start Docker Desktop (or the Docker daemon) and retry |
| `Permission denied: ./setup.sh` | `chmod +x setup.sh scripts/*.sh docker/app/entrypoint.sh` |
| `Too many login attempts` | Login is limited to 5 attempts per minute; wait a minute and try again |
| Ran `php artisan` on the host (e.g. a `tempnam()` warning, or `.env` changes ignored) | Use `./scripts/artisan.sh <command>`; undo cached config with `./scripts/artisan.sh optimize:clear` |
| Anything else during setup | `docker compose logs app mysql node` |

The app container runs as your host user (UID/GID), so `vendor/` and `storage/` stay editable from your editor.
Tests always use the `ottodot_test` database (forced in `phpunit.xml`), so running them never wipes the demo data.

## What I built

### Core (what the brief asks for)

- **Parent booking flow** (`/`): choose a child → choose a trial class (seats left, with "Full",
  "Already booked" or "Continue to payment") → mock payment (success or decline, optional gateway delay)
  → a coloured result showing the booking status, the outcome message and the payment attempts.
- **Last-seat protection** in one service ([app/Services/BookingService.php](app/Services/BookingService.php)):
  the class row is locked for every seat decision, seats are checked before and after the charge,
  a parent charged after the seat is gone is refunded, and repeated payment requests never charge twice.
- **Roster** page and API showing confirmed students only.
- **Seed data** covering every case in the brief: a class with free seats, a class with exactly
  3 confirmed students, a child already confirmed (so a duplicate attempt can be tried) and a failed payment.
- **Tests** for duplicates, overbooking, payment failure and the last-seat race, plus
  **`race:simulate`**, which sends several parents at the last seat from separate processes at the
  same instant.

### Extras (beyond the minimal slice, and why)

- **Switchable booking mode** (`hold` or `first_to_pay`): both approaches to the last-seat race are
  implemented and tested on top of the same locking and refund logic, so the trade-off can be compared
  directly rather than only described.
- **Role-based login** (parent, teacher, admin) using a Laravel Sanctum cookie session: the parent is
  always taken from the session instead of the request, a teacher sees only their own classes' rosters,
  and the two-parent race can be demoed as two real users.
- **Admin pages** to manage classes, teachers, parents and students, plus filtered booking and payment
  lists, so the demo data can be inspected and changed without touching the database.

The core booking logic and its tests were built first; the extras sit on top of the same service and
don't change its rules.

## Demo walkthrough

### Demo data

All data is synthetic (invented names, `@example.com`). Every account's password is `password`.
`./scripts/reset.sh` restores exactly this state.

**Parents**

| Parent | Login | Children (grade) |
|---|---|---|
| Maya Tan | maya.tan@example.com | Leo (3), Mia (5) |
| Daniel Okafor | daniel.okafor@example.com | Ava (4) |
| Sofia Rahman | sofia.rahman@example.com | Noah (2) |
| Ethan Wong | ethan.wong@example.com | Zara (4) |
| Priya Nair | priya.nair@example.com | Ryan (5) |
| Lucas Silva | lucas.silva@example.com | Emma (3) |
| Hana Sato | hana.sato@example.com | Kai (4) |
| Omar Haddad | omar.haddad@example.com | Lina (3) |
| Grace Lim | grace.lim@example.com | Ben (5) |
| Felix Braun | felix.braun@example.com | Iris (2) |

**Staff:** teachers Ms. Rivera (`rivera@example.com`), Mr. Chen (`chen@example.com`), Ms. Okoye (`okoye@example.com`),
Mr. Patel (`patel@example.com`); admin Jordan Admin (`admin@example.com`).

**Trial classes** (capacity 4, $15.00, always in the future)

| # | Class | Teacher | Seeded bookings | Demonstrates |
|---|---|---|---|---|
| 1 | Math Explorers: Fractions | Ms. Rivera | 3 confirmed (Ava, Noah, Zara) | Last-seat race: one seat left |
| 2 | Science Lab: Volcanoes | Mr. Chen | 1 confirmed (Leo) | Free seats; duplicate attempt |
| 3 | Space & Planets | Ms. Okoye | none | Happy path |
| 4 | Coding Logic Puzzles | Mr. Patel | 4 confirmed | Full class, cannot be booked |
| 5 | Chemistry of Colours | Ms. Rivera | 1 payment failed (Mia) | A failed payment never reaches the roster |

### Last seat, two parents

Session cookies are shared by all tabs of one browser, so use a **normal window** (A) and an **incognito window**
(B), plus a third browser for the teacher (C).

1. A: log in as `maya.tan@example.com` → Leo → Math Explorers: Fractions → **Book**. Stop on the payment screen.
2. B: log in as `priya.nair@example.com` → Ryan → Math Explorers: Fractions.
   - `hold` mode: B sees **"0 of 4 seats left"** and a disabled **Full** button. If A doesn't
     pay within `BOOKING_HOLD_MINUTES`, B can book (refresh); A's later payment is rejected with
     "Your seat hold expired… You have not been charged."
   - `first_to_pay` mode: B can **Book** → **Pay (success)** → green "Booked!". Then A → **Pay (success)** → red
     "Sorry, the last seat in this class was just taken. You have not been charged."
3. C: log in as `rivera@example.com` → lands on `/roster` → Math Explorers: Fractions → **4 / 4 confirmed**.

### Other paths

- **Duplicate:** `maya.tan@example.com` → Leo → Science Lab: Volcanoes shows **Already booked**
  (the API answers `409 already_booked`).
- **Payment declined:** `maya.tan@example.com` → Mia → Space & Planets → **Pay (decline)** → "Payment was declined…";
  the roster for class 3 stays empty (check as `admin@example.com`; Ms. Rivera gets "This is not your class.").
- **Full class:** any parent → Coding Logic Puzzles shows **Full**.
- **Parallel race in the terminal:** `./scripts/race.sh` (5 parents, 500 ms gateway delay),
  `./scripts/race.sh --delay=0`, `./scripts/race.sh --parents=8 --delay=1000`. It prints each parent's outcome and
  `Confirmed seats: 4/4 · Winners this run: 1 · …`, and exits non-zero if the invariant is ever broken. It uses the
  mode from `.env`.

## Testing & verification

```bash
./scripts/test.sh                        # everything (111 tests)
./scripts/test.sh --filter=LastSeatRaceTest
./scripts/test.sh --group=race           # the slower parallel test only
docker compose exec node npm run typecheck
```

| Test class | Proves |
|---|---|
| `SchemaConstraintsTest` | The database itself rejects a second confirmed booking per student/class, invalid or lowercase statuses, capacity above 4, duplicate idempotency keys. |
| `UsersRoleConstraintTest`, `AuthTest` | Role ↔ `parent_id`/`teacher_id` consistency (`chk_users_role`); login, logout, throttle, redirects. |
| `BookingCreationTest` | Booking for own child (201), pending booking reused (200), `already_booked`, `class_full`, `class_started`, `student_not_owned`, validation. |
| `PaymentTest` | Success confirms and reaches the roster; decline never does; same key never charges twice; key reuse across bookings rejected; paying a finished booking doesn't charge; cancel rules. |
| `LastSeatRaceTest` | The brief's scenario (loser not charged), simultaneous payments (loser refunded), two payments for one booking, 6 payers → 4 confirmed, roster shows confirmed only. |
| `SeatHoldTest` | Hold mode: a booking holds the last seat, paying in time confirms, expired holds release the seat and can't be paid, hold length from config, re-booking after expiry, a charge that outlasts the hold, decline / cancel free the seat. |
| `AuthorizationTest` | 401 for guests, 403 for wrong role or another teacher's roster, 404 for another parent's booking or child. |
| `RaceSimulationTest` (`race` group) | Real parallel processes, in both modes: exactly one winner, 4/4 confirmed, nobody charged twice, every charged loser refunded. |
| `TrialClassManagementTest`, `AccountManagementTest`, `StudentManagementTest`, `BookingListTest`, `PaymentListTest`, `ManagementPagesTest`, `DemoSeederTest`, `SampleTest` | Admin CRUD and business-rule 409s, role-scoped lists, page routes, the seed's edge cases, the `/api/ping` health check. |

**MySQL, not SQLite.** Tests run against a dedicated MySQL database (`ottodot_test`). SQLite ignores
`lockForUpdate()`, so the locking (the core of this exercise) would go untested.

**Why the parallel race is a command.** PHPUnit runs in one process, so `LastSeatRaceTest` reproduces the race
deterministically (call ordering plus a fake gateway hook that lets another parent pay *during* a charge).
`race:simulate` ([app/Console/Commands/RaceSimulateCommand.php](app/Console/Commands/RaceSimulateCommand.php))
starts one `php artisan` process per parent, each with its own MySQL connection, all starting at the same instant;
`RaceSimulationTest` runs it against the test database (with `DatabaseMigrations`, since child processes can't see
an uncommitted test transaction).

## Backend design

### Data model

Six domain tables plus Laravel's `users`, kept deliberately small.

| Table | Key fields | Notes |
|---|---|---|
| `users` | `id`, `name`, `email` (unique), `password`, `role`, `parent_id` (unique, nullable), `teacher_id` (unique, nullable) | One login per person. `role` is `parent`, `teacher` or `admin`; `CHECK chk_users_role`: a parent links only `parent_id`, a teacher only `teacher_id`, an admin neither. Names and emails live here |
| `parents` | `id` | Eloquent model is `Guardian`, because `parent` is a reserved word in PHP |
| `teachers` | `id` | Name/email are on the linked `users` row |
| `students` | `id`, `parent_id`, `name`, `grade` | A parent can have several children |
| `trial_classes` | `id`, `teacher_id`, `subject`, `title`, `starts_at`, `capacity`, `price_cents` | `CHECK (capacity BETWEEN 1 AND 4)` |
| `bookings` | `id`, `student_id`, `trial_class_id`, `status`, `confirmed_at`, `confirmed_flag` (generated), timestamps | One row per booking attempt; `CHECK` on allowed statuses |
| `payment_attempts` | `id`, `booking_id`, `status`, `amount_cents`, `idempotency_key` (unique), `failure_reason` | Many per booking (a charge, then possibly a refund); `CHECK` on allowed statuses |

**Duplicate confirmed bookings are blocked by the database.** MySQL has no partial unique indexes, so `bookings`
has a stored generated column `confirmed_flag` = `1` when the status is `CONFIRMED`, `NULL` otherwise, and a unique
index on `(student_id, trial_class_id, confirmed_flag)` (`uniq_confirmed_booking`). Any number of failed or cancelled
rows are allowed, but at most one confirmed row per student and class.

**There is no seat counter column.** Seats in use are always derived from `bookings`: `CONFIRMED` rows, plus (in
hold mode) `PENDING_PAYMENT` rows whose hold hasn't expired. Nothing can drift out of sync.

Money is integer cents. Foreign keys use `RESTRICT` on delete, so booking and payment history can't disappear.

### API endpoints

Every route except `/api/login` (and the `/api/ping` health check) needs the session (`auth:sanctum`).
The parent is **never** read from the request: it is the logged-in user's `users.parent_id`.

**Booking flow**

| Method | Endpoint | Who | Purpose |
|---|---|---|---|
| `POST` | `/api/login` | guest | Body `{email, password}`. Starts the Sanctum cookie session. Throttled 5/min |
| `POST` | `/api/logout` | any | Ends the session |
| `GET` | `/api/me` | any | `{user: {id, name, email, role}}` for the logged-in user |
| `GET` | `/api/students` | any (scoped) | Parent: own children. Teacher: students with a booking in own classes. Admin: all |
| `GET` | `/api/trial-classes` | any (scoped) | Teacher: own classes; others: all. Each with `seats_taken` (confirmed), `seats_held` (active holds), `seats_available`, `is_full`. `?student_id=` (parent's own child only, else 404) adds `student_booking_status` (`CONFIRMED`, `PENDING_PAYMENT` or `null`) |
| `POST` | `/api/bookings` | parent | Body `{student_id, trial_class_id}`. 201 with a new `PENDING_PAYMENT` booking, or 200 with the child's existing pending one. In hold mode this holds the seat for `BOOKING_HOLD_MINUTES` |
| `GET` | `/api/bookings/{id}` | parent, own | Current status for the result screen |
| `POST` | `/api/bookings/{id}/pay` | parent, own | Body `{idempotency_key (≤64), simulate: "success"\|"decline", delay_ms (0–5000)}`. Always 200 with the booking: confirmed, declined, class full and refunded are outcomes, not HTTP errors |
| `POST` | `/api/bookings/{id}/cancel` | parent, own | Cancels a `PENDING_PAYMENT` booking (409 `not_cancellable` otherwise) |
| `GET` | `/api/trial-classes/{id}/roster` | teacher (own class), admin | `CONFIRMED` bookings only: student, grade, parent name, confirmed at |

**Management and lists**

| Method | Endpoint | Who |
|---|---|---|
| `GET` | `/api/bookings` (`?status=&trial_class_id=&student_id=&parent_id=`) | any; admin all, teacher own classes, parent own children |
| `GET` | `/api/parents` | admin, teacher (parents of students with a booking in own classes) |
| `POST/PUT/DELETE` | `/api/trial-classes`, `/api/teachers` (+ `GET`), `/api/parents`, `/api/students` | admin |
| `GET` | `/api/payments` (`?status=`) | admin |

**Errors.** Every response uses one envelope: `{success: true, message, data}` or
`{success: false, message, errors}`. Unauthenticated → 401; wrong role, or a teacher reading another teacher's
roster → 403; another parent's booking or child → **404** (so ids can't be probed); validation → 422 with field
errors. Booking rule violations carry a machine-readable code in `errors.code`:

| Code | Status | When |
|---|---|---|
| `student_not_owned` | 403 | The child isn't the logged-in parent's |
| `already_booked` | 409 | The child already has a confirmed seat in this class |
| `class_full` | 409 | No seat left when booking (in hold mode, held seats count) |
| `class_started` | 409 | The class has already started |
| `not_cancellable` | 409 | Only bookings awaiting payment can be cancelled |
| `idempotency_key_reused` | 409 | The payment key was already used for a different booking |

All rules live in [app/Services/BookingService.php](app/Services/BookingService.php); controllers are thin
(FormRequest validates → service → resource). The gateway sits behind
[app/Payments/PaymentGateway.php](app/Payments/PaymentGateway.php) and is treated as an external vendor that reports
success or decline; the app decides what that means. `simulate` makes every outcome reproducible, `delay_ms` mimics a
slow vendor.

### Booking statuses

Status values are UPPER_SNAKE_CASE; the `status` columns use `utf8mb4_bin`, so the `CHECK` is case-sensitive
(MySQL's default collation would let `'confirmed'` through).

| Status | Meaning | Uses a seat? | On roster? |
|---|---|---|---|
| `PENDING_PAYMENT` | Booking created, waiting for payment | hold mode: yes, until the hold expires · first_to_pay: no | No |
| `CONFIRMED` | Paid and seat secured | Yes | Yes |
| `PAYMENT_FAILED` | The gateway declined the payment | No | No |
| `FAILED_CLASS_FULL` | The class filled up first: either rejected before charging, or charged and refunded | No | No |
| `CANCELLED` | Cancelled by the parent before paying, or by the system because the child already holds a confirmed seat in this class (any payment refunded) | No | No |
| `EXPIRED` | Hold mode: not paid within `BOOKING_HOLD_MINUTES`; the seat was released | No | No |

Only `PENDING_PAYMENT` moves to another status; the others are final. One exception: if a hold expires *while its
own payment is being charged*, that payment still decides it (confirmed if a seat is free, else refunded).
A parent who wants to try again starts a new booking.

Payment attempt statuses: `PENDING` (idempotency key claimed, charge in progress), `SUCCEEDED`, `FAILED`
(`failure_reason = card_declined`), `REFUNDED`. A refund is its own row (key `<original_key>:refund`,
`failure_reason` = `class_full`, `duplicate_booking` or `booking_closed`), so the money trail is auditable.

### Preventing duplicate bookings

1. **Database:** `uniq_confirmed_booking` makes a second confirmed booking for the same student and class
   impossible, even under concurrency or an application bug.
2. **At booking time:** a child already `CONFIRMED` in the class → `409 already_booked`; an existing
   `PENDING_PAYMENT` booking (still held, in hold mode) is returned instead of creating another.
3. **At confirmation time:** under the class lock, if the child is already confirmed (e.g. from a second tab), the
   booking becomes `CANCELLED` and the payment is refunded (`duplicate_booking`).
4. **Payment idempotency:** each payment screen generates an `idempotency_key`. The key is claimed by inserting a
   `PENDING` payment attempt **before** the gateway is called, so two identical requests at the same instant charge
   once: the second hits the unique index and returns the current booking. Paying an already-final booking is a
   no-op without a charge.

### Handling payment failure

A declined payment records a `FAILED` attempt (`card_declined`) and moves the booking to `PAYMENT_FAILED`. A
booking only becomes `CONFIRMED` after a successful payment *and* a successful seat check, so a failed payment never
reaches the roster. In hold mode the decline also releases the held seat immediately.

### Where each check lives

| Check | UI | Backend | Database |
|---|---|---|---|
| Show seats left / disable full or already-booked classes | ✓ (convenience only) | | |
| Disable "Pay" after click; one idempotency key per payment screen; hold countdown | ✓ (convenience only) | | |
| Logged in with the right role; booking / child belongs to the logged-in parent | | ✓ (`auth:sanctum`, `role` middleware, 404 checks) | |
| Seat check when booking, before charging and before confirming (under class lock) | | ✓ | ✓ (row locks) |
| Expire unpaid holds (hold mode) | | ✓ (lazily, under the class lock; no scheduler) | |
| At most one confirmed booking per student and class | | ✓ (friendly 409) | ✓ (unique index) |
| No double charge | | ✓ | ✓ (unique `idempotency_key`) |
| Valid status and capacity values | | ✓ (PHP enums, validation) | ✓ (`CHECK` constraints) |
| Roster shows `CONFIRMED` only | | ✓ (query) | |

UI checks are never trusted for correctness. **Background job:** none is needed for correctness, because
expired holds are handled lazily under the class lock. In production I would add a scheduled reconciliation
job (every `SUCCEEDED` payment has a `CONFIRMED` booking or a `REFUNDED` row) and a sweep of expired holds
for tidier lists.

## Last-seat race: approach, why, trade-offs

**Chosen approach: seat hold (`BOOKING_MODE=hold`, the default).** The race is decided at booking time,
before any money moves, so the losing parent is told the class is full and is never charged.
`first_to_pay` is kept as a switch because it follows the brief's scenario literally (B can select
and pay at step 2). Both modes share the same locked seat checks and refund path, and both are tested.

**Approach.** Every seat decision for a class is serialised by locking that class's row
(`lockForUpdate()` as the **first** statement of each transaction), and seat counts are locking reads, so under
MySQL's REPEATABLE READ they always see the latest committed bookings. The gateway is **never** called inside a
transaction. Paying runs three phases:

1. **Pre-check** (short transaction): booking still pending, hold not expired, child not already confirmed, a seat
   free; otherwise reject **without charging**.
2. **Charge** outside any lock (claim the idempotency key first).
3. **Final check** (short transaction): seat still free → `CONFIRMED`; otherwise `FAILED_CLASS_FULL` and, after the
   transaction commits, a refund.

Who gets the seat depends on `BOOKING_MODE`:

| Brief's scenario (3 of 4 confirmed) | `hold` (default) | `first_to_pay` |
|---|---|---|
| 1. A selects the last seat, moves to payment | A holds the seat | A has a pending booking, no seat |
| 2. B selects the same seat | **B is told the class is full** (`class_full`) | B gets a pending booking |
| 3. B pays | (B never got a booking) | B `CONFIRMED` (4/4) |
| 4. A pays | A `CONFIRMED` (if within the hold) | A `FAILED_CLASS_FULL`, **not charged** |
| Two payments truly at the same instant | can't happen for one seat: the hold already decided | both may be charged; exactly one confirmed, the other **refunded** |

**Why.**

- One class-row lock gives a single, obvious place where seat decisions are serialised, while different classes
  never block each other. `DB::transaction(..., 3)` retries on deadlock.
- Keeping the vendor call outside the lock means a slow gateway can't stall other bookings for the class.
- **Hold mode (default)** decides the race at booking time, before any money moves: the loser is told "full" and is
  never charged, and refunds only happen if a payment takes longer than the hold.
- **First-to-pay** follows the brief's scenario literally (B is free to select and pay at step 2) and needs no
  expiry; the pre-check keeps refunds to the narrow window where two payments are in flight together.
- The refund path exists in both modes, because once a vendor has taken the money it can only be refunded.

**Trade-offs.**

- *Hold:* an unpaid hold blocks other parents for up to `BOOKING_HOLD_MINUTES`; a parent with several children could
  hold several seats, or keep re-holding after expiry (no per-parent cap yet). Expired holds are cleaned lazily, so a
  list can show a stale `PENDING_PAYMENT` until the next booking or payment in that class (seat counts are by time,
  so they are always right). The hold end is `created_at + BOOKING_HOLD_MINUTES`, so changing the setting also
  shifts live holds.
- *First to pay:* a parent can reach payment for a seat that is then taken: turned away before paying, or (rarely)
  charged and refunded.
- *Both:* seat availability in the list can be stale by the time a parent acts; the backend checks are the source of
  truth. Refunds are real money movements; a production integration would use authorize-then-capture so the loser's
  authorization is voided instead.

**Alternatives considered.** Holding the database lock during the payment call (no refunds, but every booking for
the class queues behind a slow vendor); a waitlist or account credit for a lost race (better for the business, out of
scope).

## Assumptions

- Authentication is Laravel Sanctum cookie sessions; accounts per role (parent / teacher / admin) come from the seeder
  or are created by the admin. No self-registration.
- One currency; each class has its own price in cents.
- The payment gateway is an external vendor reporting success or decline; here it is a mock, instant unless
  `delay_ms` is set, and refunds always succeed instantly.
- Capacity is at most 4 per class.
- Classes that have already started can't be booked.
- Default `BOOKING_MODE=hold` with a 1-minute hold (short, so it is visible in a demo); in `first_to_pay` a pending
  booking never reserves a seat.
- Times are stored and shown in UTC.

## What I deliberately cut

- Registration, password reset and API tokens (session login only).
- A real payment provider, webhooks and authorize-then-capture.
- Waitlist and account credit for a lost race.
- Per-parent limits on active holds, and rate limits beyond login.
- Emails and notifications.
- Pagination, frontend polish and production deployment.

## What I would monitor after release

- Count and rate of `booking.refunded` log events, by reason (especially `class_full`).
- `booking.rejected_before_charge` (by reason) and, in hold mode, `booking.holds_expired` vs confirmed holds
  (hold → payment conversion).
- Payment failure rate (`booking.payment_failed`).
- An alert if any class ever has more confirmed bookings than its capacity (must always be zero).
- Reconciliation: every `SUCCEEDED` payment belongs to a `CONFIRMED` booking or has a `REFUNDED` row.
- Stuck `PENDING` payment attempts (claimed key, no outcome).
- Booking and payment latency, and lock wait time on `trial_classes`.

## What I would do next

- A real provider with authorize-then-capture, webhook handling and a scheduled reconciliation job.
- Hold hardening: a per-parent cap on active holds, a stored `hold_expires_at`, and a sweep of expired holds for
  tidier lists.
- Waitlist with auto-promotion, or credit for a lost race.
- Registration and password reset.
- Cleanup of abandoned `PENDING_PAYMENT` bookings in first-to-pay mode.

## Time spent

About 4 hours of build time (3 h 58 min), spread across several sessions rather than one sitting. Before that, I
spent about 2 hours exploring the design with AI (last-seat approach, data model, instructions for the coding
agent); see [AI_USAGE.md](AI_USAGE.md) for how AI was used.

Rough split of the build time:

- Setup and schema: ~50 min
- Booking service and tests: ~60 min
- Second booking mode (seat hold vs first-to-pay, made switchable) and its tests: ~60 min
- Frontend and admin pages: ~50 min
- README, AI_USAGE and final verification: ~18 min

## Video walkthrough

[Watch the walkthrough (about 7 minutes)](PASTE_YOUR_LINK_HERE): booking flow, duplicate and declined payment, the
two-parent last-seat scenario, the parallel `race.sh` run, and the trade-offs.
