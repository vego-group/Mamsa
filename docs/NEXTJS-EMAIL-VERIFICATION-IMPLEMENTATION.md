# Next.js implementation guide — Email verification (user app)

**Date:** 2026-07-19 · Companion to `NEXTJS-EMAIL-VERIFICATION.md` (the contract/answers doc). This one is the build guide.
**Surface:** user app only — `https://api.mamsaa.com/api/v1`, Bearer token. Nothing here touches the partner dashboard.
**Staging:** everything is live on `https://staging.mamsaa.com/api/v1`, booking gate **ON**, email OTP fixed to **`111222`** (not 123456).

The mental model: **login stays phone-OTP; email is a verified contact channel** the server requires before booking. You never send any email yourself — you only collect the address, submit the code, and react to machine codes.

---

## 1. Types

```ts
// lib/types/email.ts
export interface EmailStatus {
  email: string;
  verified: boolean;
  /** seconds until /resend is allowed — drive the countdown from THIS, never hardcode 60 */
  resend_available_in?: number;
}

export type EmailErrorCode =
  | 'EMAIL_INVALID'
  | 'EMAIL_ALREADY_IN_USE'
  | 'RATE_LIMITED'            // 429 — has retry_after (seconds)
  | 'OTP_INVALID'             // 422 — has remaining_attempts
  | 'OTP_EXPIRED'
  | 'OTP_MAX_ATTEMPTS'
  | 'EMAIL_VERIFICATION_REQUIRED'; // only from POST /bookings

/** Error envelope: { success:false, message, code, retry_after?, remaining_attempts? } */
export interface ApiEmailError {
  success: false;
  message: string;            // Arabic, display-ready — but BRANCH ON code, never on message
  code: EmailErrorCode;
  retry_after?: number;
  remaining_attempts?: number;
}
```

`GET /auth/me` (you already consume it) carries the two flags you need everywhere:

```ts
interface Me {
  // ...existing fields
  email: string | null;
  email_verified: boolean;
}
```

## 2. API client

```ts
// lib/api/email.ts  — assumes your existing authed fetch wrapper `api`
export const addEmail = (email: string) =>
  api.post<{ data: EmailStatus }>('/user/email', { email });

export const verifyEmail = (code: string) =>
  api.post<{ data: EmailStatus }>('/user/email/verify', { code });

export const resendEmailOtp = () =>
  api.post<{ data: EmailStatus }>('/user/email/resend');
```

Notes:
- All three require the Bearer token (401 otherwise).
- Route throttles exist on top of the business cooldown (5/min for send+resend, 10/min for verify) — a plain 429 **without** a `code` field is the route throttle; honor `Retry-After` and treat it like `RATE_LIMITED`.
- Server normalizes the address (trim + lowercase); render `data.email` from the response, not the user's raw input.

## 3. The verification flow (one reusable component)

Build it once — it's reached from two entry points: **account settings** ("add/change email") and the **booking gate interception** (§4).

```
[Step 1: email input] --addEmail--> [Step 2: OTP input, 60s resend timer] --verifyEmail--> done → refetch /auth/me
```

### Step 1 — email input

| Result | Handling |
|---|---|
| 200 | go to Step 2; start countdown from `data.resend_available_in` |
| `EMAIL_INVALID` | inline field error |
| `EMAIL_ALREADY_IN_USE` | inline field error — the address belongs to another account (emails are unique per account, DB-enforced) |
| `RATE_LIMITED` | stay on Step 1 is wrong — the code from a previous attempt is still live: **go to Step 2** with the timer set to `retry_after` (this happens when the user re-enters the flow within the cooldown) |

Submitting the **same still-unverified address again is treated as a resend** by the server — safe to re-enter the flow.

### Step 2 — OTP input (6 digits)

- 6-digit numeric input, auto-submit on the 6th digit is fine.
- Resend button disabled while the countdown runs; on click → `resendEmailOtp()` → reset countdown from the response (or from `retry_after` on 429).
- Code validity is **300s**; attempts allowed: **5**.

| `code` | UX |
|---|---|
| `OTP_INVALID` | shake + "الرمز غير صحيح" + show `remaining_attempts` ("متبقي 3 محاولات") |
| `OTP_MAX_ATTEMPTS` | code is dead — clear the input, disable it, surface a single CTA: "أرسل رمزاً جديداً" (→ resend) |
| `OTP_EXPIRED` | same recovery as max-attempts: only a new code helps. Covers both "300s passed" and "well-formed but stale code" |
| 200 `verified:true` | success → **refetch `/auth/me`** (your session store must pick up `email_verified:true`), then continue to wherever the user came from |

Don't implement your own expiry timer to pre-block submission — let the server answer; your timer would drift from the authoritative one.

## 4. Booking gate — `EMAIL_VERIFICATION_REQUIRED`

`POST /bookings` returns `422 { code: "EMAIL_VERIFICATION_REQUIRED" }` when the guest's email is missing **or unverified**. Handle it centrally in the booking submit path:

```ts
try {
  const booking = await createBooking(payload);
} catch (e) {
  if (isApiError(e) && e.code === 'EMAIL_VERIFICATION_REQUIRED') {
    // keep the draft booking payload in state, open the email flow (modal or route),
    // and on success -> retry createBooking(payload) automatically
    openEmailVerification({ onVerified: () => retry() });
    return;
  }
  throw e;
}
```

- **Pre-empt it for better UX**: if `me.email_verified === false`, show the verification step *before* the pay CTA — but keep the catch anyway; the server is the enforcement point.
- The flag is **ON on staging** today and **OFF on prod** until you ship this screen — so build against staging; prod won't surface the code until we flip it (tell us when you release).

## 5. Account settings screen

- Show current `email` + a "موثّق" / "غير موثّق" badge from `me.email_verified`.
- "Change email" → the same flow component. Changing the address **always** resets `verified` to `false` (server-side), including changes made through `PUT /user/profile` — after any email edit, refetch `/auth/me` and expect the badge to drop.
- Prefer `POST /user/email` over `PUT /user/profile { email }` for changes: same result, but it also fires the OTP in one call.

## 6. Testing on staging

1. Login with any phone (fixed phone OTP `111222`, `debug_otp` echoed).
2. `POST /user/email` with any inbox (no real email needed — staging's mailer writes to server logs).
3. Verify with **`111222`**.
4. Booking on a bookable unit before verifying → expect the 422 gate; after verifying → gate opens.
5. Wrong-code path: submit `000000` up to 5× to see `remaining_attempts` count down → `OTP_MAX_ATTEMPTS`.

Fixed codes never work in production — real inboxes only there.

## 7. What you do NOT build

- No email sending, ever — confirmations, cancellation/refund notices, and the day-before check-in reminder are all server-side (see `NEXTJS-EMAIL-VERIFICATION.md` §3 for the full matrix).
- No client-side refund/policy math in those emails' spirit either — same FR-036 rule as always.
- No handling of a Resend/API key — the provider is fully backend-side.
