# Mamsa — Registration & Login Guide for the Next.js Frontend

How to implement **individual user sign-up/login** and **"Join as Partner"** (individual & company)
against the live Laravel API. Everything below is verified against the deployed backend — the partner
payloads and flow were **re-run end-to-end on staging 2026-07-16** (individual + company registration
→ `201 pending`, then dashboard login gate). The exact bodies that returned `201` are in §4.2.

> **Two different logins — don't conflate them.** This guide is the **user-site** (`mamsaa.com`)
> auth: passwordless OTP that returns a **Bearer token**. The **partner dashboard** at
> `partner.mamsaa.com` is a **separate app** with its **own** login — a root-mounted **cookie
> session** (`POST /auth/otp/verify`, no `/api/v1`) that **only admits `approved` partners**. A
> freshly-registered partner is `pending` and **cannot enter the dashboard yet** (see §4.4). Dashboard
> wiring lives in `NEXTJS-DASHBOARD-PRODUCTION.md`; this doc covers registration + the customer/partner
> user-site.

---

## 0. Environments & Conventions

| | Production | Staging (test bench) |
|---|---|---|
| API base | `https://api.mamsaa.com/api/v1` | `https://staging.mamsaa.com/api/v1` |
| OTP delivery | Real SMS (FGC) | **Fixed code `111222`** + `debug_otp` field in the response |
| Payments | Live Moyasar | Moyasar test keys |

**Response envelope** — every endpoint returns:

```json
{ "success": true, "message": "…arabic…", "data": { } }
```

**Validation errors** — standard Laravel 422:

```json
{ "message": "…", "errors": { "field": ["…arabic…"] } }
```

**Phone format** — send whatever the user types; the backend normalises to E.164
(`+9665XXXXXXXX`). Accepted inputs: `0512345678`, `512345678`, `9665…`, `+9665…`, `009665…`.
The `user.phone` you get back is always `+9665XXXXXXXX`.

**Auth header** — `Authorization: Bearer <access_token>` on all authenticated calls.

---

## 1. Auth Model (read this first)

There are **no passwords** for users/partners — auth is passwordless phone OTP.
The same OTP endpoints power both login and registration; an `intent` field separates them:

- A phone with a **completed profile** (non-blank `name`) = registered.
- A phone that never completed a profile = not registered (even if an OTP was once sent).

Roles returned in `user.roles`:

| Role | Meaning |
|---|---|
| `User` | Regular individual customer (guest who books units) |
| `Individual` | Partner — individual owner |
| `Company` | Partner — company |

`user` object shape (from `verify-otp`, `partner/register`, `/auth/me`):

```json
{
  "id": 12,
  "name": "محمد أشرف",
  "phone": "+966512345678",
  "email": "m@example.com",
  "email_verified": false,
  "is_active": true,
  "roles": ["Individual"],
  "permissions": ["..."],
  "is_admin": false,
  "is_partner": true,
  "partner_status": "pending",
  "profile_complete": true
}
```

`partner_status` is only present when `is_partner` is true: `pending` | `approved` | `rejected`.

---

## 2. Shared OTP Endpoints

### 2.1 Request OTP

```
POST /auth/request-otp                        (throttle: 5/min per IP)
{ "phone": "0512345678", "intent": "register" }
```

- `intent` is `"login"`, `"register"`, or omitted (omitted = unified: works for both).
- Success `200`: `data.phone` echoed back. On **staging** you also get `data.debug_otp`.
- Intent guard fails **fast, before any SMS is sent**, with a machine-readable `code`:

```json
// intent=login but phone not registered  → HTTP 422
{ "success": false, "message": "هذا الرقم غير مسجّل", "code": "PHONE_NOT_REGISTERED" }

// intent=register but phone already registered  → HTTP 422
{ "success": false, "message": "هذا الرقم مسجّل بالفعل، يرجى تسجيل الدخول", "code": "PHONE_ALREADY_REGISTERED" }
```

Branch on `code`, not on the Arabic message. Typical UX: on `PHONE_NOT_REGISTERED`
offer "Create an account instead?", on `PHONE_ALREADY_REGISTERED` bounce to the login tab.

### 2.2 Resend OTP

```
POST /auth/resend-otp                         (throttle: 3/min per IP)
{ "phone": "0512345678", "intent": "register" }
```

There is a **60-second cooldown** between sends (server-enforced). Violations return 422:
`errors.phone[0] = "الرجاء الانتظار {n} ثانية قبل إعادة الإرسال"`. Run a 60s countdown on the
resend button and keep the server message as fallback.

### 2.3 OTP policy (for your UI copy/timers)

- Code: **6 digits**, expires after **5 minutes**.
- **3 wrong attempts** invalidates the code (`تم تجاوز الحد الأقصى للمحاولات…` → user must request a new one).
- Wrong-code 422 includes remaining attempts: `رمز غير صحيح. المحاولات المتبقية: 2`.
- Daily anti-fraud caps: 10 sends/phone, 30 sends/IP.

---

## 3. Flow A — Individual User (register & login)

Same two steps for both; `intent` + `needs_profile` do the branching.

### Step 1 — request OTP

`POST /auth/request-otp` with `intent: "login"` or `intent: "register"` (§2.1).

### Step 2 — verify OTP → tokens

```
POST /auth/verify-otp                         (throttle: 10/min per IP)
{ "phone": "0512345678", "code": "111222", "device": "web" }
```

Success `200`:

```json
{
  "success": true,
  "message": "يرجى إكمال بيانات الملف الشخصي",
  "data": {
    "access_token": "1|…",
    "refresh_token": "…",
    "token_type": "Bearer",
    "expires_in": 3600,
    "needs_profile": true,
    "user": { … }
  }
}
```

- This call **creates the user row if it didn't exist** and assigns the `User` role.
- `needs_profile: true` → new user (blank name): route to the complete-profile screen.
- `needs_profile: false` → returning user: route to their account/home.

### Step 3 — complete profile (new users only)

```
POST /auth/complete-profile                   (Bearer token)
{ "name": "محمد أشرف", "email": "m@example.com" }   // email optional, must be unique
```

Returns the updated `user`. Registration is now complete — this phone counts as
"registered" for future `intent` checks.

---

## 4. Flow B — Join as Partner (individual & company)

One extra endpoint replaces `verify-otp`: the OTP is verified **inside** `partner/register`,
so the flow is: request OTP → collect profile fields → submit everything together.

> **Important — do NOT send `intent: "register"` here.** An existing regular user is allowed
> to upgrade to a partner, but `intent: "register"` would reject their (already registered)
> phone with `PHONE_ALREADY_REGISTERED` before the SMS is sent. For the partner-join flow,
> call `request-otp` with **no `intent` field**.

### Step 1 — request OTP

```
POST /auth/request-otp
{ "phone": "0512345678" }          // no intent — allows both new phones and upgrades
```

### Step 2 — register (submits profile + OTP code in one shot)   ✅ verified 2026-07-16

```
POST /auth/partner/register                   (throttle: 5/min per IP)
```

Both bodies below were sent to staging and returned **`201`** with `partner_status: "pending"`.

Individual owner — **`national_id` required, no `cr_number`**:

```json
{
  "type": "individual",
  "name": "محمد أشرف",
  "phone": "0512345678",
  "code": "111222",
  "email": "partner@example.com",
  "national_id": "1012345678",
  "device": "partner-web"
}
```

Company — **`cr_number` required, no `national_id`**:

```json
{
  "type": "company",
  "name": "شركة الضيافة",
  "phone": "0512345678",
  "code": "111222",
  "email": "info@company.sa",
  "cr_number": "4030123456",
  "device": "partner-web"
}
```

Validation rules (mirror these client-side):

| Field | Rule |
|---|---|
| `type` | required, `individual` \| `company` |
| `name` | required, max 100 |
| `phone` | required, 8–20 chars |
| `code` | required, the SMS OTP (6 digits) |
| `email` | **required** for partners (it gets verified in step 3), max 150 |
| `national_id` | required when `type=individual`, max 20 |
| `cr_number` | required when `type=company`, max 20 |
| `device` | optional label stored with the token |

Success `201`:

```json
{
  "success": true,
  "message": "تم تسجيلك كشريك بنجاح",
  "data": {
    "access_token": "…",
    "refresh_token": "…",
    "token_type": "Bearer",
    "expires_in": 3600,
    "needs_email_verification": true,
    "user": { "roles": ["Individual"], "partner_status": "pending", … }
  }
}
```

Failure cases to handle:

- Wrong/expired OTP → 422 `errors.code[…]` (same messages as §2.3).
- Admin phone → 422 `errors.phone[0] = "هذا الرقم مسجَّل كحساب إداري ولا يمكن تحويله إلى شريك."`.
- A previously **rejected** applicant can re-submit this same endpoint — their application
  automatically goes back to `pending` review.

### Step 3 — verify email (FR-005)

The API already emailed a 6-digit code when registration succeeded
(`needs_email_verification: true`). Show an email-code screen right after:

```
POST /auth/email/verify                       (Bearer token, throttle: 10/min)
{ "code": "482913" }
```

Resend (60s cooldown, same policy as SMS OTP):

```
POST /auth/email/request-otp                  (Bearer token, throttle: 5/min)
```

`email/verify` returns the fresh `user` with `email_verified: true`.

### Step 4 — pending-review state (this gates the dashboard)   ✅ verified 2026-07-16

New partners land with `partner_status: "pending"`. An admin reviews the application:

- `pending` → "application under review" screen.
- `approved` → full partner dashboard.
- `rejected` → rejection notice + offer re-submitting the form (step 2 again).

**What this means for `partner.mamsaa.com` specifically** — the dashboard login (`POST /auth/otp/verify`,
cookie session) enforces the status server-side; it does **not** just role-gate individual endpoints:

| Partner state | Dashboard login (`/auth/otp/verify`) |
|---|---|
| `pending` **or** `rejected` | **`403 { error.code: "ACCOUNT_PENDING" }`** — no session issued |
| suspended (`is_active:false`) | `403 ACCOUNT_SUSPENDED` |
| not a partner | `404 PARTNER_NOT_FOUND` |
| `approved` | `200` + session cookie → dashboard opens |

Verified live: right after registration the individual got `403 ACCOUNT_PENDING`; after an admin set
`approved`, the same login returned `200` and `GET /me` succeeded. So on `ACCOUNT_PENDING`, the
dashboard app must show an "under review" screen — **not** a login error — and the partner cannot see
any dashboard data until approved. (The customer user-site below is different — a partner CAN log into
`mamsaa.com` while `pending`; only the dashboard is gated.)

Check status any time via `GET /auth/me`.

### Partner logs in to the user-site later — same as Flow A

On **`mamsaa.com`**, an existing partner signs in with the normal `request-otp (intent: "login")` +
`verify-otp` → Bearer token. `user.roles` will contain `Individual`/`Company`. This works even while
`pending`. For the **dashboard** (`partner.mamsaa.com`) use the cookie-session login above, which
requires `approved`.

---

## 5. Session / Token Management

- `access_token` is a Sanctum bearer token, valid **1 hour** (`expires_in: 3600`).
- `refresh_token` is single-use; rotate before/on expiry:

```
POST /auth/refresh                            (public, throttle: 10/min)
{ "refresh_token": "…", "device": "web" }
```

→ same token-pair payload. `401` = refresh token invalid/expired → force re-login.

- `GET /auth/me` (Bearer) → current user (fresh `roles`, `partner_status`, `email_verified`).
- `POST /auth/logout` (Bearer) → revokes the access token **and** its refresh token.

Recommended Next.js pattern: keep the pair in httpOnly cookies via a route handler
(`/api/session`), refresh server-side in middleware when `expires_in` is near, and never
expose the refresh token to client JS.

---

## 6. Quick Reference

| Endpoint | Auth | Throttle | Purpose |
|---|---|---|---|
| `POST /auth/request-otp` | — | 5/min | Send SMS OTP (`intent`: login/register/omit) |
| `POST /auth/resend-otp` | — | 3/min | Resend (60s cooldown) |
| `POST /auth/verify-otp` | — | 10/min | Login/register user → token pair |
| `POST /auth/complete-profile` | Bearer | — | New user sets `name` (+optional email) |
| `POST /auth/partner/register` | — | 5/min | Partner sign-up (OTP inside) → token pair |
| `POST /auth/email/request-otp` | Bearer | 5/min | (Re)send partner email code |
| `POST /auth/email/verify` | Bearer | 10/min | Confirm partner email |
| `POST /auth/refresh` | — | 10/min | Rotate token pair |
| `GET /auth/me` | Bearer | — | Current user + roles + partner_status |
| `POST /auth/logout` | Bearer | — | Revoke session |

### Staging test recipe (end-to-end, no real SMS)

1. `POST https://staging.mamsaa.com/api/v1/auth/request-otp` with any `05…` number.
2. Use code **`111222`** (also echoed in `data.debug_otp`).
3. Partner email codes on staging arrive via the configured mailer — or check with the backend team if you need a fixed email code too.
