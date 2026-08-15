# Task: wallets & the payout run are real (Claude Code — Next.js)

**For:** a Claude Code agent working in the **admin panel** (`/wallets`, the payout run) and the
**partner dashboard** (`/wallet`, `/wallet/payouts`).
**Backend status:** ✅ **live on staging AND production**, verified 2026-08-15 ~16:30 UTC.
**Action required:** mostly **none** — the shapes are unchanged from the stubs you built against.
Read §3 and §6 before you demo.

---

## 1. What changed: the fixtures are gone

Every wallet and payout endpoint is now database-backed. **Nothing was a stub anymore, on either
surface, in any environment.**

```
admin:    GET  /admin/payouts/eligible      GET  /admin/wallets
          GET  /admin/payouts/ineligible    GET  /admin/wallets/{partnerId}
          POST /admin/payouts/record        GET  /admin/wallets/{partnerId}/ledger

partner:  GET  /wallet          GET  /payouts          GET|PUT /me/bank-details
          GET  /wallet/ledger   GET  /payouts/{id}
```

**Response shapes, casing, envelopes and error codes are exactly what the stubs returned**, so wired
components should work untouched. The differences are behavioural, and they are in §3.

### The stub error triggers are gone

Those fixture branches no longer exist:

| Old trigger | Now |
|---|---|
| `bankReference: "DUP-REF-0001"` | any **reused** bank reference → `409 DUPLICATE_BANK_REFERENCE` |
| `partnerId: "prt_paid"` | any partner **already paid this month** → `409 ALREADY_PAID_THIS_MONTH` |
| `partnerId: "prt_ineligible"` | any partner **failing eligibility** → `409 NOT_ELIGIBLE` |

All three are still the same codes and status — they now fire on real conditions. To exercise them,
record a transfer twice (duplicate reference), or record for a partner with no verified bank account.

---

## 2. Verified end-to-end on staging

A real run against 22 real bookings:

```jsonc
// GET /admin/payouts/eligible
{"partnerId":"prt_4","partnerName":"محمد الشريك الفردي","partnerType":"individual",
 "amount":87800,"bookingsCount":22,"iban":"SA0380000000608010167519",
 "bankName":"مصرف الراجحي","accountHolderName":"محمد الشريك الفردي",
 "lastPaidAt":null,"lastPaidPeriod":null}

// POST /admin/payouts/record  — body deliberately included amount 999999.99 and a junk IBAN
{"ok":true,"payoutId":"pay_1","reference":"PO-2026-08-0001"}

// what was actually recorded
ref=PO-2026-08-0001  amount=87800  bookings=22  iban=••••7519  period=2026-07
```

The partner immediately sees it:

```jsonc
// GET /wallet
{"availableBalance":0,"lifetimeEarnings":87800,"lifetimePaidOut":87800,
 "paidThisMonth":true,"bankVerified":true,
 "lastPayoutAt":"2026-08-15T16:26:18Z","lastPayoutAmount":87800,
 "payoutEligible":false,"ineligibleReason":"below_minimum"}
```

```
INVARIANT 4: amount=87800  sum(partnerShare)=87800  bookings=22  => HOLDS
```

---

## 3. Behavioural differences you must handle

### 3.1 `amount` and `iban` in the record body are ignored — by design

Send them or don't; the server decides both. The accountant states **who** they paid and the **bank's
reference**. The recorded transfer above was `87800`, not the `999999.99` the request carried.

- [ ] Don't build UI that lets an accountant type an amount. It will be silently discarded, which is
      worse than not offering the field.
- [ ] Render `amount` from `/admin/payouts/eligible` as authoritative — that is exactly what will be
      paid.

### 3.2 Eligibility is re-checked at the moment of recording

The list an accountant loaded may be minutes old, and a refund may have landed. A partner shown as
eligible can still be refused with `409 NOT_ELIGIBLE`.

- [ ] Handle a 409 on a row that your list said was eligible — it is not a bug. Show the Arabic
      `message` and refresh the list.

### 3.3 The bank reference is the idempotency key

A double-submitted form is refused with `409 DUPLICATE_BANK_REFERENCE` and **records nothing**.

- [ ] Treat that 409 as "already recorded", not as a failure needing a retry with a new reference.

### 3.4 `partnerId` is `prt_{id}`, and it round-trips

Send it back exactly as received. The `prt_` prefix is accepted with or without.

### 3.5 A paid booking never appears again

Once covered by a transfer, a stay is attached to it. Re-running the payout run the same month, or
next month, will not pay it twice — the partner simply drops out of `eligible` with nothing left to
pay.

---

## 4. `ineligible` — the reasons, and what to show

| `reason` | `shortfall` | `paidThisMonthReference` | What the accountant should read |
|---|---|---|---|
| `below_minimum` | **number** | null | needs X more to reach 2000 SAR |
| `bank_missing` | null | null | no payout account at all |
| `bank_unverified` | null | null | account saved, finance hasn't verified it |
| `partner_suspended` | null | null | payouts held |
| `negative_balance` | null | null | balance below zero after reversals |
| `already_paid_this_month` | null | **the reference** | done for the cycle — show the reference |

`shortfall` is only ever non-null for `below_minimum` (it is `2000 − availableBalance`).

**Evaluation order** (first match wins) — this differs from the order printed in the original spec
table, deliberately:

```
already_paid_this_month → partner_suspended → negative_balance → bank_missing → bank_unverified → below_minimum
```

A blocking condition outranks an arithmetic one: telling a suspended partner "earn 1510 SAR more" is
advice that cannot help them, because earning more changes nothing while they are suspended.

---

## 5. Partner side — unchanged contract, real numbers

Nothing to change. Worth knowing:

- `lifetimePaidOut` **excludes reversed transfers** — money that came back was never paid out.
- `paidThisMonth: true` is a **positive** state, not an ineligibility. Keep showing it before you look
  at `ineligibleReason` — a partner who was paid this month will *also* read `below_minimum` (their
  balance is now near zero), and leading with that would be misleading.
- `Σ bookings[].partnerShare === payout.amount` always holds. The amount is computed **from** those
  bookings, not from the raw balance, so the sheet cannot disagree with its own total.

---

## 6. ⚠️ Reversal has no endpoint — deliberately

A bounced transfer is handled by an operator command, not the admin UI:

```
php artisan payouts:reverse PO-2026-08-0001 --reason="رفض البنك"
```

It flips the payout to `reversed`, returns the money as an `adjustment` credit, and **detaches the
covered bookings so those earnings can go out again** in the next run.

- [ ] Render `status: "reversed"` with `reversedAt` / `reversalReason` on both surfaces — the data
      arrives even though nothing in your UI can cause it.
- [ ] **Do not build a reverse button.** Reversal is rare and irreversible, and it was not in the
      contract. If you want one, say so and we will design the endpoint properly rather than expose
      the command.

---

## 7. Two things that will look empty, and are correct

**Production has no eligible partners.** Live response right now:

```jsonc
// GET /admin/payouts/eligible   → []
// GET /admin/payouts/ineligible → both partners, reason "bank_missing"
```

No partner has saved a payout account yet, so the run is legitimately empty. It fills as soon as
partners add an IBAN **and finance verifies it**.

**Which raises the one gap left:** there is **no admin endpoint to verify a bank account**. Finance
can see `verified: false` on `/admin/wallets/{id}` but has no way to flip it, so today no partner can
become eligible without a database change. That is the next piece of work — **tell us if you want it
prioritised**, and whether you want it as a per-account verify/reject pair (mirroring the KYC document
flow) or folded into the existing partner-documents screen.

---

## 8. Bank names still need finance to confirm the map

`bankName` is derived from the IBAN's SAMA bank code. **Only code `80` (مصرف الراجحي) is confirmed;**
the other nine entries are the commonly published mapping and are unverified. Unknown codes return
`null`, which your neutral state already handles — so the failure mode is harmless, but a *wrong* name
against a payout account is not. Correcting it is a config edit, no deploy.

---

## 9. Checklist

**Admin panel:**
- [ ] Remove any mock/fixture switch for wallets and payouts
- [ ] No amount input on the record form (§3.1)
- [ ] 409 on an eligible-looking row is handled and refreshes the list (§3.2)
- [ ] `DUPLICATE_BANK_REFERENCE` reads as "already recorded" (§3.3)
- [ ] Empty payout run renders as an empty state, not an error (§7)
- [ ] `reversed` payouts render with reason (§6); no reverse button
- [ ] Tell us whether you want the bank-verification endpoint next (§7)

**Partner dashboard:**
- [ ] Nothing required — `paidThisMonth` before `ineligibleReason` (§5) is the only subtlety
- [ ] `NEXT_PUBLIC_ENABLE_BANK_DETAILS` can be switched on in production

---

## 10. Deploy state

| | staging | production |
|---|---|---|
| Admin payouts (eligible/ineligible/record) | ✅ live | ✅ live |
| Admin wallets (list/detail/ledger) | ✅ live | ✅ live |
| Partner wallet, ledger, payouts, bank details | ✅ live | ✅ live |
| Fixture stubs | ❌ removed | ❌ removed |

Suite: **184 passed, 1056 assertions** — including that a recorded transfer equals the server's own
figure rather than the client's, that a paid booking is never paid twice, and that a reversal restores
both the balance and the payability of the stays behind it.
