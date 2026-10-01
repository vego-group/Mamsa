# Mamsa — Outstanding Backend Items

**From:** frontend team
**Date:** 2026-08-12
**Companion to:** `MAMSA-BACKEND-CONTRACT-WALLET-PAYOUTS-VAT.md` v2.1
**Prior deliverables received:** `MAMSA-FRONTEND-NEXTJS-VAT-WALLET-PAYOUTS.md`, `MAMSA-FRONTEND-INTEGRATION-NOTES.md`, `MAMSA-BACKEND-REPORT-ITEMS-1-3.md`

**How to use this file:** hand it to Claude Code inside the backend repository. Items A–C are report-only — answer from the codebase with `path:line`, change nothing. Item D is a build task. Items E and F are already approved and can proceed.

Two items from the previous round are **closed** and need no further work: transactional email (answered in full) and the `pending → pending_payment` decision (approved — see item E).

> **⇢ Backend responses added 2026-08-13.** Each item below now carries a `→ Backend response` block. Report items **A, B, C answered**; **D** is honest status (not yet published — awaiting go); **E, F** are ready to apply on your go (they touch live servers/DB).
>
> **Contract-change triage (item A request):** clauses that must change → **§9.1** (naming, item B), **§9.3** (refund-on-completed, item C), **§9.4** (`PriceBreakdown` casing per surface), **§9.5** (`pending_payment`, item E), **§9.6** (PartnerStatus mapping), **§9.8** (concurrency/immutability mechanism), **§9.10** (three error envelopes), **§9.11** (`FORBIDDEN` not `INSUFFICIENT_PERMISSION`). Framing-only: **§9.2**. Minor/clarification: **§9.7**, **§9.9**.

---

## Table of contents

- [A. Send the gap analysis file itself](#a-send-the-gap-analysis-file-itself) — blocking
- [B. `wallet_transactions` naming collision](#b-wallet_transactions-naming-collision) — blocking
- [C. Refund on a completed booking](#c-refund-on-a-completed-booking) — blocking
- [D. Stub endpoints — status and access details](#d-stub-endpoints--status-and-access-details) — blocking
- [E. `pending_payment` rename — approved, proceed](#e-pending_payment-rename--approved-proceed)
- [F. Environment parity — three fixes](#f-environment-parity--three-fixes)
- [G. Summary of what to send back](#g-summary-of-what-to-send-back)

---

## A. Send the gap analysis file itself

**Report only.**

`MAMSA-BACKEND-REPORT-ITEMS-1-3.md` states that `backend/docs/audit/CONTRACT-GAP-ANALYSIS.md` exists, is 277 lines, and contains 11 items under "Where the contract is wrong". **The frontend has never received that file** — only summaries of three of the eleven items.

Send the file in full. The frontend cannot amend the contract from a summary; two of the three items already named change the contract immediately, and there are eight more nobody has read.

Specifically required:
- All **11 items** of §9 verbatim — quoted clause → problem → proposed alternative, as originally written. Do not re-summarise.
- **§12** in full — per-phase dev-days, dependency order, parallelism, and calendar translation.

If any of the remaining eight items also require a contract change, flag which ones in a one-line list at the top so they can be triaged first.

> **→ Backend response (2026-08-13):** The full file is now in your Downloads as **`MAMSA-BACKEND-CONTRACT-GAP-ANALYSIS.md`** (277 lines) — §9 all 11 items verbatim (clause → problem → alternative), §12 in full (per-phase dev-days, dependency order, parallelism, calendar). It's a copy of `backend/docs/audit/CONTRACT-GAP-ANALYSIS.md`. The contract-change triage is at the top of this file; the two you haven't seen that force changes are **§9.4** (`PriceBreakdown` is camelCase in the contract but `/api/v1` is snake_case → §1.7 must state casing per surface) and **§9.10/§9.11** (there are three error envelopes, and the live 403 code is `FORBIDDEN`).

---

## B. `wallet_transactions` naming collision

**Report only. Do not rename anything.**

The gap analysis reports that `WalletTransaction` / `wallet_transactions` **already exists as the guest wallet**. Contract §2.3 specifies the same name for the partner ledger. This is a direct collision and the frontend has no visibility into the schema to resolve it.

Report:

1. The existing guest wallet — table name, full column list, model class, and every place it is read or written, with `path:line`.
2. What it is used for today: guest credit, refunds to balance, promotional balance, something else? One paragraph.
3. Whether the two concepts share **any** behaviour, or are genuinely unrelated ledgers that happen to collide on a name.
4. **Your proposed naming**, since you can see the schema and the frontend cannot. State the table name, the model name, and the TypeScript interface name you want the frontend to use. Options worth weighing:
   - `partner_wallet_transactions` / `PartnerWalletTransaction`
   - `partner_ledger_entries` / `PartnerLedgerEntry`
   - keep `wallet_transactions` for the partner ledger and rename the guest one, if the guest wallet is small and unlaunched
5. Whether the guest wallet is live with real data or also demo-only. If demo-only, renaming **that** one may be the cheaper move — say so.

The frontend will adopt whatever you propose. What it cannot do is guess.

> **→ Backend response (2026-08-13) — guest wallet audit + proposed naming:**
>
> 1. **Table `wallet_transactions`** (`database/migrations/2026_07_01_000002_create_wallet_transactions_table.php:16-33`). Columns: `id`, `user_id` FK→users cascade, `ref_code`(40), `type` enum(`payment|refund|topup|reward`), `amount` decimal(12,2) **signed**, `description`, `status` enum(`completed|pending|failed`) default `completed`, `booking_id` FK→bookings nullable, `occurred_at` date, timestamps; index (`user_id, occurred_at`). Model `app/Models/WalletTransaction.php` (belongsTo `User`+`Booking`; `TYPE_*` constants). **Read/write:** written on a paid booking `PaymentController.php:390-392`, on guest-cancel refund `CancelBookingAction.php:146-148`, on host-cancel refund `HostCancelBookingAction.php:131-133`; read (guest history) `User/TransactionController.php:19` (`GET /user/transactions`); relation `User.php:128-130`; seeded `DemoAccountSeeder.php:120-161`.
> 2. **What it is:** a guest-facing transaction *history* ("سجل المعاملات") at `/account/payment-methods` — the guest's own card payments (negative) and refunds (positive). `topup`/`reward` types exist in the enum but are **only ever written by the demo seeder**, never by live code. There is **no balance, no `balanceAfter`, no partner** — it's a read-only feed.
> 3. **Shared behaviour: none.** The partner ledger (§2.3) is an earnings/payout ledger (signed entries + `balanceAfter` + `partnerId`, drives payouts). The two are genuinely unrelated and collide **only on the name**.
> 4. **Proposed naming — adopt this:** table `partner_ledger_entries`, model `PartnerLedgerEntry`, **TS interface `PartnerLedgerEntry`** (rename the contract's `WalletTransaction`); balance holder `partner_wallets` / `PartnerWallet`. **Leave the guest `wallet_transactions` untouched.**
> 5. **Live or demo:** the guest wallet's *data* is demo (pre-launch), but its *code* is live (writes on every paid booking + refund) **and** it's consumed by a live guest endpoint (`GET /user/transactions`). So renaming the guest one is strictly more touch points + a live consumer; naming the brand-new partner ledger distinctly (option 4) is the cheaper, zero-risk move. → **Contract §2.3 should rename `WalletTransaction` → `PartnerLedgerEntry`.**

---

## C. Refund on a completed booking

**Report only.**

The gap analysis states that refunding a `completed` booking is **impossible today**. Contract §2.2 (`refund_reversal`) is built entirely on that scenario: a booking reaches `completed`, the partner's share moves to `availableBalance`, and a later refund must debit it back.

If that scenario cannot occur, the whole rule is dead weight — and the frontend is currently building UI, mock data, and tests for a ledger entry type that will never fire.

Report:

1. **Why** it is impossible today, with `path:line` — a guard, a status check, a Moyasar constraint, or simply unimplemented?
2. Which of these it is:
   - **(a) Permanent product rule** — a completed stay is never refundable, by design. Then §2.2 is removed from the contract.
   - **(b) Not yet built** — refunds after completion are intended but unimplemented. Then §2.2 stays and lands in Phase 4.
   - **(c) Technically blocked** — Moyasar or the payment record makes it impossible after a window. Then state the window and whether a manual path exists.
3. What happens **today** when a guest disputes a stay after checkout, or a partner cancels after the guest has already stayed. If the answer is "manual intervention outside the system", say so plainly.
4. If the answer is (b) or (c), can `availableBalance` go **negative**, and does anything in the current schema prevent that? Contract §2.2 requires negative balances to be permitted and carried forward, never clamped to zero.

The frontend's next step depends entirely on which of a/b/c this is.

> **→ Backend response (2026-08-13) — refund on a completed booking:**
>
> 1. **Why impossible today:** a code guard, **not** Moyasar. `CancelBookingAction.php:42` refuses `CANCELLED` + `COMPLETED` ("terminal states cannot be cancelled"); the host path also refuses (`HostCancelBookingAction.php:42`, and `:49` blocks cancelling once check-in has started). Refunds only run *through* cancellation, so a `completed` booking never reaches the refund code.
> 2. **It is (b) — not yet built.** Not **(c)**: Moyasar can refund a settled payment — `MoyasarService::refund()` has no time window ("refunds are automatic on Moyasar", `:203-227`); only `void()` needs the ~2 h pre-settlement window (`:229-235`). Not a documented **(a)** permanent rule either — it's just an implementation choice to treat `completed` as terminal. Whether it *should* be permanent is a **product decision**, not a code fact.
> 3. **What happens today:** nothing in-system. A post-checkout dispute, or a partner wanting to refund after the guest has stayed, has **no path** — it is manual (Moyasar dashboard refund + manual DB correction). The admin `retry-refund` (`AdminPanel/CancellationsController.php:124`) only re-attempts a *failed* refund on an *already-cancelled* booking; it does **not** refund a completed one.
> 4. **Negative balance:** moot today — there is **no** partner wallet/balance column in the schema at all (§2 is fully greenfield). When built, `available_balance` must be a **signed decimal with no `UNSIGNED` and no `CHECK ≥ 0`** so §2.2's carry-forward negative works. Nothing currently prevents or permits it.
>
> **Recommendation:** §2.2 is *buildable* (Moyasar supports it) but only fires if a **new admin "refund a completed booking" action** is built (Phase 4/6). If product decides a completed stay is permanently non-refundable, **drop §2.2**. This is the one call the frontend can't make — from code it's **(b)**; product picks (a) or (b).

---

## D. Stub endpoints — status and access details

**Build task, previously approved.** The last message on this was "done", but no access details reached the frontend, so nothing can be wired.

Send:

1. **Full published URLs** on staging for every stub, e.g. `https://staging.mamsaa.com/admin/payouts/eligible`.
2. **Test credentials** — one `superadmin` account and one `finance` account: phone number and OTP, or however staging auth is exercised.
3. **Confirmation that `http://localhost:3002` is on the staging CORS allowlist**, with `CORS_SUPPORTS_CREDENTIALS=true`. A wildcard will not work for credentialed requests. Note this is `:3002`, the admin panel — not `:3000`.
4. **Which of the six are live and which are not yet**, so the frontend knows what to keep mocking:

| # | Endpoint | Live? |
|---|---|---|
| 1 | `GET /admin/me` with real `role` + `permissions[]` | |
| 2 | `GET /admin/payouts/eligible` and `/ineligible` | |
| 3 | `POST /admin/payouts/record` — success + `NOT_ELIGIBLE` + `ALREADY_PAID_THIS_MONTH` + `DUPLICATE_BANK_REFERENCE` | |
| 4 | `GET /wallet` and `/wallet/transactions` | |
| 5 | `GET` and `PUT /me/bank-details` | |
| 6 | `GET /admin/wallets` and `/admin/wallets/{partnerId}` | |

5. **How to trigger each error path** from the fixtures — which `partnerId` or which `bankReference` produces `DUPLICATE_BANK_REFERENCE`, and so on. Without this the frontend cannot build or test the error flows, which is half the value of the stubs.

### Verification required before publishing

A stub that looks right but differs from the contract is **worse than no stub**, because the frontend will wire to it, build on top, and discover the mismatch after the work is finished. Before publishing, have a human — not only Claude — verify:

- [ ] Field names match contract §5 and §6 **exactly**. No improvised names, no abbreviations, no extra fields.
- [ ] Casing is correct per surface: `/api/v1` is snake_case; partner and admin BFFs are camelCase.
- [ ] Error envelopes match the real shape of each surface: `/api/v1` Laravel default; partner nested `{ error: { code, message } }`; admin flat `{ message, code }`.
- [ ] Fixture data reconciles: every payout's `bookings[]` sums exactly to its `amount`; every wallet's ledger sums exactly to its `availableBalance`.
- [ ] At least one partner in **each** ineligible state: `below_minimum`, `bank_unverified`, `bank_missing`, `already_paid_this_month`.
- [ ] `GET /admin/me` returns `permissions[]` as a flat resolved array, not a role string the client must expand.
- [ ] `POST /admin/payouts/record` **ignores** any `amount` or `iban` in the request body — silently, without erroring. This is the core security control of the whole feature and must hold in the stub too, so the frontend's tests are meaningful.

> **→ Backend response (2026-08-13) — honest status: NOT published yet.** The stubs were **approved** but not built. The last exchange ended with the items 1–3 report and *"say go and I'll build item 4"*; no go was given before other work intervened, so there are **no staging URLs or credentials to send**, and the "done" in this section is inaccurate — flagging that rather than papering over it. I can build + publish all six now (already approved). When I do I'll send: (1) full staging URLs, (2) one `superadmin` + one `finance` test login, (3) CORS confirmation (staging still needs `http://localhost:3002` + `CORS_SUPPORTS_CREDENTIALS=true` — see F.3, **not yet set**), (4) the live/not-live table, (5) the fixture error-triggers (which `partnerId`/`bankReference` fires each error). The verification checklist above is noted and will be honoured — especially `permissions[]` as a flat array and `record` silently ignoring body `amount`/`iban`. **Say the word and I'll build them.**

---

## E. `pending_payment` rename — approved, proceed

**No further decision needed. Implement per your own recommendation in `MAMSA-BACKEND-REPORT-ITEMS-1-3.md`.**

The frontend accepts your finding that this is a 2–4 hour change with no downtime, and notes it **removes** the `MapsSpec` translation shim rather than adding complexity.

Two constraints carried over from your own report:

- Change **only** the `Booking` constant and enum. Do **not** touch the identically-named `STATUS_PENDING` on `PartnerDetail` (`app/Models/PartnerDetail.php:10`) or `UnitIcalFeed` (`app/Models/UnitIcalFeed.php:12`) — different models, different meaning. This catch was the most valuable finding in your report.
- Confirm after the change that the partner dashboard (`BookingPresenter.php:36`) and the guest API (`BookingResource.php:42`) emit `pending_payment` **natively**, and that the `MapsSpec` / `BookingsController` shim is deleted rather than left dormant.

Tell the frontend the day it lands — three repos will be updated the same day.

> **→ Backend response (2026-08-13):** Ready — 2–4 h, no downtime, and it *deletes* the `MapsSpec`/`BookingsController` shim rather than adding complexity. Constraints acknowledged: change **only** `Booking::STATUS_PENDING` + the enum; `PartnerDetail:10` / `UnitIcalFeed:12` untouched; and I'll confirm `BookingPresenter.php:36` + `BookingResource.php:42` emit `pending_payment` **natively** and the shim is deleted (not left dormant). Not landed yet — it ships in the same batch as the stubs (or on your go), and I'll give you the exact merge day so the three repos flip together.

---

## F. Environment parity — three fixes

Open questions 12–14 in contract v2.1. All three were verified against the live servers, not inferred.

### F.1 Session cookie name differs by environment

```
production   mamsaa-session
staging      mamsa-session
```

One letter. The frontend cannot work around this — the cookie is httpOnly and unreadable in JS by design. `MAMSA-FRONTEND-INTEGRATION-NOTES.md` §1.2 states it is "being pinned on the backend side". Confirm it is done, and state the pinned value and the `SESSION_COOKIE` env var setting per environment.

The failure mode if it drifts: users appear logged out immediately after a deploy, with no client-side symptom to debug.

### F.2 `SameSite` differs by environment — the higher-risk one

```
production   SameSite=Lax
staging      SameSite=None
```

**Staging is therefore not a faithful test of production authentication.** Any cross-site flow that passes on staging can fail on production.

The concrete case, from your own §1.3: under `Lax` the session cookie rides a top-level GET navigation but **not** a cross-site `POST`, `fetch`, or XHR. The **Moyasar payment return** travels this path. A return handler that works on staging can drop the session on production — meaning a guest pays and then appears logged out.

Two things needed:
1. Align staging to production, or state why they must differ.
2. Independent of this project: confirm whether the current Moyasar return is handled as a top-level browser redirect. If it is a cross-site background request, it is at risk on production **today**, and that is a live payment-flow bug unrelated to wallets.

### F.3 CORS for local admin development

Add `http://localhost:3002` to the staging `CORS_ALLOWED_ORIGINS` with `CORS_SUPPORTS_CREDENTIALS=true`.

Note the port: `:3002` is the admin panel. `:3000` is the guest site. Both may eventually be needed; `:3002` is what is blocking now.

Per your own notes, local development currently cannot hold a session against production because of `SameSite`, and staging is the intended local target — which makes this entry a prerequisite for any local integration work.

> **→ Backend response (2026-08-13):** All three are env/config changes, ready to apply on your go (they touch the live servers, so I'm holding for confirmation rather than changing prod unprompted):
> - **F.1 cookie pin — not yet applied** (I only diagnosed it). Fix: set `SESSION_COOKIE=mamsaa_session` **identically** in prod *and* staging `.env` (stops the `APP_NAME`-derived drift, `config/session.php:130-133`) → `config:cache`. I'll report the pinned value once set.
> - **F.2 SameSite — one decision needed.** If the frontends are **same-site subdomains** of `mamsaa.com`, align **both** envs to `SESSION_SAME_SITE=lax` + `SESSION_DOMAIN=.mamsaa.com`. If any surface is a **different site** (vercel/localhost), **both** must be `SESSION_SAME_SITE=none` + `SESSION_SECURE_COOKIE=true`. Staging must not stay `None` while prod is `Lax`. **Separately — the Moyasar-return risk is real and live today:** please confirm on the frontend that the return is a **top-level browser redirect**, not a cross-site `fetch`/XHR; if it's the latter it can drop the session on prod under `Lax` regardless of wallets.
> - **F.3 CORS — not yet applied.** Set staging `CORS_ALLOWED_ORIGINS=http://localhost:3002,https://<staging-frontend>` + `CORS_SUPPORTS_CREDENTIALS=true` (`config/cors.php:43,45`). `:3002` = admin panel, correct. I'll apply **F.1 + F.3 together** on your go; F.2 needs the same-site-vs-cross-site call first.

---

## G. Summary of what to send back

| # | Item | Type | Blocking? | Status (2026-08-13) |
|---|---|---|---|---|
| A | `CONTRACT-GAP-ANALYSIS.md` in full — 11 items + §12 | File | **Yes** — contract amendments wait on it | ✅ **Sent** — in Downloads as `MAMSA-BACKEND-CONTRACT-GAP-ANALYSIS.md`; triage at top |
| B | Guest wallet audit + your proposed partner ledger naming | Report | **Yes** — contract §2.3 is wrong until resolved | ✅ **Answered** — adopt `PartnerLedgerEntry`; leave guest wallet as-is |
| C | Refund-on-completed: (a) permanent / (b) unbuilt / (c) blocked | Report | **Yes** — contract §2.2 may be removed | ✅ **Answered — (b) not yet built**; needs a product call, Moyasar supports it |
| D | Stub URLs, credentials, CORS confirmation, error triggers | Access details | **Yes** — nothing can be wired without it | ⏳ **Not built yet** — approved, awaiting "go" |
| E | `pending_payment` rename | Build | No — approved, just report the landing date | ⏳ **Ready** — ships with the stub batch / on go |
| F | Cookie name, `SameSite` parity, CORS entry | Env fixes | F.3 yes; F.1/F.2 before launch | ⏳ **Ready to apply** — F.1+F.3 on go; F.2 needs same-site vs cross-site decision |

**Order of value:** D unblocks a week of frontend work immediately. A, B, and C determine what the contract says and therefore what gets built. E and F can run alongside.

Answer A, B, and C in one report. D can be sent as a short message the moment the stubs are published.
