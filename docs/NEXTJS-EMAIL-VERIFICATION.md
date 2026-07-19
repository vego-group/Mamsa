# Done — Email verification + booking emails (backend)

**Date:** 2026-07-19 · Reply to `mamsa-email-backend-task.md` · **Status:** live on staging, deployed to prod (delivery notes in §5).

Agreed with your framing: login stays **phone-OTP only**; email is a verified contact channel. One contract correction up front, then everything you asked for, then your 4 questions.

---

## 0. Path correction — user app is `/api/v1`, not root-mounted

Root-mounted + cookie session is the **partner dashboard** surface only. The user app (www) speaks Bearer to `https://api.mamsaa.com/api/v1` (flow map §0). So your `/account/*` proposal landed as:

| Your doc | Actual endpoint (Bearer, `/api/v1`) |
|---|---|
| `POST /account/email` | `POST /user/email` |
| `POST /account/email/verify` | `POST /user/email/verify` |
| `POST /account/email/resend` | `POST /user/email/resend` |
| `GET /account` | `GET /auth/me` — already returns `email` + `email_verified` (also on `GET /user/profile`) |

Envelope: the standard user-site `{ success, message, data }`, and every failure carries a **machine `code`** at top level (same pattern as the intent guard) — branch on `code`, never on the Arabic `message`.

## 1. The endpoints

```
POST /user/email            { "email": "guest@example.com" }
  200 → { data: { email, verified: false, resend_available_in: 60 } }
  Stores the email UNVERIFIED + sends a 6-digit OTP to it.
  Changing an existing email → verified drops to false (also true if changed via PUT /user/profile).
  422 EMAIL_INVALID | 422 EMAIL_ALREADY_IN_USE | 429 RATE_LIMITED (+ retry_after)

POST /user/email/verify     { "code": "123456" }
  200 → { data: { email, verified: true } }
  422 OTP_INVALID (+ remaining_attempts) | 422 OTP_EXPIRED | 422 OTP_MAX_ATTEMPTS

POST /user/email/resend     (no body)
  200 → same shape as POST /user/email
  429 RATE_LIMITED (+ retry_after seconds)
```

- **Validity 300s (5 min)** — unified with the phone OTP (your open question).
- **5 wrong attempts** kill the code (`OTP_MAX_ATTEMPTS`); after that, only a new code helps. A stale-but-well-formed code after the kill or after expiry → `OTP_EXPIRED`.
- Cooldown 60s, code 6 digits, throttled routes (5/min send + resend, 10/min verify).
- ⚠️ **Staging test code is `111222`, not `123456`** — same fixed code as the phone OTP. Fixed codes are hard-disabled when `APP_ENV=production` (your production rule holds, enforced in one place for both channels).

## 2. Booking gate — `EMAIL_VERIFICATION_REQUIRED`

`POST /bookings` (server-side) returns `422 { code: "EMAIL_VERIFICATION_REQUIRED" }` when the guest has no **verified** email.

- **Staging: ON now** — integrate against it.
- **Prod: OFF (env flag)** until your user site ships the verification screen — the current live frontend has no handler for this code, and every existing phone-only guest would be blocked from booking. Tell us when you go live and we flip `BOOKING_REQUIRE_VERIFIED_EMAIL=true` — zero deploy.

## 3. The emails (all server-side — you send nothing)

| Trigger | Recipient | Status |
|---|---|---|
| `booking.confirmed` (Moyasar success) | Guest: code, unit, dates, total SAR, **frozen** policy snapshot tiers | ✅ |
| `booking.confirmed` | Partner: code, unit, dates, guest, **net share** (total − frozen 2% commission — same number the dashboard shows) | ✅ |
| 24h before check-in | Guest: address + check-in time | ✅ daily job, **10:00 Asia/Riyadh**, idempotent (a marker column — double runs can't double-send) |
| `booking.cancelled` (guest) | Guest + Partner, refund from the frozen `policy_snapshot` | ✅ |
| `booking.cancelled_by_host` | Guest: host cancelled + 100% refund | ✅ |
| `refund.processed` (Moyasar webhook) | Guest: refund settled | ✅ fires once per refund, replay-safe |

Template rules implemented as specified: Arabic RTL, Gregorian `DD/MM/YYYY`, Latin digits, SAR only, sender `no-reply@mamsaa.com`, reply-to `info@mamsaa.com` (global), policy text **only** from the frozen snapshot (FR-036) — no live lookups anywhere.

One deliberate rule: booking emails go only to **verified** addresses (an unverified address may be a typo — SMS still covers those guests). With the §2 gate on, every new booking's guest is verified by construction.

## 4. Your 4 questions

1. **OTP validity:** **300s**, unified with phone. Cooldown 60s. (`resend_available_in` / `retry_after` in the responses are authoritative — render those, don't hardcode.)
2. **Email uniqueness:** **Unique per account** — DB-level unique index on `users.email` + `EMAIL_ALREADY_IN_USE` on the API.
3. **Provider:** **Resend** (already integrated). SPF/DKIM for `mamsaa.com` is on our side (records pending in DNS — being handled); nothing needed from you, and no keys will ever reach the frontend.
4. **Reminder job:** confirmed — **daily 10:00 Asia/Riyadh**, targets bookings whose check-in date is tomorrow (Riyadh time).

## 5. Delivery notes

- Everything is live on `staging.mamsaa.com` now; with the staging fixed code `111222` you can run the whole flow without a real inbox. Staging's mailer writes to server logs (we verify content on our side) — real SMTP delivery isn't wired on staging by design.
- Postman: `04 — User` folder gained the 3 email requests; `Create Booking` documents the gate.
- Full flow for your UI: `POST /user/email` → OTP screen (60s resend timer from `resend_available_in`) → `verify` → poll `GET /auth/me` → retry the booking.
