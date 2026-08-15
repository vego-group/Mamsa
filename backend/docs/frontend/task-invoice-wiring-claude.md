# Task: wire the tax invoice endpoint (Claude Code — Next.js guest site)

**For:** a Claude Code agent working in the **guest site** repo (`mamsa-app`).
**Backend status:** ✅ **live on staging** · ⏳ not on production yet.
**Verified:** staging, 2026-08-15 — the payload below is a real response, not a spec.

## TL;DR

The invoice endpoint is live and returns **exactly the agreed shape**. Two things to get right:

1. **The path is `/api/v1/...`**, not root (§1).
2. **`qrCode: null` is the normal state today** — render the "preparing" placeholder, and the real code
   will appear on its own with **no code change** when the VAT number is configured (§3).

---

## 1. The endpoint

```
GET https://staging.mamsaa.com/api/v1/bookings/{id}/invoice
```

**Guest surface (`/api/v1`), Sanctum bearer auth** — not the root partner path. The invoice carries a
`buyerName` and is issued *to the guest*, so it lives where the buyer authenticates.

- [ ] If your code currently calls `/bookings/{id}/invoice` (no prefix), change it to `/api/v1/...`.

Scoped to the booking's owner: another guest requesting it gets **403**.

---

## 2. Real response (staging, booking #61)

```jsonc
{
  "invoiceNumber": "INV-2026-08-000061",
  "issuedAt": "2026-08-15T11:11:14+03:00",
  "seller": {
    "name": "منصة ممسى",
    "vatNumber": "",        // empty until the company data is supplied
    "crNumber": "",
    "address": ""
  },
  "buyerName": "نورة المستخدمة",
  "lines": [
    {
      "description": "إقامة — شقة مودرن بإطلالة على الواجهة، حي الملقا، الرياض",
      "checkIn": "2026-09-10",
      "checkOut": "2026-09-12",
      "nights": 2,
      "netBase": 782.61,
      "vatRate": 0.15,
      "vat": 117.39,
      "gross": 900
    }
  ],
  "totalNetBase": 782.61,
  "totalVat": 117.39,
  "totalGross": 900,
  "currency": "SAR",
  "qrCode": null
}
```

`totalNetBase + totalVat === totalGross` holds on every invoice, including pre-conversion bookings.

---

## 3. `qrCode` — the placeholder path is the current path

`qrCode` is **`null`** today and will stay null until a real ZATCA VAT registration number is
configured on the server.

- [ ] Render your "رمز الاستجابة السريعة قيد الإعداد" placeholder when `qrCode === null`.
- [ ] Render the base64 string as a QR image when it is a string.
- [ ] **Do not** substitute a fake or placeholder *code*, and do not encode anything client-side.

**Why it is null rather than a stub:** a QR encoding a placeholder VAT number looks valid to a scanner
and fails at the tax authority — with your branding on the document. Absent is safer than wrong.

**When the data lands it becomes a string automatically — no frontend release.** The seller block fills
in at the same moment, from the same config.

---

## 4. `seller` comes from the server — drop the fallback when you are ready

Your instinct was right and it is implemented that way: the seller block is server-owned
(`config/invoice.php`), so a change to the company's registration details updates **every future
invoice with no frontend deploy**.

Right now `vatNumber`, `crNumber` and `address` are **empty strings** because the company data has not
been supplied yet. `name` already returns `منصة ممسى`.

- [ ] Keep your local fallback if you prefer, but treat the server values as authoritative when present.
- [ ] Handle empty strings gracefully — omit the row rather than printing an empty label.

---

## 5. ⚠️ Only paid bookings have an invoice

An unpaid or cancelled booking returns:

```jsonc
// 409
{ "message": "الفاتورة الضريبية متاحة بعد إتمام الدفع فقط", "code": "INVOICE_NOT_AVAILABLE" }
```

A tax invoice documents a supply that was actually paid for, so this is intentional.

- [ ] Only show the invoice action on `confirmed` / `completed` bookings.
- [ ] Handle `409 INVOICE_NOT_AVAILABLE` as an expected state, not an error toast.

**Note for testing:** booking `#107` is `pending_payment`, so it will 409. Use the paid booking below.

---

## 6. Test data on staging

A **paid** booking was created for you to wire against:

| | |
|---|---|
| Booking | **#61** |
| Guest | `+966500000004` |
| Unit | شقة مودرن بإطلالة على الواجهة |
| Stay | 2026-09-10 → 2026-09-12 (2 nights) |
| Totals | net 782.61 · vat 117.39 · **gross 900.00** |

```
GET https://staging.mamsaa.com/api/v1/bookings/61/invoice
```

Log in as that guest through the normal `/api/v1/auth` OTP flow; ask the backend lead for the current
fixed code (it is deliberately not written in any document).

---

## 7. Implementation notes you may want to mirror

- **Figures come from the booking's frozen split**, never recomputed. A reprint years later is
  identical to the original — and pre-conversion bookings (before 14 Aug) render correctly too, so the
  invoice layout needs **no legacy branch**.
- **Invoice numbers are deterministic** (`INV-{YYYY}-{MM}-{booking id}`), so a reprint always yields the
  same number. Safe to cache or use as a filename.
- **Do not compute a per-night figure** by dividing `gross / nights` on legacy bookings — it disagrees
  with the stored nightly rate. Render what the API returns.

---

## 8. Checklist

- [ ] Path corrected to `/api/v1/bookings/{id}/invoice`
- [ ] `qrCode === null` → placeholder; string → QR image; never a fake code
- [ ] Seller block read from the response; empty fields omitted rather than printed blank
- [ ] Invoice action shown only on paid bookings; `409 INVOICE_NOT_AVAILABLE` handled as expected
- [ ] Wired against staging booking **#61**
- [ ] No client-side recomputation of any figure

---

## 9. What is still outstanding on the backend

| Item | Status |
|---|---|
| Endpoint + shape | ✅ live on staging |
| `qrCode` generation | ⏳ code is written and tested; **blocked on the company VAT number** |
| Seller details in the response | ⏳ returns empty strings until supplied |
| Production deploy | ⏳ on request |

**Needed to finish it:** VAT registration number (15 digits), CR (10 digits), company address, and the
legal seller name as registered. Once supplied it is a config change — **not a deploy** — and both the
QR and the seller block populate immediately.
