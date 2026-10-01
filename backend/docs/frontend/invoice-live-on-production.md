# Mamsa — Invoice endpoint is live on production

**From:** backend · **Date:** 2026-08-15
**Verified:** production and staging, 2026-08-15.
**Status:** ✅ live on **both** environments · `qrCode` still `null` pending company data.

## TL;DR

`GET /api/v1/bookings/{id}/invoice` is now on **production** as well as staging. Same shape, same
behaviour, no surprises.

Ship the invoice page whenever you are ready — the `qrCode: null` placeholder path is the live path,
and the real code will appear on its own with **no frontend release**.

---

## 1. Endpoint

```
production   GET https://api.mamsaa.com/api/v1/bookings/{id}/invoice
staging      GET https://staging.mamsaa.com/api/v1/bookings/{id}/invoice
```

Guest surface (`/api/v1`), Sanctum bearer, scoped to the booking's owner.

**Response shape is exactly the one you specified** — see
`MAMSA-FRONTEND-TASK-INVOICE-WIRING-CLAUDE.md` §2 for the verbatim payload; nothing changed between
staging and production.

---

## 2. Verified on production

| Check | Result |
|---|---|
| Route registered | ✅ |
| Unpaid / cancelled booking | **409 `INVOICE_NOT_AVAILABLE`** |
| A different guest requesting it | **403** |
| Seller config | `name` present · `vatNumber` empty → **`qrCode: null`** |
| Health | 200 |

### 2.1 One limit worth stating plainly

**The happy path was not exercised on production** — there are no paid bookings there (the only
booking, `#107`, is now `cancelled`). The identical code renders correctly on staging against the paid
booking `#61` (net 782.61 · vat 117.39 · gross 900.00).

A fake paid booking was deliberately **not** created on production to demo it — that would inject
invented financial data into a live system. **The first real paid booking is the true confirmation**,
and the backend will check it then.

**What this means for you:** wire and test against **staging booking #61**. On production the endpoint
will return 409 for every booking that exists today, which is correct behaviour, not a fault.

---

## 3. Nothing changed since the staging hand-off

If you have already wired against staging, there is nothing to redo. Restating only the parts that
commonly get missed:

- **Path is `/api/v1/...`**, not root.
- **`qrCode === null` → render the placeholder.** Never substitute a fake code; never encode client-side.
- **`409 INVOICE_NOT_AVAILABLE` is an expected state**, not an error toast — show the invoice action
  only on `confirmed` / `completed` bookings.
- **Render the returned figures; compute nothing.** This matters on pre-conversion bookings where
  `nightly_rate × nights ≠ gross`.
- **Seller block is authoritative from the server**; omit empty fields rather than printing blank labels.

---

## 4. 🔴 The only outstanding item — and it is not code

`qrCode` returns `null` and `seller.vatNumber` / `crNumber` / `address` are empty on **both**
environments, because these four company records have never been supplied:

| Field | Format |
|---|---|
| VAT registration number | 15 digits, starts and ends with `3` |
| Commercial Registration (CR) | 10 digits |
| Company address | Arabic free text |
| Legal seller name | exactly as registered |

**When they arrive it is a configuration change on the two servers — not a deploy, and not a frontend
release.** The QR and the seller block populate immediately, and your page starts rendering the real
code without a single line changing on either side.

That is exactly why the field was designed as `string | null` rather than shipped with a stub: a QR
encoding a placeholder VAT number scans as valid and fails at the tax authority, on a document carrying
our branding.

---

## 5. Where the whole VAT phase now stands

| Item | Staging | Production |
|---|---|---|
| VAT-inclusive prices (`gross` / `net_base` / `vat`) | ✅ | ✅ |
| Partner split (`netBase` / `commission` / `partnerShare`) | ✅ | ✅ |
| Reports `netRevenue` / `vat` / `vatCollected` | ✅ | ✅ |
| Guest surfaces stripped of internal margin | ✅ | ✅ |
| Admin authorization enforced (50 routes) | ✅ | ✅ |
| **Tax invoice endpoint** | ✅ | ✅ **new** |
| ZATCA QR generation | ⏳ blocked on §4 | ⏳ blocked on §4 |
| Admin BFF booking `PriceBreakdown` | to build | — |
| Partner reports `netProfit` → `partnerShare` | to build | — |

Backend suite: **129 tests, 858 assertions** green.

---

## 6. Checklist

- [ ] Invoice page pointed at `/api/v1/bookings/{id}/invoice`
- [ ] Tested against **staging booking #61** (production has no paid booking yet)
- [ ] `qrCode: null` → placeholder; string → QR image
- [ ] `409 INVOICE_NOT_AVAILABLE` handled as an expected state
- [ ] Seller fields read from the response, empty ones omitted
- [ ] Chase the VAT number / CR / address / legal name — the last blocker
