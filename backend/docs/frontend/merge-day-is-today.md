# 🚀 Merge day is TODAY — `pending_payment` is live on production

**From:** backend · **Date:** 2026-08-13
**Status:** ✅ **deployed and verified on production and staging**
**Action for you:** ship the three frontend repos today. Details below.

You asked to be told the day so the three repos flip together. **This is the day.** The backend went out
this morning; production and staging now both return `pending_payment`.

---

## 1. What is live, on both environments

```ts
type BookingStatus = 'pending_payment' | 'confirmed' | 'completed' | 'cancelled';
```

| | Staging | Production |
|---|---|---|
| `booking.status` | `pending_payment` ✅ | **`pending_payment`** ✅ |
| DB enum | renamed | renamed |
| Admin BFF translation shim | deleted | deleted |

The value is native end to end — nothing is being translated any more.

---

## 2. What you should do today

### 2.1 Delete the transitional normaliser

`MAMSA-FRONTEND-TASK-PENDING-PAYMENT-CLAUDE.md` §2 told you to accept **both** `pending` and
`pending_payment` while the two environments disagreed. **They no longer disagree.**

```ts
// DELETE this — both environments now speak the new literal
const LEGACY_STATUS = { pending: 'pending_payment' };
```

Remove the map, simplify or drop `normaliseBookingStatus`, and delete the `TODO` that referenced this day.

### 2.2 Ship the repos

- [ ] **Partner dashboard** — comparisons, badge/label maps, status filters → `pending_payment`.
- [ ] **Guest site** — same; and note the Arabic label the API returns changed from
      `قيد الانتظار` to **`بانتظار الدفع`** (no code change, but snapshot/copy tests may need updating).
- [ ] **Admin panel** — expected to be a no-op; it already asserted `pending_payment`. Regression-check the
      bookings list, status filter, and count tiles.
- [ ] All repos — fixtures, mocks, MSW handlers, factories.

### 2.3 Two things that did **not** change

- **`/api/v1` admin count keys still say `pending`.** Deliberate, so the legacy Vue admin keeps working.
  These are count labels, not status values — do not normalise them.
  (`GET /api/v1/admin/dashboard`, `.../admin/bookings/stats`, `.../admin/reports/summary`)
- **Every other `pending` in the system.** Unit approval, partner application, KYC, refunds, guest wallet,
  uploads, `pending_activation` — all untouched, all still `pending`.

---

## 3. Risk assessment — why this was safe to ship

- **Zero rows were converted.** Production had **0** bookings in `pending` (69 total: 56 completed,
  13 cancelled), so no existing record changed state. The migration altered the column definition only.
- **Zero external customers.** Every account that has ever booked is internal.
- **Existing data is unaffected.** Only **new** unpaid bookings will carry the new literal, so anything
  still comparing against `'pending'` will mismatch going forward rather than breaking history.

So there is no urgency-driven outage risk — but the sooner you ship, the sooner the transitional code goes.

---

## 4. Verified on production before this note was written

| Check | Result |
|---|---|
| Migration applied | ✅ enum `('pending_payment','confirmed','cancelled','completed')`, default `pending_payment` |
| Bookings intact | ✅ 69 |
| `GET /admin/bookings/counts` | `{all:69, pending_payment:0, confirmed:0, completed:56, cancelled:13}` |
| `?status=pending_payment` filter | ✅ 200 (shim removed, native filter) |
| Dashboard status slices | ✅ keyed `pending_payment` |
| Guest API `/api/v1/units` | ✅ 200 |
| **New booking creates as `pending_payment`** | ✅ |
| **Payment-initiate still finds the unpaid booking** | ✅ — the revenue path is intact |
| Availability guard blocks it | ✅ — double-booking protection working |
| `expire-pending` job targets it | ✅ |
| `/up` | ✅ 200 |

Full test suite before deploy: **`OK (102 tests, 752 assertions)`**.

---

## 5. If something looks wrong

Rollback is prepared on our side and takes minutes:

- **Code:** production was a clean checkout before the deploy, so `git checkout -- .` restores it exactly.
- **Database:** the reverse migration is tested in both directions, plus a `bookings` table dump was taken
  immediately before the change.

Report anything odd and we will roll back rather than debug forward — nothing here is worth a broken
booking flow.

---

## 6. Now unblocked — the `/api/v1` count keys

With the rename live, the only remaining piece of the old world is the three `/api/v1` count endpoints
that still return the key `pending` for Vue's benefit (§2.3).

That cleanup depends on the question still open on your side: **how long does the legacy Vue admin stay
alive?**

- **Under ~3 months** → leave them. Removing them later is a five-minute change.
- **~a year or more** → worth fixing the Vue side and unifying every key on `pending_payment`.

Tell us which and we will schedule it as a small follow-up PR.
