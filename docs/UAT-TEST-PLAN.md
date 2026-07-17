# Mamsa — UAT Test Plan (browser acceptance run)

Printable/repo version of the interactive plan (`Mamsa-UAT-Test-Plan.html`) — generated from it, keep in sync.

## 0 · Environment & test data — read first

**All payment tests on STAGING only** — testvue.mamsaa.com runs production with live Moyasar (real charges).

| What | Value |
|---|---|
| User website (staging data) | Next.js dev/preview → `https://staging.mamsaa.com/api/v1` |
| Partner dashboard | `partner.mamsaa.com` (or local) → `https://staging.mamsaa.com` (root, no /api/v1) |
| Admin panel | `testvue.mamsaa.com/admin` = ⚠ production · staging admin: local Vue → staging API |
| OTP on staging | fixed `111222` (echoed as `debug_otp`) · caps 100/phone/day, 300/IP/day, 5/min burst |
| Approved partner | `0512345678` — units in all 4 states, bookings, iCal feed |
| Approved individual | `0577777777` — clean, no units |
| Pending partners | `0533333333`, `0599999999` — keep pending (they test the review screen) |
| Admin (staging) | `admin@mamsaa.sa` / `Password1` |
| Moyasar test card | `4111 1111 1111 1111` · future expiry · CVC `123` · 3-DS: pick success/failure |
| Fresh phones | use `05971xxxxx` for registrations |

---

## 1 · Visitor — browse & discover  `staging`

> No login. The public storefront on the user website.

### [ ] T-1.1 — Home page & popular units
1. Open the site home
2. Scroll the popular-units rail

**Expected:** Units render with photo, name, city, price/night and rating; no broken images (default image shows where a unit has no photos).

### [ ] T-1.2 — Search & filters
1. Open listing page
2. Filter by city, type, price range; combine with dates

**Expected:** Result set changes per filter; empty result shows a friendly empty state, not an error.

### [ ] T-1.3 — Unit detail — tiered cancellation policy
1. Open any unit page
2. Find the cancellation-policy section

**Expected:** Shows the policy NAME + tiers (e.g. مرنة: 100% / 50% / 0% by days-before-checkin) — not a generic ‘48 hours’ text.

### [ ] T-1.4 — Reviews on unit page
1. Open a unit that has reviews

**Expected:** Rating average and individual reviews render; count matches.

### [ ] T-1.5 — Availability check
1. Pick dates that overlap an existing booking on u_12
2. Pick free dates

**Expected:** Overlapping dates are refused with a clear message; free dates show the price breakdown.

### [ ] T-1.6 — Offers & testimonials
1. Open the offers section/page
2. Check testimonials block

**Expected:** Active offers and testimonials load from the API (not hardcoded).

### [ ] T-1.7 — Contact form
1. Submit the contact form with valid data
2. Submit 6× rapidly

**Expected:** First submits succeed with confirmation; rapid submits hit the 5/min throttle with a polite message.

---

## 2 · User account — register & login  `staging`

> Passwordless OTP. Staging code is always 111222.

### [ ] T-2.1 — Register a new user
1. Register with a fresh 05971… number
2. Enter OTP 111222
3. Complete profile (name)

**Expected:** Account created; you land signed-in; profile shows the name.

### [ ] T-2.2 — Intent guards
1. Try LOGIN with a never-registered phone
2. Try REGISTER with an already-registered phone

**Expected:** Login → ‘not registered’ with a switch-to-register suggestion; Register → هذا الرقم مسجّل بالفعل bouncing to login. No SMS wasted on either.

### [ ] T-2.3 — Wrong OTP & lockout
1. Request OTP, enter a wrong code 3×

**Expected:** Each attempt shows remaining tries; 3rd locks the code and asks to request a new one.

### [ ] T-2.4 — Resend cooldown
1. Request OTP, immediately press resend

**Expected:** Resend blocked for 60s with a countdown.

### [ ] T-2.5 — Session persistence & logout
1. Reload the page while signed in
2. Log out

**Expected:** Reload keeps you signed in (token refresh is silent); logout returns to guest state and back-button does not restore the session.

---

## 3 · Booking & payment  `staging`

> STAGING ONLY — live Moyasar runs on production/testvue. Use the test card.

### [ ] T-3.1 — Create a booking
1. As a signed-in user pick a unit + free dates
2. Confirm the booking

**Expected:** Pending booking created; price breakdown (nightly × nights, fees, total) matches the unit page quote.

### [ ] T-3.2 — Pay with test card + 3-DS success
1. Pay with 4111… card
2. On the 3-DS page choose SUCCESS

**Expected:** You return to the app’s callback page, then the booking shows CONFIRMED with a receipt/payment reference.

### [ ] T-3.3 — 3-DS failure path
1. Make another booking
2. On the 3-DS page choose FAILURE

**Expected:** App shows payment failed with retry option; booking is NOT confirmed; no money state shown as paid.

### [ ] T-3.4 — Booking detail — frozen policy
1. Open the confirmed booking’s detail

**Expected:** Cancellation section shows the policy snapshot frozen at payment time (name + tiers), plus dates, guests, and full price breakdown.

### [ ] T-3.5 — Double-booking guard
1. With a second user, try to book the exact same dates on the same unit

**Expected:** Second attempt is refused — dates are already taken.

---

## 4 · Cancellation & wallet  `staging`

### [ ] T-4.1 — Cancellation preview
1. On a confirmed booking press cancel
2. Read the preview before confirming

**Expected:** Preview names the applicable tier NOW and the exact refund amount, derived from the frozen policy.

### [ ] T-4.2 — Cancel & refund
1. Confirm the cancellation

**Expected:** Booking becomes cancelled; refund appears in the wallet ledger; the unit’s dates become bookable again.

### [ ] T-4.3 — Wallet ledger
1. Open account → transactions

**Expected:** Refund row with reference code, amount and date; running history is read-only.

---

## 5 · User account area  `staging`

### [ ] T-5.1 — Edit profile
1. Change name/email in account settings

**Expected:** Saved and reflected after reload.

### [ ] T-5.2 — Change phone (two-step OTP)
1. Start phone change to a fresh number
2. Enter the OTP sent to the NEW number

**Expected:** Phone updated; login works with the new number afterwards.

### [ ] T-5.3 — Favorites
1. Heart a unit from listing and from detail
2. Open favorites; unheart one

**Expected:** Hearts sync across pages and survive reload (server-side, not just local).

### [ ] T-5.4 — Saved cards
1. Save a card from the wallet (test card)
2. Set default; delete a card

**Expected:** Card shows masked (last-4 + brand only); default badge moves; deletion removes it.

### [ ] T-5.5 — Review after stay
1. With a COMPLETED booking, submit a rating + text

**Expected:** Review accepted once (second attempt refused) and appears on the unit page.

### [ ] T-5.6 — Delete account
1. Use a throwaway account → account deletion

**Expected:** Confirmation required; after deletion login says not registered.

---

## 6 · Join as partner (on the user website)  `staging`

### [ ] T-6.1 — Register — individual
1. Join as partner → individual
2. Fresh phone + OTP 111222 + national ID (10 digits)

**Expected:** 201 success; account has partner role; status is PENDING.

### [ ] T-6.2 — Register — company
1. Join as partner → company with CR number

**Expected:** Same as 6.1 with company type; CR stored, no national-ID field.

### [ ] T-6.3 — Email verification
1. After registering, enter the 6-digit code from the email screen
2. Try resend inside 60s

**Expected:** Email verified flag flips; resend respects the cooldown. (If email delivery is pending Resend DNS, code arrives once that’s live.)

### [ ] T-6.4 — Existing user upgrades to partner
1. Use an existing normal user’s phone in the partner form (request OTP WITHOUT choosing register-intent)

**Expected:** Upgrade succeeds — no ‘already registered’ block on the partner flow.

### [ ] T-6.5 — Pending partner CAN use the user site
1. As the fresh pending partner, log into the user website

**Expected:** Login works — only the partner DASHBOARD is gated by approval, not the website.

---

## 7 · Admin panel  `staging`

> Test against the staging admin. On testvue.mamsaa.com/admin you are touching production.

### [ ] T-7.1 — Admin login
1. Log in with admin@mamsaa.sa / Password1

**Expected:** Admin dashboard loads with KPI cards.

### [ ] T-7.2 — Partner review screen exists
1. Open sidebar → طلبات الشركاء

**Expected:** List defaults to pending tab; stats row; search by name/phone/email works; individual vs company badge with ID/CR shown.

### [ ] T-7.3 — Approve a partner
1. Approve the partner you registered in 6.1

**Expected:** Toast confirms; row moves to approved; applicant receives an in-app notification.

### [ ] T-7.4 — Reject with reason
1. Reject the company from 6.2 with a reason

**Expected:** Reason required; row shows rejected + the reason text.

### [ ] T-7.5 — User status ≠ partner approval
1. On the USERS screen toggle a pending partner’s active status

**Expected:** Toggling is_active does NOT approve them — dashboard login still says under review. (This is the distinction that caused a real incident.)

### [ ] T-7.6 — Unit requests review
1. Open requests; approve one pending unit; reject another with reason

**Expected:** Approved unit goes public on the website; rejected one shows the reason to its partner.

---

## 8 · Partner dashboard — login & approval gate  `staging`

> partner.mamsaa.com pointed at staging. Cookie session — check the browser doesn’t block third-party cookies in your setup.

### [ ] T-8.1 — Pending partner is gated
1. Try dashboard login with a PENDING partner (0533333333)

**Expected:** OTP accepted but entry refused with طلب انضمامك قيد المراجعة — an under-review screen WITH a ‘try again’ action, not a dead end.

### [ ] T-8.2 — Approved partner enters
1. Log in with 0512345678 + OTP 111222

**Expected:** Straight to the dashboard; reloading keeps the session (2-hour cookie).

### [ ] T-8.3 — Approval unlocks the same login
1. After 7.3, retry login with that newly-approved partner

**Expected:** The retry that previously said under-review now enters — no cache traps.

### [ ] T-8.4 — Non-partner phone
1. Try a regular user’s phone on the dashboard login

**Expected:** Clear ‘not registered as partner’ message pointing to Join-as-Partner.

### [ ] T-8.5 — Logout
1. Log out from the dashboard menu

**Expected:** Back to login; deep links to /units redirect to login.

---

## 9 · Partner dashboard — property wizard & units  `staging`

### [ ] T-9.1 — Save a partial draft
1. Start Add Property; fill ONLY the name; save draft

**Expected:** Draft saves without price/type/capacity — no error. It lists with a draft badge.

### [ ] T-9.2 — License & photos upload
1. Upload tourism license PDF
2. Upload 3+ photos; reorder; pick a non-first cover

**Expected:** Uploads succeed (≤10MB, correct types enforced); order and cover survive save + reload.

### [ ] T-9.3 — City enum
1. In details choose Makkah / Madinah from the city list

**Expected:** Saves cleanly — the app sends slugs makkah/madinah (mecca/medina would be rejected by the API).

### [ ] T-9.4 — Submit validation
1. Submit the incomplete draft from 9.1

**Expected:** Field-level errors list everything missing (price, location, license…) — not one generic error.

### [ ] T-9.5 — Submit for review
1. Complete all fields; submit

**Expected:** Status becomes pending review; unit is NOT yet on the public site.

### [ ] T-9.6 — Approval → public
1. Admin approves it (7.6)
2. Open its public URL from the unit card

**Expected:** Unit is live on the website with photos, price and policy.

### [ ] T-9.7 — Edit approved → re-review
1. Edit the approved unit’s price

**Expected:** Warning that it returns to review; status flips to pending; public page keeps serving until re-approved per product rules.

### [ ] T-9.8 — Delete rules
1. Try deleting an approved unit, then a draft

**Expected:** Approved refuses with explanation; draft deletes.

### [ ] T-9.9 — Company docs gate (company partner)
1. As an approved COMPANY partner missing docs, try submitting a unit
2. Fill CR/IBAN + 3 PDFs in company docs, partially first

**Expected:** Submit blocked until docs complete; partial saves are kept between visits; complete flag flips when all five present.

---

## 10 · Partner dashboard — calendar & iCal  `staging`

### [ ] T-10.1 — Month grid states
1. Open the calendar for u_12; browse prev/current/next months

**Expected:** Days render as available / booked (with booking code) / blocked (reason) / external (source name e.g. Airbnb). No month errors.

### [ ] T-10.2 — Block & unblock
1. Block 2 free days with a reason; unblock one

**Expected:** Blocked days flip instantly; unblock only works on manual blocks.

### [ ] T-10.3 — Guards
1. Try blocking an already-booked day

**Expected:** Refused with a clear conflict message.

### [ ] T-10.4 — Add iCal feed — invalid then valid
1. Add feed with a junk URL
2. Add a real .ics URL

**Expected:** Junk → invalid-calendar error, nothing saved; valid → feed listed, synced, its days show as external.

### [ ] T-10.5 — Feed sync & delete
1. Press sync-now; then delete the feed

**Expected:** Sync updates the last-synced stamp; deleting removes the feed AND its external days from the grid.

### [ ] T-10.6 — Export link
1. Copy the export-calendar URL; open in an incognito tab

**Expected:** A .ics file downloads without any login — contains the unit’s bookings/blocks.

### [ ] T-10.7 — Booked dates auto-block after host-cancel
1. Do 11.2 then return to this calendar

**Expected:** The cancelled booking’s dates show as blocked (not instantly rebookable).

---

## 11 · Partner dashboard — bookings, reports, notifications  `staging`

### [ ] T-11.1 — Bookings list & detail
1. Open bookings; filter/paginate; open one

**Expected:** Guest name, dates, amounts (partner share consistent with 2% commission), status chips.

### [ ] T-11.2 — Host-cancel (the dangerous one)
1. Host-cancel a confirmed booking with a reason
2. Double-click the confirm button on purpose

**Expected:** Guest refunded 100%; dates blocked; double-click does NOT double-refund; the cancellation counts toward the partner’s 12-month tally in profile.

### [ ] T-11.3 — Overview KPIs
1. Open the dashboard home

**Expected:** Units count (drafts excluded), bookings, revenue (partner share), occupancy %, 12-month charts — numbers plausible vs the bookings list.

### [ ] T-11.4 — Reports summary
1. Set a from/to range covering known bookings
2. Set to < from

**Expected:** Gross / commission (2%) / net add up; per-unit table matches; inverted range shows a field error.

### [ ] T-11.5 — PDF export
1. Export the range as PDF; open it

**Expected:** Arabic renders correctly (proper letter joining, RTL) on ~1 page — not mirrored or disconnected letters.

### [ ] T-11.6 — CSV/Excel export
1. Export CSV; open in Excel

**Expected:** Arabic intact (UTF-8 BOM), columns: code, unit, guest, dates, nights, total, commission, net, status.

### [ ] T-11.7 — Notifications
1. Check the bell after admin actions (approval, unit review)
2. Mark one read; mark all read

**Expected:** Unread count accurate; read states persist.

### [ ] T-11.8 — Profile & phone change
1. Edit partner name/email
2. Change phone with OTP to the new number

**Expected:** Both persist; next login uses the new phone.

---

## 12 · Cross-cutting quality  `both`

### [ ] T-12.1 — RTL & Arabic rendering
1. Skim every page in Arabic UI

**Expected:** No mirrored icons, cut-off Arabic, or LTR-leaking numbers where they shouldn’t.

### [ ] T-12.2 — Mobile viewport
1. Repeat key flows (browse, book, wizard) at 375px width

**Expected:** No horizontal scroll; menus and modals usable.

### [ ] T-12.3 — Throttle handling
1. Hammer OTP request >5×/min

**Expected:** UI shows a wait message with retry timing — not a raw error.

### [ ] T-12.4 — Session expiry behaviour
1. Leave the dashboard idle >2h (or clear the cookie) then click something

**Expected:** Clean redirect to login, no broken half-rendered screens.

### [ ] T-12.5 — Error envelope sanity
1. Trigger any validation error in each app

**Expected:** User sees the Arabic message from the API, mapped to the right field — never a raw JSON dump or English stack trace.

---

_Total: 71 tests across 12 suites (+ setup). Generated 2026-07-17._
