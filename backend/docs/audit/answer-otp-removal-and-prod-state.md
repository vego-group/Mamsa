# Mamsa — Backend Answer: Fixed OTP on Production, and a Correction to §D

**From:** backend · **Date:** 2026-08-14
**In reply to:** the consolidated outstanding items. **Report only — no code changed.**

**Answer to Question 1, plainly: NO. It has not been removed. A fixed OTP is still accepted on
production**, for a whitelist of three phone numbers — one of which is a SuperAdmin. Your reasoning is
correct and the rotation did not fix it. Proof and lines in §1.

**And a correction you need before planning the cutover: production is NOT on the old shape.** It
already carries all three changes. §5.

---

## 1. Question 1 — is a fixed OTP still accepted on production?

### 1.1 There are TWO fixed-code paths, and only one of them is production-guarded

**Path A — `OTP_FIXED_CODE` → prod-guarded ✅**

`app/Services/OtpService.php:166-174`:

```php
private function generateCode(): string
{
    $fixed = config('otp.fixed_code');
    if ($fixed !== null && $fixed !== '' && ! app()->isProduction()) {   // ← line 172
        return (string) $fixed;
    }
    // …random code…
}
```

Line **172** carries `! app()->isProduction()`, so this path **cannot** fire on production. This is the
path that was described as "never honoured in production" — and for this path that is true.

**Path B — `TEST_OTP_CODE` → NOT guarded ❌**

`app/Support/TestMode.php:46-51`:

```php
public static function otpBypass(?string $rawPhone): bool
{
    return (bool) config('test_mode.otp')      // TEST_OTP_MODE
        && self::code() !== null               // TEST_OTP_CODE
        && self::isTestPhone($rawPhone);       // allowlist
}
```

**There is no `isProduction()` check anywhere in this branch.** It is consumed by
`app/Services/OtpService.php:32` and `:50`:

```php
$bypass = TestMode::otpBypass($phone);                              // line 32
…
$code = $bypass ? (string) TestMode::code() : $this->generateCode(); // line 50
```

When `$bypass` is true the fixed value is written into the OTP cache as the expected code, the SMS is
skipped (`:58-60`), and the resend cooldown and daily caps are bypassed (`:36-48`).
`verify()` then compares the submitted code against that stored value —
`app/Services/OtpService.php:112` (`hash_equals`). So verification succeeds on the fixed value.

### 1.2 Which env var controls it, and its value per environment

| | Production (`api.mamsaa.com`) | Staging (`staging.mamsaa.com`) |
|---|---|---|
| `APP_ENV` / `isProduction()` | `production` / **true** | `local` / false |
| `TEST_OTP_MODE` (`config('test_mode.otp')`) | **`true`** | not set |
| `TEST_OTP_CODE` (`config('test_mode.code')`) | **set, non-empty** | set |
| `OTP_FIXED_CODE` (`config('otp.fixed_code')`) | **not set** | set |
| Allowlist (`config('test_mode.phones')`) | **3 phones** | — |
| **Fixed OTP accepted?** | **YES — via Path B** | **YES — via Path A, for ALL accounts** |

Config sources: `config/test_mode.php:31` (`otp`), `:36` (`code`), `:44-47` (`phones`);
`config/otp.php:10` (`fixed_code`).

### 1.3 Which accounts does it work for on production?

**A whitelist of exactly three**, not all accounts:

| Phone | Role |
|---|---|
| `+966555000001` | User |
| `+966555000002` | Partner |
| **`+966555000003`** | **SuperAdmin** |

Enforced by `TestMode::isTestPhone()` — `app/Support/TestMode.php:26-31` — against the allowlist built
in `config/test_mode.php:44-47` (the three `TEST_*_PHONE` values plus any `TEST_PHONES` extras).
Every other phone on production still receives a random SMS OTP and is unaffected.

### 1.4 Your argument is correct

Scoping to three accounts limits the blast radius; it does not change the nature of the flaw. For those
three, **the OTP step verifies nothing** — possession of the phone number plus a value that exists in a
config file is sufficient, and one of the three is a SuperAdmin on the live API. Rotation replaced a
published constant with an unpublished one; it did not restore the property that the second factor
proves anything.

**Removal is one environment line, no deploy, no downtime:**

```
TEST_OTP_MODE=false        # then: php artisan config:cache
```

`TestMode::otpBypass()` short-circuits on the first condition, the three phones fall back to real SMS
OTP, and no fixed value is accepted anywhere on production. Blanking `TEST_OTP_CODE` is an equivalent
second lever (`otpBypass` also requires `code() !== null`), which is why the previous shutdown on
2026-08-11 used both.

**This has not been done because the accounts are in active use for production testing** — the owner
re-enabled it deliberately. It is a live decision, not an oversight, and it can be reversed the moment
testing no longer needs it. **Recommendation: turn it off, and use staging for demo logins** — staging
already accepts a fixed code for *every* account (Path A), which is exactly what a test environment is
for.

---

## 2. Question 2 — the new value

Sent through the private channel, **not written here** — consistent with your request and with the
lesson from the exposure.

Two things worth knowing while you use it:

- **Staging is broader than production.** On staging the fixed code works for **every** account (Path A,
  `OTP_FIXED_CODE` + non-production), not just the allowlist. So any partner or admin phone on staging
  will accept it — including `500000002`, `500000003`, `512345678` for the partner dashboard.
- `request-otp` is throttled to **3 per 10 minutes per phone**; a 429 is the throttle, not a bad value.
  The `422 OTP_INVALID` with a remaining-attempts counter that you observed is the correct signature of
  a changed value, as you read it.

---

## 3. §A — confirmed, and it applies to our repositories too

You were right to suggest checking. `git log -S` on our side:

| Repository | Commits touching the value |
|---|---|
| Monorepo (`vego-group/Mamsa`, public) | **28** |
| Deploy repo (`vego-group/mamsaa-backend-api`) | **8** |

Earliest is `69d0a44` / `d17700b` (2026-07-28). So the value is in the history of **both** repositories,
and the same conclusion holds: editing files does not touch history, a rewrite would break every clone,
and since the value is rotated it is no longer a live credential. History is being left alone here too.

**But note the asymmetry this creates:** on your side rotation closed the issue, because §B removed the
fixed value entirely. On our side rotation only moved it — the current value is live on production right
now (§1). The equivalent of your fix is §1.4, and it remains open.

Separately, the **old** value is still present in four working files and will be scrubbed so the next
one does not leak the same way: `backend/.env.example:97` (a real `OTP_FIXED_CODE=` default),
`backend/config/otp.php:7`, `backend/database/seeders/DashboardTestPartnerSeeder.php:27,104`, and
`backend/postman/Mamsa-API.postman_collection.json` (6 occurrences).

---

## 4. §B — the root cause, acknowledged

Your diagnosis is the right one and the fix is the right shape: a mock that accepts any six digits has
no credential to leak, rotate, or synchronise. The coupling — "the same constant works in mock mode and
against a real environment" — is what turned a test convenience into a production credential.

The backend equivalent is **§1.4**: production should not accept any fixed value, and the environment
that legitimately needs deterministic logins (staging) already has its own, independent of production.
That is now the only remaining piece of this incident.

---

## 5. ⚠️ §D — correction: production is NOT on the old shape

**This is the part to read before planning the cutover.** §D states *"production stays on the old shape
until then."* That is no longer accurate — **production already carries all three changes:**

| Change | Production status |
|---|---|
| `pending_payment` | **Live** since 2026-08-13 |
| `isActive` on `/admin/partners` | **Live** since 2026-08-13 |
| `netRevenue` + `vat` / `vatCollected` on both `reports/summary` | **Live** since 2026-08-13 |

They were deployed on the owner's instruction. Verified live on production: `/admin/me` returns
`role` + `permissions[]`; `/admin/partners` returns `status` + `isActive`; `/admin/reports/summary`
returns `netRevenue` + `vatCollected` reconciling exactly
(`266747 + 15943.35 = 282690.35`); bookings intact at 69; `/up` 200.

**What this means for you — and why it is not an emergency:**

- **The two additive changes are harmless.** `isActive` and the VAT fields are *new* keys. Clients that
  do not read them are unaffected, so your current production build keeps working.
- **`pending_payment` is the one with a behavioural edge**, and it is bounded: production had **zero**
  bookings in that state, so no existing record changed. Only a **new** unpaid booking would render
  wrongly on a frontend still comparing against `pending`.
- **You can still ship as one release** — nothing forces a rushed deploy. The window is the same one
  described previously and it has not widened.

The reason this is being stated rather than left in a status table: **your cutover plan assumes a
production shape that is not the current one**, and that assumption is exactly the class of thing that
caused the previous two coordination problems. The wallet/payout work has *not* shipped functionally —
those endpoints are registered only outside production and return **404** there (verified: 0 routes,
all endpoints 404), and the new tables are empty.

Apologies for the sequencing; the underlying pattern was raised and acknowledged previously and it
recurred here.

---

## 6. Summary

| Item | Answer |
|---|---|
| **Q1 — fixed OTP removed from production?** | **No.** Still accepted via `TestMode::otpBypass()` (`TestMode.php:46-51`, consumed at `OtpService.php:32,50`) — that branch has **no** `isProduction()` guard |
| Which accounts | **Whitelist of 3**: `+966555000001/2/3` — the last is **SuperAdmin** (`TestMode.php:26-31`, `config/test_mode.php:44-47`) |
| The other path | `OTP_FIXED_CODE` **is** prod-guarded — `OtpService.php:172` |
| Removal | `TEST_OTP_MODE=false` + `config:cache` — one line, no deploy. **Recommended**; open pending the owner's decision, since the accounts are in active use |
| Staging scope | Fixed code works for **all** accounts there (Path A), not just an allowlist |
| **Q2 — new value** | Sent privately, not in this document |
| §A | Confirmed — value is in **28** monorepo commits and **8** backend-api commits; history left alone |
| §B | Acknowledged; our equivalent fix is Q1's removal |
| §C | Agreed |
| **§D** | ⚠️ **Corrected — production already has all three changes** (§5). Additive ones are harmless; `pending_payment` affects only new unpaid bookings |
