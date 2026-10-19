# FloatBook

[![CI](https://github.com/asgatcreation/floatbook/actions/workflows/ci.yml/badge.svg)](https://github.com/asgatcreation/floatbook/actions/workflows/ci.yml)
[![License: All rights reserved](https://img.shields.io/badge/license-All%20rights%20reserved-red.svg)](LICENSE)

**Back-office software for Nigerian POS and agent-banking businesses.**

FloatBook replaces the notebook and WhatsApp messages that POS owners use today. It keeps a
log of every transaction, tracks cash and e-wallet float at every outlet, runs the daily
cash count with shortage detection, follows up failed transactions that need a reversal,
and shows the owner today's numbers across all outlets, live.

**[Try the demo](https://floatbook.onrender.com)** — one click signs you in as the owner,
a supervisor, an agent or an accountant of a made-up business with two weeks of trading.
Nothing you do there is kept: it is rebuilt every night.

> The demo sleeps when nobody is using it, so the first page can take ~30 seconds.

> **Honest note:** FloatBook does **not** connect to Moniepoint, OPay, Palmpay or any other
> provider. Data comes from manual entry at the counter and from imported provider
> statements (CSV/Excel). The code is designed around an adapter layer so real integrations can be added later.

---

## What it does

|                                     |                                                                                                                                                                  |
| ----------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Record a sale in seconds**        | A keypad built for one thumb. Charges, provider fees and your profit are worked out as you type, from your own price list.                                       |
| **Works offline**                   | Entries are kept on the phone when the network drops and sent when it returns — never twice. Installs to the home screen.                                        |
| **Several terminals per shop**      | Any provider, including bank POS. Each terminal keeps its own record and balance, or shares one. Staff are assigned to the machines they use.                    |
| **Cash and wallet float**           | Every drawer and wallet, live. Top-ups and transfers need approval; low balances raise an alert.                                                                 |
| **End-of-day count**                | A blind count of the notes and each terminal's balance. Submitting closes the day for good; a supervisor approves any difference and can hold staff responsible. |
| **Failed and pending transactions** | Each one opens a case with a 72-hour target, notes and ticket numbers, so no customer is forgotten.                                                              |
| **Provider statements**             | Upload the CSV or Excel export; FloatBook shows what matches, what disagrees and what nobody recorded.                                                           |
| **Reports**                         | Any date range, grouped by day, outlet, terminal, staff or type, downloadable as Excel or CSV.                                                                   |
| **Unusual activity**                | Duplicate references, unusually large amounts, after-hours entries, frequent voids and repeated shortages get flagged for a person to judge.                     |
| **The owner's view**                | A live dashboard and one email after closing time with the day's profit and anything needing attention.                                                          |

Every figure sits on a **double-entry ledger** checked by the database itself, and nothing
is ever edited or deleted in place: mistakes are voided or reversed and both sides stay.

## Tech stack

| Area            | Technology                                                 |
| --------------- | ---------------------------------------------------------- |
| Backend         | PHP 8.5, Laravel 13, Laravel Fortify (auth, 2FA, passkeys) |
| Frontend        | Inertia.js 3, Vue 3, TypeScript, Tailwind CSS 4, Vite+     |
| Database        | PostgreSQL 17 (Neon in production, Docker locally)         |
| Background work | Laravel queues and scheduler (database driver)             |
| Tests           | Pest 5 (unit and feature), Playwright (end-to-end)         |
| Quality         | Pint, Larastan (level 7), Oxlint, vue-tsc, GitHub Actions  |
| Hosting         | Render (Docker), Neon PostgreSQL, Brevo email API          |

## Running it locally

You need **PHP 8.5** (with the `pdo_pgsql` extension), **Composer**, **Node 24** and
**Docker Desktop**.

```bash
# 1. Start the database (PostgreSQL in Docker, on port 5433)
docker compose up -d db

# 2. One-time setup: install packages, create .env, create tables, build the frontend
composer run setup

# 3. Start the app, background worker and frontend dev server together
composer run dev
```

Then open **http://localhost:8010**.

Next time you only need steps 1 and 3. To stop the database: `docker compose stop db`.

### Local test accounts

For trying every screen on your own computer, this command creates a small business
("Local Test POS") with two outlets, opening balances and a few transactions:

```bash
php artisan db:seed --class=LocalTestAccountsSeeder
```

| Role       | Email                       | Password   |
| ---------- | --------------------------- | ---------- |
| Owner      | `owner@floatbook.test`      | `password` |
| Supervisor | `supervisor@floatbook.test` | `password` |
| Agent      | `agent@floatbook.test`      | `password` |
| Accountant | `accountant@floatbook.test` | `password` |

These exist only in your local database and the seeder refuses to run in production.
Emails sent locally (verification codes, invitations) are written to
`storage/logs/laravel.log` instead of being delivered.

## Tests and checks

```bash
php artisan test        # Pest tests (uses the separate floatbook_test database)
composer lint:check     # Pint code style
composer types:check    # Larastan static analysis
npm run check           # Oxlint + formatter
npm run types:check     # TypeScript
```

GitHub Actions runs all of these, plus a production build, on every push.

## Putting it online

See **[docs/DEPLOY.md](docs/DEPLOY.md)** — a step-by-step walk through Neon (database),
Brevo (email), Render (the app) and a free pinger to keep it awake. All free plans.

The whole thing runs as one Docker container: nginx, PHP-FPM, the queue worker and the
scheduler, kept alive by supervisord. `render.yaml` describes the service; secrets are
pasted into Render and never committed.

## Guided tour

**[docs/TRY-IT.md](docs/TRY-IT.md)** walks through every screen on your own computer, in
the order a real business would use them.

## Licence

Copyright © 2026 Akanji Oluwaseun Gabriel. **All rights reserved.** This code is public so
clients and employers can review it; it is not open source. See [LICENSE](LICENSE).

## Credits

Designed and built by **Akanji Oluwaseun Gabriel** ([@asgatcreation](https://github.com/asgatcreation)).
