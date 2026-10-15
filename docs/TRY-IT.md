# Try FloatBook on your computer

A guided tour of everything built so far (milestones 1–5 plus terminals). It takes about 20 minutes.

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
> After pulling new code, run `php artisan migrate` once to update your database (your data is kept).

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

## 7. Failed and pending transactions (reversals)

1. As the agent, record a **Withdraw** `20000` with status **Pending** and tick _"I already gave the
   customer cash"_. A follow-up case opens and **Reversals** in the sidebar shows a count. (A
   **Failed** entry opens a case too.)
2. Open **Reversals**. Each case shows how long it has been open against the 72-hour target.
   Add a note and ticket number (**Save note**), mark it **Escalated**, then close it with
   **It went through**, **Customer refunded** or (owner) **Write off**.
3. A pending entry can also be settled from its receipt page: **It went through** or **It failed**.

## 8. Import a provider statement

1. As the owner or supervisor, open **Statement imports**, choose the **terminal** the statement is for,
   and upload a CSV or Excel export from the provider. If the columns aren't recognised you can pick
   them yourself. Rows are checked only against that terminal's entries.
2. FloatBook compares every row with what was recorded and sorts them into **Matched**,
   **Doesn't agree**, **Missing in FloatBook** (on the statement but nobody entered it) and
   **Only in FloatBook** (entered, but the provider has no record of it).
3. Tick the _Missing in FloatBook_ rows you want and press **Add … to FloatBook**.

## 9. Several terminals in one shop

1. As the **owner**, open **Outlets**. Each outlet lists its **Terminals**. Ketu Main already has
   _Terminal 2_ (a second Moniepoint machine with its own balance).
2. Press **Add terminal**: name it _Terminal 3_, pick **Moniepoint**, type its TID, and choose
   **Same account as Terminal 2** (both machines pay into one account, so they share a balance).
   A bank POS works too: add the bank first under **Settings → Charges & providers**.
3. Open **Staff**, **Edit** the agent and tick **Terminal 2** and **Terminal 3** under
   _Terminals they're in charge of_.
4. Log in as the **agent** and open **New transaction**: only those two terminals show, and the
   one used last is already picked. Record a withdrawal on Terminal 2.
5. Oops, it was Terminal 3. Open the entry and press **Wrong terminal?** Pick Terminal 3, give a
   reason, and **Move entry**. The original stays, marked as moved; the same transaction appears on
   Terminal 3 at the same time with the same charge. Nothing is deleted. Agents can do this for
   their own entries on the same day; after that a supervisor or the owner has to.
6. **Float** shows each balance with the terminals that pay into it.

## 10. Offline mode (the agent's phone)

Offline mode only works on the built app, not while `npm run dev` is running:

1. Stop `npm run dev` if it's running, then run `npm run build` once.
2. Log in as the **agent** and open **New transaction** (this saves the page on the device).
3. In Chrome press **F12 → Network** and set _No throttling_ to **Offline**.
4. Record a withdrawal. It says _"Saved on this phone"_, the top bar shows **1 waiting to sync**,
   and the drawer balance already includes it. Reloading the page still works.
5. Set the network back to **No throttling**. Within a few seconds the entry is sent, the pill
   disappears and the entry shows under _Your recent entries_. It is never recorded twice.
6. In Chrome's address bar, the **Install** icon adds FloatBook to the home screen / desktop.
   On a real phone, a _"Put FloatBook on this phone"_ card offers the same (on iPhone it shows
   the Share → Add to Home Screen steps).
7. At phone width, the menu moves to a bar at the bottom: Home, Transactions, the gold **+** for a
   new entry, Reversals and More.

## 11. Expenses

1. Open **Expenses**. Record generator fuel: pick the outlet, **Generator fuel**, type `4,500`,
   choose **Cash in drawer** and add a note. The month's total and a bar per category appear.
2. The drawer goes down by ₦4,500 and the day's profit is counted after it. A mistake is
   **Void**ed with a reason (owner or supervisor); the money goes back and the line stays, crossed out.

## 12. End of day (counting the drawer)

1. Open **End of day**. Each outlet shows its last 7 days: _Not counted_, _To approve_,
   _Balanced_ or _Difference_. Press **Count today** for Ketu Main.
2. The count is **blind**: you don't see what the books expect. Count the notes with the − / +
   buttons (₦1,000 × n, ₦500 × n …), add coins, and type each terminal's balance from its screen.
3. If it doesn't match, FloatBook asks why. **Submit count** closes the day: nothing more can be
   recorded, voided or moved on it for that outlet.
4. Now you see **Books vs Counted vs Difference** for the drawer and each wallet. Log in as the
   **supervisor** (someone else must approve your own count; only the owner may approve their own)
   and **Approve**, optionally holding a staff member responsible for a shortage, or
   **Send back for a recount**.
5. Approving posts the difference so the books match the shop. Shortages owed by staff show on
   the End of day page; **Paid back** puts the cash into today's drawer.
6. The **owner** can **Reopen this day** with a reason: the difference is undone and it can be
   counted again. Everything is in the audit log.

## What is not built yet

The live owner dashboard, reports and exports, alerts and the daily summary email, the demo data and
the live website. See `docs/PLAN.md`.
