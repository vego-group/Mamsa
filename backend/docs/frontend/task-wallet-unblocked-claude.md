# Task: partner wallet is unblocked + `isActive` is live (Claude Code — Next.js frontend repos)

**For:** a Claude Code agent working in the **partner dashboard** and **admin panel** repos.
**Date:** 2026-08-13
**Follows:** the backend security audit reply and `MAMSA-FRONTEND-STUB-WIRING-CLAUDE.md` (shapes/URLs).

**Two things changed since your last hand-off:**
1. **The partner wallet screen is unblocked** — the security audit found no cross-partner leak (§1).
2. **`isActive` now ships on `/admin/partners`** and is live on staging (§2).

**And one thing you must not misread:** a clean security result does **not** mean wallet scoping exists.
It hasn't been written yet. §3 is the important section in this file.

---

## 1. Partner wallet — cleared to build

The backend tested isolation with a **real authenticated partner session** (not by reading code):

| As partner A | Result |
|---|---|
| own `/me`, own unit | 200 |
| **partner B's unit** | **404** |
| **partner B's booking** | **404** |
| any `/admin/*` endpoint | **401** |

Cross-tenant reads return **404, not 403**, so a response never confirms another partner's resource
exists. Two independent controls block the admin surface: separate session guards, and an admin login
gate that refuses to issue an OTP to a non-admin.

**So: build the partner wallet screen.** Shapes, URLs, credentials and error triggers are unchanged —
use `MAMSA-FRONTEND-STUB-WIRING-CLAUDE.md`.

---

## 2. `isActive` — live on staging, use it for eligibility

`GET /admin/partners` and `GET /admin/partners/{id}` now return the raw flag **alongside** the existing
derived string. Additive — `status` is untouched, so nothing you built stops working.

```jsonc
{
  "status":   "active",   // unchanged: 'pending' | 'active' | 'suspended' | 'rejected'
  "isActive": true,       // NEW — the raw signal
  "verified": true
}
```

Verified to carry real signal (a staging partner was suspended and restored):

| State | `status` | `isActive` |
|---|---|---|
| suspended | `"suspended"` | `false` |
| restored | `"active"` | `true` |

**Use it for eligibility rather than inferring from the folded label:**

```ts
const payoutEligible = partner.status === 'active' && partner.isActive;
```

### 2.1 A correction to earlier advice

An earlier hand-off told you the contract's `PartnerStatus` union was wrong. **It is not** — for
`/admin/partners`, the backend already derives exactly `pending | active | suspended | rejected`
server-side. Keep your union.

What *was* substantive, and stands: the storage underneath is `approved` + a separate `is_active`
boolean, so **eligibility is `approved` AND active — never a single status comparison.** A partner
suspended while still approved would otherwise render as payable.

Note a **third vocabulary** exists on the partner's own surface: `GET /me` returns `accountState` ∈
`pending | approved | suspended` — `approved`, not `active`. That is partner-facing and has always been
this way. Do not unify them client-side; treat them as separate fields.

---

## 3. ⚠️ The stubs are NOT scoped — do not build on the assumption that they are

`GET /wallet` and `GET /wallet/ledger` are **static fixtures today**. They ignore who is asking:
two different partner sessions both receive `availableBalance: 4310.75`.

That is not a leak — there is no real wallet data yet — but it means:

- **§1's clean result says nothing about wallet scoping.** Ownership enforcement for wallets has not
  been written. Do not treat the stub's behaviour as a guarantee.
- **Never render a partner identifier that came from the wallet payload as if it were verified.** Bind
  wallet UI to the **session's own** partner, not to a `partnerId` echoed back by the API.
- Do not add a partner-side "view another partner's wallet" affordance of any kind — there is no
  legitimate route for it, and no server check to catch a mistake.

### 3.1 What the real implementation will look like (so your tests match)

The backend confirmed the scoping will be **new code, not the existing `ownUnit`/`ownBooking` helpers** —
because those exist for **ID-addressed** resources, and the wallet endpoints take no identifier at all:

| Endpoint | Takes an ID? | How it will be scoped |
|---|---|---|
| `GET /wallet` | no | derived from the session user directly (like `/me`, `/overview`) |
| `GET /wallet/ledger` | no | same, plus the cursor must not cross partners |
| **`GET /payouts/{id}`** | **yes** | a new `ownPayout()` helper — non-leaking **404**, same shape as `ownBooking()` |

**Nothing in your code changes when this lands** — same shapes, same URLs. The only visible difference:
`GET /payouts/{id}` with another partner's id will return **404** instead of a fixture. If you write a
test for that, expect 404, not 403.

---

## 4. Admin panel — your gate is still the only gate

Unchanged and worth repeating, because it inverts the usual assumption:

The admin API (`/admin/*`) currently has **authentication only, no per-permission checks**. A finance
session receives **200** from `/admin/users`, `/admin/units` and `/admin/approvals` — endpoints its
`permissions[]` excludes.

- Keep gating on the flat `permissions[]` from `GET /admin/me`.
- **Hide** unpermitted controls; do not merely disable them.
- Still handle `403` on every mutating call — enforcement is landing, and UI that assumed success will
  break when it does.
- Do not surface data purely because the API returned it.

Server-side enforcement is confirmed as **phase 1, ahead of the wallet work**, and will be in place on
production before any wallet endpoint carries a real balance.

---

## 5. Decisions recorded (no action needed)

- **`/admin/*` permissions ship before wallets reach production** — even if wallets finish first.
- **Production rollback stays armed** until you confirm all three repos have shipped `pending_payment`.
- **Legacy Vue admin: under three months** → the three `/api/v1` count keys stay `pending`. No cleanup PR.

---

## 6. Checklist

- [ ] Partner wallet screen built against the staging stubs (§1).
- [ ] Wallet UI bound to the **session's** partner, never to a `partnerId` from the payload (§3).
- [ ] No partner-side affordance to view another partner's wallet (§3).
- [ ] `payoutEligible = status === 'active' && isActive` — not a single status compare (§2).
- [ ] `PartnerStatus` union kept as-is; `/me`'s `accountState` treated as a separate field (§2.1).
- [ ] Any `GET /payouts/{id}` cross-tenant test expects **404** (§3.1).
- [ ] Admin gating hides rather than disables; 403 handled everywhere (§4).

---

## 7. Access

Staging `https://staging.mamsaa.com`, cookie session, `credentials: 'include'`.
Superadmin `+966555000003`, finance `+966555000004`.

**The fixed staging OTP may have been rotated** — the previously shared value was found published in
the public repository, so it is being changed. Request the current value from the backend lead rather
than relying on an older document.
