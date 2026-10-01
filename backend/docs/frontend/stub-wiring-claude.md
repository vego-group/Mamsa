# Mamsa — Wire the Wallet/Payout Stub Endpoints (Claude Code task)

**For:** a Claude Code agent working **inside the frontend repos** (admin panel + partner dashboard, Next.js).
**Goal:** wire the UI against the backend **stub endpoints** so the wallet/payout/finance screens can be built and tested now, and swap to the real endpoints later with zero rework.
**Backend contract:** `MAMSA-BACKEND-CONTRACT-WALLET-PAYOUTS-VAT.md` **v2.2** (names: `PartnerLedgerEntry`, `partner_ledger_entries`, `/ledger` paths).
**Status of these endpoints:** **fixture-backed stubs**, active on **staging only** (non-production). Shapes/casing/envelopes match the contract exactly. Do **not** hardcode fixture values — read them from the API.

> These stubs return realistic, self-consistent fixtures (balances reconcile, one partner in every ineligible state, one negative wallet). Build the real flows against them; when the backend swaps in the real controllers the shapes are identical.

---

## LIVE ON STAGING — access (published & verified 2026-08-13)

**Host:** `https://staging.mamsaa.com` · **Cookie session**, `credentials: 'include'`. Staging is
`SameSite=None` + on the CORS allowlist for `http://localhost:3002` with credentials — so local dev
against staging holds a session.

**Login (both accounts, staging OTP is fixed `<fixed OTP — request privately>`):**
```
POST /admin/auth/request-otp   { "phone": "<phone>" }
POST /admin/auth/verify-otp    { "phone": "<phone>", "code": "<fixed OTP — request privately>" }   # opens the session cookie
```
| Account | Phone | `/admin/me` role |
|---|---|---|
| Superadmin | `+966555000003` | `superadmin` (full permissions[]) |
| Finance | `+966555000004` | `finance` (9-permission subset) |

**All 9 endpoints are live** (verified end-to-end through real auth). `/admin/me` is the **real**
role+permissions response; the other eight are fixture stubs. All are inert on production (non-prod
gated). The `record` error triggers in §1.4 are live: `DUP-REF-0001` → 409 `DUPLICATE_BANK_REFERENCE`,
`prt_paid` → 409 `ALREADY_PAID_THIS_MONTH`, `prt_ineligible` → 409 `NOT_ELIGIBLE` (bankReference 4–64
chars); body `amount`/`iban` are silently ignored.

---

## 0. Ground rules (do not skip)

- **Base + auth per surface** — all calls are credentialed (cookie session), `credentials: 'include'`:
  | Surface | Base | Prefix | Auth |
  |---|---|---|---|
  | Admin panel | `https://staging.mamsaa.com` (→ `api.` in prod) | `/admin/*` | httpOnly session cookie |
  | Partner dashboard | same host | **root** (no `/api/v1`) | httpOnly session cookie |
- **Casing:** both surfaces are **camelCase**. Never snake_case here.
- **Error envelopes differ by surface — handle both:**
  - Admin panel: **flat** `{ "message": "...", "code": "..." }`
  - Partner dashboard: **nested** `{ "error": { "code": "...", "message": "...", "fields"?: {...} } }`
- **403 code is `FORBIDDEN`** (live server), not `INSUFFICIENT_PERMISSION`. Accept both.
- **Never send `amount` or `iban` to `POST /admin/payouts/record`** — the server ignores them by design; the accountant can never type an amount or destination.

---

## 1. Endpoints, exact shapes, and what to build

### 1.1 `GET /admin/me` — role + permissions (gate on this)

Returns the admin profile **now including** `role` and a flat resolved `permissions[]`:

```jsonc
{
  "id": "1", "name": "...", "email": "...", "phone": "...",
  "role": "superadmin",            // 'superadmin' | 'finance'
  "permissions": ["dashboard.view","payouts.view","payouts.execute", ...],  // flat, resolved
  "verified": true, "preferredLocale": "ar"
}
```

**Wire:** build a `useCan(perm)` hook / `<Can perm>` gate that reads `permissions[]` — **never** branch on `role`. Hide (don't just disable) actions the permission list lacks. Landing route: `superadmin → /overview`, `finance → /payouts`. Server enforces independently — treat gating as UX only.

### 1.2 `GET /admin/payouts/eligible` → `EligiblePartner[]` (bare array)

```jsonc
[{ "partnerId":"prt_101","partnerName":"...","partnerType":"company",
   "amount":4310.75,"bookingsCount":7,"iban":"SA03...","bankName":"...",
   "accountHolderName":"...","lastPaidAt":"2026-07-18T09:00:00+03:00","lastPaidPeriod":"2026-07" }]
```
`amount` is exactly what will be paid — display it read-only; never an input.

### 1.3 `GET /admin/payouts/ineligible` → `IneligiblePartner[]`

One partner in **each** reason: `below_minimum` (prt_102, has `shortfall`), `bank_unverified` (prt_104), `bank_missing` (prt_105), `already_paid_this_month` (prt_paid, has `paidThisMonthReference`), `negative_balance` (prt_103).
```jsonc
[{ "partnerId":"prt_102","partnerName":"...","partnerType":"individual",
   "availableBalance":1240.00,"reason":"below_minimum","shortfall":760.00,"paidThisMonthReference":null }]
```

### 1.4 `POST /admin/payouts/record` — the record-transfer modal

**Request (the entire payload you may send):**
```jsonc
{ "partnerId":"prt_101", "bankReference":"FT26082200144", "paidAt"?:"...", "note"?:"..." }
```
- No `amount`, no `iban` field in the form — display them read-only from the eligible row.
- Send an `Idempotency-Key` header (dedupe double-submit).

**Success:** `{ "ok": true, "payoutId": "pay_stub_0001", "reference": "PO-2026-08-0042" }`

**Error triggers (build/test the flows against these):** `bankReference` must be **4–64 chars**.
| Send | Result (409) |
|---|---|
| `bankReference: "DUP-REF-0001"` | `DUPLICATE_BANK_REFERENCE` |
| `partnerId: "prt_paid"` (+ any valid ref) | `ALREADY_PAID_THIS_MONTH` |
| `partnerId: "prt_ineligible"` (+ any valid ref) | `NOT_ELIGIBLE` |
| anything else | success |

Because the eligible list can be stale, always be ready to render a 409 even from a row that looked eligible.

### 1.5 `GET /admin/wallets` → `Paginated<PartnerWallet>`

Envelope: `{ "items":[...], "total":N, "page":1, "pageSize":10 }`. Fixtures include **one negative** (`prt_103 = -150.00`) — render it red, never clamp.
```jsonc
{ "partnerId":"prt_101","partnerName":"...","partnerType":"company",
  "availableBalance":4310.75,"pendingBalance":1204.00,"lifetimeEarnings":38920.40,
  "lifetimePaidOut":34609.65,"currency":"SAR","bankVerified":true,"payoutEligible":true,
  "ineligibleReason":null,"lastPayoutAt":"...","updatedAt":"..." }
```

### 1.6 `GET /admin/wallets/{partnerId}` → `PartnerWalletDetail`

`PartnerWallet` + `bankDetails` + `recentLedger` (`PartnerLedgerEntry[]`, sums to `availableBalance`) + `recentPayouts`.
```jsonc
// PartnerLedgerEntry
{ "id":"ple_01","partnerId":"prt_101","type":"earning","amount":2000.00,"balanceAfter":2000.00,
  "refType":"booking","refId":"bkg_9001","refCode":"BKG-9001","description":"...","createdAt":"...","createdByAdminId":null }
// type ∈ earning | payout | refund_reversal | adjustment
```

### 1.7 `GET /wallet` → `PartnerWalletSummary` (partner dashboard)

```jsonc
{ "availableBalance":4310.75,"pendingBalance":1204.00,"lifetimeEarnings":38920.40,
  "lifetimePaidOut":34609.65,"currency":"SAR","minPayoutAmount":2000.00,"payoutEligible":true,
  "ineligibleReason":null,"nextPayoutDate":"2026-09-01","bankVerified":true,
  "lastPayoutAt":"...","lastPayoutAmount":3980.00 }
```
The partner **never requests a payout** — remove any such control. `nextPayoutDate` is informational.

### 1.8 `GET /wallet/ledger?limit=&before=` → **paginated** (partner)

The ledger grows without bound (immutable rows, one per booking/payout), so it is **cursor-paginated, not a bare array**:
```jsonc
{
  "items": [ /* PartnerLedgerEntry[] — newest first */ ],
  "hasMore": true,
  "nextCursor": "2026-08-06T09:00:00+03:00"   // createdAt of the last item, or null when no more
}
```
`?limit=` default 20, max 100 · `?before=` = an ISO-8601 `createdAt` cursor (returns entries older than it). Build the load-more path now. **Do not** show `commission` per line on the partner side.

**Admin equivalent:** `GET /admin/wallets/{partnerId}/ledger?limit=&before=` returns the **same** paginated envelope. (This is distinct from `PartnerWalletDetail.recentLedger` in §1.6, which stays a bounded last-N preview.)

### 1.9 `GET` / `PUT /me/bank-details` → `BankDetails`

```jsonc
{ "iban":"SA03...","accountHolderName":"...","bankName":"...",
  "verified":true,"verifiedAt":"...","rejectionReason":null,"updatedAt":"..." }
```
`PUT { iban, accountHolderName }` — client-validate `^SA\d{22}$` (server also runs mod-97 → `422 INVALID_IBAN`). **Any IBAN change resets `verified` to `false`** — warn before save, then reflect "pending verification". Works for **both** individual and company partners.

---

## 2. Frontend build tasks (per surface)

**Admin panel**
- [ ] `useCan`/`<Can>` gate reading `permissions[]` from `/admin/me`; route guard + landing routes.
- [ ] Payouts: eligible list, ineligible list (reason chips), **record modal with NO amount/iban input** + `Idempotency-Key`, and the three 409 toasts.
- [ ] Wallets: list (stats + negative in red), detail (ledger timeline reconciling to balance, recent payouts, bank details read-only).

**Partner dashboard**
- [ ] Wallet: four balance cards + eligibility banner (map `ineligibleReason` → Arabic), ledger list; negative in red; no "request payout".
- [ ] Bank details screen (both account types): IBAN input with `^SA\d{22}$`, the verified→false-on-change warning, states missing/unverified/verified/rejected.

**Both**
- [ ] One error parser handling flat (admin) + nested (partner) envelopes; treat `FORBIDDEN` and `INSUFFICIENT_PERMISSION` as forbidden.
- [ ] TS types mirror the shapes above verbatim (camelCase). Money = number, 2dp. Dates ISO-8601 w/ offset.

---

## 3. The two shapes — now resolved (locked)

1. **`PartnerWalletDetail.recentLedger`** — **confirmed correct**, keep it (bounded last-N preview).
2. **`GET /wallet/ledger` + `GET /admin/wallets/{partnerId}/ledger`** — **now paginated** `{ items, hasMore, nextCursor }` with `?limit=&before=` (§1.8). Live on staging.

## Per-environment hosts (confirmed)

All three surfaces share **one host per environment**, distinguished only by path — so on staging `GET /wallet` (partner, root) and `GET /admin/wallets` (admin, `/admin/*`) sit on the same host:

| Surface | Staging host | Production host | Prefix |
|---|---|---|---|
| Guest site | `https://staging.mamsaa.com` | `https://api.mamsaa.com` | `/api/v1` |
| Partner dashboard | `https://staging.mamsaa.com` | `https://api.mamsaa.com` | **root** (no prefix) |
| Admin panel | `https://staging.mamsaa.com` | `https://api.mamsaa.com` | `/admin/*` |

(The earlier integration note listing the partner base as `api.mamsaa.com` was the **production** host — both are correct, one per environment.)

---

## 4. When the real endpoints land

The stubs are non-production only and will be replaced by real controllers with **identical shapes**. No frontend rewiring expected — only the fixtures become live data. The backend will send the real staging URLs and test credentials (superadmin + finance) alongside this file.
