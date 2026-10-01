# Staging exposure: `debug_otp` and `APP_DEBUG` — decision record

**Written:** 2026-10-01 · **Status:** ⏸ **decision pending** (Ahmed). Nothing below has been changed.

## What is true today (verified by real calls, 2026-10-01)

| Surface on `staging.mamsaa.com` | Returns the one-time code in the response? |
|---|---|
| Guest phone login: `POST /api/v1/auth/request-otp` | ✅ **yes**, `data.debug_otp`, **for any phone number** |
| Guest email verification: `POST /api/v1/user/email`, `/email/resend` | ✅ **yes**, `data.debug_otp` |
| Partner dashboard: `POST /auth/otp/request` | ❌ no. A fixed code works for allowlisted test phones only |
| Admin panel: `POST /admin/auth/request-otp` | ❌ no. Same allowlist rule |

**Consequence:** anyone who can reach `staging.mamsaa.com` can sign in as **any guest account on staging**.
No leaked code is needed: the server hands out the real code. The code path is `! app()->isProduction()`
in `OtpAuthController` and `User\EmailController`, so it's on in every non-production environment.

**Also on:** `APP_DEBUG=true` on staging. An error response (seen on a rate-limited request) returns the
**full stack trace with server file paths**. Production refuses to boot with debug on, but staging doesn't.

## The rule

> **While `debug_otp` is on in an environment, no real person's data lives in that environment.**
> That covers phone numbers, email addresses, names, identity or commercial-registration documents, bank
> details, and real bookings or payments. Test data only.

### ⚠️ The rule is broken on staging today

- **Both real phone numbers on record** (`+966537486167`, `+966500433980`) have accounts on staging.
- **9 accounts on staging use `gmail.com` addresses,** which may belong to real people. They haven't been
  checked one by one.
- **Staging totals:** 28 users, 86 bookings, 37 payments (test-mode Moyasar).

So today, anyone can request a code for one of those real numbers on staging and sign in as that person.
**This was not tested against the real accounts.**

## Options, before UAT (Ahmed decides)

| | What | UAT impact |
|---|---|---|
| **A** | Keep `debug_otp` through UAT. **Remove or anonymise every real person's account on staging now**, and keep it that way | None. Testers sign in with any synthetic number (`05971xxxxx`) |
| **B** | Turn `debug_otp` off on staging (code change: gate it behind an env flag, default off) | Guest testers can only sign in with allowlisted test phones. Fresh-registration tests need allowlisted numbers |
| **C** | Do nothing | ❌ Leaves a real person's staging account open to anyone |

**Separately, whichever option is chosen:** set `APP_DEBUG=false` on staging. UAT doesn't need stack
traces in HTTP responses, and errors are still in `storage/logs/laravel.log`.

**Recommendation:** **A**, plus `APP_DEBUG=false` on staging, with the clean-up of real accounts done
before UAT starts. Production is unaffected either way: `debug_otp` is never returned there, and
production doesn't boot with debug on.
