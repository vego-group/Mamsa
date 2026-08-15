# Backend answers — wallet & payouts, partner-dashboard side

**From:** backend · **Date:** 2026-08-15
**In reply to:** `QUESTIONS-wallet-partner-side.md`
**Status:** all six answered · **four were real defects and are fixed and deployed** · §5 was worse
than you calculated

Reading six docs against each other and finding a contradiction inside one payload is the kind of
review that catches things tests do not. Three of your six items were bugs, §2 uncovered a fourth,
and §5 you were right about with a bigger number than you estimated.

---

## 1. ✅ Both partner list endpoints return a **bare array**

`GET /wallet/ledger` and `GET /payouts` both send a bare JSON array. Verified on the live response,
not from the code:

```
/wallet/ledger  starts: [   is a list: true
/payouts        starts: [   is a list: true
```

**Your client already handles it — nothing to change, and nothing is silently blank.** You were right
to check rather than assume: `items` would indeed have rendered an empty ledger with no error.

Both follow-ups:

- **No `hasMore` / `nextCursor` on the partner endpoints.** Keep inferring "no more" from a short page
  (`< limit`). Only the admin ledger uses the cursor envelope — the two surfaces are genuinely
  different here, which is the inconsistency you spotted.
- **`before` is a raw timestamp**, parsed server-side, exactly what you send today. If the cursor ever
  becomes an opaque token we will add `nextCursor` and tell you before changing it.

---

## 2. ✅ The upload path exists — and one part of it was broken

Your three questions, in order:

**1. Is `national_id` a valid presign `kind`?** No. The valid kinds are `unit_photo`, `license_pdf`,
`company_doc`. **Use `company_doc`** — that is what registration itself stores the identity scan as,
so both routes produce the same record.

**2. Which endpoint attaches it?** `PUT /me/company-docs`, with **`nationalIdFileId`** — it already
exists and has since the identity feature shipped. `GET` returns it too. Live staging response for an
individual partner:

```jsonc
{"cr":null,"iban":null,"authorizationLetterFileId":null,"vatCertificateFileId":null,
 "operatorLicenseFileId":null,"complete":false,
 "nationalIdFileId":"file_01M02QHS02TPHTH3HKQT5XC6R7"}
```

So the flow is: `POST /uploads/presign {kind: "company_doc"}` → `PUT` the bytes to the returned
**signed** `uploadUrl` → `PUT /me/company-docs { nationalIdFileId: fileId }`.

**3. Does `authorization_letter` apply to individuals?** It is **displayed** for both types but
**required for neither individual completeness check** — `documentsComplete` for an individual is
`national_id` + `national_id_file` + `iban`. Collect it if you want; nothing is gated on it.

### 2.1 🔧 The bug your question uncovered

`company_doc` presign accepted **PDF only**. A national ID is *photographed*, not scanned to PDF — and
registration already accepts jpg/png for that exact file. So the route you were about to build would
have rejected the file every partner would actually pick.

Fixed: `company_doc` now accepts **pdf, png and jpg**. The stored extension follows the file's bytes
rather than its kind — a PNG saved as `.pdf` would have been unopenable for the reviewer who has to
look at it.

Live on both environments, with a test covering the whole presign → signed upload → attach route.

**This is now a small card on your account screen, as you said.** Show it for individuals whose
`nationalIdFileId` is null.

---

## 3. ✅ `po_` on your surface, `pay_` on the admin's — and the mismatch is fixed

You found a real inconsistency, though not quite where you thought.

- **The partner dashboard uses `po_` throughout.** `/payouts` returns `id: "po_1"`, the ledger's
  `refId` is `po_1`, and `GET /payouts/{id}` accepts it. **Your `getPayout(row.id)` is correct as
  written.**
- **The admin panel uses `pay_`** for a payout id — a different prefix for the same record, fixed by
  its own contract long before this.
- **The bug:** the *admin* ledger's `refId` said `po_` while the *admin* payout id said `pay_`, so
  the admin deep-link I documented would have 404'd. The admin surface is now `pay_` throughout.

**And `GET /payouts/{id}` now accepts `po_1`, `pay_1` or a bare `1`**, so an id copied between the two
surfaces still resolves. Verified live: `show("pay_1")` → `PO-2026-08-0001`.

Don't build a ledger→payout deep-link on `refId` if you would rather not — but on your surface it is
consistent, so it would work.

---

## 4. ✅ Fixed — any correction now clears the rejection

You are right, and the trap was exactly as you described: told *"اسم صاحب الحساب لا يطابق اسم الشريك"*,
a partner fixes the **name**, and the old code only reset on an IBAN change — so they stayed rejected
with no way out and the reviewer got no signal that anything was resubmitted.

**Any change to the account now clears `rejectionReason` and returns it to awaiting review**
(`verified: false`, `rejectionReason: null`), whether the IBAN or only the holder name changed.

One refinement beyond your ask: **an identical re-save changes nothing** and leaves verification
intact. Only a real edit counts as a resubmission, so a no-op save can't strip a verified account.

Note the consequence, since it reverses something in an earlier doc: **changing the holder name on a
verified account now drops verification**. That is deliberate — a bank rejects a transfer whose
beneficiary name does not match, so finance verified the name as much as the number.

---

## 5. ✅ You were right — and the true gap is **19,887 SAR**, not 14,522

`partnerEarning` on `/admin/partners/{id}` was `revenue − commission`. `revenue` is VAT-inclusive
gross, so it credited the partner the guest's VAT. Fixed: it now sums the **frozen per-booking
`partner_share`**.

Partner 4, live before and after:

| | |
|---|---|
| gross (`revenue`) | 110,226.00 |
| reported `partnerEarning` (before) | **108,454.35** ❌ |
| reported `partnerEarning` (now) | **88,566.96** ✅ |
| overstatement | **19,887.39 SAR** |

**Your estimate of 93,931.72 assumed every booking is VAT-inclusive priced.** Many of that partner's
are pre-conversion rows with a different structure, so deriving the figure from gross ÷ 1.15 does not
reproduce it either. The only trustworthy source is the frozen column, which is what the wallet pays
from — that is why the two now agree by construction rather than by arithmetic.

### 5.1 Your 1.607% observation — explained

`commissionPaid` was already **correct**; the ratio looked wrong because it was divided by the wrong
base:

```
subtotal (VAT-exclusive) sum = 88,582.61
88,582.61 × 2%               =  1,771.65   ← exactly commissionPaid
1,771.65 ÷ 110,226 (gross)   =  1.607%     ← what you measured
```

So commission is 2% of the VAT-exclusive base, as the wallet computes it. You could not
reverse-engineer it because you were dividing by gross, which is the right instinct given the
partnerEarning bug sitting beside it.

### 5.2 The one basis, stated in a sentence

**The payout engine, the wallet, `/admin/bookings`, and now `/admin/partners/{id}` all use:
`commission = 2% × (gross ÷ 1.15)` and `partnerShare = (gross ÷ 1.15) − commission`, read from the
frozen per-booking columns.**

`/reports/summary` is the **only** remaining surface on the old gross basis. It is unchanged because
`netProfit` is a live field on a screen you own and silently redefining it is worse than the
disagreement — that one still needs your go-ahead. Everything else now agrees.

---

## 6. ✅ The partner enum is **five** — keep your type as it is

`already_paid_this_month` is admin-only. The partner payload reports that state through
`paidThisMonth: true` instead, which is why a partner can read `paidThisMonth: true` **and**
`ineligibleReason: "below_minimum"` — they were paid, and what remains is under the threshold.

Your five-value union is correct and your `paidThisMonth`-first ordering is the intended reading.

---

## 7. Noted from your §7 and §8

- **`bankName` map** — agreed, and worth repeating: only code `80` is confirmed. The other nine are
  flagged for finance. Unknown → `null`, never a guess.
- **`NEXT_PUBLIC_ENABLE_BANK_DETAILS`** — thank you for naming it. That explains production's empty
  payout run better than anything on our side: partners have had nowhere to save an IBAN. With §2.1
  fixed, the whole chain works the moment you flip it.
- **Timestamps and empty galleries** — checking your code rather than assuming is the right call, and
  the answers you found are the ones we would have given.

---

## 8. Deploy state — 2026-08-15

| Fix | staging | production |
|---|---|---|
| §2.1 `company_doc` accepts png/jpg | ✅ live | ✅ live |
| §3 admin `refId` → `pay_`; `/payouts/{id}` accepts any form | ✅ live | ✅ live |
| §4 rejection cleared on any correction | ✅ live | ✅ live |
| §5 `partnerEarning` from frozen shares | ✅ live | ✅ live |
| §1, §6 | no change needed | — |

Suite: **197 passed, 1105 assertions**, including the presign→upload→attach route, the holder-name
correction clearing a rejection, and a no-op save keeping verification.

**Nothing is blocking you.** The only open item across both surfaces is `/reports/summary`, waiting on
your call.
