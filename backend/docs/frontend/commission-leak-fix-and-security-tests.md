# Mamsa — Guest API stops returning `commission_amount` + security invariants now pinned

**From:** backend · **Date:** 2026-08-14
**Status:** ⏳ **not deployed** — rides the coordinated cutover, since it changes a guest API response.
**Audience:** guest site (`mamsa-app`) primarily; partner dashboard and admin panel are **unaffected**.

## TL;DR

A new security test suite found a real leak: **the guest booking API was returning Mamsa's per-booking
commission.** It is now withheld from guests. One field, one surface — but check whether you read it
before this ships.

Everything else here is informational: the invariants your surfaces can now rely on, because they are
pinned by tests rather than by convention.

---

## 1. The change — `commission_amount` is gated

`BookingResource` is shared by the guest, partner and admin `/api/v1` endpoints, and it exposed
`commission_amount` **unconditionally**. So every guest booking response carried the platform's cut —
contrary to contract §1.7 and §7 (*"do not return `commission` or `partnerShare` on any user-site
endpoint"*).

It is now returned **only** to the unit's owner (the partner) and to admins.

### 1.1 What changes, per surface

| Surface | Endpoint | `commission_amount` after this ships |
|---|---|---|
| **Guest site** | `GET /api/v1/bookings/{id}` | ❌ **removed** |
| **Guest site** | `GET /api/v1/user/bookings` | ❌ **removed** |
| **Guest site** | `POST /api/v1/bookings` (creation response) | ❌ **removed** |
| Partner (`/api/v1/partner/bookings`) | — | ✅ unchanged — a partner sees their own |
| Legacy Vue admin (`/api/v1/admin/bookings`) | — | ✅ unchanged |
| Partner dashboard (root `/bookings`) | — | ✅ unaffected — different serialiser entirely |
| Admin panel (`/admin/bookings`) | — | ✅ unaffected — different serialiser entirely |

**So only the guest app is affected**, and only for that one key. Nothing else in the response shape
changes.

### 1.2 What to do

- [ ] Search the guest app for `commission_amount`. If it is read anywhere, remove that usage — the
      value was never meant to be visible to a guest.
- [ ] If any guest fixture or mock includes `commission_amount`, drop it so tests match reality.
- [ ] No action for the partner dashboard or admin panel.

If the guest app never used it — which is the likely case — this is a no-op for you and you can ignore
the rest of §1.

---

## 2. Invariants now pinned by tests

These were previously verified by hand during the audits and could have regressed silently. They are
now covered by `tests/Feature/SecurityTest.php` (14 tests), so you can build against them as guarantees
rather than observations.

### 2.1 Auth surfaces cannot be crossed

- A **partner** session gets **401** on `/admin/me`, `/admin/partners`, `/admin/bookings`, `/admin/users`.
- An **admin** session gets **401** on the partner routes (`/me`, `/units`, `/bookings`).
- Unauthenticated requests get **401** on all three surfaces.

### 2.2 Cross-tenant reads return **404, not 403**

Partner A requesting partner B's booking gets **404**. This is deliberate — a 403 would confirm that the
resource exists. The test asserts 404 specifically, so it will not drift to 403 later.

**For your error handling:** treat a 404 on a resource you believe exists as "not yours or not there",
never as a bug to retry.

### 2.3 A guest response never carries platform margin

Asserted directly: no `commission`, `commission_amount`, `partner_share` or `partnerShare` in a guest
booking response. This is the test that caught §1.

### 2.4 Money controls (relevant when you wire payouts)

- `POST /admin/payouts/record` **ignores** any client-supplied `amount` or `iban` — pinned, so the
  control you build your UI around cannot quietly disappear.
- The partner ledger **rejects updates and deletes** — a correction is always a new row, never an edit.
  Your "no edit/delete affordance on a ledger row" rule is now enforced server-side too.
- `available_balance` **stores negatives** rather than clamping to zero.

### 2.5 Fixed-OTP containment

- `OTP_FIXED_CODE` is inert when `APP_ENV=production`.
- The scoped test-mode bypass never applies to a non-allowlisted phone — asserted *in a production
  environment*, because that path has no environment guard of its own and the allowlist is the control.
- A blank code disables the bypass entirely.

### 2.6 Fixture endpoints stay out of production

The stub wallet/payout endpoints remain behind a production check, asserted against the route sources.
So the 404s you see for them on production are guaranteed, not incidental.

---

## 3. Deployment

**Not deployed.** It is a test file plus a one-line resource change, but because it alters a guest API
response it should ship with the coordinated cutover rather than on its own — alongside
`pending_payment`, `isActive` and the reports VAT fields.

Full suite green: **116 tests, 787 assertions**.

---

## 4. Checklist

- [ ] Guest app: confirm nothing reads `commission_amount` (§1.2)
- [ ] Guest fixtures/mocks: drop the field if present
- [ ] Error handling: a cross-tenant 404 is expected behaviour, not a fault (§2.2)
- [ ] Payout UI: keep relying on the server-computed amount — the control is now test-pinned (§2.4)
- [ ] No ledger edit/delete affordance — the server rejects both (§2.4)
