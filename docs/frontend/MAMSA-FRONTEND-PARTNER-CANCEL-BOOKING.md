# Partner cancels a booking — the guest is refunded in full

**From:** backend · **Date:** 2026-08-19 · **For:** the Next.js **partner dashboard**
**Status:** ✅ endpoint live on staging + production · **verified end to end against a real Moyasar
charge and a real Moyasar refund** — not simulated

The partner can no longer honour a stay (double-booked elsewhere, a burst pipe). The guest did
nothing wrong, so **no cancellation policy applies to them**: they get 100% of what they paid back,
the partner forfeits their share, and Mamsa forfeits its commission. The platform absorbs the loss,
not the guest.

That last sentence is the whole feature. Everything below serves it.

---

## 1. The endpoint

```
POST /bookings/{bookingId}/host-cancel
Cookie session (the partner dashboard's normal auth)
```

```jsonc
// request
{ "reason": "الوحدة محجوزة في منصة أخرى" }     // required, 1–500 chars
```

```jsonc
// 200 — the full updated booking, same shape GET /bookings/{id} returns
{
  "id": "b_63",
  "status": "cancelled",
  "cancellation": {
    "type": "host",                       // ← 'host', not 'guest'
    "reason": "الوحدة محجوزة في منصة أخرى",
    "date": "2026-08-19T10:28:23Z",
    "refundAmount": 900.00,               // ← the FULL total the guest paid
    "refundStatus": "processing"          // 'processing' | 'completed' — see §4
  }
}
```

`{bookingId}` accepts either `b_63` or `63`.

**Re-render from the response.** It is the whole booking, so you do not need to refetch — and the
`cancellation` block only exists once `status === "cancelled"`.

### 1.1 `Idempotency-Key` — please send one

```
Idempotency-Key: <uuid, stable per cancel attempt>
```

The endpoint reads it. A duplicate key returns the already-cancelled booking **without issuing a
second refund**. Without it, a double-click is guarded only by the status check, which is a narrower
race. Generate one when the modal opens, reuse it across retries of that same attempt, and discard it
when the modal closes.

Custom headers are fine on this API (`allowed_headers: ['*']`) — the earlier concern about a preflight
was mistaken, and `Idempotency-Key` is already in live use on this surface.

---

## 2. When to show the control

Show it **only** when both hold:

```ts
booking.status === 'confirmed' && checkInIsInTheFuture(booking)
```

- **`confirmed` only.** A `pending_payment` booking has no money to return; a `completed` or already
  `cancelled` one is finished.
- **Check-in must not have passed.** The API refuses with `409 CHECKIN_PASSED` once the stay's
  check-in time (the unit's `checkIn`, default 15:00 local) is in the past.

Hiding it in those cases is better than letting the partner discover a correct refusal as an error.
If you would rather show it disabled, the tooltip should say *"لا يمكن الإلغاء بعد موعد تسجيل الدخول"*.

---

## 3. The confirmation step — this is where the design matters

**Do not ship a bare "are you sure".** The partner is about to give up money, and they should see how
much before they type anything:

> **إلغاء الحجز #63**
> سيتم استرداد **900 ر.س** كاملة للضيف، ولن تحصل على أي مبلغ من هذا الحجز.
> لا يمكن التراجع عن هذا الإجراء.
>
> **سبب الإلغاء** — [textarea, required]

Three things that must be in the copy, because each is a fact the partner cannot recover from:

| | |
|---|---|
| **The exact amount** | the guest's full total, from `booking.totalAmount` |
| **That the partner receives nothing** | not "a fee applies" — they get **zero** from this booking |
| **That it is irreversible** | there is no un-cancel |

The reason is **required** and reaches the guest, so treat it as guest-facing copy rather than an
internal note. Disable the confirm button until it has real content (≥ 3 characters is a sensible
client-side floor; the API's minimum is 1).

---

## 4. `refundStatus` — two values, and why it is usually `processing` at first

```
"processing"  → the refund is with the payment gateway, not yet settled
"completed"   → the gateway confirmed it
```

**A fresh host cancellation almost always returns `processing`.** Moyasar accepts the refund
immediately but settles asynchronously and calls us back; only then does it become `completed`.

So render `processing` as **reassurance, not a warning**:

> ✅ تم إلغاء الحجز — جارٍ استرداد **900 ر.س** للضيف

not

> ⚠️ الاسترداد قيد المعالجة

The money is already committed at the gateway. `processing` is the normal, healthy first state, and it
typically becomes `completed` within minutes. Poll or refetch on next view; **do not block the UI on
it** and do not offer a retry — retrying would be refused by the gateway, because the refund already
happened.

Guest-side timing to set expectations with: the refund reaches the card in **5–10 business days**,
which is the bank's schedule, not ours.

---

## 5. Errors

The dashboard envelope: `{ "error": { "code", "message", "fields"? } }`.

| status | code | means | what to show |
|---|---|---|---|
| `409` | `BOOKING_NOT_CANCELLABLE` | not `confirmed` any more | refetch — someone changed it |
| `409` | `CHECKIN_PASSED` | the stay has started | hide the control; it will not succeed |
| `400` | `VALIDATION` | reason missing or too long | inline under the textarea, from `fields.reason` |
| `502` | `REFUND_FAILED` | **the gateway refused the refund** | see §5.1 |
| `404` | `NOT_FOUND` | not this partner's booking | refetch the list |

⚠️ **Read `error.message` and render it.** It is written in Arabic for the partner. A generic "try
again" instead of the API's own message cost us a debugging round on the Vue app — the API had already
named the cause precisely and the UI threw it away.

```ts
const msg =
  err?.response?.data?.error?.message ??
  err?.response?.data?.message ??
  'تعذّر إلغاء الحجز، حاول مرة أخرى'
```

### 5.1 `REFUND_FAILED` is the one that matters

The booking is **still confirmed** — nothing was cancelled, and the guest was not charged twice or
refunded. The gateway declined, so we fail closed rather than cancel a booking we cannot refund.

Show the API's message and let them retry later. **Do not** offer a "cancel anyway" escape hatch:
cancelling a guest's stay without returning their money is the one outcome this whole flow exists to
prevent.

---

## 6. What happens behind the endpoint

Useful for support conversations and for knowing what not to duplicate:

1. The refund is issued at the **payment gateway** for the full amount actually still owed
2. The booking becomes `cancelled`, `cancelledBy: partner`, with the reason stored
3. A refund record is written and `payment.refundedAmount` set to the full total
4. **The guest gets a wallet entry** for the refund — so their transactions net to zero
5. **The partner earns nothing**: no ledger entry, and the amount leaves their pending balance
6. **The freed dates are blocked** so the unit is not instantly resold into the same gap
7. The guest is notified

You do not need to trigger, mirror or reconcile any of that.

---

## 7. Verified, not asserted

This flow was run end to end on staging against Moyasar's real test gateway — a real card charge and
a real refund — and checked against Moyasar's own record rather than only our database:

```
Moyasar   : status=refunded, 900.00 of 900.00
payment   : refundedAmount 900.00 / 900.00
refund    : 900.00, 100%, succeeded, إلغاء المضيف
guest     : −900 paid, +900 refunded → net 0.00
partner   : wallet unchanged, no ledger entry
unit      : dates blocked
```

That run found four real bugs, all now fixed and covered by tests — including one where the gateway
refunded the guest but a database write failed, leaving the money moved and the booking still reading
as confirmed. **Backend suite: 258 passed, 1344 assertions.**

---

## 8. Checklist

- [ ] Control shown only for `confirmed` **and** future check-in (§2)
- [ ] Confirmation states the **exact refund amount**, that the partner gets **nothing**, and that it is **irreversible** (§3)
- [ ] Reason required, disabled confirm until filled, treated as guest-facing copy
- [ ] `Idempotency-Key` sent, stable across retries of one attempt (§1.1)
- [ ] Booking re-rendered from the response, not refetched
- [ ] `refundStatus: "processing"` shown as **success**, not a warning (§4)
- [ ] All five error codes branched; `error.message` rendered verbatim (§5)
- [ ] `REFUND_FAILED` offers retry only — never a "cancel anyway" (§5.1)
- [ ] No client-side refund arithmetic anywhere — the amount always comes from the API
