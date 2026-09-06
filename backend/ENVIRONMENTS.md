# Environments — Production vs Staging

> **`switch-env.sh` was removed on 2026-09-06, along with the workflow this
> document used to describe. Do not recreate it.** What follows records why, and
> what to do instead.

## Why it was removed

The script swapped the active `.env` between two complete files kept on the same
box (`.env.production` and `.env.testing`), then rebuilt the config cache. It
worked exactly as designed, and that was the problem.

On **2026-06-30 14:25** it was run on the production host. Production then served
traffic with `APP_ENV=local` and `APP_DEBUG=true` until **2026-07-05 13:07** —
four days and 23 hours. Sixty errors were logged in that window. The application's
own log dates it precisely, because Laravel writes the environment name into
every line: 108 entries on that host are prefixed `local.`, bracketed by those two
timestamps, against 717 prefixed `production.` outside them.

Debug mode on a production host is not a degraded service. Laravel's exception
page renders the loaded environment, so one unhandled error displays the database
password, the app key and every service secret to whoever triggered it.

The previous version of this file said, as a convenience:

> *Everything else (DB, `APP_KEY`, OTP policy, cache/session/queue drivers) stays
> the same across both files.*

That sentence is the leak. Because those keys were identical, the "testing"
profile was not a sandbox — it was the production database and the production app
key, with debug switched on and live payment keys swapped for test ones. A
rendered debug page would have exposed the live values of `APP_KEY`,
`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` and `MOYASAR_WEBHOOK_SECRET`.

Two further details made it worse than a mistake anyone would notice:

- `.env.production` had been renamed to `.env.production.disabled`, so the way
  *back* failed while the way *in* still worked.
- Both profile files were last touched on 2026-06-30 while the live `.env` moved
  on to 2026-08-23, so the switch would have restored a two-month-old config.

## What replaces it

**Nothing, on production.** A production host runs one configuration. Testing
happens on staging, which is a separate host with a separate database.

Two guards now exist, and neither can be turned off by editing an env file:

1. **A production marker.** A file at `<domain>/.mamsa-production`, outside the
   application directory and outside git. When it is present and `APP_DEBUG` is
   true, the application refuses to boot — see
   `AppServiceProvider::refuseDebugOnAProductionHost()`. The path is hardcoded on
   purpose: read from config, it could be pointed at a file that does not exist,
   disabling the check by the same mechanism it exists to survive.
2. **The test-suite guard.** `Tests\TestCase::setUp()` refuses to run against any
   service that is not isolated. Unrelated to this incident, same principle.

To change one setting on a live host, edit that host's `.env` and rebuild the
cache — never swap whole files:

```bash
cd ~/domains/<host>/app_core
cp .env ~/env-backups/$(date +%F-%H%M).env      # outside the domain directory
# edit the single key, matching on CONTENT not line number:
sed -i '/^SOME_KEY=/s/.*/SOME_KEY=value/' .env
php artisan config:clear && php artisan config:cache
php artisan tinker --execute="echo config('some.key');"   # verify the RESOLVED value
```

The last line matters: config is cached on these hosts, so an `.env` edit alone
changes nothing until the cache is rebuilt, and the file is not evidence of what
the application is using.

## How the two environments differ

Kept as reference. This is a description of two **separate hosts**, not a recipe
for two files on one host.

| Key | STAGING | PRODUCTION | Effect |
|---|---|---|---|
| `APP_ENV` | `local` | `production` | Master switch. `production` disables fake payments and hides `debug_otp`. |
| `APP_DEBUG` | `true` | `false` | Stack traces on/off. **Never true on production** — the marker above enforces this. |
| `MOYASAR_SECRET_KEY` | `sk_test_…` | `sk_live_…` | Test keys charge the sandbox; live keys charge real cards. |
| `MOYASAR_PUBLISHABLE_KEY` | `pk_test_…` | `pk_live_…` | Frontend payment form. |
| `MOYASAR_WEBHOOK_SECRET` | (own value) | (own value) | Callback signature check. **Must differ between hosts** — a shared value means an event accepted by one validates on the other. |
| `SMS_DRIVER` | `log` | `fgc` | `log` writes the OTP to `storage/logs` instead of sending. |
| `MAIL_MAILER` | `log` | `resend` | `log` writes the email code to the log instead of sending. |
| `DB_*`, `APP_KEY` | staging's own | production's own | **Must never match.** Identical values are what turned a "sandbox" into production with debug on. |

> **Behavioral notes**
> - On staging with `SMS_DRIVER=log` / `MAIL_MAILER=log`, read OTP and email codes
>   from `storage/logs/laravel.log` — nothing is actually sent.
> - On staging (`APP_ENV` ≠ production) the OTP endpoints also return `debug_otp`,
>   so the log is not needed for phone OTP.
> - Payment test mode activates when the Moyasar secret is an `sk_test_…` key (or
>   blank) AND `APP_ENV` ≠ production. Never leave the secret blank on production —
>   the gateway guard returns `503` rather than faking a payment.
> - Moyasar's webhook registry is **account-level and shared across test and live
>   keys**. Staging events are delivered to production's endpoints and vice versa,
>   so staging must never hold a `moyasar_id` copied from production.

## Frontend (separate app)

The dashboard and site read their own env (`NEXT_PUBLIC_API_BASE_URL`,
`NEXT_PUBLIC_MOYASAR_PUBLIC_KEY`). Point those at the matching backend and
publishable key per environment using the frontend's own `.env` files.
