# Try FloatBook on your computer

A guided tour of everything built so far (milestones 1–4). It takes about 15 minutes.

## 1. Start it

Open **PowerShell** in the `floatbook` folder (in File Explorer, open the folder, click the
address bar, type `powershell`, press Enter). Make sure **Docker Desktop** is running.

```powershell
docker compose up -d db
composer run dev
```

Leave that window open (it is the app running). Open **http://localhost:8010** in your browser.
To stop the app later, click the PowerShell window and press **Ctrl + C**.

> If `composer` or `php` is "not recognized", close PowerShell and open a new one.
> If the test accounts below don't work, run `php artisan db:seed --class=LocalTestAccountsSeeder`
> in a second PowerShell window (it is safe to run more than once).

## 2. Log in

The test accounts are in the README, section **Local test accounts**: one each for Owner,
Supervisor, Agent and Accountant, all in the business **Local Test POS**. Use a private/incognito
window for a second person at the same time (e.g. Owner in one window, Agent in another).

## 3. Tour as the Agent (the counter)

1. Log in as the **agent**. You land on the Dashboard; the sidebar only shows what an agent may use.
2. Click the gold **New transaction** button.
3. Choose **Withdraw**, **Moniepoint**, and type `10000` on the keypad (or your keyboard).
    - Watch the charge (₦200), fee (₦50) and profit (₦150) appear as you type.
    - On the right: _Cash in drawer −₦9,800, Moniepoint wallet +₦9,950_.
4. Press **Save** (or Enter). The drawer balance at the top drops, and the entry appears under
   _Your recent entries_.
5. Try **Transfer** `5000`, **Airtime** `1000` (3% commission), and a **Pending** withdrawal with
   _"I already gave the customer cash"_ ticked.
6. Open **Transactions**: today's volume and profit. Click a row to see the receipt and its
   **ledger lines** (money in and out always add up).
7. Open **Float** and press **Move money → Top-up**. As an agent it becomes a _request_.
8. Press **Lock screen** (bottom of sidebar). Set a PIN first under _Settings → Security_ to unlock
   with the keypad.
9. Try it on a phone-sized window: in Chrome press **F12**, then **Ctrl + Shift + M**.

## 4. Tour as the Owner

1. Log out (your name, bottom-left → Log out) and log in as the **owner**.
2. **Float** — see the agent's request under _Waiting for approval_; **Approve** it and watch the
   balances move. Use **Move money** for a top-up, transfer between Ketu and Ojota, or a cash
   pickup. Click the bell icons to set _low-balance alerts_.
3. **Transactions** — every outlet and agent. Open one and **Void this entry** (a reason is
   required); the money is reversed but the record stays, marked _Voided_.
4. **Settings → Charges & providers** — the rules in plain words. Click **Edit** on
   _Transfer → Customer charge_, change a band, save, then record a transfer as the agent to see the
   new charge. Old transactions keep their old charges. Add a provider (e.g. _Paystack Terminal_)
   and every outlet gets a wallet for it.
5. **Settings → Business** — closing time, require two-factor login, idle lock time.
6. **Outlets** — add an outlet; it gets its own cash drawer and wallets automatically.
7. **Staff** — invite someone by email. Locally the email is written to
   `storage/logs/laravel.log`; open that file, find the newest _Accept invitation_ link and open it
   in a private window.
8. **Audit log** — every important action, who did it and when, with before/after values.
9. **Settings → Appearance** (your name, bottom-left → Settings) to switch light/dark mode.

## 5. Tour as the Supervisor and Accountant

- **Supervisor**: can approve transfers and swaps between their outlets and void entries, but not
  head-office top-ups, settings or the audit log.
- **Accountant**: read-only — sees transactions and the audit log, cannot record or change anything.

## 6. Start from scratch (optional)

Sign up at http://localhost:8010/register as a brand-new business. The 6-digit email code is in
`storage/logs/laravel.log` (search for the newest "Confirm your email"). New businesses get the
three providers and starter charge rules automatically.

## What is not built yet

Offline mode for agents, statement import, reversal follow-up, end-of-day reconciliation, the
live owner dashboard, reports and exports, the demo data and the live website. See `docs/PLAN.md`.
