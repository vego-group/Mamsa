# Mamsa — Booking status values, refund rule, sequencing, and two corrections

**From:** backend · **Date:** 2026-08-14
**Report only — no code changed.**

**Item 1 answer up front: your default is NOT reachable.** The API can return exactly four values and
your map covers all four. Detail and lines in §1.

**But items 2–4 need two corrections** — the production cleanup **already happened**, and
**`TEST_OTP_MODE` is already OFF**. Both were done at the owner's instruction earlier today, so §4 is
based on a state that no longer exists. Details in §4.

---

## 1. Booking `status` — the complete set the API actually returns

### 1.1 There is no transformation left on either surface

Both surfaces emit the **raw database column**. The translation shim that used to sit in the admin BFF
was deleted with the `pending_payment` rename, and nothing replaced it:

| Surface | Endpoint | Serialiser | Line | What it emits |
|---|---|---|---|---|
| **Partner dashboard** | `GET /bookings`, `GET /bookings/{id}` | `BookingPresenter` | `app/Support/Dashboard/BookingPresenter.php:36` | `'status' => $booking->status` — raw |
| **Guest API** | `GET /api/v1/bookings/{id}`, `GET /api/v1/user/bookings` | `BookingResource` | `app/Http/Resources/BookingResource.php:53` | `'status' => $this->status` — raw |

A repository-wide search for a surviving status map (`bookingStatus(`, `'paid' => …`,
`=> 'confirmed'`) returns **nothing**. No presenter, resource or middleware rewrites the value.

### 1.2 The authoritative value set

Verified against the **production** schema, not the migration files:

```
bookings.status : enum('pending_payment','confirmed','cancelled','completed')
                  default = pending_payment
```

Model constants agree — `app/Models/Booking.php:12-15`:
`pending_payment`, `confirmed`, `completed`, `cancelled`.

**So both surfaces return exactly these four values, and no others:**

| Value | Meaning |
|---|---|
| `pending_payment` | created, not yet paid |
| `confirmed` | paid, stay upcoming |
| `completed` | stay finished (set by the nightly `bookings:complete` job) |
| `cancelled` | cancelled by guest, partner or admin |

**The partner BFF and the guest API return the same set.** No divergence.

### 1.3 Answer to your actual question

Your inbound map:

```
pending_payment ← pending, pending_payment, awaiting_payment
confirmed       ← confirmed, paid, active
completed       ← completed
cancelled       ← cancelled, canceled
```

**Every value the API can produce is covered**, so the `'confirmed'` default is **unreachable with real
data**. An unpaid booking cannot silently render as paid.

- `pending_payment` ✅ · `confirmed` ✅ · `completed` ✅ · `cancelled` ✅
- The extra aliases you accept (`pending`, `awaiting_payment`, `paid`, `active`, `canceled`) are
  harmless — the API emits none of them. `pending` was the pre-rename value and is now gone from the
  enum entirely, so keeping it costs nothing and protects you if you hit a stale cache.

**There are no values outside your list.** Nothing to add.

### 1.4 ⚠️ One field that is easy to confuse — and it *would* hit your default

`payment_status` is a **different field, on a different table**, with a **different vocabulary**:

```
payments.payment_status : enum('pending','paid','failed')
```

It appears in the guest response at `BookingResource.php:79` as `payment.payment_status`, and there is a
`paymentStatus` on the admin surface too.

**If that value were ever fed into your booking-status map by mistake**, `pending` and `paid` would map
to `pending_payment` and `confirmed` by luck — but **`failed` is not in your list and would fall through
to the `'confirmed'` default**, rendering a failed payment as a paid booking.

That is the one realistic route to the bug you were worried about. Worth an assertion in your mapper
that it is never handed a `payment_status`.

### 1.5 Also returned (guest surface only)

`status_label` — an Arabic display string derived from `status` (`BookingResource.php:54`, map at
`:121-127`). Presentation only; do not parse it. `pending_payment` renders as **`بانتظار الدفع`**.

---

## 2. Item 2 — host-cancellation commission rule: recorded

**Rule accepted and recorded:** when a partner cancels a confirmed booking and the guest receives a
100% refund, **Mamsa does not keep the 2% commission**. The full pending amount is reversed —
commission and partner share together.

Your reasoning is sound and matches how the money actually moves: the platform did not deliver a
completed service, and keeping a cut on a partner's own cancellation rewards the cancellation.

**Scope, restated so the implementation cannot drift:**

| Cancellation type | Refund to guest | Commission |
|---|---|---|
| **Host (partner) cancels a confirmed booking** | 100%, mandatory | **Not kept** — fully reversed |
| **Guest cancels** | per the frozen policy tier | unchanged from today's behaviour |

One mechanical note carried over from the earlier design discussion: a host cancellation can only occur
on a `confirmed` booking **before check-in**, so the partner's share is still in **pendingBalance** and
was never credited to `availableBalance`. The effect is therefore a **pending clear of the whole
amount** — not a `refund_reversal` ledger row, which belongs only to post-completion refunds.

This is now recorded alongside the cancellation policy tiers for whoever builds the wallet.

---

## 3. Item 3 — sequencing: agreed, VAT first

**No objection — your order is the right one**, and for a stronger reason than the one you gave.

1. **VAT conversion**
2. **`bank_details` Phase A**

The deciding factor is that VAT has a **guest-visible cost every day it waits**: every visitor sees the
amber "price excludes VAT" caveat, which is both a conversion problem and, per contract §1.2, a
compliance one — a consumer price in Saudi Arabia must be displayed VAT-inclusive. `bank_details` is
behind a feature flag with no guest-visible impact.

There is also a dependency argument in the same direction: the wallet's balances are derived from
`partnerShare`, which the VAT split defines. Doing VAT first means the wallet is built once against
final numbers instead of being reworked.

**One thing to flag about the invoice**, since it is on the VAT critical path: the ZATCA Phase 1 QR is
**TLV-encoded base64 generated server-side** — the backend returns the string and the frontend renders
it as an image, exactly as you described. It carries seller name, VAT number, timestamp, total, and VAT
amount. That means **the company's VAT registration number and CR must be available** before the
invoice can be issued for real. Worth confirming those exist now rather than discovering it at the end.

---

## 4. ⚠️ Item 4 — both instructions are based on a state that has changed

Neither is actionable as written, because both were already done earlier today at the owner's
instruction.

### 4.1 The production cleanup already happened — and went further than option (a)

The owner asked for a cleanup and then narrowed it to *"leave the three test users only"* plus one unit.
That was executed, **with a full backup taken first** (`~/backup-preclean-20260814-143720.sql`, 216K),
inside a single transaction.

| | Before | After |
|---|---|---|
| users | 15 | **3** (then 5 — see §4.2) |
| units | 14 | **1** |
| bookings | 69 | **0** |
| payments | 18 | **0** |
| refunds / reviews / favorites / wallet_transactions / saved_cards / notifications / audit_logs / contacts | populated | **0** |

**Preserved:** roles, permissions, cancellation policies and tiers, features, offers, testimonials.

So option (a) — *"transactions only, keep users, units, partners"* — is **not what was done**. Users and
units were also removed. If you were relying on production still having partners and inventory for any
testing, it does not: **one unit, and the accounts below.**

### 4.2 `TEST_OTP_MODE` is already OFF — production is fully live

*"Leave TEST_OTP_MODE as is — it stays on until launch"* no longer describes production. It was
**disabled today**, closed on two levels (`TEST_OTP_MODE=false` **and** `TEST_OTP_CODE` blanked), after
the blocker that had prevented it was resolved.

**What made it safe to close:**
- A **real-phone SuperAdmin** now exists — `+966537486167` (previously every admin phone was synthetic
  and could not receive an SMS, so disabling test mode would have locked everyone out permanently).
- **Real SMS sending was confirmed working** on production.

**What this means for you:**
- **Every login on production now requires a real SMS OTP.** There is no fixed code on production, for
  any account.
- **The three demo phones `+966555000001/2/3` can no longer log in at all** — they are synthetic numbers
  that cannot receive an SMS. They still exist as records.
- **Staging is unchanged** and still uses its fixed code, so your development loop is unaffected.

If you need working production logins for testing, use the real-phone accounts:

| Phone | Roles |
|---|---|
| `+966537486167` | SuperAdmin |
| `+9665XXXXXXXX` | Individual (partner, approved) + User |

Reverting is possible in ~30 seconds if it turns out to be needed, but the security posture is much
better as it stands — this closed the last open item from the security audit.

---

## 5. Summary

| Item | Answer |
|---|---|
| **1. Status values** | Exactly four: `pending_payment`, `confirmed`, `completed`, `cancelled`. Raw column on both surfaces, no transformation. **Your default is unreachable** |
| 1. Values not in your map | **None** |
| 1. Watch out | `payments.payment_status` is a different vocabulary (`pending\|paid\|failed`) — `failed` *would* hit your default if ever mixed up |
| **2. Host-cancel commission** | Recorded: **not kept**, full pending amount reversed. Guest cancellations unchanged |
| **3. Sequencing** | **Agreed — VAT first.** Confirm the VAT registration number + CR exist, they gate the invoice QR |
| **4. Cleanup** | ⚠️ **Already done**, and beyond option (a) — users and units were also cleared |
| **4. `TEST_OTP_MODE`** | ⚠️ **Already off.** Production is fully live on real SMS; use the real-phone accounts above |
