# Mamsa — `/admin/*` authorization is now enforced server-side

**From:** backend · **Date:** 2026-08-14
**Status:** ⏳ **not deployed** — rides the coordinated cutover.
**Audience:** admin panel primarily. Partner dashboard and guest site are unaffected.

## TL;DR

**Your permission gate is no longer the only gate.** The admin API now enforces the §4.3 matrix on
**50 endpoints**. The premise you built on — *"server-side enforcement is the real control, the UI is
UX"* — is finally true.

Nothing you built needs changing. This is the backend catching up to your assumption.

---

## 1. What changed

Previously the admin BFF had **authentication only**: any authenticated admin could call any admin
endpoint, so a `finance` session received `200` from `/admin/users`, `/admin/units` and
`/admin/approvals`. An `Admin` role was also silently treated as a `SuperAdmin` there.

Both are closed. Every admin route is now gated against the permission matrix.

| | Before | After |
|---|---|---|
| finance → `GET /admin/users` | **200** (data returned) | **403** `INSUFFICIENT_PERMISSION` |
| finance → `GET /admin/units`, `/admin/approvals`, `/admin/dashboard/summary` | **200** | **403** |
| finance → `POST /admin/partners/{id}/suspend` | 200 | **403** |
| finance → `/admin/partners`, `/admin/bookings`, `/admin/payouts/eligible` | 200 | **200** — unchanged |
| superadmin → anything | 200 | **200** — unchanged |
| any admin → `/admin/me` | 200 | **200** — never gated |

**`/admin/me` is deliberately never permission-gated** — you need it to learn which permissions you
have before you can gate anything.

---

## 2. The error shape

Permission denials return **`403`** with:

```jsonc
{ "message": "ليس لديك صلاحية لهذا الإجراء", "code": "INSUFFICIENT_PERMISSION" }
```

This is deliberately **distinct from the login gate's `FORBIDDEN`**, so you can tell *"you may not do
this"* from *"you may not be here at all"*:

| Code | Meaning | Sensible UI |
|---|---|---|
| `FORBIDDEN` | not an admin at all / login refused | bounce to login |
| `INSUFFICIENT_PERMISSION` | authenticated, but this action isn't yours | forbidden screen, stay signed in |

Your `isForbidden()` helper already accepts both, so nothing breaks if you treat them the same — but
distinguishing them gives a better experience. **Do not sign the user out on
`INSUFFICIENT_PERMISSION`** — the session is valid.

---

## 3. One thing to re-check on your side

Your gating hides controls the role lacks, which means a correctly-built UI should never *trigger* a
403. The risk is the opposite: **over-blocking**, where the UI hides something the server would allow.

- [ ] Confirm the finance role can still reach everything in its matrix: partners (read-only),
      bookings, cancellations (read-only), wallets, payouts, reports, notifications, profile.
- [ ] Confirm no screen now renders empty because it fires a request the server refuses — a 403 should
      produce your forbidden state, never a blank page or an infinite spinner.
- [ ] Keep handling 403 on **mutating** calls even where the button is hidden. Hidden ≠ impossible.

The backend pins the "not over-blocked" half with a test asserting finance still gets `200` on
`/admin/partners`, `/admin/bookings` and `/admin/payouts/eligible`, so that cannot silently regress.

---

## 4. Why the permission list cannot drift from yours

The middleware resolves permissions from **the same source `/admin/me` returns** — not from a
separately seeded database set.

That was a deliberate choice. Two sources would eventually disagree, and the failure is quiet in both
directions: the UI hides an action the server allows, or offers one it refuses. With a single source,
what `/admin/me` tells you is exactly what the server enforces.

So you can keep treating `permissions[]` as authoritative and keep the local role map as a fallback —
which is the shape you already have.

---

## 5. Also fixed in the same change (no frontend impact)

The Moyasar webhook verified its shared secret **only when one was configured** — a blank environment
variable silently disabled authentication on an endpoint that settles refunds and sends settlement
email. It now rejects and logs when the secret is missing. Both environments already set it, so nothing
changes operationally.

Mentioned only so the security picture is complete; there is no client-side action.

---

## 6. Where the security work stands

| Item | Status |
|---|---|
| Guest API leaked `commission_amount` | ✅ fixed (separate note) |
| `/admin/*` had no per-endpoint authorization | ✅ **fixed — this note** |
| `Admin` silently treated as `SuperAdmin` | ✅ fixed |
| Payment webhook failed open on a blank secret | ✅ fixed |
| Fixed OTP still accepted on production | 🔴 **open** — deliberate, pending the end of the test phase |
| Old OTP in public git history | ⚪ accepted — the value is rotated and dead |

Checked clean while auditing: mass assignment, SQL injection, auth throttling, file uploads
(magic-byte validated, extension forced server-side).

Not yet audited: dependency CVEs, stored XSS, CSRF posture, a full IDOR sweep of `/api/v1`, SSRF in the
iCal importer.

---

## 7. Deployment

Not deployed. It changes behaviour **for any admin account that is not a SuperAdmin**, so it ships with
the coordinated cutover alongside `pending_payment`, `isActive`, the reports VAT fields and the
`commission_amount` gating.

Full suite green: **121 tests, 808 assertions**, including 5 that pin this matrix.
