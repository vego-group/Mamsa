# Mamsa — OTP Scrub Done, Production State Confirmed, and the Refund Design

**From:** backend · **Date:** 2026-08-14
**In reply to:** the five consolidated items.

**Item 3 is done** — and it went further than the four files you listed; three more carried the same
string (§1). **Item 4 is confirmed, all of it** (§2). **Item 5 changes one of my earlier answers**: you
are right that the partner-cancels case is live today, but it needs a different wallet mechanism than
the reversal — and getting that distinction wrong before building would produce a real accounting bug
(§3).

---

## 1. Item 3 — the old value is scrubbed from every committed file

Your framing was the right one: this is not about the rotated value, it is about the pattern that leaks
the *next* one. Done, with the reasoning recorded in the files themselves.

**The four you listed:**

| File | Change |
|---|---|
| `backend/.env.example:97` | `OTP_FIXED_CODE=` now **blank**, with a comment explaining that a value committed here is a published credential and must be set privately in the target `.env` |
| `backend/config/otp.php:7` | comment no longer names a value; points at `OTP_FIXED_CODE` |
| `backend/database/seeders/DashboardTestPartnerSeeder.php:27,104` | docblock and console output now say "OTP = `OTP_FIXED_CODE` in this environment" |
| `backend/postman/Mamsa-API.postman_collection.json` | 6 descriptive mentions rewritten; the request body now uses `{{otp_code}}` (which the collection already auto-captures from `debug_otp`) |

**Three more that were not on the list** — found by sweeping the whole repository rather than the
reported paths:

| File | Why it mattered |
|---|---|
| `backend/postman/Mamsa-Staging.postman_environment.json:8` | shipped the value as the default `otp_code` — an importable file pointed at a real environment. **Blanked.** |
| `backend/postman/Mamsa-Local.postman_environment.json:8` | same. **Blanked.** |
| `backend/tests/Feature/EmailVerificationFlowTest.php` (5 occurrences) | self-contained fixture, so never a live credential — but it was *the same string as production*, which is precisely the coupling you identified in §B. **Changed to an unrelated test value.** |

**Verified:** a full-repository sweep now returns **zero** references outside the incident report in
`docs/audit/` (which retains it deliberately, as dead-value context). Both Postman environment files
still parse as valid JSON. **Full suite green: `OK (102 tests, 752 assertions)`.**

Local development is unaffected — non-production responses already return `debug_otp`
(`OtpAuthController.php:41,60`), so no committed value was ever needed.

**Not touched:** git history, per your §A. The value is dead; a rewrite would break every clone.

---

## 2. Item 4 — production state confirmed

All measured just now against `api.mamsaa.com`:

| Check | Result |
|---|---|
| Stub routes registered on production | **0** |
| `/admin/wallets`, `/admin/wallets/{id}` | **404** |
| `/admin/payouts/eligible`, `/ineligible` | **404** |
| `POST /admin/payouts/record` | **404** |
| `/wallet`, `/wallet/ledger` | **404** |
| `/me/bank-details` | **404** |
| `partner_wallets` rows | **0** |
| `partner_ledger_entries` rows | **0** |
| `available_balance` column | `decimal(12,2)` — **signed** (no `UNSIGNED`, no `CHECK`) as agreed |

### 2.1 Phase A (`bank_details`) status: **not started**

`bank_details` does not exist on production (`Schema::hasTable('bank_details')` → false). The estimate
stands at **~4 developer-days** (Phase A ~2.5 unblocks production, Phase B ~1 for `bankName` derivation
pending a SAMA table, +0.5 for admin verify/reject).

It has not been scheduled because the work queued ahead of it changed twice — first the
`pending_payment` rename, then this security work. **It is the next substantial piece unless you want
something else first.** Say the word and it starts.

**Reminder of the interim while it is unbuilt:** `PUT /me/company-docs` remains the only endpoint that
stores an IBAN, for individuals and companies alike. Keep sending `iban` there.

---

## 3. Item 5 — the refund decision, and a correction

### 3.1 You are right that the second case is live today

`app/Actions/Bookings/HostCancelBookingAction.php` exists, is routed
(`routes/dashboard.php:71` → `POST /bookings/{id}/host-cancel`), and refunds **100% of the full total**:

- `:22` — *"Locked business rules #4: refund is 100% of the FULL total"*
- `:53` — `$refundTotal = (float) $booking->total_amount;`
- `:120` — `'refund_percent' => 100`

So a partner cancelling a confirmed booking already triggers a mandatory full refund to the guest, with
no new feature required. That is exactly as you described.

### 3.2 But it needs a different wallet mechanism than `refund_reversal` — and this matters

The two cases hit the wallet at **different points in the booking lifecycle**, so they need different
handling. Getting this wrong would double-count.

| Case | Booking status when it fires | Where the partner's share sits | Correct wallet effect |
|---|---|---|---|
| **Partner cancels a confirmed booking** | `confirmed` — enforced at `HostCancelBookingAction.php:41`, and blocked once check-in has passed at `:49` | **`pendingBalance`** — the earning is only credited at `completed` (contract §2.1) | **Clear the pending amount.** No ledger row, because nothing was ever credited |
| **Guest complains after checkout** | `completed` | **`availableBalance`** — already credited | **`refund_reversal` ledger row**, debiting the available balance |

The key fact: **host-cancel can only fire before check-in**, and a booking is only completed *after* the
stay ends (`bookings:complete`, daily). So the partner's earning has **never** been credited to
`availableBalance` at the moment a host-cancel happens. Writing a `refund_reversal` there would debit a
balance that was never credited — the partner would end up owing money they never received.

**So my earlier statement needs correcting.** I previously said `refund_reversal` would not fire until a
new admin action was built. The accurate version:

- **`refund_reversal` fires only for post-completion refunds** — which still require the new
  discretionary-refund action, since `CancelBookingAction.php:42` currently refuses `completed`
  bookings outright.
- **Host-cancel is not dead weight** — it is live and must be handled from day one of the wallet, but as
  a **pending-balance clear**, not a reversal.

### 3.3 What will be built

Taking your instruction — *build the action, not just the type*:

1. **Pending-balance handling for cancellations (both kinds)** — when a `confirmed` booking is
   cancelled, by partner or guest, the pending amount clears. Ships with the wallet itself, because it
   is the common path and is live today.
2. **The discretionary post-checkout refund action** — the genuinely new piece: an admin-only path that
   refunds a `completed` booking, issues the credit note, and writes the `refund_reversal` row.
3. **`available_balance` stays signed** — no `UNSIGNED`, no `CHECK >= 0`, confirmed live on production
   as `decimal(12,2)` signed. A reversal or adjustment can drive it negative and it carries forward.

**One open question for you**, which the two cases above expose: when a partner cancels and the guest is
refunded 100%, **does Mamsa keep its 2% commission?** The guest is made whole either way, so this is
purely whether the platform absorbs the cost of a partner's cancellation or passes it on. It does not
block building — but it determines whether the pending clear is the full partner share or the share plus
a commission adjustment, so it is cheaper to answer before the ledger is written than after.

---

## 4. Items 1 and 2 — acknowledged

- **`TEST_OTP_MODE` stays on.** Understood as deliberate and temporary. It will be raised again before
  launch, and it remains a one-line change (`TEST_OTP_MODE=false` + `config:cache`) whenever you want
  it closed.
- **§D correction received.** Nothing further needed; shipping the three repos in one release once
  `mamsa-app` is done is exactly right, and production will simply already be ahead until then.

---

## 5. Summary

| Item | Status |
|---|---|
| 3 — scrub the old value | ✅ **Done** — 4 listed files **+ 3 more found**; repo sweep clean; suite green |
| 4 — production state | ✅ **Confirmed** — 0 stub routes, all endpoints 404, both tables empty, `available_balance` signed |
| 4 — Phase A | **Not started.** ~4 dev-days, ready to begin on your word |
| 5 — refund | Host-cancel is **live** and is a **pending clear**, not a reversal (§3.2). `refund_reversal` is for post-completion refunds only, which still need the new action |
| 5 — open question | **Does Mamsa keep its 2% when a partner cancels?** Cheaper to answer before the ledger is written |
| 1 — `TEST_OTP_MODE` | Left on, as instructed |
| 2 — §D | Acknowledged |
