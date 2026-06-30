# Environment Switching — Production ⇄ Testing

The app runs from a single active `.env`. To switch quickly and safely, keep two
complete env files on the server and swap them with [`switch-env.sh`](./switch-env.sh).

## One-time setup (on the server)

```bash
cd ~/domains/api.mamsaa.com/app_core

cp .env .env.production     # snapshot the live config
cp .env .env.testing        # then edit the TESTING toggles below
```

Edit `.env.testing` so the differing keys point at sandbox/safe values (table below).
`.env.production`, `.env.testing` and `.env` are all git-ignored — secrets never get committed.

## Switch

```bash
# php is the 8.4 binary on this host:
PHP_BIN=/opt/alt/php84/usr/bin/php ./switch-env.sh test    # → testing/sandbox
PHP_BIN=/opt/alt/php84/usr/bin/php ./switch-env.sh prod    # → live
./switch-env.sh status                                     # show active APP_ENV
```

The script backs up the current `.env` to `.env.backup`, copies the chosen file
into place, then runs `config:clear && config:cache && route:cache`.
(Tip: add `alias php=/opt/alt/php84/usr/bin/php` to `~/.bashrc` and you can drop
the `PHP_BIN=` prefix.)

## The keys that differ

| Key | TESTING | PRODUCTION | Effect |
|---|---|---|---|
| `APP_ENV` | `local` | `production` | **Master switch.** `production` disables fake payments and hides `debug_otp`. |
| `APP_DEBUG` | `true` | `false` | Stack traces on/off. |
| `MOYASAR_SECRET_KEY` | `sk_test_…` | `sk_live_…` | Test keys charge the Moyasar sandbox; live keys charge real cards. |
| `MOYASAR_PUBLISHABLE_KEY` | `pk_test_…` | `pk_live_…` | Frontend payment form. |
| `MOYASAR_WEBHOOK_SECRET` | (sandbox token) | (live token) | Callback signature check. |
| `SMS_DRIVER` | `log` | `fgc` | `log` writes the OTP to `storage/logs` instead of sending an SMS. |
| `MAIL_MAILER` | `log` | `resend` | `log` writes the email verification code to the log instead of sending. |

Everything else (DB, `APP_KEY`, OTP policy, cache/session/queue drivers) stays the
same across both files.

> **Behavioral notes**
> - In **testing** with `SMS_DRIVER=log` / `MAIL_MAILER=log`, read OTP & email codes
>   from `storage/logs/laravel.log` — no real SMS/email is sent.
> - In **testing** (`APP_ENV` ≠ production) the OTP endpoints also return `debug_otp`
>   in the response, so you don't need the log at all for phone OTP.
> - Payment **test mode** activates when the Moyasar secret key is a `sk_test_…` key
>   (or blank) AND `APP_ENV` ≠ production. Never leave the secret blank in production —
>   the gateway guard returns `503` rather than faking a payment.

## Frontend (separate app)
The dashboard/site read their own env (`NEXT_PUBLIC_API_BASE_URL`,
`NEXT_PUBLIC_MOYASAR_PUBLIC_KEY`). Point those at the matching backend + Moyasar
publishable key per environment using the frontend's own `.env` files.
