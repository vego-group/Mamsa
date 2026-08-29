# Task: the Wallets screen (Claude Code — Next.js admin panel)

**For:** a Claude Code agent building `/admin/wallets` (list) and `/admin/wallets/{partnerId}`
(detail) in the admin panel.
**Backend status:** ✅ **live on staging AND production**, database-backed. Verified 2026-08-15.
**Related docs:** `MAMSA-FRONTEND-BANK-VERIFICATION.md` (the verify/reject controls that live on the
detail screen) · `MAMSA-FRONTEND-WALLET-PAYOUT-RUN.md` (the payout run).

Every payload below is a **real staging response**, not an invented example.

---

## 1. The three endpoints

```
GET /admin/wallets?page=&pageSize=&search=&sortBy=&sortDir=
GET /admin/wallets/{partnerId}
GET /admin/wallets/{partnerId}/ledger?limit=&before=
```

Root-mounted, **no `/api/v1`**. Cookie session, `credentials: "include"`. Permission:
**`wallets.view`** — held by both superadmin and finance, so both roles reach this screen.

This screen is **read-only**. Balances move through earnings and payouts; nothing here writes them.
The only mutations on the detail page are bank verify/reject, which are a separate permission — see
the bank-verification doc.

---

## 2. List — `GET /admin/wallets`

Standard admin paginated envelope:

```jsonc
{ "items": [ … ], "total": 7, "page": 1, "pageSize": 3 }
```

A real row:

```jsonc
{
  "partnerId": "prt_5",
  "partnerName": "شركة الأفق للعقارات",
  "partnerType": "company",              // company | individual
  "availableBalance": 100190,            // SAR, SIGNED — may be negative
  "pendingBalance": 0,
  "lifetimeEarnings": 100190,
  "lifetimePaidOut": 0,
  "currency": "SAR",
  "bankVerified": true,
  "payoutEligible": true,
  "ineligibleReason": null,              // non-null IFF payoutEligible is false
  "lastPayoutAt": null                   // ISO, null if never paid
}
```

### Query parameters

| Param | Default | Notes |
|---|---|---|
| `page` | 1 | |
| `pageSize` | 10 | capped at **100** server-side |
| `search` | — | matches partner **name** or **phone** |
| `sortBy` | — | **only `partnerName`** is accepted; anything else is ignored |
| `sortDir` | `desc` | `asc` \| `desc` |

Default order is by partner id ascending (stable, oldest partners first).

- [ ] Only offer sorting on the partner-name column — the money columns are **not** sortable
      server-side. An unsupported `sortBy` is silently ignored, which would look like a broken
      control.
- [ ] `search` is the same param the other admin lists use; strip empty/`"all"` before sending.

### Rendering the money

- [ ] **`availableBalance` is signed and can be negative** — a refund reversal after a payout drives
      it below zero. Render negatives distinctly (red / parenthesised); do not clamp to 0.
- [ ] `pendingBalance` is money from confirmed stays that **haven't finished yet**. It is not
      transferable and is not part of `availableBalance`. Keep the two visually separate or the
      number will read as withheld funds.
- [ ] `lifetimePaidOut` **excludes reversed transfers** — money that bounced was never paid out.

### The eligibility column

`payoutEligible` + `ineligibleReason` are the same values the payout run uses, computed by the same
service — the two screens cannot disagree.

| `ineligibleReason` | Meaning |
|---|---|
| `already_paid_this_month` | done for the cycle |
| `partner_suspended` | payouts held |
| `negative_balance` | balance below zero |
| `bank_missing` | no payout account at all |
| `bank_unverified` | saved, not yet verified |
| `below_minimum` | under the 2000 SAR threshold |

Evaluated in exactly that order, first match wins — a blocking condition outranks an arithmetic one.

- [ ] `already_paid_this_month` is a **positive** state. Don't colour it as a problem alongside
      `bank_missing`.

---

## 3. Detail — `GET /admin/wallets/{partnerId}`

Everything from the list row, **plus** three blocks:

```jsonc
{
  "partnerId": "prt_4", "partnerName": "محمد الشريك الفردي", "partnerType": "individual",
  "availableBalance": 0, "pendingBalance": 766.96,
  "lifetimeEarnings": 87800, "lifetimePaidOut": 87800,
  "currency": "SAR", "bankVerified": true,
  "payoutEligible": false, "ineligibleReason": "below_minimum",
  "lastPayoutAt": "2026-08-15T16:26:18Z",

  "bankDetails": { … },      // null when the partner never saved an account
  "recentLedger": [ … ],     // last 10, NOT paginated
  "recentPayouts": [ … ]     // last 10
}
```

`partnerId` accepts `prt_4` or a bare `4`; it always comes back prefixed.

### 3.1 `bankDetails`

```jsonc
{
  "iban": "SA2480000000000000000000",     // FULL iban — admin surface only
  "accountHolderName": "محمد الشريك الفردي",
  "bankName": "مصرف الراجحي",              // server-derived, may be null
  "verified": true,
  "verifiedAt": "2026-08-15T16:26:17Z",
  "verifiedBy": null,                      // admin name, null for older records
  "rejectionReason": null,
  "updatedAt": "2026-08-15T16:26:18Z"
}
```

- [ ] **`null` when no account has ever been saved** — render an empty state, not a skeleton.
- [ ] `bankName` may be `null` for an unrecognised bank code. Neutral state, never block on it.
- [ ] The **full IBAN appears here**. It must not leak to any partner-facing view — partners only
      ever see `••••0000`.
- [ ] Verify/reject controls belong here → see `MAMSA-FRONTEND-BANK-VERIFICATION.md`.

### 3.2 `recentLedger` — a bounded preview

Last **10** entries, newest first. **Not paginated** — the paginated feed is §4. Real entry:

```jsonc
{
  "id": "led_51",
  "type": "payout",                        // earning | payout | refund_reversal | adjustment
  "amount": -87800,                        // SIGNED: + credit, − debit
  "balanceAfter": 0,                       // running balance AFTER this row
  "refType": "payout",                     // booking | payout | manual
  "refId": "po_1",                         // deep-link target
  "refCode": "PO-2026-08-0001",
  "description": "تحويل بنكي PO-2026-08-0001",   // Arabic, render as-is
  "createdAt": "2026-08-15T16:26:18Z"
}
```

- [ ] **Render `amount` with its sign** — don't take an absolute value and infer direction from
      `type`. The sign is authoritative.
- [ ] `description` is user-facing Arabic. **Do not build your own copy from `type`** — the server
      already includes the booking code and unit name where relevant.
- [ ] Deep-link on `refId`: `b_{id}` → the booking, `po_{id}` → the payout.
- [ ] Show a "view full ledger" affordance — 10 rows is a preview, and a busy partner has hundreds.

**`balanceAfter` on the newest row always equals `availableBalance`.** That is a backend invariant,
enforced by writing both in one row-locked transaction. Use it as a sanity check in dev; if they ever
disagree, that is a backend bug worth reporting immediately.

### 3.3 `recentPayouts`

```jsonc
{
  "id": "pay_1",
  "reference": "PO-2026-08-0001",
  "partnerId": "prt_4",
  "periodMonth": "2026-07",               // the month EARNED, not the month paid
  "amount": 87800,
  "bookingsCount": 22,
  "currency": "SAR",
  "status": "paid",                        // paid | reversed — no pending/failed exists
  "paidAt": "2026-08-15T16:26:18Z",
  "bankReference": "FT-STAGING-0001"       // the bank's own reference, for support tickets
}
```

- [ ] `periodMonth` ≠ the month of `paidAt`. Label it clearly ("عن شهر") or it reads as a bug.
- [ ] Only two statuses exist. Don't build pending/processing/failed states — a payout is recorded
      **after** the transfer has already happened.
- [ ] `bankReference` is what an accountant quotes when a partner opens a ticket. Make it
      copy-to-clipboard.

---

## 4. Ledger — `GET /admin/wallets/{partnerId}/ledger`

Cursor-paginated, newest first:

```jsonc
{
  "items": [ … ],                       // same entry shape as §3.2
  "hasMore": true,
  "nextCursor": "2026-07-29T21:00:00Z"
}
```

| Param | Default | Notes |
|---|---|---|
| `limit` | 20 | capped at 100 |
| `before` | — | pass `nextCursor` **verbatim** |

- [ ] It is a **cursor, not an offset**. Send back `nextCursor` exactly as received; don't compute a
      page number. The ledger only grows, and an offset would skip or repeat rows whenever a new
      earning lands between two pages.
- [ ] Stop when `hasMore` is `false`. `nextCursor` is `null` on the last page.
- [ ] Infinite scroll or "load more" — not numbered pages, which this endpoint cannot serve.

---

## 5. What the ledger types mean

| `type` | Sign | Written when |
|---|---|---|
| `earning` | `+` | the guest **checks out** — the partner's share of a finished stay |
| `payout` | `−` | finance records the monthly transfer |
| `refund_reversal` | `−` | a guest refund clawed back after the earning landed |
| `adjustment` | `±` | manual correction, **including crediting back a reversed payout** |

A **reversed payout produces two rows**: the original `payout` (−) and an `adjustment` (+) of the same
magnitude. The money returns to the balance while the record survives — so a partner's history shows
both, and that is correct, not a duplicate.

---

## 6. Errors

Flat admin envelope `{ message, code }`, Arabic messages.

| Status | `code` | When |
|---|---|---|
| `404` | `NOT_FOUND` — `"الشريك غير موجود"` | unknown id, or a user who is not a partner |
| `403` | `INSUFFICIENT_PERMISSION` | caller lacks `wallets.view` |
| `401` | `UNAUTHENTICATED` | session expired → bounce to OTP login |

---

## 7. Checklist

**List:**
- [ ] Paginated envelope `{ items, total, page, pageSize }`
- [ ] Search on name/phone; sort **only** on `partnerName`
- [ ] Negative `availableBalance` rendered distinctly, never clamped
- [ ] `pendingBalance` visually separate from available
- [ ] Eligibility badge from `ineligibleReason`, with `already_paid_this_month` as a positive state

**Detail:**
- [ ] `bankDetails: null` renders an empty state
- [ ] Full IBAN never reaches a partner-facing view
- [ ] `recentLedger` signs rendered as sent; `description` shown verbatim
- [ ] `refId` deep-links (`b_` → booking, `po_` → payout)
- [ ] "View full ledger" → the cursor-paginated feed
- [ ] `periodMonth` labelled as the month **earned**
- [ ] `bankReference` copyable
- [ ] Verify/reject controls per the bank-verification doc, keyed on `wallets.adjust`

**Ledger:**
- [ ] Cursor pagination via `nextCursor`, never an offset
- [ ] Stops on `hasMore: false`

---

## 8. Testing it

**Staging** has real data — 7 partners, one executed payout of 87,800 SAR across 22 bookings:

| Partner | Use it for |
|---|---|
| `prt_4` | full detail: verified bank, 23 ledger rows (22 earnings + 1 payout), `pendingBalance` 766.96 |
| `prt_5` | eligible partner, ~100,190 SAR, verified bank |
| `prt_9` | `bankDetails: null` — the empty-account state |

`prt_4` is the best single fixture: its ledger contains both `earning` and `payout` rows, so signs,
`balanceAfter`, and the payout deep-link can all be exercised on one screen.

**Production** has partners but no earnings yet, so balances are zero and both partners read
`bank_missing`. That is correct, not an empty-state bug.
