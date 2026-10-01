# Staging exposure: `debug_otp` and `APP_DEBUG` — decision record

**Written:** 2026-10-01 · **Ahmed's decision (written, 2026-10-01):** do **A now** and **B before UAT**, both of them.

| | Status |
|---|---|
| `APP_DEBUG=false` on staging | ✅ **done 2026-10-01**. A rate-limited request showed `trace`/`file`/`exception` before and a clean message after |
| Anonymise every real person's account on staging (identity only; bookings and payments kept) | ✅ **done 2026-10-01: 16 accounts.** 16 names, 13 emails and 9 phones replaced; 0 original real phones or emails left; 86 bookings and 37 payments untouched; all 9 UAT allowlist phones intact |
| **B:** `debug_otp` behind an env flag, default OFF | ⏳ **before UAT**, not yet built |

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

### The rule was broken on staging until 2026-10-01 (fixed: see Status)

- **Both real phone numbers on record** (`+96653*****67`, `+96650*****80`) have accounts on staging.
- **9 accounts on staging use `gmail.com` addresses,** which may belong to real people. They haven't been
  checked one by one.
- **Staging totals:** 28 users, 86 bookings, 37 payments (test-mode Moyasar).

Until the anonymisation, anyone could request a code for one of those real numbers on staging and sign in
as that person. **This was never tested against the real accounts.**

**How they got there:** self-registration on staging during testing, 2026-07-16 to 07-19 (plus one on
2026-08-19). It was **not** a copy of production. The two real phones are staging ids 23/24, created
2026-07-16/17. On production they're different ids (19/20), created **later** (2026-08-14), and their
emails differ. The other 14 don't exist on production. The one production copy ever made (the
2026-09-27 rehearsal) went into a **local** container, not staging; see `known-risks-local-copies.md`.

**Kept:** a backup of the 16 original rows is on the staging server only (`~/backups/`, chmod 600).
Ahmed decides whether to delete it, since it holds the original personal data.

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
