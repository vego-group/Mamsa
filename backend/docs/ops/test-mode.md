# Test mode — fixed OTP + simulated payments (production-safe)

Lets you log in and book on **production** with three fixed demo accounts —
**without** a real SMS or a real Moyasar charge — while every real user keeps
getting a random SMS OTP and a live charge.

It is **scoped to an explicit phone allowlist**, so it is safe to leave enabled
in production: only the three numbers below are ever affected.

## The three accounts

| Role | Phone (login) | Dashboard/admin form | App |
|---|---|---|---|
| **SuperAdmin** | `+966555000003` | `555000003` | admin.mamsaa.com |
| **Partner** | `+966555000002` | `555000002` | partner.mamsaa.com |
| **User** | `+966555000001` | — | mamsaa.com |

The login OTP for all three is the value of `TEST_OTP_CODE` (see below).

## Env (both switches default OFF)

```env
TEST_OTP_MODE=true            # the 3 phones accept TEST_OTP_CODE, no SMS sent
TEST_PAYMENTS_MODE=true       # the 3 phones book without a real Moyasar charge
TEST_OTP_CODE=<private-6-digit>   # e.g. 731905 — NOT a guessable value
# Optional overrides (defaults shown):
TEST_SUPERADMIN_PHONE=+966555000003
TEST_PARTNER_PHONE=+966555000002
TEST_USER_PHONE=+966555000001
# TEST_PHONES=+9665...,+9665...   # extra allowlisted numbers, comma-separated
```

`TEST_OTP_CODE` has **no default** — leave it blank and the OTP bypass stays
inert even with `TEST_OTP_MODE=true` (so the public repo never ships a working
credential). Set a private 6-digit value; it must be 6 digits because the
admin/partner login screens validate `digits:6`.

## Turn it on (production)

```bash
cd ~/domains/api.mamsaa.com/app_core
# 1) set the three env vars in .env (TEST_OTP_MODE, TEST_PAYMENTS_MODE, TEST_OTP_CODE)
/opt/alt/php84/usr/bin/php artisan config:clear && /opt/alt/php84/usr/bin/php artisan config:cache
# 2) create/refresh the three accounts (idempotent; only ADDS roles, never renames/demotes)
/opt/alt/php84/usr/bin/php artisan test-accounts:sync
# restart the worker so the long-running process picks up the new env
docker compose restart worker   # (or the host equivalent)
```

`test-accounts:sync` prints the numbers, their roles, and the active OTP code.

## Turn it off

Set `TEST_OTP_MODE=false` and `TEST_PAYMENTS_MODE=false`, then
`config:clear && config:cache`. The three phones immediately fall back to real
SMS OTP and live charges — no code change, no redeploy. The accounts remain in
the DB (harmless; the SuperAdmin one can log in only via real SMS once the
switch is off).

## Security notes

- **Only the allowlisted phones** are ever bypassed. `App\Support\TestMode`
  checks both master switches AND allowlist membership on every call; unit tests
  assert a real number is never bypassed.
- **The SuperAdmin test account is a standing admin credential** while
  `TEST_OTP_MODE=true`. Keep `TEST_OTP_CODE` private and flip `TEST_OTP_MODE=false`
  whenever you are not actively testing. Rotate the code if it leaks.
- Test payments mark the booking **paid** locally and skip Moyasar entirely — no
  money moves, but reports/wallet reflect a paid booking for the demo user.

## Code map

| Concern | File |
|---|---|
| Config + env | `config/test_mode.php`, `.env.example` |
| The gate | `app/Support/TestMode.php` |
| OTP bypass | `app/Services/OtpService.php` (`request()`) |
| Payment bypass | `app/Http/Controllers/Api/V1/PaymentController.php` (`isTestMode()`) |
| Provisioning | `app/Console/Commands/SyncTestAccounts.php` (`test-accounts:sync`) |
| Tests | `tests/Feature/TestModeTest.php` |
