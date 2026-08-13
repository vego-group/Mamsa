# Mamsa Backend — Report for Frontend Items 1–3

**Report only — no application code was changed.** Item 4 (stub endpoints) is built separately.
Every factual claim cites `path:line` in the backend repo. `UNKNOWN — not verifiable from code`
marks anything that lives in a server `.env`, not the repo.

---

## ITEM 1 — Gap analysis: exists, complete, ready to forward

**Path:** `backend/docs/audit/CONTRACT-GAP-ANALYSIS.md` (277 lines). Copied to
`Downloads/MAMSA-BACKEND-CONTRACT-GAP-ANALYSIS.md` so it can be forwarded — it is a **separate file**
from the integration notes, which is why the frontend only got the latter.

Both blocking sections are present and substantial:

- **§12 Timeline** (from line 253): per-phase effort in developer-days, cumulative column, dependency
  order, explicit parallelism, and the calendar translation. Summary:

  | Phase | Scope | Dev-days | Runs in parallel? |
  |---|---|---:|---|
  | 1 | Roles/permissions + BFF authz + `/admin/me` | 4.5 | critical path |
  | 2 | VAT-inclusive refactor + `PriceBreakdown` + freeze | 8 | critical path |
  | 3 | `bank_details` + mod-97 | 3.5 | **∥ (off critical path)** |
  | 4 | Wallets + ledger + reconciliation | 7 | critical path (needs §9.3 decision) |
  | 5 | eligible / ineligible queries | 2 | critical path |
  | 6 | `POST /payouts/record` + locks + idempotency | 7 | critical path |
  | 7 | payout notifications + categories | 2 | critical path |
  | 8 | reverse + manual off-cycle | 2 | critical path |
  | 9 | tax invoice + ZATCA QR + credit notes | 7 | **∥ (off critical path)** |
  | — | endpoint wiring, filters, CSV | 5 | — |
  | — | integration + reconciliation tests + buffer | ~10 | — |
  | | **Total** | **~58** | |

  Money spine 1→2→4→5→6→7→8 is serial; Phases 3 and 9 parallelise. Calendar: 1 dev ≈ 11–12 weeks
  (~3 mo); 2 devs ≈ 7–8 weeks (~2 mo) on the critical path. Frontend unblocks off **stub endpoints in
  ~1 week** (that is item 4).

- **§9 Where the contract is wrong** (from line 167): **11 items**, each with quoted clause → problem →
  alternative. Top three: (1) `WalletTransaction`/`wallet_transactions` is already the **guest** wallet;
  (2) refunding a `completed` booking is **impossible today** yet the reversal depends on it; (3) §4.1
  overstates the missing auth — Spatie roles/permissions/middleware already exist, the real gap is the
  admin BFF. Full list is in the file.

**Nothing is missing or thin.** The file just was not forwarded.

---

## ITEM 2 — Transactional email

| Question | Answer | Evidence |
|---|---|---|
| Driver / provider per env | Default mailer `log`; the transactional path is the **Resend** transport. Actual per-env value is the `MAIL_MAILER` env var (log locally, `resend` on prod). | `config/mail.php:17` (default), `:64-65` (resend transport); `config/services.php:21-22` (`RESEND_API_KEY`). Prod value is env → `UNKNOWN` from repo, but Resend is the wired provider. |
| Any transactional email today? | **Yes, several.** Mailable `EmailVerificationCode` + notifications on the `mail` channel: `BookingConfirmed`, `NewBooking`, `PartnerApplicationResult`, `UnitReviewResult`, `GuestCancelledBooking`, plus refund-processed / booking-reminder templates. | `app/Mail/EmailVerificationCode.php`; `app/Notifications/*` (`via()` includes `'mail'`); `resources/views/emails/*` |
| Templates | Custom Blade under `resources/views/emails/` — 8 message templates + shared `layout.blade.php` + `partials/booking-summary.blade.php`. | `resources/views/emails/{refund-processed,booking-confirmed-guest,verify-code,booking-cancelled-guest,booking-reminder,booking-confirmed-partner,booking-cancelled-partner,layout}.blade.php` |
| Queued or synchronous? | **Synchronous.** Nothing implements `ShouldQueue`; mail is sent inline in the request. | `app/Mail/EmailVerificationCode.php:14` ("Sent synchronously (no ShouldQueue)"); no `ShouldQueue` anywhere in `app/Notifications` or `app/Mail`. |
| Queue worker in prod? | **Not required** — nothing is queued. On Hostinger shared hosting no persistent `queue:work` runs (only `schedule:run` via cron). | `routes/console.php` (scheduler only, no worker); `QUEUE_CONNECTION` per env → `UNKNOWN` from repo, but moot since no job is queued. |
| Arabic RTL template? | **Yes.** The shared layout is Arabic-first RTL. | `resources/views/emails/layout.blade.php:2` `<html lang="ar" dir="rtl">`, `:10` `direction:rtl;text-align:right`; `:8-9` "Locked rules: Arabic RTL, Gregorian DD/MM/YYYY, Latin digits, SAR". |
| Failure detection / retry | Every dispatch is **best-effort**: wrapped in `try/catch` + `report($e)` (logs to the error channel). **No automatic retry, no resend today.** | `app/Actions/Bookings/CancelBookingAction.php:155-157,206-208`; `PaymentController::confirmBooking` best-effort block. |

**Can this satisfy §3.6?**

- **"A failed email must NOT roll back a recorded bank transfer" — already satisfied by the existing
  pattern.** Notifications are dispatched outside/after the DB write in a `try/catch` + `report()`, so a
  mail failure never bubbles into the transaction. Build the payout the same way: record → commit →
  notify in a post-commit `try/catch`.
- **What §3.6 needs beyond today** (all small, no infra change):
  1. a **payout email template** (new Blade under `resources/views/emails/`, reusing `layout.blade.php`);
  2. a **resend/retry** path — the contract already lists `POST /admin/payouts/{id}/resend-notification` (§5.2);
  3. persist **`notifiedAt`** + a delivery-failure flag on the payout row so the retry has state to act on.
- **Bottom line:** the mail stack (Resend + Arabic-RTL layout + synchronous best-effort) **can satisfy
  §3.6 as-is.** No driver or infrastructure change; the only work is one template + the resend endpoint +
  a `notifiedAt` column.

---

## ITEM 3 — Booking status `pending` → `pending_payment` (decision report; nothing changed)

**Column type:** it is a **DB `ENUM` column** (MySQL). SQLite stores it as TEXT. Renaming therefore needs
a MySQL `ALTER TABLE bookings MODIFY status ENUM(...)` **plus** an `UPDATE` of existing rows.
- create: `database/migrations/2026_06_15_000006_create_bookings_table.php:22`
- alters: `2026_06_24_000004_add_cancellation_fields_to_bookings.php:28-29,42-43`;
  `2026_06_30_000003_add_completed_to_bookings_status.php:15,25`

**Every place the booking-status `pending` appears:**

| Kind | Location | Change needed |
|---|---|---|
| DB enum (create) | `2026_06_15_000006_create_bookings_table.php:22` | new migration ALTERs it |
| DB enum (alter) | `2026_06_24_000004:28-29,42-43`; `2026_06_30_000003:15,25` | historical — leave; new migration supersedes |
| PHP constant (the value) | `app/Models/Booking.php:12` | `const STATUS_PENDING = 'pending'` → `'pending_payment'` |
| Constant usages (no literal) | `app/Actions/Bookings/CancelBookingAction.php:47`; `app/Console/Commands/ExpirePendingBookings.php:26` | none — they use the constant |
| Literal — availability | `app/Services/IcalService.php:27` | `whereIn('status',['pending','confirmed'])` → edit literal |
| Literal — payment init | `app/Http/Controllers/Api/V1/PaymentController.php:60` | `->where('status','pending')` (pending-booking reuse) → edit literal |
| Literal — API label map | `app/Http/Resources/BookingResource.php:121` | `'pending' => 'قيد الانتظار'` → change map key |
| Seeder | `database/seeders/DemoAccountSeeder.php:155` | `'status' => 'pending'` → edit |
| Serialized **raw** (partner) | `app/Support/Dashboard/BookingPresenter.php:36` | `'status' => $booking->status` — emits raw; becomes `pending_payment` automatically after rename |
| Serialized **raw** (guest) | `app/Http/Resources/BookingResource.php:42` | `'status' => $this->status` — same |
| **Already translated (admin BFF)** | in: `AdminPanel/BookingsController.php:30`; out: `AdminPanel/Concerns/MapsSpec.php:66-74` | remove the shim — the backend would speak `pending_payment` natively |

**The finding the frontend could not have known:** the **admin panel surface already returns
`pending_payment`.** `MapsSpec::bookingStatus()` maps internal `pending` → `pending_payment`
(`app/Http/Controllers/AdminPanel/Concerns/MapsSpec.php:72`), and `BookingsController.php:30` accepts the
reverse. So on the admin BFF the frontend's rule is **already met** via a translation layer. The rename's
real effect is to make the **partner dashboard** (`BookingPresenter.php:36`) and the **/api/v1 guest API**
(`BookingResource.php:42`) emit it natively, and to let us **delete that shim** (net tech-debt reduction).

**Second thing they could not have known:** `STATUS_PENDING = 'pending'` also exists as a constant on
**`PartnerDetail`** (`app/Models/PartnerDetail.php:10`) and **`UnitIcalFeed`** (`app/Models/UnitIcalFeed.php:12`)
— different models, different meaning. **Do not touch those.** Only `Booking`'s value changes.

**External dependencies on the string:** **none.**
- The `pending` literals in `PaymentController` lines 69/128/175/222 are `payments.payment_status`
  (a *different* column), not booking status.
- Moyasar never sees `booking.status` — its own statuses (`paid`/`failed`) are unrelated.
- No SMS/email template embeds the booking-status literal.
- All current bookings are demo (pre-launch); nothing real is stored.

**Effort:** ~**2–4 hours** — one migration (ALTER ENUM + UPDATE demo rows), change the constant value,
edit ~5 literals (`IcalService:27`, `PaymentController:60`, `BookingResource:121`, `DemoAccountSeeder:155`,
and remove the `MapsSpec`/`BookingsController` shim), update the few tests asserting `'pending'`.
**No downtime** — small ENUM alter on demo data, pre-launch.

**Recommendation: do it now.** The frontend's timing argument is correct — it is genuinely cheap
pre-launch, it *removes* an existing translation shim rather than adding complexity, and after launch it
becomes a live-data migration. The only technical caveat is the one above: change **only** the `Booking`
constant/enum, never the identically-named constants on `PartnerDetail`/`UnitIcalFeed`.
