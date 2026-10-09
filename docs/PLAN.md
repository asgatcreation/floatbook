# FloatBook — Plan and Data Model (Milestone 1)

Status: draft for approval, 9 October 2026.

---

## 1. Stack (checked October 2026)

| Layer                    | Choice                                                                 | Version to install                                                                          | Notes                                                                                                 |
| ------------------------ | ---------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------- |
| Language                 | PHP                                                                    | 8.5.x (8.5.11 is the latest stable)                                                         | 8.6 is still a release candidate until about November 2026, so we skip it.                            |
| Framework                | Laravel                                                                | 13.x (latest 13 patch)                                                                      | Laravel 13 came out March 2026. Laravel 14 is not expected until 2027.                                |
| Auth backend             | Laravel Fortify                                                        | latest                                                                                      | Ships with the official Vue starter kit. Gives login, password reset and 2FA (TOTP + recovery codes). |
| UI bridge                | Inertia.js                                                             | v3                                                                                          | v3 released March 2026 (adds optimistic updates and `useHttp`).                                       |
| Frontend                 | Vue 3 + TypeScript + Vite                                              | Vue 3.5.x                                                                                   | From the official Laravel Vue starter kit.                                                            |
| CSS                      | Tailwind CSS                                                           | v4.3.x                                                                                      | Design tokens as CSS variables (`@theme`).                                                            |
| UI primitives            | reka-ui / shadcn-vue (from the starter kit)                            | latest                                                                                      | Accessible dialogs, menus and so on, restyled with our own tokens.                                    |
| Charts                   | Apache ECharts via vue-echarts                                         | latest                                                                                      | Good dark mode, small tree-shaken build, works on cheap phones.                                       |
| Realtime                 | Laravel Reverb + Laravel Echo                                          | Reverb 1.x                                                                                  | Runs inside the same container. Falls back to polling every 30 seconds.                               |
| Queue / cache / sessions | `database` driver on Postgres                                          | —                                                                                           | No Redis needed, so it stays free.                                                                    |
| PWA                      | vite-plugin-pwa (Workbox) + IndexedDB (idb)                            | latest                                                                                      | Offline queue for agents.                                                                             |
| Excel / CSV              | openspout/openspout (streams large files)                              | latest                                                                                      | Used for imports and exports.                                                                         |
| PDF                      | barryvdh/laravel-dompdf                                                | latest                                                                                      | Report PDFs.                                                                                          |
| Email                    | Brevo HTTP API (`symfony/brevo-mailer` transport)                      | latest                                                                                      | Render's free tier blocks SMTP.                                                                       |
| Tests                    | Pest (unit + feature), Playwright (end-to-end)                         | the latest Pest major that supports Laravel 13 (4 or 5, confirmed with Composer at install) |                                                                                                       |
| Quality                  | Pint, ESLint + Prettier, Larastan (PHPStan level 6 or higher), vue-tsc | Larastan 3.x                                                                                | All run in CI.                                                                                        |
| Database                 | PostgreSQL on Neon (production), Postgres 17 in Docker (local and CI)  | —                                                                                           | Never SQLite. Tests run on real Postgres too.                                                         |
| Hosting                  | Render free web service (Docker) + `render.yaml`                       | —                                                                                           | See section 6.                                                                                        |

Exact versions are fixed when we install (Composer and npm pick the latest stable). I'll confirm them at the start of milestone 2.

### Local setup on your PC

PHP and Composer are **not installed** on this machine. Node 24, Git and Docker are installed.

- **Recommended:** install PHP 8.5 + Composer with the official Laravel one-line installer (php.new). It's fast and doesn't need admin rights. Postgres runs in Docker (`docker compose up db`) on port **5433**, so it won't clash with anything you run.
- Alternative: run everything in Docker. It works, but file syncing on Windows makes it noticeably slower.

My own dev servers will use ports **8010** (Laravel), **5174** (Vite) and **8090** (Reverb). Your ports 8000 and 3000 are left alone.

---

## 2. Main design decisions

1. **Money = integer kobo** (`bigint`) everywhere: database, PHP and TypeScript. A `Money` value object handles all arithmetic, and percentages are stored as _basis points_ (0.5% = 50 bps) with explicit rounding rules. Floats never touch money.
2. **Double-entry ledger.** Every money event (transaction, top-up, expense, variance and so on) creates one _journal entry_ made of lines that **sum to zero**. Balances (cash drawer, each wallet, profit) are sums of lines, so they always reconcile. Each account also keeps a cached `balance_kobo` that is updated in the same database transaction, under a `SELECT … FOR UPDATE` row lock. A nightly check compares the cache with the real sum of lines.
3. **Immutability.** Journal entries, journal lines and audit logs can't be updated or deleted. A Postgres trigger enforces this, with one exception: deletes are allowed when the business row has `is_demo = true`, which the nightly demo reset needs. Corrections are made with _reversing entries_, never by editing.
4. **Tenant isolation.** One database. Every tenant table has `business_id`. A `BelongsToBusiness` trait adds a global scope and fills `business_id` automatically from the current tenant. Route model binding is scoped, and Policies check role + outlet. An **isolation test suite** loops over every tenant model and proves that business A can never read or write business B's data. (Postgres Row-Level Security is an optional extra layer we can add in milestone 7.)
5. **IDs = ULIDs.** They're safe to show in URLs (no guessable 1, 2, 3) and sort by time. The offline client creates its own UUID **idempotency key** for each entry.
6. **Snapshots.** A transaction stores the charge, fee and commission it was calculated with. If the rules change later, history stays the same.
7. **Provider adapter.** `ProviderAdapter` interface → `ManualAdapter` and `CsvStatementAdapter` (with mapping presets for Moniepoint, OPay and Palmpay exports). A real API adapter could be added later without changing the core. The README and UI say clearly that there are **no live integrations**.
8. **Business time zone** defaults to `Africa/Lagos`. Dates are stored in UTC, and the "business day" is calculated in the business's time zone.

---

## 3. Data model

`(T)` = tenant table (has `business_id` + global scope). All tables have ULID `id` and timestamps unless noted. All money columns are `bigint` kobo.

### 3.1 Tenancy, people, access

| Table               | Key columns                                                                                                                                                                                                                    |
| ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `businesses`        | name, slug, timezone, closing_time, require_2fa, is_demo, settings (jsonb: alert thresholds, after-hours window, large-amount limit, reversal SLA hours)                                                                       |
| `users`             | name, email (unique), phone, password, email_verified_at, two_factor_secret / recovery_codes / confirmed_at                                                                                                                    |
| `memberships` (T)   | user_id, role (`owner` / `supervisor` / `agent` / `accountant`), status (active / suspended), pin_hash, pin_failed_attempts, pin_locked_until. Unique (business_id, user_id). One person can belong to more than one business. |
| `membership_outlet` | membership_id, outlet_id. Which outlets a supervisor or agent covers.                                                                                                                                                          |
| `invitations` (T)   | email, role, outlet_ids (jsonb), token_hash, invited_by, expires_at, accepted_at                                                                                                                                               |
| `one_time_codes`    | user_id, purpose (verify_email / login_challenge), code_hash, attempts, expires_at, used_at. The code itself only appears in the email.                                                                                        |
| `devices` (T)       | outlet_id, name, device_token_hash, registered_by, last_seen_at, revoked_at. A shared counter phone where agents switch user with a PIN.                                                                                       |
| `sessions`          | Laravel database sessions plus user_agent and ip, shown on a "your devices" page with "sign out other sessions".                                                                                                               |

### 3.2 Setup

| Table                | Key columns                                                                                                                                                                                                                        |
| -------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `outlets` (T)        | name, code, address, opens_at, closes_at, low_cash_threshold, is_active                                                                                                                                                            |
| `providers` (T)      | name, slug, colour. Pre-filled with Moniepoint, OPay, Palmpay, Paystack Terminal and Other.                                                                                                                                        |
| `outlet_wallets` (T) | outlet_id, provider_id, terminal_id/label, ledger_account_id, low_balance_threshold. One row per provider wallet at an outlet.                                                                                                     |
| `charge_rules` (T)   | provider_id (null = all), outlet_id (null = all), transaction_type, kind (`customer_charge` / `provider_fee` / `provider_commission`), charge_collected_via (cash / added to card amount), effective_from, effective_to, is_active |
| `charge_rule_tiers`  | charge_rule_id, min_amount, max_amount (null = no limit), flat, percent_bps, cap. Example: ₦100 per ₦5,000 band, or 0.5% capped at ₦100.                                                                                           |

### 3.3 Ledger (core of the money system)

| Table                 | Key columns                                                                                                                                                                                                                                                                                                                                                              |
| --------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `ledger_accounts` (T) | outlet_id (nullable), provider_id (nullable), type, name, balance (cached), normal_side. Types: `cash_drawer`, `provider_wallet`, `head_office_bank`, `pending_settlement` (cash paid out while a withdrawal is still pending), `income_charges`, `income_commissions`, `expense_provider_fees`, `expense_operating`, `cash_variance` (shortage/excess), `owner_capital` |
| `journal_entries` (T) | outlet_id, type, source_type + source_id (what caused it), occurred_at, business_date, posted_by, memo, reverses_entry_id. Append-only.                                                                                                                                                                                                                                  |
| `journal_lines`       | journal_entry_id, ledger_account_id, business_id, amount (signed; debit +, credit −). A deferred Postgres constraint trigger checks that each entry sums to 0.                                                                                                                                                                                                           |

**Worked example — cash withdrawal ₦10,000, charge ₦200 collected in cash, provider fee 0.5% = ₦50:**

| Account                                | Amount                                                  |
| -------------------------------------- | ------------------------------------------------------- |
| Provider wallet (Moniepoint, Outlet 1) | +995,000 kobo (₦9,950 reaches the wallet after the fee) |
| Expense: provider fees                 | +5,000                                                  |
| Cash drawer (Outlet 1)                 | −980,000 (₦10,000 paid out, ₦200 charge taken in)       |
| Income: charges                        | −20,000                                                 |
| **Sum**                                | **0** ✅                                                |

Transfer/deposit ₦10,000 with ₦100 charge: cash +1,010,000 · wallet −1,000,000 · income −10,000.

### 3.4 Operations

| Table                        | Key columns                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| ---------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `transactions` (T)           | outlet_id, provider_id, outlet_wallet_id, membership_id (agent), type (`cash_withdrawal`, `transfer_deposit`, `airtime`, `data`, `bill_payment`, `bvn_nin_service`, `card_issuance`, `other_service`), amount, charge, provider_fee, commission (all snapshots), customer_name, customer_phone, reference (RRN), status (`successful` / `pending` / `failed` / `reversed`), cash_paid_while_pending (bool), source (`counter` / `offline_sync` / `import`), idempotency_key (unique per business), client_recorded_at, occurred_at, business_date, journal_entry_id, voided_at, voided_by, void_reason |
| `reversal_cases` (T)         | transaction_id, outlet_id, customer_name, customer_phone, amount, provider_ticket_ref, status (`open`, `escalated`, `reversed`, `resolved_successful`, `written_off`), due_at (from the SLA), closed_at                                                                                                                                                                                                                                                                                                                                                                                                |
| `reversal_case_updates`      | reversal_case_id, membership_id, from_status, to_status, note                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| `float_movements` (T)        | type (`top_up`, `outlet_transfer`, `cash_pickup`, `bank_deposit`, `rebalance` cash↔wallet, `opening_balance`), from_account_id, to_account_id, amount, status (`requested` → `approved` / `rejected` → `completed`), requested_by, approved_by, reason, journal_entry_id                                                                                                                                                                                                                                                                                                                               |
| `expenses` (T)               | outlet_id, category (rent, fuel, data, salary, repairs, other), amount, paid_from_account_id, description, occurred_at, journal_entry_id                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| `statement_imports` (T)      | outlet_id, provider_id, original_filename, status (`uploaded` / `mapped` / `previewed` / `committed` / `failed`), column_mapping (jsonb), row counts, uploaded_by. The file is parsed straight into the database, never kept on disk (Render's disk is wiped).                                                                                                                                                                                                                                                                                                                                         |
| `statement_import_rows`      | import_id, business_id, row_number, raw (jsonb), normalised fields (reference, amount, occurred_at, type, status), row_hash, match_status (`new` / `matched` / `duplicate` / `conflict`), matched_transaction_id                                                                                                                                                                                                                                                                                                                                                                                       |
| `import_mapping_presets` (T) | provider_id, name, mapping (jsonb)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |

### 3.5 Reconciliation

| Table                    | Key columns                                                                                                                                                                                                                                                                                                                                                  |
| ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `reconciliations` (T)    | outlet_id, business_date (unique per outlet + date), status (`open` → `submitted` → `approved`/`locked`; `reopened`), expected_cash, counted_cash, cash_variance, denominations (jsonb: ₦1000×n, ₦500×n, ₦200×n, ₦100×n, ₦50×n, ₦20×n, ₦10×n, ₦5×n, coins), variance_reason, agents_on_duty (jsonb), submitted_by, approved_by, locked_at, variance_entry_id |
| `reconciliation_wallets` | reconciliation_id, outlet_wallet_id, expected, actual (from the terminal screen), variance, reason                                                                                                                                                                                                                                                           |
| `reconciliation_reopens` | reconciliation_id, reopened_by, reason (audited)                                                                                                                                                                                                                                                                                                             |
| `staff_shortages` (T)    | reconciliation_id, membership_id, amount, status (`outstanding` / `recovered` / `written_off`), notes                                                                                                                                                                                                                                                        |

Once a day is locked, no transaction, expense or float movement dated that day can be created, voided or changed. The only way is an audited reopen.

### 3.6 Oversight

| Table                                           | Key columns                                                                                                                                                                                                                     |
| ----------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `audit_logs` (T)                                | actor_user_id, actor_role, action, subject_type + subject_id, before (jsonb), after (jsonb), ip, user_agent, created_at. Append-only (trigger).                                                                                 |
| `anomaly_flags` (T)                             | subject_type + subject_id, kind (`duplicate_reference`, `large_amount`, `after_hours`, `frequent_voids`, `repeated_shortage`, `float_low`), severity, details (jsonb), status (`open` / `dismissed` / `confirmed`), reviewed_by |
| `daily_outlet_stats` (T)                        | outlet_id, business_date, volume, txn_count, charges, commissions, fees, expenses, net_profit, cash_close, wallet_close. Pre-calculated so the dashboard and reports load fast on slow networks.                                |
| `notifications`, `jobs`, `failed_jobs`, `cache` | Laravel standard tables                                                                                                                                                                                                         |

---

## 4. Key flows (short)

- **Recording a transaction:** `RecordTransaction` action → works out charge/fee/commission from the rules → in one DB transaction: lock the affected ledger account rows (always in id order, to avoid deadlocks) → insert transaction + journal entry + lines → update cached balances → audit log → run anomaly checks → broadcast `OutletUpdated`.
- **Pending withdrawal:** nothing is posted until it's resolved. If the agent already paid out cash, the cash goes into `pending_settlement` → when it succeeds, it's moved to the wallet; if it's reversed, it becomes a loss. A reversal case opens automatically for a failed or pending debit.
- **Offline sync:** the phone saves the entry in IndexedDB with a UUID → `POST /api/sync/transactions` sends a batch → the server does an insert-or-return-existing on (business_id, idempotency_key), so a retry never creates a duplicate → each item is answered as accepted / duplicate / rejected (for example, the day is locked).
- **Reconciliation:** expected = opening + all journal lines for the day on that outlet's accounts → agent enters the counted cash (denomination counter) and the wallet balance on the terminal → a variance needs a reason → the supervisor approves → a variance journal entry is posted to `cash_variance`, shortages are assigned to staff → the day is locked → the stats are refreshed.

---

## 5. Roles (summary)

|                                            | Owner          | Supervisor       | Agent              | Accountant                |
| ------------------------------------------ | -------------- | ---------------- | ------------------ | ------------------------- |
| Scope                                      | whole business | assigned outlets | assigned outlet(s) | whole business, read-only |
| Record transactions / expenses             | ✅             | ✅               | ✅ (own outlet)    | ❌                        |
| Void                                       | ✅             | ✅               | request only       | ❌                        |
| Approve float requests, approve/lock day   | ✅             | ✅               | ❌                 | ❌                        |
| Reopen locked day                          | ✅             | ❌               | ❌                 | ❌                        |
| Settings, staff, charge rules, require 2FA | ✅             | ❌               | ❌                 | ❌                        |
| Reports and exports                        | ✅             | own outlets      | own figures        | ✅                        |

---

## 6. Hosting on the free tier — honest limits

- **Render free web service:** sleeps after 15 minutes with no traffic and takes about 1 minute to wake up. You get 750 free hours a month — enough for **one** service running all day, every day (a 31-day month is 744 hours). Background workers and cron jobs are **not** free. Since August 2026 the Hobby plan includes 5 GB of outbound bandwidth a month, which is fine for a portfolio demo.
- **So:** one Docker container runs nginx + PHP-FPM + `queue:work` + `schedule:work` + Reverb, all managed by supervisord. Reverb is served through nginx on the same port (`/app`).
- **The scheduler only runs while the service is awake.** Fix: a free external pinger (cron-job.org) calls `/up` every 10 minutes, so the service stays awake 24/7 within the 750 hours. As a safety net, every scheduled job can **catch up**: the nightly demo reset and the daily summary emails record when they last ran, so if the service was asleep they run as soon as it wakes, and they never run twice.
- **Neon free:** 0.5 GB per project and scale-to-zero (about 0.5–1 second to wake). Plenty for the demo plus a few real businesses. We'll use the direct (non-pooled) connection with `sslmode=require`.
- **No persistent files:** imports are parsed straight into Postgres. Exports are generated on request and streamed. No receipt photo uploads in the first version (they would need object storage — a possible later addition).

---

## 7. Milestones

1. **Stack + data model** — this document. ✅ once you approve it.
   1b. **Visual direction** — a clickable design preview (owner dashboard, agent entry screen, reconciliation) in light and dark mode, at phone and desktop width. You approve it before the UI is built.
2. **Scaffold** — Laravel 13 Vue starter kit, Docker Postgres, Pint/ESLint/Larastan/Pest config, GitHub Actions CI (Postgres service), LICENSE, .env.example, README skeleton, first push.
3. **Auth + tenancy** — business sign-up, email code verification (branded email), invitations, roles/policies, owner-required 2FA, agent PIN + devices, sessions page, audit log, **isolation tests**.
4. **Money core** — Money object, charge rules engine, ledger + triggers, transactions, voids, float movements with approvals, opening balances. **Money-safety tests** (zero-sum entries, rounding, concurrency with parallel requests, row locks, locked-day guard).
5. **Agent PWA + imports + reversals** — fast entry screen, offline queue + sync, statement import (mapping → preview → duplicates → match → commit), reversal tracker with overdue alerts.
6. **Reconciliation + dashboard + reports** — end-of-day flow, denominations counter, shortages, live owner dashboard (Reverb + polling), reports, charts, PDF/Excel/CSV, expenses, low-float alerts, anomaly flags, daily summary email.
7. **Demo + legal + hardening** — "Ade & Sons POS" seed (3 outlets, 8 agents, 90 days), one-click demo logins, nightly reset, Terms + Privacy (NDPA 2023), security headers, rate limits, password rules, structured JSON logs, Lighthouse/accessibility pass, Playwright flows.
8. **Go live** — Neon, Render Blueprint, Brevo, cron-job.org pinger, anonymous live checks, README with screenshots + architecture diagram, final push, your to-do list.

Each milestone ends with: tests green, a browser check (mobile + dark mode + console), a commit, and a push.
