# Task: apply the backend gap-analysis corrections (Claude Code — Next.js frontend repos)

**For:** a Claude Code agent working in the **admin panel**, **partner dashboard**, and **guest site** repos.
**Source:** `CONTRACT-GAP-ANALYSIS.md` §9 (11 items) and §12 — the backend audit of contract v2.2 against the real codebase.
**Why this file exists:** seven of those eleven items were never read by the frontend, and several contradict what the contract says. **If you are building from the contract alone, some of your types and assumptions are wrong.** This extracts only what changes frontend code.

**Do not treat the contract as authoritative where this file disagrees.** These corrections come from reading the running backend.

---

## 0. Triage — what each item does to you

| Item | Subject | Frontend impact | Status |
|---|---|---|---|
| §9.1 | Ledger naming | TS interface name | ✅ resolved — verify only (§1) |
| **§9.2** | **BFF has no server-side authz yet** | **Security assumption is wrong** | ⚠️ **read this** (§2) |
| §9.3 | Refund on completed booking impossible | `refund_reversal` never appears in data | ⚠️ (§3) |
| **§9.4** | **Casing differs per surface** | **`PriceBreakdown` is not one shape** | ⚠️ **breaking** (§4) |
| §9.5 | `pending` → `pending_payment` | booking status literal | ✅ resolved (§5) |
| **§9.6** | **`PartnerStatus` union is wrong** | **your TS type does not match reality** | ⚠️ **breaking** (§6) |
| **§9.7** | **Individual-partner IBAN is a *frontend* limitation** | **the blocker is yours to fix** | ⚠️ (§7) |
| §9.8 | Ledger immutability + idempotency | no edit/delete UI; send `Idempotency-Key` | ⚠️ (§8) |
| **§9.9** | **Mamsa-owned units skip wallet writes entirely** | **contract is incomplete here** | ⚠️ (§9) |
| §9.10 | Three error envelopes, not one | error parser | ⚠️ (§10) |
| §9.11 | `FORBIDDEN` not `INSUFFICIENT_PERMISSION` | error codes | ✅ accept both (§10) |

---

## 1. §9.1 — ledger naming (resolved, verify)

The contract originally called the partner ledger row `WalletTransaction`. That name was **already taken**
by a live *guest* wallet table, so the partner ledger was renamed. Canonical names:

```ts
interface PartnerLedgerEntry { /* partner earnings ledger */ }   // NOT WalletTransaction
interface PartnerWallet { /* balance holder */ }
```
Paths are `/wallet/ledger` and `/admin/wallets/{partnerId}/ledger` (not `/transactions`).
- [ ] Confirm no `WalletTransaction` type name survives in the wallet/payout code.

---

## 2. ⚠️ §9.2 — the backend does NOT yet enforce permissions on the admin BFF

**The correction:** the contract said server-side authorisation must be built from scratch. That is only
half true — the backend *does* have roles and enforces them on the legacy `/api/v1`, but **the admin
panel's own API (`/admin/*`) currently has authentication only, with no per-permission checks.** Every
authenticated admin can call every admin endpoint.

**What this means for you — and it is the opposite of the usual advice:**

- `GET /admin/me` now returns a real `role` and a flat resolved `permissions[]` — gate on that.
- **But right now, your gating is the only gate.** The usual reassurance ("the server will reject it
  anyway") is **not yet true** for the admin BFF. Do not rely on it while building.
- Still write the code as if the server enforces (handle 403 everywhere) — because it will, and when it
  does, a UI that assumed success will break.

```ts
// gate on the resolved list, never on the role string
const { permissions } = useAdminProfile();     // from GET /admin/me
const can = (p: Permission) => permissions.includes(p);
```

- [ ] Hide (do not merely disable) any control whose permission is absent.
- [ ] Handle 403 on every mutating call regardless of what the UI thought.
- [ ] Do **not** ship anything that leaks data purely because "the API allowed it."

---

## 3. §9.3 — `refund_reversal` will not appear in real data yet

Refunding a **completed** booking is impossible on the backend today (a guard rejects completed
bookings), and no admin action exists to trigger it. The `refund_reversal` ledger type is being built
now so the enum never has to change later, but **nothing will emit it** until product decides whether a
completed stay is refundable at all.

- [ ] Keep `refund_reversal` in the `PartnerLedgerEntryType` union and render it if present.
- [ ] Do **not** build a UI flow that triggers it.
- [ ] Do **not** write tests that assume it appears in live data (fixtures are fine).

---

## 4. ⚠️ §9.4 — `PriceBreakdown` is NOT one shape across surfaces

**The correction:** the contract specifies camelCase for `PriceBreakdown`. The guest API (`/api/v1`) is
**snake_case** and is consumed by the live Vue app — it will not be renamed. Only the partner and admin
BFFs are camelCase.

| Surface | Casing | Example fields |
|---|---|---|
| Guest site (`/api/v1`) | **snake_case** | `subtotal`, `taxes`, `tax_percent`, `commission_amount` |
| Partner dashboard (root) | camelCase | `taxPercent`, `netBase`, `partnerShare` |
| Admin panel (`/admin/*`) | camelCase | same |

- [ ] Do not share one `PriceBreakdown` TS type across all three repos as-is.
- [ ] In the guest repo, either type it snake_case or normalise at the API boundary — but **do not assume
      the contract's camelCase**.
- [ ] Remember the guest surface must never receive `commission` / `partnerShare` at all.

---

## 5. §9.5 — booking status (resolved, but transitional)

The contract's `pending_payment` did not exist in the backend; it has since been implemented and is
**live on staging, not yet on production**.

```ts
type BookingStatus = 'pending_payment' | 'confirmed' | 'completed' | 'cancelled';
```

Until production ships, **normalise `pending` → `pending_payment` at the API boundary** so one build works
against both environments. Full detail in `MAMSA-FRONTEND-TASK-PENDING-PAYMENT-CLAUDE.md`.

---

## 6. ⚠️ §9.6 — your `PartnerStatus` union does not match the backend

**The correction:** the contract says `pending | active | suspended | rejected`. The backend has **no such
field**. It stores `pending | approved | rejected` on the partner record, **plus a separate boolean**
`is_active` that expresses suspension.

So `active` ≈ `approved`, and `suspended` is not a status value at all — it is `is_active === false`.

```ts
// what the backend actually models
type PartnerRecordStatus = 'pending' | 'approved' | 'rejected';
interface Partner { status: PartnerRecordStatus; isActive: boolean; /* … */ }

// derive the contract's four-state view for display
const partnerState = (p: Partner) =>
  !p.isActive ? 'suspended'
  : p.status === 'approved' ? 'active'
  : p.status;                                   // 'pending' | 'rejected'
```

Consequence worth knowing: **"payout eligible" means `approved` AND `isActive`** — a single status
comparison is wrong and will show suspended partners as payable.

- [ ] Fix the `PartnerStatus` union and any filter/badge keyed on `active`/`suspended`.
- [ ] Confirm with backend which shape each endpoint returns (raw pair vs derived string) before locking types.

---

## 7. ⚠️ §9.7 — the individual-partner IBAN blocker is **yours**, not the backend's

**The correction:** the contract states individual partners "have no way to supply a bank account."
That is false at the API — the backend has always accepted an IBAN for **any** partner type. **The
restriction is in the frontend**, which only renders the IBAN field when `accountType === 'company'`.

- [ ] Render the bank-details form for **individual** partners too — this is the actual fix.
- [ ] Keep the client regex `^SA\d{22}$`, and surface the server's `422 INVALID_IBAN` when the stricter
      mod-97 checksum lands (the client check alone is not sufficient).

---

## 8. §9.8 — immutable ledger + idempotency

Ledger rows are append-only by design; there is no update or delete path, and there never will be.

- [ ] **No edit/delete affordance** anywhere on a ledger entry. A correction appears as a *new* row
      (`adjustment`), never as a changed one.
- [ ] Send an **`Idempotency-Key` header** on `POST /admin/payouts/record`. A double-submit must return the
      original payout, not create a second transfer.
- [ ] Assume `balanceAfter` is authoritative and server-computed — never recompute a running balance
      client-side from the entries.

---

## 9. ⚠️ §9.9 — Mamsa-owned units produce NO ledger entries (contract is incomplete)

**This is the item the contract gets wrong by omission.** The contract says a Mamsa-owned unit bypasses
the *commission split* (`partnerShare = 0`). That is not the whole story: a Mamsa-owned unit is stored
against the **admin who created it**, so if the wallet writer keyed on the unit's owner, that admin would
accrue a phantom partner balance. The backend therefore **skips wallet and ledger writes entirely** for
Mamsa-owned units.

`partnerShare = 0` and *no ledger row at all* are different things — the second is what actually happens.

- [ ] Do not expect a `PartnerWallet` to exist for an admin who owns Mamsa-owned units.
- [ ] Do not build an "earnings" view for Mamsa-owned listings; bookings on them never credit anyone.
- [ ] In fixtures/mocks, a Mamsa-owned booking must produce **zero** ledger entries — not an entry with
      amount `0`.

---

## 10. §9.10 + §9.11 — three error envelopes, and the real 403 code

There is no single error envelope. Handle all three, and accept both permission-denied codes:

```ts
export function parseError(body: any): { code: string; message: string } {
  if (body?.error?.code) return body.error;                            // partner dashboard (nested)
  if (body?.code) return { code: body.code, message: body.message };   // admin panel (flat)
  return { code: 'SERVER_ERROR', message: body?.message ?? 'حدث خطأ' };// /api/v1 (Laravel default)
}

export const isForbidden = (c: string) =>
  c === 'FORBIDDEN' || c === 'INSUFFICIENT_PERMISSION';
```

The live server emits **`FORBIDDEN`**; the contract says `INSUFFICIENT_PERMISSION`. Which one ships long
term is undecided, so accept both permanently.

---

## 11. Build order — what actually unblocks when (§12)

The backend scope is roughly **58 dev-days** (~3 months at 1 dev, ~2 months at 2). The order is largely
serial because the money spine depends on itself. Sequence your work to match:

| Backend phase | Lands ~ | Frontend it unblocks |
|---|---|---|
| 1 · roles/permissions | first | auth + permission gating — **already delivered** |
| 2 · VAT-inclusive refactor | after 1 | VAT display, checkout breakdown, `PriceBreakdown` |
| 3 · bank details | parallel | bank-details screen |
| 4 · wallets + ledger | after 2 | partner wallet UI |
| 5–6 · eligible list + record | after 4 | admin payouts + partner payout history |
| 7–8 · notifications, reverse/manual | after 6 | payout notifications, reverse controls |
| 9 · tax invoice + ZATCA QR | parallel | invoice/receipt screen |

Two of these are **already available on staging as contract-shaped stubs** (wallets, payouts, bank
details) — see `MAMSA-FRONTEND-STUB-WIRING-CLAUDE.md`. Build against those now; the swap to real data is
an environment change, not a rewrite.

**VAT stays mocked until phase 2 lands** — the backend is still VAT-*exclusive* today (`subtotal + 15%`),
so nothing returns `gross`/`netBase`/`partnerShare` yet.

---

## 12. Acceptance checklist

- [ ] `PartnerStatus` fixed to `approved` + `isActive` (§6); eligibility = both.
- [ ] Bank-details form renders for **individual** partners (§7).
- [ ] `PriceBreakdown` not assumed camelCase on the guest API (§4).
- [ ] No wallet/earnings UI for Mamsa-owned units; fixtures emit zero ledger rows (§9).
- [ ] Error parser handles three envelopes; `FORBIDDEN` **and** `INSUFFICIENT_PERMISSION` both treated as forbidden (§10).
- [ ] `Idempotency-Key` sent on payout record; no edit/delete on ledger rows (§8).
- [ ] Permission gating in place, **without** assuming the server enforces it yet (§2).
- [ ] `refund_reversal` renderable but never triggerable (§3).
- [ ] Booking status normalised `pending` → `pending_payment` until production ships (§5).
