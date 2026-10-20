# Putting FloatBook online

Everything here is free. You do the sign-ups and paste the secrets yourself; nothing
secret is ever committed to the repository.

It takes about 30 minutes. Keep a notepad open: you will collect **four values** along
the way and paste them into Render at the end.

| #   | What you need       | Where it comes from                              |
| --- | ------------------- | ------------------------------------------------ |
| 1   | `APP_KEY`           | Your own computer (step 1)                       |
| 2   | `DB_URL`            | Neon (step 2)                                    |
| 3   | `BREVO_API_KEY`     | Brevo (step 3)                                   |
| 4   | `MAIL_FROM_ADDRESS` | The email address you verify with Brevo (step 3) |

---

## Step 1 — Make an app key

This is the secret FloatBook uses to encrypt cookies and sessions. Open PowerShell in
the `floatbook` folder and run:

```powershell
php artisan key:generate --show
```

It prints something like `base64:9Xk2…=`. Copy the **whole line**, including
`base64:`. That is **value 1**.

> Do not reuse the key from your `.env`. A separate key for the live site means a
> leaked local file cannot unlock the live one.

---

## Step 2 — The database (Neon)

Neon gives a free PostgreSQL database that keeps your data between deploys.

1. Go to **https://neon.com** and click **Sign up**. Use **Continue with GitHub** so
   there is one less password to remember.
2. It asks you to create a project:
    - **Project name:** `floatbook`
    - **Postgres version:** leave the default
    - **Region:** choose **Europe (Frankfurt)** — the closest free region to Nigeria
    - Click **Create project**.
3. You land on a page with a **Connection string** box. Make sure the dropdown says
   **Pooled connection** (it usually does), then click the **copy** icon.

    It looks like:

    ```
    postgresql://floatbook_owner:AbC123@ep-cool-name-123456-pooler.eu-central-1.aws.neon.tech/neondb?sslmode=require
    ```

    That is **value 2**. Paste it into your notepad now — Neon hides the password
    afterwards.

4. Nothing else to do here. FloatBook creates its own tables on the first deploy.

---

## Step 3 — Email (Brevo)

Render's free plan blocks normal email sending, so FloatBook uses Brevo's web API.
Free plan: 300 emails a day, far more than this needs.

1. Go to **https://www.brevo.com** and click **Sign up free**. Use a real email
   address; they send a confirmation link.
2. Confirm the email, then fill in the short "about your company" form (any honest
   answer is fine; it is a small business account).
3. **Verify the address FloatBook will send from:**
    - In the left menu click **Senders, Domains & Dedicated IPs** (under _Settings_,
      the gear icon).
    - Click **Senders** → **Add a sender**.
    - **From name:** `FloatBook`
    - **From email:** your email address (e.g. `asgatcreation@gmail.com`)
    - Click **Save**, then open the email Brevo sends and click the confirmation link.
    - That address is **value 4**.
4. **Get the API key:**
    - Click your name (top right) → **SMTP & API**.
    - Open the **API keys** tab → **Generate a new API key**.
    - Name it `floatbook-live` → **Generate**.
    - Copy the key (it starts with `xkeysib-`). That is **value 3**. Brevo shows it
      **once**, so paste it into your notepad straight away.

---

## Step 4 — The app (Render)

1. Go to **https://render.com** and click **Get Started** → **GitHub**. Allow Render
   to see your repositories.
2. On the dashboard click **Add new** → **Blueprint**.
3. Pick the **asgatcreation/floatbook** repository and click **Connect**.
   Render reads `render.yaml` and shows one service called **floatbook**.
4. It asks for the values marked as secrets. Fill them in:

    | Field               | What to paste                   |
    | ------------------- | ------------------------------- |
    | `APP_KEY`           | value 1 (`base64:…`)            |
    | `DB_URL`            | value 2 (the Neon string)       |
    | `BREVO_API_KEY`     | value 3 (`xkeysib-…`)           |
    | `MAIL_FROM_ADDRESS` | value 4 (your verified address) |
    | `APP_URL`           | leave blank for now — step 5    |

5. Click **Apply**. The first build takes about 5–10 minutes (it installs PHP, builds
   the frontend and creates the database tables). Watch the **Logs** tab; you want to
   see `FloatBook ready on port 8080`.

---

## Step 5 — Tell FloatBook its address

Once the service is live, Render shows its URL at the top, something like
`https://floatbook.onrender.com`.

1. Open the **Environment** tab of the service.
2. Set **`APP_URL`** to that exact URL — starting `https://`, with no slash at the end —
   and click **Save, rebuild, and deploy**.

This matters: links inside emails and the sign-in flow use `APP_URL`. It must start with
`https://`: a page served over https cannot load a stylesheet or a script over plain
http, so an `http://` value here shows visitors a blank white page.

---

## Step 6 — Keep it awake

Render's free service falls asleep after 15 minutes with no visitors, and a sleeping
service runs nothing — no daily email, no nightly demo rebuild. A free pinger fixes it.

1. Go to **https://cron-job.org** and sign up (free).
2. Click **Create cronjob**.
    - **Title:** `FloatBook awake`
    - **URL:** `https://your-app.onrender.com/up`
    - **Schedule:** **Every 10 minutes**
    - Save.

`/up` is a tiny health-check page, so this costs almost nothing.

> Even if a ping is missed, the daily summary and the demo rebuild both check whether
> they are due and catch up when the service next wakes.

---

## Step 7 — Check it works

1. Open your Render URL. The landing page should appear with the demo buttons.
2. Click **Agent** under "look around the demo business". You should land on the
   dashboard inside _Ade & Sons POS_ with the yellow demo banner.
3. Record a transaction, then open **Reports** and download the CSV.
4. Create your own business: sign out, click **Set up your business**, and check the
   6-digit confirmation code arrives by email. (If it doesn't, see below.)

---

## If something goes wrong

**The build fails.** Open the **Logs** tab and read the last red line.

- `APP_KEY is not set` — step 1 wasn't pasted, or has a space in it.
- `SQLSTATE[08006] … could not connect` — the `DB_URL` is wrong or incomplete. Copy it
  again from Neon; it must end with `?sslmode=require`.

**The site loads but every page errors.** Usually the database. In Render open
**Shell** and run `php artisan migrate --force` to see the real message.

**No email arrives.**

- The 6-digit code is also written to the logs: Render → **Logs**, search for `code`.
- Check the Brevo key was pasted whole (starting `xkeysib-`).
- Check `MAIL_FROM_ADDRESS` is exactly the address you verified in Brevo. An
  unverified sender is Brevo's most common rejection.

**The first visit of the day is slow.** That is the free service waking up, about 30
seconds. The pinger in step 6 keeps it rare.

**You want to turn the demo off.** In Render's **Environment** tab set `DEMO_MODE`
to `false` and redeploy. The demo buttons disappear and the nightly rebuild stops.

---

## Day-to-day afterwards

- **New code:** push to `main`. Render rebuilds and redeploys on its own, running any
  new migrations first.
- **A one-off command:** Render → **Shell**, e.g. `php artisan floatbook:daily-summary --force`.
- **The database:** Neon keeps a 7-day history on the free plan, so a bad day can be
  restored from the **Branches** page.
