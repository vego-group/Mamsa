# Task: the Payouts screen (Claude Code — Next.js admin panel)

**For:** a Claude Code agent building `/admin/payouts` — the monthly payout run.
**Backend status:** ✅ **live on staging AND production**, database-backed. Verified 2026-08-15.
**Related:** `MAMSA-FRONTEND-ADMIN-WALLETS-SCREEN.md` · `MAMSA-FRONTEND-BANK-VERIFICATION.md`

Every payload and error below is a **real staging response**, captured against the live API.

---

## 0. What this screen actually is

**It is not a payment button.** The accountant executes transfers in their own bank, then comes here
to **record what they already did**. That single fact drives the whole design:

- there is no `pending`, `processing` or `failed` state — a payout row exists only after the money
  moved;
- the server decides the amount, because the accountant is reporting, not instructing;
- recording is irreversible from the UI (a bounce is handled by an operator command).

Build it as a **reconciliation worksheet**, not a checkout flow.

---

## 1. The three endpoints

```
GET  /admin/payouts/eligible      → EligiblePartner[]    (bare array)
GET  /admin/payouts/ineligible    → IneligiblePartner[]  (bare array)
POST /admin/payouts/record        → { ok, payoutId, reference }
```

Root-mounted, **no `/api/v1`**. Cookie session, `credentials: "include"`.
No query parameters, no pagination — the run is a monthly working set, not a feed.

| Endpoint | Permission | Finance role |
|---|---|---|
| `GET /admin/payouts/*` | `payouts.view` | ✅ has it |
| `POST /admin/payouts/record` | `payouts.execute` | ✅ has it |
| (bank verify/reject) | `wallets.adjust` | ❌ **does not** |

Finance can run payouts end to end but **cannot approve a destination** — that split is deliberate,
see the bank-verification doc.

---

## 2. `GET /admin/payouts/eligible` — the work list

A real staging row:

```jsonc
{
  "partnerId": "prt_4",
  "partnerName": "محمد الشريك الفردي",
  "partnerType": "individual",
  "amount": 87800,                                  // exactly what will be paid
  "bookingsCount": 22,
  "iban": "SA2480000000000000000000",               // FULL — the accountant types this into the bank
  "bankName": "مصرف الراجحي",                        // may be null
  "accountHolderName": "محمد الشريك الفردي",
  "lastPaidAt": null,                               // ISO, null if never paid
  "lastPaidPeriod": null                            // "YYYY-MM"
}
```

### What the accountant needs from this screen

- [ ] **`iban` copy-to-clipboard, prominently.** This is the one screen where a full IBAN is shown on
      purpose — it is what gets pasted into the banking portal. A transcription error here sends real
      money to the wrong account.
- [ ] Show `accountHolderName` next to it — the bank will reject a mismatched name, and catching it
      here saves a bounced transfer.
- [ ] `amount` is authoritative and **server-computed**. Display it read-only.
- [ ] `bookingsCount` is worth showing — "87,800 SAR across 22 bookings" reads as a checkable figure.
- [ ] `lastPaidAt` / `lastPaidPeriod` are context for "have we paid this partner before".
- [ ] Empty array is the **normal** state outside a run window. Render "لا يوجد شركاء مستحقون حالياً",
      not an error.

### ⚠️ Never offer an amount input

`amount` sent in the record body is **read and discarded**. Proven on staging: a request carrying
`amount: 999999.99` and a junk IBAN recorded `87800` to `••••0000`.

A field the user can fill that silently does nothing is worse than no field — it implies control that
does not exist.

---

## 3. `GET /admin/payouts/ineligible` — why the rest are not on the list

```jsonc
{
  "partnerId": "prt_9",
  "partnerName": "شريك تجريبي للوحة",
  "partnerType": "individual",
  "availableBalance": 5659.5,
  "reason": "bank_missing",
  "shortfall": null,                    // number ONLY for below_minimum
  "paidThisMonthReference": null        // set ONLY for already_paid_this_month
}
```

| `reason` | Show | Action the admin can take |
|---|---|---|
| `already_paid_this_month` | ✅ done for the cycle + `paidThisMonthReference` | none — this is success |
| `partner_suspended` | payouts held | lift the suspension on the partner screen |
| `negative_balance` | balance below zero | investigate reversals; usually resolves itself |
| `bank_missing` | no payout account | chase the partner to add one |
| `bank_unverified` | awaiting review | **verify it** → bank-verification doc |
| `below_minimum` | needs `shortfall` more | none — carries forward |

Evaluated in exactly that order, first match wins.

- [ ] `already_paid_this_month` is a **positive** outcome. Do not style it like `bank_missing`.
      Sorting or grouping it to the bottom is better than mixing it with problems.
- [ ] `bank_unverified` is the one row that is **actionable from an adjacent screen** — deep-link it
      to the partner's wallet detail so the reviewer can verify and pull them into the run.
- [ ] `shortfall` is `2000 − availableBalance`, and non-null only for `below_minimum`.

---

## 4. `POST /admin/payouts/record`

```ts
await fetch(`${API}/admin/payouts/record`, {
  method: 'POST',
  credentials: 'include',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    partnerId: 'prt_4',                 // exactly as received
    bankReference: 'FT26081500091',     // the bank's own transfer reference — required
    paidAt: '2026-08-15T09:00:00Z',     // optional, defaults to now
    note: 'دفعة أغسطس',                  // optional, Arabic, shown to the partner
  }),
});
```

```jsonc
// 200
{ "ok": true, "payoutId": "pay_1", "reference": "PO-2026-08-0001" }
```

`reference` is Mamsa's own payout reference — **show it back to the accountant** and make it
copyable; it is what they quote in support tickets, and it is what the partner sees.

- [ ] `bankReference` is required, 4–64 chars. It is the **bank's** reference, not yours.
- [ ] After success: refetch **both** lists (the partner moves from eligible to
      `already_paid_this_month`) and any wallet queries.
- [ ] `paidAt` only matters when recording a transfer executed on an earlier day. Default to omitting
      it.

---

## 5. Errors — all four are expected, none are bugs

Flat admin envelope `{ message, code }`. Messages are Arabic and user-facing; branch on `code`.
Captured live:

```jsonc
409 {"message":"رقم المرجع البنكي مستخدم من قبل","code":"DUPLICATE_BANK_REFERENCE"}
409 {"message":"تم صرف مستحقات هذا الشهر بالفعل","code":"ALREADY_PAID_THIS_MONTH"}
409 {"message":"الشريك غير مؤهل للصرف حالياً","code":"NOT_ELIGIBLE"}
422 {"message":"رقم المرجع البنكي مطلوب","code":"VALIDATION_ERROR"}
```

| `code` | Meaning | How to handle |
|---|---|---|
| `DUPLICATE_BANK_REFERENCE` | that bank reference is already recorded | **"already recorded"** — not a retry. Nothing was written. Refresh, don't invent a new reference |
| `ALREADY_PAID_THIS_MONTH` | paid in this Gregorian month | refresh the lists; the row is stale |
| `NOT_ELIGIBLE` | failed re-check at the moment of recording | show `message`, refresh — see §6 |
| `VALIDATION_ERROR` | bad/missing `bankReference` | show under the field |
| `403 INSUFFICIENT_PERMISSION` | lacks `payouts.execute` | gate the button on `/admin/me` permissions |

**None of the 409s write anything.** They are all safe to hit.

---

## 6. Eligibility is re-checked at the moment of recording

The list the accountant loaded may be minutes old. Between loading it and clicking record, a refund
can land or a bank account can be un-verified — so a row shown as eligible **can still return
`409 NOT_ELIGIBLE`**.

This is the system protecting a real transfer, not a race-condition bug.

- [ ] Treat that 409 as a normal outcome: show the Arabic `message`, refresh both lists, don't retry
      automatically.
- [ ] If a run takes a while, consider refetching `eligible` before submitting each row.

---

## 7. The two things this screen must never do

**7.1 No amount input** (§2). The server computes it from the covered bookings.

**7.2 No reverse button.** A bounced transfer is an operator command:

```
php artisan payouts:reverse PO-2026-08-0001 --reason="رفض البنك"
```

It flips the payout to `reversed`, returns the money as an `adjustment` credit, and re-opens the
covered stays for the next run. It is rare, irreversible, and deliberately not an endpoint.

- [ ] Render `status: "reversed"` wherever payouts are listed (wallet detail, partner side) — the data
      arrives even though nothing here can cause it.
- [ ] Want a UI for it? Say so and we will design the endpoint with a confirmation and an audit
      trail, rather than exposing the command.

---

## 8. Suggested layout

```
┌─ Payout run — أغسطس 2026 ──────────────────────────────┐
│  مستحقون (1)          إجمالي: 100,190 ر.س              │
├────────────────────────────────────────────────────────┤
│  شركة الأفق للعقارات        company                     │
│  100,190.00 ر.س · 23 حجز                                │
│  SA44 2000 0001 2345 6789 1234        [نسخ]            │
│  بنك الرياض · شركة الأفق للعقارات                        │
│  آخر صرف: —                            [تسجيل تحويل]    │
├─ غير مستحقين (6) ──────────────────────────────────────┤
│  محمد الشريك الفردي   ✅ تم الصرف هذا الشهر  PO-2026-08-0001│
│  شريك تجريبي للوحة    ⚠️ لا يوجد حساب بنكي   5,659.50 ر.س │
│  …                    🔎 بانتظار التوثيق  → [توثيق]      │
└────────────────────────────────────────────────────────┘
```

The record dialog needs exactly one input: **the bank reference** (plus optional date and note).

---

## 9. Checklist

- [ ] Two sections: eligible (work) and ineligible (why not)
- [ ] Full IBAN copy-to-clipboard on eligible rows, with `accountHolderName` beside it
- [ ] `amount` read-only; **no amount input anywhere** (§2)
- [ ] Record dialog: `bankReference` required, 4–64 chars
- [ ] Show and copy the returned `reference` after success
- [ ] Refetch both lists + wallet queries after a successful record
- [ ] All four error codes handled; `DUPLICATE_BANK_REFERENCE` reads as "already recorded" (§5)
- [ ] `409 NOT_ELIGIBLE` on an eligible-looking row is handled gracefully (§6)
- [ ] `already_paid_this_month` styled as success, not a problem (§3)
- [ ] `bank_unverified` deep-links to the wallet detail (§3)
- [ ] Empty eligible list renders an empty state, not an error
- [ ] Record button gated on `payouts.execute` from `/admin/me`
- [ ] No reverse button (§7.2)

---

## 10. Testing it

**Staging** is set up for a full run right now:

| Partner | State | Use it for |
|---|---|---|
| `prt_5` | **eligible**, ~100,190 SAR, 23 bookings | the happy path — record a real transfer |
| `prt_4` | `already_paid_this_month`, ref `PO-2026-08-0001` | the positive ineligible state |
| `prt_9` | `bank_missing`, 5,659.50 SAR | the chase-the-partner state |

To reproduce each error safely — **none of them write anything**:

- `DUPLICATE_BANK_REFERENCE` → record with `bankReference: "FT-STAGING-0001"` (already used)
- `ALREADY_PAID_THIS_MONTH` → record for `prt_4`
- `NOT_ELIGIBLE` → record for `prt_9`
- `VALIDATION_ERROR` → record with no `bankReference`

**Production** has no eligible partners: neither partner has a verified bank account yet, so the run
is legitimately empty. Verifying an account is what fills it.

Backend suite: **190 passed, 1079 assertions**, including that a recorded transfer equals the server's
figure rather than the client's, that a paid booking is never paid twice, and that a duplicate bank
reference records nothing.
