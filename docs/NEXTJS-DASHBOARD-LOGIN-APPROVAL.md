# Partner Dashboard — login & the "تحت المراجعة" (under review) state

For the Next.js dashboard team. Explains why login shows **"تحت المراجعة"**, and exactly what to do so
an **approved** individual/company partner can get in. Verified live against the backend 2026-07-16.

**TL;DR:** "تحت المراجعة" is **correct** — the backend blocks login for any partner who isn't
`approved` yet (`403 ACCOUNT_PENDING`, no session). It is **not** a bug and **cannot** be bypassed from
the frontend. After a **SuperAdmin approves** the partner, the **same login flow returns `200`** — the
partner just logs in **again**. Your job: don't trap them on a cached "pending" screen; always let them
**re-attempt login**, because that retry *is* the approval check.

---

## 1. The login gate — `POST /auth/otp/verify`

Root-mounted, cookie session (see `NEXTJS-DASHBOARD-PRODUCTION.md`). After the OTP is correct, the
backend checks the partner's account state and returns one of:

| Account state | HTTP | `error.code` | `error.message` (Arabic) | What the UI should show |
|---|---|---|---|---|
| **`approved`** | `200` | — | — | Session set → open the dashboard |
| `pending` **or** `rejected` | `403` | `ACCOUNT_PENDING` | طلب انضمامك قيد المراجعة | **"تحت المراجعة"** screen + a "try again" action |
| suspended (`is_active:false`) | `403` | `ACCOUNT_SUSPENDED` | تم إيقاف حسابك، تواصل مع الدعم | "account suspended, contact support" |
| phone isn't a partner | `404` | `PARTNER_NOT_FOUND` | هذا الرقم غير مسجّل كشريك | "not registered as a partner" → send to Join-as-Partner |

Branch on **`error.code`** (stable), not the Arabic text. Note: `pending` and `rejected` **both**
return `ACCOUNT_PENDING` on purpose — the login endpoint deliberately doesn't distinguish them.

> A fresh partner who just registered on `mamsaa.com` is `pending`, so their **first** dashboard login
> correctly returns `ACCOUNT_PENDING`. That's expected — not every registration is auto-approved.

---

## 2. Why you can't "let him in" from the frontend

There is nothing to toggle client-side. On `403 ACCOUNT_PENDING` the server sets **no session cookie**,
so every subsequent dashboard call (`/me`, `/units`, …) is anonymous and returns `401`. Access is
granted **only** when the login itself returns `200`, and that happens **only** once the partner's
`partnerDetail.status === "approved"` in the database. The gate is server-side by design (partner
verification is a business control).

So the only path from "تحت المراجعة" to "in" is: **an admin approves → the partner logs in again.**

---

## 3. Who approves, and what happens on approval

A **SuperAdmin/Admin** approves the applicant from the admin panel
(`POST /api/v1/admin/partners/{user}/approve`). That call:

- sets `partnerDetail.status = "approved"` (+ `reviewed_at`),
- **notifies the applicant** (they get a message that their application was approved).

The approval is **not pushed** to an already-open dashboard tab — there is no websocket. The partner
finds out via that notification, then returns and logs in.

Verified live: a `pending` partner's login returned `403 ACCOUNT_PENDING`; immediately after an admin
set them `approved`, the **same** login (request OTP → verify) returned `200` and `/me` worked.

---

## 4. What to change on the frontend

The bug to avoid is **caching "pending" and never re-checking**. There is **no status-poll endpoint** —
a pending partner has no session, so they can't call `/me`. **Re-attempting the login IS the status
check.** Concretely:

1. On `403 ACCOUNT_PENDING`, show the "تحت المراجعة" screen — but keep a **"حاول تسجيل الدخول مرة أخرى"
   / Try logging in again** button that restarts the OTP login (`/auth/otp/request` → `/auth/otp/verify`).
2. **Never** persist `pending` as a permanent state that blocks the login screen. Each login attempt is
   a fresh gate evaluation; after approval it will pass.
3. When the retry returns `200`, proceed into the dashboard as normal.
4. Optional nicety: tell the user they'll be notified when approved, so they know to come back.

```ts
async function partnerLogin(phone: string, code: string) {
  const res = await fetch(`${API}/auth/otp/verify`, {
    method: "POST",
    credentials: "include",                       // REQUIRED — this is what stores the session
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ phone, code }),
  });

  if (res.ok) return "OK";                         // 200 → session set, go to dashboard

  const { error } = await res.json();              // { code, message }
  switch (error.code) {
    case "ACCOUNT_PENDING":                        // pending OR rejected
      return "UNDER_REVIEW";                       // show تحت المراجعة + "try again" button
    case "ACCOUNT_SUSPENDED":
      return "SUSPENDED";                          // contact support
    case "PARTNER_NOT_FOUND":
      return "NOT_PARTNER";                        // route to Join-as-Partner
    default:                                       // OTP_WRONG / OTP_EXPIRED / OTP_LOCKED → re-enter code
      return error.code;
  }
}
```

The "تحت المراجعة" copy stays — just make sure it sits behind a **retryable** login, so the moment the
SuperAdmin approves, the partner's next attempt lets them straight in. Nothing else changes; the backend
already flips `pending → approved → 200` correctly.

---

## 5. Note for the Vue site (different, don't copy this behavior)

The **user-site** partner area on `mamsaa.com`/`testvue` is **role-gated, not approval-gated** — a
pending partner can already use it right after register. Only **this dashboard** (`partner.mamsaa.com`)
requires `approved`. If someone reports "but it works on the other site while pending," that's why —
it's intentional. (Registration flow: `NEXTJS-REGISTRATION.md`.)
