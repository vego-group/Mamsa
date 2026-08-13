# Contract Gap Analysis — Wallets, Payouts, VAT

Repository: `backend/` (Laravel API). Every claim cites `path:line`. `UNKNOWN — not verifiable from code` marks anything not in the source.

**Read this first — two contract versions exist.** This report was written against a `v2.1` whose VAT section I had hardened with **§1.8–§1.11** (per-booking rate freeze, fee treatment, refund→credit-note VAT reversal, charge/settlement rules). Those four subsections are **my additions to the VAT section — not clauses in the canonical contract you maintain.** So where this report cites "§1.8 / §1.10 / §1.11", read them as *recommended additions*, not existing references; every underlying **fact** stands on its own `path:line` citation regardless. Your canonical v2.1 instead carries **open questions 12–14** (cookie/SameSite/CORS) — answered here in **§11**. The per-phase day estimate you asked for is **§12**. And the live server's 403 code is **`FORBIDDEN`, not `INSUFFICIENT_PERMISSION`** — **§9.11**.

---

## 1. Stack and conventions

| Aspect | Fact | Evidence |
|---|---|---|
| Language | PHP `^8.3` (runtime container is 8.4.x) | `composer.json:12` |
| Framework | Laravel `^13.8` | `composer.json:13` |
| Auth libs | Sanctum `^4.0` (Bearer, `/api/v1`); Spatie Permission `^8.0` (roles/permissions) | `composer.json:14,18` |
| Mail | Resend `^1.5` | `composer.json:17`; `config/mail.php:64` |
| PDF | mpdf `^8.2` (present; no invoice use found) | `composer.json` require `mpdf/mpdf` |
| ORM | Eloquent | models under `app/Models/` |
| DB engine | default `sqlite`; prod `mysql` via `DB_CONNECTION` | `config/database.php:20,35`; exact MySQL version `UNKNOWN — not verifiable from code` |
| Migrations | Timestamped files, `php artisan migrate`; enum changes are raw `ALTER … MODIFY` guarded to MySQL | `database/migrations/…`; `2026_06_30_000003_add_completed_to_bookings_status.php:14` |
| Tests | PHPUnit `^12.5` (`tests/Feature`, `tests/Unit`) | `composer.json` require-dev; `tests/Feature/PricingTest.php`, `tests/Feature/TestModeTest.php` |
| Queue | `QUEUE_CONNECTION` default `database`; notifications are sent **synchronously** (not `ShouldQueue`) | `config/queue.php:16`; see §3.6 note |
| Scheduler | `Schedule::command(...)` in console; requires system-cron `schedule:run` | `routes/console.php:17,21,25,31,35` |
| Routes | 3 surfaces: `/api/v1/*` (`routes/api.php`), root partner `routes/dashboard.php`, root admin `routes/admin-panel.php` mounted in `bootstrap/app.php:19-29` |
| Validation | Inline `$this->validate()/validated()` in controllers (no dedicated FormRequests for these) | `AdminPanel/AuthController.php:32`; `Dashboard/ProfileController.php:94` |
| Error shape | **Per-surface, three different envelopes** — `/api/v1`: Laravel default JSON; dashboard: `{ error: { code, message, fields? } }`; admin-panel: flat `{ message, code }` | `bootstrap/app.php:70-176` |
| Auth mechanism | `/api/v1` = Sanctum Bearer (`auth:sanctum`); partner = session cookie (`auth:dashboard`); admin = session cookie (`auth:admin-panel`) — all three guards use the **same `users` table** | `config/auth.php:40-59`; `routes/api.php:85`; `routes/dashboard.php:33`; `routes/admin-panel.php:27` |

---

## 2. Current pricing and VAT implementation

| Question | Answer | Evidence |
|---|---|---|
| `pricePerNight` storage | `units.price` `decimal(10,2)` | `2026_06_15_000002_create_units_table.php:19` |
| Net or gross today? | **NET** — VAT is added on top | `app/Support/Pricing.php:38-39,53` |
| Current total formula | `subtotal = round(nightly × nights,2)`; `taxes = round(subtotal × 15%,2)`; `total = subtotal + taxes` | `app/Support/Pricing.php:38-39,53` |
| VAT rate source | `config('booking.tax_rate')` = `0.15` (env `BOOKING_TAX_RATE`), config-only, no admin edit | `config/booking.php:19`; `app/Support/Pricing.php:25-28` |
| VAT frozen per booking? | **Yes** — `bookings.tax_percent` (and `service_fee_percent`) frozen at creation | `2026_07_18_000002_add_percent_fields_to_bookings.php:23-24`; `app/Models/Booking.php:57,78` |
| Money representation | `decimal` columns cast to PHP `float`; rounded to 2dp at every write | `app/Models/Booking.php:74-83`; `app/Models/Payment.php:24-26` |
| Moyasar amount unit | **Halalas (integer)** = `(int) round(SAR × 100)` | `app/Services/MoyasarService.php:40,192`; refund `:209,211`; `app/Actions/Bookings/CancelBookingAction.php:103` |
| Commission computed? | **Yes** — `2%` of **`subtotal` (net)**, frozen per booking as `commission_amount`/`commission_rate` | `app/Support/Pricing.php:52`; `2026_07_12_000001_add_commission_to_bookings_table.php:18-19,28`; `app/Models/Booking.php:25,40-44` |
| `partnerShare` computed? | **No** — not stored or returned anywhere; derived ad-hoc as `subtotal + cleaning − commission` in one dashboard query | `app/Http/Controllers/Api/V1/Partner/DashboardController.php:24,29` |
| Tax invoice / ZATCA / QR / invoice numbering | **None exists.** No `fatoora`/`zatca`/`qr`/invoice-number code in `app/` | absence across `app/` (searched) |
| Fees | `cleaning_fee`/`service_fee` columns exist but abolished; `0` going forward, historical rows kept | `2026_07_18_000003_revert_cleaning_and_service_fees.php`; `config/booking.php:12-14`; `app/Support/Pricing.php:12-14` |

**Guest wallet ledger already exists and is unrelated to partner earnings** — see §5 and §9.1.

---

## 3. Auth and authorisation — current state

| Question | Answer | Evidence |
|---|---|---|
| Admin session establishment | OTP → `Auth::guard('admin-panel')->login()`, session regenerated; **login gated on `isAdmin()`** (non-admins get 403, no SMS) | `AdminPanel/AuthController.php:38,60,66,70,100` |
| `isAdmin()` definition | `hasAnyRole(['Admin','SuperAdmin'])` | `app/Models/User.php:170-172` |
| Role/permission concept? | **Yes** — Spatie roles `User, Individual, Company, Admin, SuperAdmin` + a seeded permission set; SuperAdmin = all permissions | `database/seeders/RolesAndPermissionsSeeder.php:19-112` |
| Enforcement — `/api/v1` | **Role middleware present**: partner group `role:Individual|Company`, admin group `role:Admin|SuperAdmin` | `routes/api.php:174,210`; aliases `bootstrap/app.php:64-68` |
| Enforcement — admin-panel BFF | **Authentication only** (`auth:admin-panel`); **no role/permission middleware on any endpoint** — every authenticated admin can hit everything | `routes/admin-panel.php:27-94` |
| `AdminProfile.role` today | **Hardcoded string literal `'superadmin'`** for every admin; no `permissions` field; the user's real Spatie role is never read here | `app/Http/Resources/AdminPanel/AdminProfileResource.php` (`'role' => 'superadmin'`) |
| What stops a non-admin calling an admin endpoint | `/api/v1`: `role:` middleware. admin-panel: the login gate (`isAdmin()`) + a distinct session guard; a partner's `dashboard` cookie is a different guard | `AuthController.php:100`; `config/auth.php:48-58` |

**Correction to contract §4.1:** the premise "server-side enforcement must be built" is **only partly true**. Authentication exists on every surface; `/api/v1` already enforces role-based authz; Spatie permission middleware is aliased and ready (`bootstrap/app.php:65-67`). What is genuinely missing: (a) **fine-grained per-permission authz on the admin-panel BFF**, (b) a **`finance` role** and the contract's permission literals, (c) `AdminProfileResource` stops hardcoding `role` and emits real `role` + `permissions[]`. Today an `Admin` (lesser) is treated identically to `SuperAdmin` in the BFF — a live privilege gap.

---

## 4. Partner and bank data — current state

| Question | Answer | Evidence |
|---|---|---|
| Partner model | `partner_details` (1:1 user): `type`, `national_id`, `cr_number`, `iban`, doc-file columns, `status`, `verified_at`, `rejection_reason`, `suspension_reason`, `verified_documents`, `reviewed_at` | `app/Models/PartnerDetail.php:14-29` |
| individual vs company | `partner_details.type` enum `individual|company`; also Spatie roles `Individual`/`Company` | `2026_06_15_000001_create_partner_details_table.php:14`; `RolesAndPermissionsSeeder.php:76,94` |
| IBAN storage | `partner_details.iban` `string(34)` nullable — a **single column, not type-restricted** | `2026_07_14_000001_partner_dashboard_schema.php:69` |
| IBAN available to individuals? | **At the API, yes** — `PUT /me/company-docs` accepts `iban` for any partner; it is only the **frontend** that hides it for individuals (contract's blocker is client-side) | `Dashboard/ProfileController.php:96,113-119` |
| IBAN validation | **Regex only** `^SA\d{22}$` — **no mod-97 checksum** | `Dashboard/ProfileController.php:96` |
| Partner status values | `partner_details.status` = `pending|approved|rejected`; **no `active`/`suspended` enum** — "suspended" is derived from `users.is_active = false` | `PartnerDetail.php:10-12`; `Dashboard/ProfileController.php:130-134` |

---

## 5. Booking and money data — current state

| Question | Answer | Evidence |
|---|---|---|
| Booking statuses | `pending, confirmed, cancelled, completed` (note: `pending`, **not** `pending_payment`) | `app/Models/Booking.php:12-15`; enum `2026_06_30_000003_...:15` |
| Booking money columns | `total_amount, nightly_rate, subtotal, service_fee(+percent), cleaning_fee, taxes, tax_percent, commission_rate, commission_amount` (all frozen) | `Booking.php:46-69`; `2026_06_27_000002_...:19-23`; `2026_07_12_000001_...:18-19` |
| What marks `completed` | Scheduled command `bookings:complete` daily 00:30 — **automatic** (needs system cron) | `routes/console.php:15-17` |
| Refund trigger | `CancelBookingAction`: quote from frozen snapshot → Moyasar `refund`/`void` (void only if full & ≤120 min) → record `Refund` + `payment.refunded_amount +=` + audit | `app/Actions/Bookings/CancelBookingAction.php:39-80,87-142` |
| Refund stored | `refunds` row (`amount, refund_percent, tier_label, moyasar_refund_id, moyasar_response`) + `payments.refunded_amount` | `CancelBookingAction.php:131-142`; `Payment.php:14,41-43` |
| **Completed bookings are non-refundable today** | `CancelBookingAction` refuses `cancelled` **and `completed`** | `CancelBookingAction.php:42` |
| Existing ledger/balance/payout/settlement | **A guest `wallet_transactions` ledger exists** (payment/refund/topup/reward, signed `amount`, no `balance_after`, no `partner_id`); **no partner balance, payout, or settlement concept anywhere** | `2026_07_01_000002_create_wallet_transactions_table.php:16-33`; `app/Models/WalletTransaction.php`; `app/Http/Controllers/Api/V1/User/TransactionController.php:11-23` |
| Audit log | `audit_logs` table + `AuditLog::record()` used for refunds/bookings/partner actions | `app/Models/AuditLog.php:19,39`; `CancelBookingAction.php:159,179` |

---

## 6. Gap table — contract section by section

Gap ∈ `ALREADY EXISTS` · `PARTIAL` · `NEW` · `CONFLICTS`. Effort in developer-days (single dev).

| Contract § | Requirement | Current state | Gap | New tables | New endpoints | Effort |
|---|---|---|---|---|---|---|
| §1.1–1.3 VAT split (inclusive) | `pricePerNight` gross; `gross/netBase/vat/commission/partnerShare` | Net model, VAT added on top; commission on net exists | **CONFLICTS** (semantics flip of `units.price`; rewrite `Pricing::breakdown`) | — | — | 5 |
| §1.7 `PriceBreakdown` shape | camelCase object incl. `partnerShare`, `vatRate` | `/api/v1` snake_case; no `partnerShare`; BFFs camelCase | **PARTIAL** (add `partnerShare`; reconcile casing per surface) | — | — | 2 |
| §1.8 per-booking freeze | freeze `vatRate` + split | `tax_percent`, `commission_*` already frozen | **PARTIAL** (add frozen `net_base`, `vat`, `partner_share`, `gross`) | — | — | 1 |
| §1.9 fees | `0` going forward, historical grandfathered | already abolished + grandfathered | **ALREADY EXISTS** | — | — | 0 |
| §1.10 refund → credit note + VAT reversal | proportional split + ZATCA credit note + wallet `refund_reversal` | refund exists but **cannot refund completed**; no credit note; no partner ledger | **NEW / CONFLICTS** (see §9.3) | credit-note numbering | `GET /bookings/{id}/credit-notes` | 4 |
| §1.11 charge = gross, halalas | `amountHalalas = round(gross×100)` | already `round(SAR×100)` | **ALREADY EXISTS** (verify after flip) | — | — | 0.5 |
| §2 wallets + ledger | `partner_wallets` + immutable ledger + `balanceAfter` + reconciliation + earning-on-completed | none (guest wallet is unrelated) | **NEW** (name collision — §9.1) | `partner_wallets`, `partner_ledger_entries` | admin + partner wallet reads | 7 |
| §2.5 bank details | both types + mod-97 + verify/reject + backfill | company-docs `iban` regex-only, frontend company-only | **PARTIAL** | `bank_details` | `GET/PUT /me/bank-details`, admin verify/reject | 3.5 |
| §3 payouts | eligible/ineligible live queries, record (server-computed amount/iban), reverse, manual, once-per-month, idempotency | none | **NEW** | `payouts` | 8 admin endpoints (§5.2) | 7 |
| §3.6 notifications | partner payout email + in-app + category `payout` | notification infra exists; **no `payout`/`wallet` category** | **PARTIAL** | — | — | 2 |
| §4 roles & permissions | `finance` role, `permissions[]`, per-endpoint authz on BFF, `/admin/me` change | Spatie roles/permissions + middleware exist; BFF unguarded; `role` hardcoded | **PARTIAL** (§9.2) | — | — | 4.5 |
| §5 admin endpoints | wallets/payouts controllers, stats, filters, CSV export | none of these controllers exist | **NEW** | — | §5.1–5.3 routes | 3 |
| §6 partner endpoints | `/wallet`, `/wallet/transactions`, `/payouts`, `/me/bank-details` | none | **NEW** | — | §6 routes | 2 |
| §7 user-site + tax invoice | VAT display data + invoice + server QR (ZATCA Phase-1 TLV) | no invoice/QR at all | **NEW** | — | `GET /bookings/{id}/invoice` | 7 |
| §8.7 status enums | `pending_payment`, `active`, `suspended` | `pending`, `approved`, `is_active` | **CONFLICTS** (map, don't rename — §9.6/9.7) | — | — | 1 |

Subtotal ≈ **48.5** dev-days before the buffer; see the closing estimate.

---

## 7. Schema impact

**New tables** (dependency order):

1. `bank_details` — `id`, `user_id` FK unique, `iban` (34), `account_holder_name`, `bank_name` nullable, `verified` bool default false, `verified_at` nullable, `rejection_reason` nullable, `timestamps`. Index `user_id`. Backfill from `partner_details.iban` with `verified=false` (contract §10.2).
2. `partner_wallets` — `id`, `partner_user_id` FK **unique**, `available_balance` `decimal(12,2)` default 0, `pending_balance`, `lifetime_earnings`, `lifetime_paid_out`, `currency`, `timestamps`. One row per partner.
3. `partner_ledger_entries` — `id`, `partner_user_id` FK, `type` enum(`earning|payout|refund_reversal|adjustment`), `amount` `decimal(12,2)` signed, `balance_after` `decimal(12,2)`, `ref_type`, `ref_id`, `ref_code`, `description`, `created_by_admin_id` nullable, `created_at`. Indexes `(partner_user_id, created_at)`. **Named distinctly from the existing `wallet_transactions`** (§9.1).
4. `payouts` — `id`, `reference` unique, `partner_user_id` FK, `period_month` (`YYYY-MM`), `amount` `decimal(12,2)`, `bookings_count`, `iban`/`bank_name`/`account_holder_name` (frozen snapshot), `status` enum(`paid|reversed`), `paid_at`, `recorded_by_admin_id`, `bank_reference` **unique**, `note`, reversal columns, `notified_at`. **Unique `(partner_user_id, period_month)` where status='paid'** (once-per-month) + unique `bank_reference`.

**Altered existing tables:**

| Table | Change | Note |
|---|---|---|
| `units` | `price` semantic → gross (no column change); `mamsa_owned` already exists | `2026_07_28_000002_...:38` |
| `bookings` | add frozen `net_base`, `vat`, `partner_share`, `gross` (or reuse `subtotal`/`taxes`/`total_amount` re-interpreted) `decimal(10,2)` | enum literal `pending` unchanged — §9.6 |
| `partner_details` | `iban` **deprecated** (read-only, backfill source) | `ProfileController.php:239`-equivalent read path |
| `users`/roles | seed `finance` role + contract permission set | `RolesAndPermissionsSeeder.php` |

**Ledger immutability:** Eloquent alone cannot prevent updates/deletes. Options, strongest first: (a) a DB `BEFORE UPDATE`/`BEFORE DELETE` trigger raising `SIGNAL` on `partner_ledger_entries`; (b) a MySQL user with only `INSERT`/`SELECT` on that table; (c) an Eloquent `saving`/`deleting` model guard (defence-in-depth, not authoritative). Recommend (a)+(c). `UNKNOWN — not verifiable from code` whether prod MySQL grants allow triggers.

**Concurrency (two simultaneous `record` for one partner):** the actual mechanism must be **`SELECT … FOR UPDATE` on the `partner_wallets` row inside a transaction** + the **unique `(partner_user_id, period_month)`** constraint as the backstop. Default MySQL isolation is `REPEATABLE READ` (`UNKNOWN — not verifiable from code`, but the Laravel/MySQL default), under which a naive read-modify-write of `balance_after` can double-apply without the row lock. `Idempotency-Key` (§5.2) also needs a store; the contract's "same convention as `hostCancel`" is **UNKNOWN — not verified in this pass** (the host-cancel idempotency mechanism was not read).

**`balance_after` stored-at-write:** workable **only** inside the same `FOR UPDATE` transaction that mutates the wallet — compute `new_balance = locked_row.available_balance + signed_amount`, write the ledger row and the wallet row together, commit. Add the reconciliation invariant `Σ ledger.amount == wallet.available_balance` as a nightly check (contract §10.3) from day one.

**Downtime/rewrite:** none required — the platform is pre-launch demo data (contract §10.1); new tables are additive; `units.price` semantic flip needs **no data migration** (truncate/reseed demo). No table rewrite.

---

## 8. Answers to the contract's open questions

| # | Question | Answer (from code / decision) |
|---|---|---|
| 1 / 1b | VAT remittance / invoice in Mamsa's name | Answered in contract; **no code today** (no invoice generation) — NEW build. |
| 1c | Recognised revenue = net base | **Human decision (accountant)** — not a code answer. |
| 3 | `partnerShare` available immediately on `completed` vs a hold | **Human decision.** Code note: `completed` is currently terminal & non-refundable (`CancelBookingAction.php:42`), so "immediately available" collides with any later reversal — see §9.3. |
| 4 | `bankName` from IBAN bank code vs partner selects | **Derivable**: SA IBAN positions 5–6 are the bank code → a static ~30-entry KSA bank map. No such map exists in code (`UNKNOWN`). Recommend derive + allow override. |
| **5** | Email provider + Arabic RTL | **Resend** (`resend/resend-php ^1.5`, `config/mail.php:64` transport, `config/services.php:21-22`). Arabic RTL: already sending Arabic transactional mail (Blade templates); **yes, supported**. Prod from `no-reply@…`, reply-to `info@mamsaa.com` (`config/mail.php:119-121`). |
| 6 | Audit retention ≥5y | `audit_logs` + `AuditLog::record()` exist (`app/Models/AuditLog.php:39`); extend to payout/wallet/IBAN-read events. 5-year retention = **ops/infra decision** (DB growth), not code. |
| 7 | Negative-balance notification/collection | **Human decision** (spec: silently offsets). Code allows negative (contract §2.2); no collection flow. |
| **8** | Payout CSV export format | No payout CSV exists. Precedent: `Dashboard/ReportController::export` (`routes/dashboard.php:75`). **Column set = human decision (accountant)** before building. |
| **9** | Finance role scope (global vs segment) | **Human decision** (spec: global). No code constraint; Spatie roles are global by default. |
| 10 | CORS `localhost:3000` for credentialed staging | Real config gap: `config/cors.php:45` `supports_credentials` defaults **false** and `allowed_origins` defaults `*` (`:27-28`) — credentialed cross-origin needs `supports_credentials=true` + explicit origins (a `*` wildcard is rejected for credentialed requests). |
| 11 | Email partner on **reversal** | **Recommend yes** (product decision) — they were already emailed they were paid. No code yet. |

---

## 9. Where the contract is wrong / must adapt

**9.1 — `WalletTransaction` / `wallet_transactions` is already taken by the guest wallet.**
Contract §2.3 names the partner ledger row `WalletTransaction`. That class and table already exist for a **guest** ledger (payment/refund/topup/reward), with `user_id`, no `balance_after`, no `partner_id`, no immutability (`app/Models/WalletTransaction.php`; `2026_07_01_000002_create_wallet_transactions_table.php:16-33`; `User.php:128-130`). Reusing the name/table will collide head-on. **Fix:** name the partner ledger `PartnerLedgerEntry` / `partner_ledger_entries` and the balance holder `partner_wallets`; leave the guest wallet untouched.

**9.2 — §4.1 overstates the missing enforcement (it describes the frontend, not this backend).**
Spatie roles + a seeded permission set + role/permission middleware already exist and are wired (`RolesAndPermissionsSeeder.php:19-112`; `bootstrap/app.php:64-68`), and `/api/v1` already enforces `role:` (`routes/api.php:174,210`). What is actually true: the **admin-panel BFF has no per-permission authz** (`routes/admin-panel.php:27-94`) and `AdminProfileResource` **hardcodes `role='superadmin'`**, so an `Admin` is silently elevated to SuperAdmin there. **Fix framing:** not "build authz from zero" but "apply the existing `permission` middleware to the BFF, add a `finance` role + the contract's permission literals, and emit real `role`+`permissions[]`."

**9.3 — Refunding a `completed` booking is currently impossible, but §1.10/§2.2 depend on it.**
`CancelBookingAction.php:42` refuses both `cancelled` and `completed`. Yet §2.1 credits `partnerShare` to `availableBalance` **at `completed`**, and §1.10/§2.2 describe a `refund_reversal` on a completed booking. There is **no trigger** for that today. **Fix:** define the exact admin-only action that refunds a completed booking (and issues the credit note), or couple `partnerShare` availability to a post-completion hold (ties to open Q3). This must be resolved before the wallet is built.

**9.4 — camelCase `PriceBreakdown` conflicts with `/api/v1` snake_case.**
`/api/v1` serialisers are snake_case (`subtotal`, `taxes`, `tax_percent`, `commission_amount`) and are consumed by the live Vue app + the Postman collection; the two BFFs are camelCase (`BookingPresenter`: `taxPercent`, `cleaningFee`). Forcing the contract's camelCase onto `/api/v1` breaks existing clients. **Fix:** specify casing **per surface** — camelCase on the BFFs, snake_case on `/api/v1` — or add fields without renaming existing ones.

**9.5 — Booking status literal `pending_payment` does not exist.**
Contract §8.7 uses `pending_payment`; the enum and model use `pending` (`Booking.php:12`; `2026_06_15_000006_...:22`). **Fix:** the gap-table/frontends adopt the real literals; do not silently `ALTER` an enum that has live rows and a scheduled `bookings:expire-pending` job keyed on `pending`.

**9.6 — PartnerStatus model differs from §8.7.**
Contract: `pending|active|suspended|rejected`. Code: `partner_details.status` = `pending|approved|rejected` (`PartnerDetail.php:10-12`) **plus** `users.is_active` for suspension (`ProfileController.php:130-134`). `active` ≈ `approved`; `suspended` is not a status value. **Fix:** the payout eligibility rule §3.2(3) "status is active" must be implemented as `partner_details.status = 'approved' AND users.is_active = true`, not a single `status` compare.

**9.7 — §2.5's "individuals have no way to supply a bank account" is a frontend limitation, not a storage one.**
The API already accepts `iban` for any partner on `PUT /me/company-docs` (`ProfileController.php:96,113-119`); only the client hides it for individuals. **Fix:** the real backend work is the dedicated `bank_details` resource + mod-97 (the existing `^SA\d{22}$` regex, `ProfileController.php:96`, is genuinely insufficient as the contract says) — not "add storage."

**9.8 — Concurrency/immutability are named but not mechanised.**
§2.3/§7 require an immutable ledger and a stored `balanceAfter`, but the contract does not name the enforcement. Under the default queue (`database`/sync) and MySQL `REPEATABLE READ`, a read-modify-write double-pays without a row lock. **Fix (must be explicit in the build):** `SELECT … FOR UPDATE` on `partner_wallets` + unique `(partner_user_id, period_month)` + a DB trigger for immutability (see §7).

**9.9 — `mamsa_owned` units are owned by the creating admin — the wallet code must skip them.**
`units.mamsa_owned` sets `user_id` to the creating admin (`2026_07_28_000002_...:38`). §1.3 correctly sets `partnerShare=0`, but if the wallet/earning writer keys on `unit.user_id`, the admin accrues a phantom partner balance. **Fix:** explicitly skip wallet/ledger writes for `mamsa_owned` units.

**9.10 — "existing envelope" is three different envelopes.**
§9 assumes one error envelope. There are three (`bootstrap/app.php:70-176`): `/api/v1` default, dashboard `{error:{code,message}}`, admin-panel flat `{message,code}`. **Fix:** map the new error codes into whichever envelope the calling surface uses; don't assume a single shape.

**9.11 — the live 403 code is `FORBIDDEN`, not `INSUFFICIENT_PERMISSION`.**
Contract §9 (and the frontend guide's error table) assume `INSUFFICIENT_PERMISSION`. The running server emits code `FORBIDDEN` on every 403 today (`AdminPanel/AuthController.php:39,63,67`; `Dashboard/WebhookController.php:26`); `INSUFFICIENT_PERMISSION` appears **nowhere** in `app/`. Because the admin-panel BFF has no permission middleware yet (§9.2), there is no permission-denial code at all — the only 403s are the login gate's `FORBIDDEN`. **Fix:** when BFF authz is added, either standardise the contract on `FORBIDDEN` (matches today) or introduce `INSUFFICIENT_PERMISSION` deliberately — and the frontend must accept whichever ships. Separately, correct contract Q10: the dev port is **3002**, not 3000 (`.env.example:79-80`).

---

## 10. Sequencing and risk

**Recommended order** (adjusts contract §10.5 for the findings above):

1. **Roles/permissions foundation** — add `finance` role + contract permission literals, apply Spatie `permission` middleware to the admin-panel BFF, and make `AdminProfileResource` emit real `role`+`permissions[]` (fixes the Admin==SuperAdmin bug, §9.2). *Unblocks everything and closes a live gap.*
2. **VAT inclusive refactor** — flip `units.price` semantics, rewrite `Pricing::breakdown`, add frozen `net_base/vat/partner_share/gross`, update all serialisers **per-surface casing** (§9.4). *Balances derive from `partnerShare`, so this precedes wallets.*
3. **`bank_details`** resource + mod-97 + verify/reject (§2.5).
4. **Resolve the completed-refund decision** (§9.3), then **wallets + ledger** (distinct names, §9.1) with the reconciliation check.
5. **eligible/ineligible** live queries (§3.2).
6. **`POST /admin/payouts/record`** with the `FOR UPDATE` lock, unique constraints, and idempotency (§9.8).
7. **Payout notifications** + `payout`/`wallet` categories (§3.6).
8. **reverse** (superadmin) + **manual** off-cycle.
9. **Tax invoice + ZATCA QR + credit notes** (§7) — a self-contained parallel workstream.

**Three highest-risk items:**

1. **The net→gross money refactor across three surfaces.** A wrong sign or rounding silently mis-charges guests or mis-pays partners; the live Vue app, Postman collection, and 60+ historical rows depend on the current snake_case shape and net semantics (`Pricing.php`, `BookingResource`, `BookingPresenter`). Money + backwards-compat.
2. **Recording a payout — an irreversible external action.** Double-pay on a race, or accepting a client-supplied amount, moves real money wrongly; the whole control rests on the `FOR UPDATE` lock + unique `(partner, period_month)` + server-computed amount (§9.8, contract §3.3). Money + external.
3. **Permission enforcement on the admin-panel BFF.** Today every admin is `superadmin` with no per-endpoint authz (`AdminProfileResource`, `routes/admin-panel.php`); shipping `finance` without correct server-side gating would let a finance user reverse payouts or adjust balances. Permissions + money.

---

## 11. Session & environment — answers to open questions 12–14 (server-verified)

These are configuration facts. The **mechanism** is in the repo (cited); the **per-environment values** live in each server's `.env`, not the repo, so those are your server-verified report — which the code fully explains.

### 11.1 Q12 — cookie-name divergence (`mamsaa-session` prod vs `mamsa-session` staging)

**Root cause is in code.** The session cookie name is not pinned — it falls back to a slug of `APP_NAME`:
`'cookie' => env('SESSION_COOKIE', Str::slug(env('APP_NAME','laravel')).'-session')` (`config/session.php:130-133`).
`.env.example` never sets `SESSION_COOKIE` (it mentions only `SESSION_DOMAIN`/`SESSION_SAME_SITE`, `:87,90`), so if prod's `APP_NAME` is "Mamsaa" and staging's is "Mamsa", the auto-derived names become `mamsaa-session` vs `mamsa-session` — exactly what you saw. It bites on any promotion that assumes a fixed cookie name (an old session cookie is simply ignored under the new name → users appear logged out).
**Fix:** set `SESSION_COOKIE` **explicitly and identically** in every environment (e.g. `SESSION_COOKIE=mamsaa_session`). Never rely on the `APP_NAME` default for a cookie name. One line per `.env`; no code change.

### 11.2 Q13 — SameSite divergence (`Lax` prod vs `None` staging)

**Repo default is `lax`:** `'same_site' => env('SESSION_SAME_SITE', 'lax')` (`config/session.php:202`), and `.env.example:90` states the BFF "keeps `SESSION_SAME_SITE=lax`". Staging running `None` is a per-server override.
**Why it matters (you're right):** with different SameSite values, **staging is not a faithful test of any cross-site flow — including the Moyasar return.** Concretely: `Lax` sends the cookie on a **top-level GET navigation** (so a full-page redirect back from Moyasar *does* carry it) but **not** on a cross-site `POST`/`fetch`/XHR; `None` sends it on all of them (and **requires `Secure`**, `config/session.php:172`, plus ideally `partitioned`/CHIPS, `:215`). So a checkout-return that works on staging under `None` can silently fail on prod under `Lax` if it isn't a top-level GET.
**Fix — pick one cookie model and apply it to both envs:**
- Frontends are **same-site subdomains** of `mamsaa.com` (`admin.mamsaa.com` ↔ `api.mamsaa.com`): `SESSION_SAME_SITE=lax` + `SESSION_DOMAIN=.mamsaa.com` (`.env.example:87`) everywhere. This is the repo's intended model.
- Any frontend is a **different site** (vercel.app, `localhost:3002`): `SESSION_SAME_SITE=none` + `SESSION_SECURE_COOKIE=true` in **both** envs, or auth breaks cross-site.
Do **not** run `Lax` on prod and `None` on staging — that single combination is what makes staging lie to you.

### 11.3 Q14 — CORS needs `http://localhost:3002` (not 3000) on staging

**Already the documented dev value:** `.env.example:79-80` literally says *"Dev (Next.js on localhost:3002): `CORS_ALLOWED_ORIGINS=http://localhost:3002`"*. The repo default is the credentials-incompatible `*` (`.env.example:67`; `config/cors.php:27-28`), and browsers **reject credentialed requests against `*`** (`config/cors.php:43`; `supports_credentials` defaults false, `:45`).
**Fix on staging:** `CORS_ALLOWED_ORIGINS=http://localhost:3002,https://<staging-frontend>` **and** `CORS_SUPPORTS_CREDENTIALS=true`. Config already supports this; env change only. (Supersedes contract Q10's `localhost:3000`.)

---

## 12. Timeline — effort per phase and the earliest frontend-unblock

**First, reframe "backend not yet built":** the platform is **live in production** — auth, bookings, payments (Moyasar), cancellations/refunds, three API surfaces, notifications and the admin/partner BFFs all exist and run. What is greenfield is **only this contract's scope**: VAT-*inclusive* pricing, partner wallets/ledger, payouts, the finance role, and the tax invoice. That scope has **zero** code today (verified: no payout tables, VAT is exclusive, no `partnerShare`, no BFF authz). So "not started" is accurate for *these features*, not for the product.

**I can give effort in days; I cannot give calendar dates** — start date and head-count are the backend lead's call. Here is the translation so you can compute the wait.

| Phase | Scope (contract §) | Dev-days | Cumulative | Frontend it unblocks |
|---|---|---:|---:|---|
| 1 | Roles/permissions: finance role, apply `permission` middleware to the BFF, `/admin/me` → real `role`+`permissions[]` (§4) | 4.5 | 4.5 | **Auth/permission gating** — stop mocking `/admin/me` |
| 2 | VAT-inclusive refactor + `PriceBreakdown` + per-booking freeze (§1) | 8 | 12.5 | **VAT display + checkout breakdown + `PriceBreakdown`** |
| 3 | `bank_details` (both types) + mod-97 + verify/reject (§2.5) | 3.5 | 16 | **Bank-details screen** |
| 4 | Wallets + ledger + reconciliation + earning-on-completed + refund reversal (§2) — needs the §9.3 decision first | 7 | 23 | **Partner wallet UI** |
| 5 | Eligible / ineligible live queries (§3.2) | 2 | 25 | Admin eligible list |
| 6 | `POST /payouts/record` + idempotency + row lock + unique constraints (§3.3) | 7 | 32 | **Admin record-transfer + partner payout history** |
| 7 | Payout email + in-app + `payout`/`wallet` categories (§3.6) | 2 | 34 | Payout notifications |
| 8 | Reverse (superadmin) + manual off-cycle (§3.5/§3.7) | 2 | 36 | Reverse/manual controls |
| 9 | Tax invoice + ZATCA Phase-1 QR + credit notes (§7) — parallelisable | 7 | 43 | **Invoice/receipt screen** |
| — | Admin/partner endpoint wiring, filters, stats, CSV (§5/§6) | 5 | 48 | list/stat wiring |
| — | Integration, reconciliation tests, hardening buffer (~20%) | ~10 | **~58** | — |

**Calendar translation (effort ÷ throughput):**
- **1 developer** ≈ 58 dev-days ≈ **11–12 working weeks (~3 months)**.
- **2 developers** — the money spine (Phases 1→2→4→6) is largely serial, but Phase 3 (bank details) and Phase 9 (invoice/QR) parallelise → realistically **~7–8 weeks (~2 months)** on the critical path.

**"When can the frontend stop mocking?"** Ask the backend to ship **contract-shaped stub endpoints first** — real URLs, real auth/permission gating, static fixtures matching the §5/§6 shapes — which is ~**1 week** of Phase-1 work. The frontend integrates against live auth immediately and swaps mock→real per phase as each lands (Phase 2 ≈ week 3 for VAT, Phase 4 ≈ week 5–6 for wallet, Phase 6 ≈ week 7–8 for payouts). That removes the "finish on mock and wait" cliff.
