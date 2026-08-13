# Mamsa — Frontend Implementation Guide (Next.js)

**VAT-inclusive pricing · Partner wallets · Monthly payouts · Finance role**

**Version:** 1.0
**Date:** 2026-08-12
**Audience:** the Next.js frontend team (guest site `www`, partner dashboard, admin panel)
**Companion to:** `MAMSA-BACKEND-CONTRACT-WALLET-PAYOUTS-VAT.md` **v2.1** — that document is the
source of truth for every shape and rule; this one tells you what to build on the client and how.
**Status:** backend not yet built. Build against the shapes here behind a feature flag, wire when the
endpoints land. Where this guide and the contract ever disagree, **the contract wins** — tell the
backend team so we fix one of them.

---

## Table of contents

0. [The one rule that governs everything](#0-the-one-rule-that-governs-everything)
1. [VAT-inclusive pricing — the core change](#1-vat-inclusive-pricing--the-core-change)
2. [Partner wallet UI](#2-partner-wallet-ui-partner-dashboard)
3. [Bank details — both account types](#3-bank-details--both-account-types-partner-dashboard)
4. [Payouts — partner side (read-only)](#4-payouts--partner-side-read-only)
5. [Admin — wallets](#5-admin--wallets)
6. [Admin — payouts (the finance workflow)](#6-admin--payouts-the-finance-workflow)
7. [Finance role & permission gating](#7-finance-role--permission-gating-admin-panel)
8. [Notifications — new categories](#8-notifications--new-categories)
9. [Shared TypeScript — types, enums, constants](#9-shared-typescript--types-enums-constants)
10. [Error codes → user copy](#10-error-codes--user-copy)
11. [Build order & feature-flagging](#11-build-order--feature-flagging)
12. [Per-surface test checklist](#12-per-surface-test-checklist)

---

## 0. The one rule that governs everything

> **The frontend never computes VAT, commission, or partner share. It reads server-computed fields
> and formats them.**

Every monetary number — `gross`, `netBase`, `vat`, `commission`, `partnerShare`, wallet balances,
payout amounts — is calculated and frozen on the server. The client's job is display and input, not
arithmetic. This is not a style preference; it is what keeps three repos, a tax invoice, and a wallet
ledger reconcilable. If you find yourself writing `× 1.15`, `× 0.15`, `/ 1.15`, `× 0.02`, or
`× 0.98` **anywhere** in a frontend, stop — that number already comes from the API.

**Base URLs & auth** (unchanged from today):

| App | Base | Prefix | Auth |
|---|---|---|---|
| Guest site (`www`) | `https://api.mamsaa.com` | `/api/v1` | Sanctum Bearer |
| Partner dashboard | `https://api.mamsaa.com` | **root** (no `/api/v1`) | httpOnly session cookie, `credentials: include` |
| Admin panel | `https://api.mamsaa.com` | **root** (`/admin/*`) | httpOnly session cookie, `credentials: include` |

---

## 1. VAT-inclusive pricing — the core change

### 1.1 The mental-model shift

| | Old (deprecated) | New (this release) |
|---|---|---|
| What `pricePerNight` means | **net** price | **gross**, VAT-inclusive price |
| Guest total | `pricePerNight × nights × 1.15` | `pricePerNight × nights` |
| When VAT appears | **added** at checkout | **broken out** for transparency, never added |
| The number the guest sees in search | not what they pay | exactly what they pay |

In Saudi Arabia the price shown to a consumer **must** be the VAT-inclusive final price (contract
§1.2). So: **the guest sees the final payable figure everywhere**, and VAT is only itemised on the
booking summary and the tax invoice — it never increases a number the guest already saw.

### 1.2 The canonical object — `PriceBreakdown`

Every price surface reads this object. No client recomputes VAT.

```ts
interface PriceBreakdown {
  pricePerNight: number;   // gross, VAT-inclusive
  nights: number;
  gross: number;           // what the guest pays — the ONLY figure shown pre-checkout
  netBase: number;
  vatRate: number;         // e.g. 0.15 — READ THIS, do not hardcode 15%
  vat: number;
  commission?: number;     // internal — present on partner (own) & admin only, NEVER on guest
  partnerShare?: number;   // internal — present on partner (own) & admin only, NEVER on guest
  currency: 'SAR';
}
```

- Appears on: **checkout quote**, **booking creation**, **booking detail**.
- `vatRate` is **frozen per booking** on the backend (the same pattern the code already uses for
  `tax_percent`/commission; treat the frozen-rate rule as agreed even though it is not yet a numbered
  clause in the canonical contract). Label the invoice from `vatRate`, not from a constant — a booking
  made under a different rate must still print its own rate.
- On the **guest site**, `commission` and `partnerShare` are **absent** and must never be requested,
  logged, or rendered.

### 1.3 A shared money helper (drop into each repo)

```ts
// money.ts — formatting only, never arithmetic on displayed values
export const formatSAR = (v: number, locale = 'ar-SA') =>
  new Intl.NumberFormat(locale, {
    style: 'currency', currency: 'SAR', minimumFractionDigits: 2, maximumFractionDigits: 2,
  }).format(v);

// "شامل ضريبة القيمة المضافة (15%)" — build the % label from the API's vatRate
export const vatLabel = (vatRate: number, locale = 'ar-SA') =>
  `شامل ضريبة القيمة المضافة (${new Intl.NumberFormat(locale, { style: 'percent' }).format(vatRate)})`;
```

### 1.4 Guest site (`www`) — the payable figure everywhere

- **Search cards & unit page:** render `pricePerNight` **verbatim**. Remove any `× 1.15`. Add a small
  "شامل الضريبة" (VAT incl.) label next to the price.
- **Checkout summary:** show `gross` as the payable total, prominent. Below it, itemise for
  transparency — `netBase` and `vat` with the `vatLabel(vatRate)` — but the amount charged is
  `gross`, unchanged. **Do not add VAT on top.**
- **Booking detail:** same, from `PriceBreakdown` (guest variant — no commission/partnerShare).
- **Tax invoice / receipt** (`GET /bookings/{id}/invoice`): render the returned fields; render the
  `qrCode` string as a QR image and **nothing more** — the client never builds the TLV/QR itself
  (contract §7.1). If a refund exists, a **credit note** is available with negative lines and its own
  QR (contract §1.10) — render it the same way.
- **Never** display or fetch `commission` / `partnerShare` on any guest surface.

> If the Vue `testvue` bench is still in use for guest QA, the same three rules apply there: price
> verbatim, no `× 1.15`, breakdown from the API.

### 1.5 Partner dashboard — the price input flips meaning

- **Unit create / edit:** the price field is now the **gross, VAT-inclusive** price. Update the label
  and helper text (e.g. "السعر لليلة شامل ضريبة القيمة المضافة"). Keep a client `> 0` check; the
  server is authoritative. Endpoints unchanged: `POST /units`, `PATCH /units/{id}`.
- **Booking financials** (`GET /bookings`): each row now carries `netBase` and `vat` alongside
  `total` (now **gross**), plus `commission` and `partnerShare` for the partner's **own** bookings.
  You may show the partner their `commission` and `partnerShare` — it is their money and their cut.
- **Overview / reports** (`GET /overview`, `GET /reports/summary`): `totalRevenue` is now **partner
  share, net of VAT**; `grossRevenue` means **guest-paid gross incl. VAT**; add `netRevenue` and
  `vat` lines. `netProfit = netRevenue − commission = partnerShare` (contract §6.1). Relabel the
  cards so "revenue" is unambiguous.
- **`/me/company-docs`:** the IBAN field there is **deprecated** — stop sending it on `PUT` (read-only
  for backfill). Bank details move to their own screen (§3).

### 1.6 Admin panel

- **Booking detail** (`GET /admin/bookings/{id}`): full `PriceBreakdown` incl. `commission` &
  `partnerShare`.
- **Booking list** (`GET /admin/bookings`): rows gain `netBase` and `vat`; `total` is now gross.
- **Dashboard KPIs** (`GET /admin/dashboard/summary`): revenue must be shown **net of VAT**; a
  separate `totalVat` is provided so VAT is visible but **never double-counted** as revenue. Do not
  sum `totalVat` into a revenue tile.
- **Reports** (`GET /admin/reports/summary`): new `netRevenue`, `vatCollected`, `payoutsPaid`,
  `payoutsPending`.

### 1.7 VAT do / don't

| ✅ Do | ❌ Don't |
|---|---|
| Show `pricePerNight` and `gross` verbatim | Multiply anything by `1.15` / `0.15` |
| Read `vat`, `netBase`, `vatRate` from the API | Compute VAT from the price in the client |
| Label the rate from `vatRate` | Hardcode "15%" on the invoice |
| Charge/display `gross` as the payable | Add VAT "at checkout" |
| Hide `commission`/`partnerShare` from guests | Return or log internal margin on `www` |

---

## 2. Partner wallet UI (partner dashboard)

`GET /wallet` → `PartnerWalletSummary`. Balances are **computed and owned by the backend** — render,
don't derive.

```ts
interface PartnerWalletSummary {
  availableBalance: number;
  pendingBalance: number;
  lifetimeEarnings: number;
  lifetimePaidOut: number;
  currency: 'SAR';
  minPayoutAmount: number;          // 2000.00
  payoutEligible: boolean;
  ineligibleReason: WalletIneligibleReason | null;
  nextPayoutDate: string | null;    // YYYY-MM-DD
  bankVerified: boolean;
  lastPayoutAt: string | null;      // ISO-8601
  lastPayoutAmount: number | null;
}
```

**UI:**
- Four balance cards: Available, Pending, Lifetime earnings, Lifetime paid out.
- An eligibility banner: if `payoutEligible === false`, show the reason mapped to Arabic copy
  (§10 / §8.4 of the contract). If below minimum, show progress toward `minPayoutAmount`.
- `nextPayoutDate` is informational only — the partner never triggers a payout (§4).
- **Ledger** (`GET /wallet/transactions?limit=&before=`): list of `WalletTransaction` — a type badge,
  signed amount (green `+` / red `−`), `balanceAfter`, `refCode`, `description`, formatted date.
  Provide an empty state ("لا توجد حركات بعد").

```ts
interface WalletTransaction {
  id: string;
  type: 'earning' | 'payout' | 'refund_reversal' | 'adjustment';
  amount: number;          // signed: + credit, − debit
  balanceAfter: number;
  refType: 'booking' | 'payout' | 'manual';
  refId: string;
  refCode: string;         // human-readable, show this
  description: string;     // Arabic, show verbatim
  createdAt: string;       // ISO-8601
}
```

`availableBalance` **may be negative** (a refund reversed more than the current balance) — render it
as-is in red; do not clamp to zero.

---

## 3. Bank details — both account types (partner dashboard)

**This is a launch blocker today:** IBAN currently lives only inside company docs and only renders for
`company` partners, so **individual partners cannot be paid**. Bank details now apply to **both**.

`GET /me/bank-details` · `PUT /me/bank-details { iban, accountHolderName }` → `BankDetails`:

```ts
interface BankDetails {
  iban: string;
  accountHolderName: string;
  bankName: string | null;      // server-derived from the IBAN bank code
  verified: boolean;
  verifiedAt: string | null;
  rejectionReason: string | null;
  updatedAt: string;
}
```

**Client rules:**
- Validate `^SA\d{22}$` (uppercase, strip spaces) for instant UX. The **server** additionally runs a
  mod-97 checksum and returns `422 INVALID_IBAN` — surface that message; don't assume regex = valid.
- `accountHolderName` required, non-empty.
- **Any change to the IBAN resets `verified` to `false` server-side.** Warn the partner *before* they
  save ("تغيير الآيبان يتطلب إعادة التحقق قبل صرف المستحقات"). After save, reflect the pending state.
- Render four states clearly: **missing** (no bank details → payouts blocked), **unverified**
  (pending review), **verified** (green), **rejected** (show `rejectionReason` + allow re-submit).
- Verification is done by a superadmin in the admin panel — the partner **cannot** self-verify.
- Remove/deprecate the IBAN input inside the company-docs screen.

---

## 4. Payouts — partner side (read-only)

The partner **never requests a payout**. If any "request payout" button exists today, **remove it** —
the accountant records transfers that already happened (contract §3).

`GET /payouts?limit=` · `GET /payouts/{id}`:

```ts
// PartnerPayout = Payout minus partnerName/partnerType/recordedBy*, iban masked
interface PartnerPayout {
  id: string;
  reference: string;            // PO-2026-08-0042
  periodMonth: string;          // YYYY-MM
  amount: number;
  bookingsCount: number;
  currency: 'SAR';
  iban: string;                 // masked: "••••7519"
  bankName: string;
  accountHolderName: string;
  status: 'paid' | 'reversed';
  paidAt: string;
  bankReference: string;
  note: string | null;
  reversedAt: string | null;
  reversalReason: string | null;
}
```

**UI:**
- History list: reference, period, amount, status badge (`paid` / `reversed`), date.
- Detail (`PartnerPayoutDetail`): includes a `bookings` array so the partner can reconcile the amount
  against their own stays — show **`gross` and `partnerShare` per line only**. **Do not** itemise
  `commission` per booking line (contract §6 — the rate is fixed by contract; itemising invites a
  monthly argument).
- A `reversed` payout **stays visible** with its reversal date — never hide a payout the partner was
  already emailed about.
- An in-app notification (category `payout`, §8) links here.

---

## 5. Admin — wallets

Permission `wallets.view` to read, `wallets.adjust` to mutate.

| Method | Path | Returns |
|---|---|---|
| GET | `/admin/wallets` (filters: `type`, `eligibility`, `minBalance`, `maxBalance`) | `Paginated<PartnerWallet>` |
| GET | `/admin/wallets/stats` | `WalletStats` |
| GET | `/admin/wallets/{partnerId}` | `PartnerWalletDetail` |
| GET | `/admin/wallets/{partnerId}/transactions` (filters: `type`, `from`, `to`) | `Paginated<WalletTransaction>` |
| POST | `/admin/wallets/{partnerId}/adjust` `{ amount, reason }` | `{ ok: true }` |

**UI:** a wallets table + a stats strip (`totalAvailable`, `totalPending`, `eligibleCount`,
`eligibleAmount`, `belowMinimumCount`, `bankUnverifiedCount`, `bankMissingCount`,
`negativeBalanceCount`). Detail view = balances + ledger + recent payouts + bank details (read-only
here). **Adjust** modal (`amount` ±, `reason` min 10 chars) is **superadmin only** — gate it on
`wallets.adjust` (§7).

---

## 6. Admin — payouts (the finance workflow)

This is the biggest new admin surface. Permissions: `payouts.view` (read), `payouts.execute`
(record + resend), `payouts.reverse` (**superadmin only**), `payouts.manage` (manual off-cycle,
**superadmin only**).

| Method | Path | Permission | Returns |
|---|---|---|---|
| GET | `/admin/payouts/eligible` | `payouts.view` | `EligiblePartner[]` |
| GET | `/admin/payouts/ineligible` | `payouts.view` | `IneligiblePartner[]` |
| GET | `/admin/payouts` (filters: `status`, `periodMonth`, `partnerId`, `from`, `to`) | `payouts.view` | `Paginated<Payout>` |
| GET | `/admin/payouts/stats` | `payouts.view` | `PayoutStats` |
| GET | `/admin/payouts/{id}` | `payouts.view` | `PayoutDetail` |
| POST | `/admin/payouts/record` `{ partnerId, bankReference, paidAt?, note? }` | `payouts.execute` | `{ ok, payoutId, reference }` |
| POST | `/admin/payouts/{id}/reverse` `{ reason }` | `payouts.reverse` | `{ ok: true }` |
| POST | `/admin/payouts/{id}/resend-notification` | `payouts.execute` | `{ ok: true }` |
| POST | `/admin/payouts/manual` `{ partnerId, amount, note, override? }` | `payouts.manage` | `{ ok, payoutId }` |
| GET | `/admin/payouts/export.csv` | `payouts.view` | `text/csv` |

### 6.1 The eligible list

`EligiblePartner[]` — each row's `amount` is **exactly what will be paid** (server-computed). Show
partner, type, amount, `bookingsCount`, IBAN, bank, `accountHolderName`, last paid period. This is the
finance user's worklist.

### 6.2 Record-transfer modal — **the critical UI constraint**

> **The form has NO amount field and NO IBAN field.**

The accountant makes the bank transfer first (in their own banking channel), then records it. The only
input the finance user can influence:

```ts
{ partnerId, bankReference, paidAt?, note? }
```

- `amount` and `iban` are **server-computed and frozen** — display them (read-only, from the eligible
  row) so the user can eyeball them, but never as editable inputs. If you send `amount`/`iban` the
  server ignores them.
- `bankReference`: required, trimmed, 4–64 chars, **unique across all payouts**.
- `paidAt`: optional; defaults to now; not in the future; not a month already paid for this partner.
- Send an **`Idempotency-Key`** header (same convention as `hostCancel`) so a double-submit returns
  the original payout, not a second one.
- On success the backend emails + in-app-notifies the partner automatically — surface a confirmation.

**Why no amount box:** it satisfies segregation of duties without a two-person approval. The only
free-text is the bank reference, which is a *record*, not an instruction. Communicate this in the UI
copy so the finance user understands they can't "type an amount."

### 6.3 Errors to handle (toasts, §10)

`409 NOT_ELIGIBLE` (with specific reason) · `409 ALREADY_PAID_THIS_MONTH` ·
`409 DUPLICATE_BANK_REFERENCE` · `422 BANK_DETAILS_UNVERIFIED`. The client eligible list can be stale;
the server re-validates at call time, so **always** be ready to show a rejection even from a row that
looked eligible.

### 6.4 Payout list / detail / reverse / manual

- **List + stats** (`PayoutStats`: `eligibleCount`, `eligibleAmount`, `paidThisMonthCount/Amount`,
  `ineligibleCount`, `reversedCount`, `lifetimePaidAmount`, `currentPeriodMonth`).
- **Detail** (`PayoutDetail`): frozen snapshot + `bookings[]` (the audit trail) + `timeline[]`
  (recorded → notified). Render the timeline as an activity log.
- **Reverse** (`payouts.reverse`, superadmin): reason ≥ 10 chars. **Hide the button entirely** for
  finance — don't just disable it.
- **Manual off-cycle** (`payouts.manage`, superadmin): the **only** screen with an `amount` input.
  `override: true` bypasses the 2,000 minimum / once-per-month cap and must render a distinct badge on
  the resulting payout. Keep this route invisible to finance.
- **Ineligible view** (`IneligiblePartner[]`): show *why* each partner is excluded (reason + shortfall
  or `paidThisMonthReference`) so finance isn't left guessing.
- **CSV export** button (`payouts.view`) → download `text/csv`.

---

## 7. Finance role & permission gating (admin panel)

### 7.1 What changes

`GET /admin/me` now returns:

```ts
interface AdminProfile {
  // ...existing fields...
  role: 'superadmin' | 'finance';
  permissions: Permission[];   // resolved, flat list — gate on THIS, not on role
}
```

### 7.2 Gate on permissions, never on role

```tsx
// usePermissions.ts
export const useCan = (perm: Permission) => {
  const { permissions } = useAdminProfile();   // from /admin/me
  return permissions.includes(perm);
};

// <Can perm="payouts.reverse"><ReverseButton/></Can>
export const Can = ({ perm, children }: { perm: Permission; children: ReactNode }) =>
  useCan(perm) ? <>{children}</> : null;
```

- Route guard: redirect to the landing route if the user lacks the section's `*.view`.
- **Landing route:** `superadmin → /overview`, `finance → /payouts`.
- Hide (not just disable) actions the role can't perform: reverse, wallet adjust, manual payout, bank
  verify/reject, and everything outside finance's matrix (users, units, approvals, overview).

### 7.3 Permission matrix (finance vs superadmin)

Finance **can**: view wallets + ledgers, view eligible/ineligible, **record** a transfer, resend a
notification, view financial reports, view bookings & cancellations read-only, view partner bank
details read-only (incl. **full IBAN**, needed to transfer).
Finance **cannot**: reverse a payout, manual/custom-amount payout, adjust a wallet, verify/reject bank
details, manage partners/users/units/approvals, see the overview dashboard.

Full matrix: contract §4.3.

### 7.4 The security caveat — read this

> **Frontend gating is UX, not security. The backend enforces every permission server-side.**

Do not treat a hidden button as protection. Every mutating call is independently authorised on the
server (a `403` — the **live server returns code `FORBIDDEN`**, not `INSUFFICIENT_PERMISSION` as the
contract says; accept either); handle that response gracefully even for actions your UI thought it hid.
The full IBAN shown to finance is server-audited on every read — nothing extra for the client beyond
displaying it.

---

## 8. Notifications — new categories

Two categories are **appended** to the existing set (`approval`, `booking`, `cancellation`, `partner`,
`system`, `refund`):

```
"payout" | "wallet"
```

- **Partner dashboard** feed (`GET /notifications`): render `payout` (icon + label, link to
  `/payouts/{id}`). The existing `NotificationItem`/feed shape is unchanged.
- **Admin** feed (`GET /admin/notifications`): render `payout` and `wallet`. The admin BFF already
  maps by keyword and returns `{ category, entity }` — add the two cases to your bell/list rendering
  and deep-link by `entity`.
- No shape change — you're adding icon/label/deep-link cases for two new category strings.

---

## 9. Shared TypeScript — types, enums, constants

Copy one `mamsa-finance.types.ts` into each repo so the three apps stay byte-identical.

```ts
// ---- constants (labels/fallbacks only — real values come from the API) ----
export const VAT_RATE = 0.15;
export const PLATFORM_COMMISSION_RATE = 0.02;
export const PAYOUT_MIN_BALANCE = 2000.0;
export const PAYOUT_TIMEZONE = 'Asia/Riyadh';
export const CURRENCY = 'SAR';

// ---- enums ----
export type PayoutStatus = 'paid' | 'reversed';
export type WalletTransactionType = 'earning' | 'payout' | 'refund_reversal' | 'adjustment';
export type WalletIneligibleReason =
  | 'below_minimum' | 'bank_unverified' | 'bank_missing'
  | 'partner_suspended' | 'negative_balance' | 'already_paid_this_month' | null;
export type AdminRole = 'superadmin' | 'finance';
export type Permission =
  | 'dashboard.view' | 'users.view' | 'users.manage'
  | 'partners.view' | 'partners.manage'
  | 'units.view' | 'units.manage'
  | 'approvals.view' | 'approvals.manage'
  | 'bookings.view' | 'cancellations.view' | 'cancellations.manage'
  | 'wallets.view' | 'wallets.adjust'
  | 'payouts.view' | 'payouts.execute' | 'payouts.reverse' | 'payouts.manage'
  | 'reports.financial' | 'reports.operational'
  | 'notifications.view' | 'profile.view';
export type NotificationCategory =
  | 'approval' | 'booking' | 'cancellation' | 'partner' | 'system' | 'refund'
  | 'payout' | 'wallet';
```

Plus the interfaces already shown: `PriceBreakdown` (§1.2), `PartnerWalletSummary` (§2),
`WalletTransaction` (§2), `BankDetails` (§3), `PartnerPayout` (§4), `AdminProfile` (§7). The admin-side
`PartnerWallet`, `PartnerWalletDetail`, `WalletStats`, `Payout`, `PayoutDetail`, `EligiblePartner`,
`IneligiblePartner`, `PayoutStats` are specified verbatim in contract §2, §3, §5 — mirror them.

**Representation (contract §8.8):** money = **JSON number, SAR, 2 dp** (not halalas, not strings).
Timestamps = ISO-8601 with offset. Date-only = `YYYY-MM-DD`; format to `DD/MM/YYYY` Gregorian in the
UI. `periodMonth` = `YYYY-MM`.

---

## 10. Error codes → user copy

Show the server's Arabic message when present; otherwise map:

| HTTP | Code | Suggested Arabic copy |
|---|---|---|
| 403 | `FORBIDDEN` (live server) / `INSUFFICIENT_PERMISSION` (contract) — **accept both** | ليس لديك صلاحية لهذا الإجراء |
| 409 | `NOT_ELIGIBLE` | الشريك غير مؤهل للصرف حالياً (السبب مرفق) |
| 409 | `ALREADY_PAID_THIS_MONTH` | تم صرف مستحقات هذا الشهر بالفعل |
| 409 | `ALREADY_REVERSED` | تم عكس هذا التحويل مسبقاً |
| 409 | `DUPLICATE_BANK_REFERENCE` | رقم المرجع البنكي مستخدم من قبل |
| 422 | `INVALID_IBAN` | رقم الآيبان غير صحيح |
| 422 | `BANK_DETAILS_MISSING` | لا توجد بيانات بنكية للشريك |
| 422 | `BANK_DETAILS_UNVERIFIED` | البيانات البنكية غير موثّقة |
| 422 | `BELOW_MINIMUM_PAYOUT` | المبلغ أقل من الحد الأدنى للصرف |
| 422 | `INSUFFICIENT_BALANCE` | المبلغ يتجاوز الرصيد المتاح |

---

## 11. Build order & feature-flagging

Mirror the backend sequence (contract §10.5) so you never build against an endpoint that isn't there:

1. **Permission plumbing** — read `role`/`permissions` from `/admin/me`, ship `useCan`/`<Can>` and the
   route guard. Nothing else in the admin work lands cleanly without this.
2. **VAT display** — flip the guest price rendering, checkout breakdown, invoice + QR. Gate behind a
   `NEXT_PUBLIC_VAT_INCLUSIVE` flag until the backend split ships, because the two models can't
   coexist on one screen.
3. **Bank details** screen (both account types) — unblocks payout eligibility.
4. **Partner wallet** read UI.
5. **Admin eligible/ineligible + record-transfer** modal.
6. **Payout list / detail / reverse / manual / CSV.**
7. **Notifications** `payout` / `wallet` cases.

Keep each behind a flag and wire to the live endpoint as it lands; the shapes here won't change under
you (they're the agreed contract), but the **availability** will roll out in this order.

---

## 12. Per-surface test checklist

**Guest (`www`):**
- [ ] Search/unit price shows the gross figure with "شامل الضريبة"; no `× 1.15` anywhere.
- [ ] Checkout payable = `gross`; `netBase` + `vat` shown as a breakdown, not an addition.
- [ ] Charged amount equals the displayed `gross` (to the halala).
- [ ] Invoice renders `netBase`/`vat`/`gross` + QR; refund shows a credit note.
- [ ] `commission`/`partnerShare` appear nowhere in the network tab.

**Partner dashboard:**
- [ ] Unit price input labelled as gross/VAT-inclusive.
- [ ] Wallet cards + ledger render from the API; negative balance shows in red, not clamped.
- [ ] Bank details work for an **individual** partner; changing IBAN flips to unverified with a warning.
- [ ] No "request payout" control exists; payout history + detail render; `reversed` stays visible.

**Admin panel:**
- [ ] `finance` login lands on `/payouts`, sees only its permitted sections; reverse/adjust/manual are
      **absent** (not just disabled).
- [ ] Record-transfer modal has **no amount and no IBAN input**; sends `Idempotency-Key`; a duplicate
      reference and an already-paid partner both surface the right toast.
- [ ] Dashboard revenue is net of VAT; `totalVat` shown separately, not summed into revenue.
- [ ] A mutating call still 403s server-side when attempted beyond the UI's gating (verify once).
```
