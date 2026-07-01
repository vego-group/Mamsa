# Production Readiness Checklist — Mamsaa API

Go-live checklist for the OTP auth and Moyasar payment flows. Pairs with
[`DEPLOY.md`](./DEPLOY.md) (how to deploy) — this file covers what must be
**configured and verified** before taking real traffic.

> Most hardening is already in code (throttling, server-side amounts, server-side
> verification, idempotent webhook, PCI token flow, daily OTP caps, fail-fast
> gateway guard). The items below are the **config + ops** steps that make it real.

---

## 1. Critical environment flags

| Var | Value | Why it matters |
| --- | --- | --- |
| `APP_ENV` | `production` | Flips `app()->isProduction()` → **disables test-mode fake payments** and **removes `debug_otp`** from API responses. Single most important flag. |
| `APP_DEBUG` | `false` | No stack traces / secrets leaked in responses. |
| `APP_URL` | `https://api.mamsaa.com` | Correct HTTPS links + callback URLs; avoids http→https POST→GET downgrades. |

## 2. Payment gateway (Moyasar) — LIVE keys

```env
MOYASAR_PUBLISHABLE_KEY=pk_live_xxxxxxxxxxxx
MOYASAR_SECRET_KEY=sk_live_xxxxxxxxxxxx
MOYASAR_WEBHOOK_SECRET=<secret_token set on the Moyasar webhook>
```

- A **blank `MOYASAR_SECRET_KEY` in production now returns `503`** (never a fake
  "paid"). `initiate`/`pay` call `assertGatewayConfigured()`.
- Register the webhook in the Moyasar dashboard:
  - **URL:** `https://api.mamsaa.com/api/v1/payments/callback`
  - **Secret token:** same value as `MOYASAR_WEBHOOK_SECRET`
  - The callback rejects any request whose `secret_token` doesn't match
    (`hash_equals`).
- Keys live in `.env` only — never commit real values.

## 3. SMS / OTP

```env
SMS_DRIVER=fgc            # default is 'log' which NEVER sends — must be fgc in prod
SMS_SENDER_ID=Mamsa       # must be an approved sender name
FGC_SMS_USERNAME=xxxxxxxx
FGC_SMS_PASSWORD=xxxxxxxx
FGC_SMS_SENDER=Mamsa

OTP_MAX_PER_PHONE_PER_DAY=10   # anti SMS-pumping (0 disables)
OTP_MAX_PER_IP_PER_DAY=30      # anti SMS-pumping (0 disables)
```

- OTP route throttling is already enforced: `request-otp` 5/min, `resend-otp`
  3/min, `verify-otp` 10/min (per IP).
- OTP codes are only returned in responses outside production (`debug_otp`).

### Optional: atomic OTP store
`file` cache (shared hosting) isn't atomic, so the OTP attempt counter / daily
caps can race slightly. For stricter correctness:
```bash
php artisan cache:table && php artisan migrate --force
# .env:
OTP_STORE=database
```

## 4. Email verification — Resend (FR-005 / FR-006)

Partner registration sends a verification code by email
(`POST /auth/email/verify` + `/auth/email/request-otp`). The code uses Laravel's
`Mail` facade (driver-agnostic), so any mailer works — but **Resend is
recommended on shared hosting** because it sends over HTTPS API, avoiding the
SMTP ports (25/465/587) that Hostinger commonly blocks.

```bash
# install the official Laravel driver (one-time)
composer require resend/resend-laravel
```

```env
MAIL_MAILER=resend            # default is 'log' which NEVER sends — must change in prod
RESEND_API_KEY=re_xxxxxxxxxxxx
MAIL_FROM_ADDRESS="no-reply@mamsaa.com"
MAIL_FROM_NAME="مَمسَى"
```

- In the Resend dashboard, verify domain `mamsaa.com` and add the **SPF + DKIM**
  DNS records (otherwise mail lands in spam).
- After deploy run `php artisan view:cache` to compile the email template.
- To test without live mail: `MAIL_MAILER=log` → read the code from
  `storage/logs/laravel.log`, then switch back to `resend`.

## 5. Database migration

A migration adds `unique(booking_id)` to `payments` (DB-level idempotency so the
`firstOrCreate` race can't create duplicate payments).

```bash
# Verify no existing duplicates BEFORE migrating:
php artisan tinker --execute="echo \App\Models\Payment::groupBy('booking_id')->havingRaw('count(*) > 1')->count();"
# Expect 0, then:
php artisan migrate --force
```

## 6. Pre-launch verification

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` https — confirmed.
- [ ] Moyasar **live** keys set; webhook registered with matching secret.
- [ ] `SMS_DRIVER=fgc` with valid FGC creds + approved sender.
- [ ] `MAIL_MAILER=resend` with valid API key + domain SPF/DKIM verified; real
      partner email code received (not just logged).
- [ ] `php artisan migrate --force` ran clean (unique booking_id applied).
- [ ] `config:cache` + `route:cache` rebuilt after every `.env` change.
- [ ] Real OTP received on a live phone (not just logged).
- [ ] Real (small) payment completes; booking flips to `confirmed`; partner +
      admins notified; guest gets confirmation SMS.
- [ ] Webhook fires and is idempotent (re-delivering it doesn't double-confirm
      or downgrade).
- [ ] Refund path tested via the cancellation/refund engine.
- [ ] Error monitoring in place (log channel / Sentry) for failed OTP/payments.

---

## Hardening already in code (reference)

| Area | Protection |
| --- | --- |
| OTP | route throttling, per-phone/per-IP daily caps, cooldown, max-attempts, single-use, `hash_equals`, code hidden in prod |
| Payments | server-side amount, server-side status re-fetch (`verify` + `callback`), webhook secret check, idempotent `confirmBooking`, no-downgrade callback, `unique(booking_id)`, route throttling, fail-fast gateway guard |
| PCI | Moyasar.js token / Apple Pay token flow — card data never touches the server |
| Refunds | `MoyasarService::refund()` / `void()` with logging (SRS 2.3.3) |
