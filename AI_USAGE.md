# AI usage

## 1. Which AI tools I used

Claude Code, using Claude Opus 5.5 (in VS Code, with the Playwright browser tool for UI checks).

## 2. What I used AI for

I used AI to build the app: backend (Laravel API, booking logic, tests) and frontend (React + TypeScript), and also
the Docker setup and the shell scripts, so the project runs with a single command: `./setup.sh` to build and start,
`./scripts/test.sh` to run the tests, `./scripts/race.sh` to simulate the last-seat race, `./scripts/reset.sh` to reset
the seed data and `./scripts/down.sh` to stop the application.

I gave the coding agent one task at a time (each with its goal, rules and a verification command), reviewed each
result and commit before approving the next one, and changed the plan when I disagreed (see section 4).

## 3. One place where AI helped me move faster

Building the frontend features, and sharpening the scenarios while implementing the backend logic, especially the
`BookingService` and the mock payment gateway.

- **Frontend:** the admin management pages (trial classes, teachers, parents, students, bookings and payments lists)
  came from one generic table + form component, and the parent booking page and the roster page followed the same
  pattern. Building that by hand would have taken much longer than the timebox allowed.
- **Booking logic:** with AI I worked through the race step by step: lock the class row first, check the seat before
  charging, charge outside any database lock, check again before confirming, refund if the seat is gone. The payment
  gateway sits behind an interface with a mock (`simulate=success|decline`, optional delay), and the tests swap in a
  fake gateway with a hook that lets a second parent pay *during* the first parent's charge. That made the "tight
  race" reproducible in a normal test instead of relying on luck.
- **Real parallel race:** AI wrote `race:simulate`, which starts one process per parent at the same instant; running
  it repeatedly gave quick proof that the class never goes above 4 confirmed.

## 4. One place where I disagreed with, corrected, or rejected AI output

I often disagreed with the initial approach the AI took, and corrected it:

- **No real login.** The first version of the plan had no authentication (a demo login, then an "acting as parent"
  dropdown). I changed this to real role-based login (parent / teacher / admin with Sanctum sessions), because the
  roster has to be visible only to teachers and admins, and the parent must come from the session, not from the
  request.
- **Missing admin features.** The plan left out admin access and management that I had in mind (managing classes,
  teachers, parents and students, plus role-scoped lists). I added those as extra steps before the booking logic.
- **Booking approach.** The AI's booking service only had "first to pay wins": a booking reserves nothing, and a
  parent who loses a tight race is charged and then refunded. Thinking about a real third-party payment provider,
  where we can't reject a payment once the money is taken, I asked for a seat-hold option: a booking holds the seat
  for a configurable number of minutes (`BOOKING_MODE`, `BOOKING_HOLD_MINUTES` in `.env`), expired holds release the
  seat, and the refund path stays as the safety net.
- **Frontend stack.** The plan started with jQuery + Blade; I switched it to React + TypeScript.

## 5. What I would change about my AI workflow next time

I will use AI more for building the features and the business process, and also for reviewing the implementation
and checking the code structure, to make sure it still follows best practice and stays easy to debug.

## 6. How I verified the final implementation

- **Manual demo and checks:** I ran the main flows myself (booking, paying, declined payment, the last-seat race in
  two browser windows, the roster as teacher and admin).
- **Automated tests, written first (TDD):** for each step the AI wrote the test first, I checked it failed for the
  right reason, then the implementation made it pass. The suite (111 tests) runs against a real MySQL test database,
  not SQLite, so the row locking is actually tested. It covers the invariants: max 4 confirmed, one confirmed booking
  per child per class, a failed payment never reaches the roster, the last-seat race in both booking modes.
- **Real parallel race:** `./scripts/race.sh` run many times, in both modes and with different gateway delays: always
  `Confirmed seats: 4/4` and exactly one winner.
- **Browser checks with AI (Playwright):** the AI drove the browser through each step's manual checklist and reported
  the result and any console errors.
- **Fresh-clone check:** running the setup from a clean clone found a real bug that my local environment hid (the
  frontend container crashed on a missing native module); it was fixed and re-tested from a clean clone.
