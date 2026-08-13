# Mamsa — `pending` → `pending_payment` rename (frontend hand-off)

**From:** backend · **Date:** 2026-08-13
**Status:** code-complete and verified in the repo · **NOT deployed yet** — merge day is yours to pick (§6)
**Related:** `MAMSA-BACKEND-CONTRACT-WALLET-PAYOUTS-VAT.md` v2.2 §8.7 · gap analysis §9.5
**For:** the Next.js frontend team (admin panel, partner dashboard) + whoever maintains the legacy Vue admin

---

## 1. What changed, in one line

The booking status literal `pending` is now **`pending_payment`** in the database and on every API
surface that serves the new frontends. The admin BFF's translation shim (which used to fake
`pending_payment`) is **deleted** — the value is now native, end to end.

**The full booking status set is now:**

```ts
type BookingStatus = 'pending_payment' | 'confirmed' | 'completed' | 'cancelled';
```

This is exactly the literal set the three frontend repos already assert. No other status changed.

---

## 2. Why it needed doing (and why now)

`pending` was ambiguous: this platform has **unit approval** and **partner approval** as separate
concepts, so a bare `pending` read as "awaiting admin approval" when it actually meant "unpaid booking
awaiting payment." `pending_payment` says what it is.

Timing was the real argument: there are **no external customers** yet, so this is a value rename.
After launch it becomes a live-data migration on financial records.

> **Corrected 2026-08-13 — see `MAMSA-PRODUCTION-DATA-AUDIT-AND-TEST-RESULTS.md` for the queried
> numbers.** Production holds 69 bookings, **0 of them `pending`**, across 6 internal accounts only.
> Real money *has* moved (9 Moyasar charges, 15,909.05 SAR) but all of it internal — owner, a test
> account, and the development company. So: no external guest has relied on a price, and this
> migration **converts zero rows on production**.

---

## 3. What each surface returns after the change

| Surface | Field | Before | After |
|---|---|---|---|
| **Admin panel** (`/admin/*`) | `booking.status` | `pending_payment` *(faked by a shim)* | `pending_payment` **(native)** |
| **Admin panel** | `GET /admin/bookings?status=` filter | accepted `pending_payment`, translated | accepts `pending_payment` directly |
| **Admin panel** | `GET /admin/bookings/counts` | `{ all, pending_payment, confirmed, completed, cancelled }` | **unchanged** |
| **Partner dashboard** (root) | `booking.status` | `pending` | **`pending_payment`** ← **breaking** |
| **Guest API** (`/api/v1`) | `booking.status` | `pending` | **`pending_payment`** ← **breaking** |
| **Guest API** | `booking.status_label` (Arabic) | `قيد الانتظار` | **`بانتظار الدفع`** |

### The one deliberate exception — legacy `/api/v1` count endpoints

Three admin endpoints return **counts keyed by status**. Their **response key stays `pending`** so the
existing Vue admin does not break, even though the underlying DB value is now `pending_payment`:

- `GET /api/v1/admin/dashboard` → `bookings.pending`
- `GET /api/v1/admin/bookings/stats` → `pending`
- `GET /api/v1/admin/reports/summary` → `booking_status.pending`

If the new admin panel ever consumes these, read the key `pending` there — it is a **count label**, not
a status value. Everywhere a status *value* is returned, it is `pending_payment`.

---

## 4. Frontend action list

**Admin panel (Next.js) — likely nothing to do.**
You already assert `pending_payment`; it is now genuinely what the backend stores. Worth one
regression pass on the bookings list filter and the counts tiles.

**Partner dashboard (Next.js) — this is the breaking one.**
- [ ] Any comparison against `'pending'` for a booking → `'pending_payment'`.
- [ ] Status badge/label maps keyed by `'pending'` → rekey.
- [ ] Any status filter sent to `GET /bookings`.

**Guest site (`www` / Vue) —**
- [ ] Same: booking status comparisons and label maps.
- [ ] If you render `status_label` from the API, the Arabic string changed to **`بانتظار الدفع`** — no
      code change needed, but screenshots/copy tests may need updating.

**All repos —**
- [ ] Update the `BookingStatus` TS union (§1) and any fixtures/mocks/MSW handlers using `'pending'`.
- [ ] Search for the raw string, not just the type: `grep -rn "'pending'" src/` and check each hit is a
      booking status and not a unit approval / partner status (see §5).

---

## 5. Do NOT rename these — different concepts, same word

This was the single most dangerous part of the change; a find-and-replace would have broken partner
approval and calendar feeds. These all legitimately stay `pending`:

| Concept | Where |
|---|---|
| **Unit approval status** | `unit.approval_status` = `draft \| pending \| approved \| rejected` |
| **Partner application status** | `partner.status` = `pending \| approved \| rejected` |
| **KYC / document verification** | `missing \| pending \| verified` |
| **Refund status** | `pending \| succeeded \| failed` |
| **Guest wallet transaction status** | `completed \| pending \| failed` |
| **Upload status** | `pending \| stored` |
| **User account state** | `pending_activation` (unrelated) |

Only **booking** status changed.

---

## 6. Deployment — pick the merge day

The change is **not deployed anywhere yet**. It is code-complete, lint-clean, and the migration is
verified against real MySQL (both directions). Sequence when you give the word:

1. **Staging first** — deploy + migrate, you smoke-test all three surfaces.
2. **Production** — same, on the day you choose, so the three frontend repos flip together.

✅ **Corrected 2026-08-13:** an earlier version of this section warned that production has real
bookings in `pending`. **It does not — the count is 0** (verified by query; see
`MAMSA-PRODUCTION-DATA-AUDIT-AND-TEST-RESULTS.md`). The migration therefore **converts zero rows** on
production; it only changes the column definition. It is still a production DDL change and will not be
run without an explicit go from the owner.

**Tell us the day**, and the deploy happens that morning so the frontends can ship the same afternoon.

---

## 7. What the backend verified

- Migration on real MySQL: enum → `('pending_payment','confirmed','cancelled','completed')`, default
  `pending_payment`, existing rows converted, other statuses untouched — and the **rollback** proven.
- **9 availability/calendar guards** migrated (`whereIn('status', ['pending','confirmed'])` → the new
  literal). These prevent double-booking, so a missed one would have been a silent, real bug. Two of
  them lived in a namespace the first estimate had missed and were caught by a full sweep — which is
  why this shipped as its own change rather than bundled with the stub work.
- The shim (`MapsSpec::bookingStatus()`) and all three of its call sites **deleted**, not left dormant,
  as requested. Admin responses now emit the raw column.
- Native emission confirmed on all three surfaces: partner `BookingPresenter`, guest `BookingResource`,
  admin `BookingsController`.
- Look-alike constants on `PartnerDetail` and `UnitIcalFeed` confirmed **untouched**.
- Automated test suite: **`OK (102 tests, 752 assertions)`** — full suite, green. Getting there caught
  a real bug: on SQLite, `enum()` emits a `CHECK` constraint that the MySQL-only ALTER never updated,
  so booking creation returned **500**. Fixed (SQLite rebuilds the column as a plain string; MySQL
  keeps the real enum). A filtered run had been green — only the full suite exposed it. Details in
  `MAMSA-PRODUCTION-DATA-AUDIT-AND-TEST-RESULTS.md` §5.

---

## 8. Summary

**Changes for you:** partner dashboard + guest site must accept `pending_payment` instead of `pending`.
Admin panel already matched. `/api/v1` admin *count* keys deliberately still say `pending`.
**Needed from you:** the merge day (§6). **Not changed:** every other `pending` in the system (§5).
