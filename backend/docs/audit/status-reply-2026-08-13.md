# Mamsa — Backend Status Reply

**From:** backend · **Date:** 2026-08-13
**In reply to:** `MAMSA-FRONTEND-STATUS-AND-OUTSTANDING.md`
**Contract in force:** v2.2

**Most of §3–§6 was already delivered before your file was written** — the documents exist in the
shared Downloads folder and are listed by exact filename in §7 below, so nothing has to be chased by
path. Two items were genuinely new and are **now applied and verified**: the `local.mamsaa.com` CORS
origin, and confirmation of the SameSite state.

---

## 0. Your list → current state

| Your § | Item | State |
|---|---|---|
| §2 | **Do NOT apply `SameSite=Lax`** | ✅ **Already correct** — staging is `None`, prod is `Lax`. Nothing to undo (§1) |
| §2 | Add `https://local.mamsaa.com:3002` to staging CORS | ✅ **Applied + preflight-verified** (§2) |
| §2 | F.1 pin `SESSION_COOKIE`, report the value | ✅ **Applied — `mamsaa-session`**, identical both envs (§1) |
| §3 | Stub URLs / live table / error triggers / host table | ✅ **Sent** — `MAMSA-BACKEND-RESPONSE-STUB-ACCESS-AND-STAGING-ADMIN.md`; recap in §3 |
| §4.1 | `/wallet/ledger` paginated | ✅ **Done + deployed + cursor-walk verified** (§4) |
| §4.2 | `recentLedger` name | ✅ Confirmed, locked, unchanged |
| §5 | Production booking counts | ✅ **Sent** — `MAMSA-PRODUCTION-DATA-AUDIT-AND-TEST-RESULTS.md`; headline in §5 |
| §5 | Rename test-suite result | ✅ **`OK (102 tests, 752 assertions)`** (§5) |
| §6 | Gap analysis file | ✅ In Downloads as `MAMSA-BACKEND-CONTRACT-GAP-ANALYSIS.md` (§6) |

---

## 1. Session state — no change needed, and F.1 answered

Your correction arrived before any harm was done. **Current live state is exactly what §2 asks for:**

| | Staging | Production |
|---|---|---|
| `SESSION_SAME_SITE` | **`none`** ← left as-is, per your correction | **`lax`** ← unchanged |
| `SESSION_COOKIE` | **`mamsaa-session`** | **`mamsaa-session`** |
| `SESSION_DOMAIN` | `staging.mamsaa.com` (host-only) | `api.mamsaa.com` (host-only, **not** widened) |
| `SESSION_SECURE_COOKIE` | true | true |

**F.1 answer — the pinned value is `mamsaa-session`**, set explicitly in both environments so it no
longer derives from `APP_NAME`. That silent drift (`mamsaa-session` vs `mamsa-session`) can no longer
recur on promotion.

For the record on sequencing: staging was briefly aligned to `lax` yesterday, then returned to `none`
on your `REQUEST-staging-samesite-none.md`, which is where it has stayed. So step 1 of your three-step
plan is already the live state, and **step 3 will not be applied until you confirm the
`local.mamsaa.com` origin works and tell us to drop the `localhost` entry.**

---

## 2. New CORS origin — applied and verified

`https://local.mamsaa.com:3002` is now on the staging allowlist, **with `http://localhost:3002`
retained** as you asked.

Verified by preflight against the live server (note: this is server-response evidence, which is the
part that actually matters — a `curl` cookie walk would prove nothing here since `curl` does not
enforce CORS):

| Origin sent | `Access-Control-Allow-Origin` | Credentials |
|---|---|---|
| `https://local.mamsaa.com:3002` | `https://local.mamsaa.com:3002` | `true` |
| `http://localhost:3002` | `http://localhost:3002` | `true` |
| `https://evil.example.com` (control) | **absent — refused** | — |

`CORS_SUPPORTS_CREDENTIALS=true`; staging `/up` → 200. Tell us when to drop `localhost:3002` and we
will remove it and align both environments to `lax` in the same change.

---

## 3. Stub access — recap (full details in the sent file)

All eleven endpoints are **live on `https://staging.mamsaa.com`**, cookie session,
`credentials: 'include'`, and **all are inert on production**. Your existing credentials work:
superadmin `+966555000003`, finance `+966555000004`, OTP `<fixed OTP — request privately>`.

| # | Method | URL | Kind |
|---|---|---|---|
| 1 | GET | `https://staging.mamsaa.com/admin/me` | **real** |
| 2 | GET | `https://staging.mamsaa.com/admin/payouts/eligible` | stub |
| 2 | GET | `https://staging.mamsaa.com/admin/payouts/ineligible` | stub |
| 3 | POST | `https://staging.mamsaa.com/admin/payouts/record` | stub |
| 4 | GET | `https://staging.mamsaa.com/wallet` | stub |
| 4 | GET | `https://staging.mamsaa.com/wallet/ledger?limit=&before=` | stub, paginated |
| 5 | GET / PUT | `https://staging.mamsaa.com/me/bank-details` | stub |
| 6 | GET | `https://staging.mamsaa.com/admin/wallets` | stub |
| 6 | GET | `https://staging.mamsaa.com/admin/wallets/{partnerId}` | stub |
| 6 | GET | `https://staging.mamsaa.com/admin/wallets/{partnerId}/ledger?limit=&before=` | stub, paginated |

**Error triggers** (`POST /admin/payouts/record`; `bankReference` must be 4–64 chars):

| Send | Result |
|---|---|
| `bankReference: "DUP-REF-0001"` | 409 `DUPLICATE_BANK_REFERENCE` |
| `partnerId: "prt_paid"` | 409 `ALREADY_PAID_THIS_MONTH` |
| `partnerId: "prt_ineligible"` | 409 `NOT_ELIGIBLE` |
| anything else | 200 `{ ok, payoutId, reference }` |

Body `amount` / `iban` are **silently ignored** — verified by posting `{"amount":99999,"iban":"HACK"}`
with a valid payload and getting a clean success that used neither.

**Per-environment hosts — stated plainly:**

| Surface | Staging host | Production host | Prefix |
|---|---|---|---|
| Guest site | `https://staging.mamsaa.com` | `https://api.mamsaa.com` | `/api/v1` |
| Partner dashboard | `https://staging.mamsaa.com` | `https://api.mamsaa.com` | **root** |
| Admin panel | `https://staging.mamsaa.com` | `https://api.mamsaa.com` | `/admin/*` |

**Yes — on staging, `GET /wallet` and `GET /admin/wallets` sit on the same host, distinguished only by
path.** Only the host changes between environments; never the path or prefix.

---

## 4. Ledger pagination — done before your file arrived

Both endpoints now return the cursor envelope and accept `?limit=` (default 20, max 100) and
`?before=` (ISO-8601 `createdAt`):

```jsonc
{ "items": [ /* PartnerLedgerEntry[] — newest first */ ],
  "hasMore": true,
  "nextCursor": "2026-08-06T09:00:00+03:00" }
```

Verified by walking the cursor on staging: `?limit=2` → `ple_04, ple_03` + a `nextCursor`; passing
that cursor back → `ple_02, ple_01`. The entries still sum exactly to `availableBalance` (4310.75), so
the reconciliation property survives pagination. `recentLedger` remains a bounded preview, unpaginated,
as you specified.

---

## 5. Production counts + test results — the two merge-day blockers

Both are cleared. Full detail in `MAMSA-PRODUCTION-DATA-AUDIT-AND-TEST-RESULTS.md`; headline:

**The contradiction resolved in the unexpected direction — production has ZERO `pending` bookings.**

| status | count |
|---|---|
| `completed` | 56 |
| `cancelled` | 13 |
| **`pending`** | **0** |
| **total** | **69** |

- **9 payments carry a real Moyasar id — 15,909.05 SAR actually charged.**
- **External paying guests: zero.** All 6 accounts that ever booked are internal: the owner, an
  `@mamsaa.sa` account, a `user@mamsa.test` account, a `@vego.sa` engineer (the development company),
  and two seeded no-email accounts.

**Effect on §5's escalation question:** the load-bearing premise of contract §10.1 holds — no external
guest has seen or relied on a price, so the VAT-inclusive decision does **not** go back to the owner.
But the sentence "every booking is demo data" should be reworded, because real money did settle
through Moyasar and an auditor will see it. A drop-in replacement paragraph is in §3 of the audit file.

**Test suite:** **`OK (102 tests, 752 assertions)`** — full suite, green.

The route there matters: a **filtered** run was green, but the **full** suite caught a real bug — on
SQLite, `enum()` emits a `CHECK` constraint that the MySQL-only ALTER never updated, so booking
creation returned **500**. Fixed (SQLite rebuilds the column as a plain string; MySQL keeps the real
enum as source of truth). It also cleared a pre-existing gap where SQLite's constraint never included
`completed`. Your insistence on seeing results before deploy is what caught it.

**Merge-day consequence:** because production has no `pending` rows, the migration **converts zero
rows** — it changes the column definition only. The earlier warning about a one-way data change on real
records does not apply. It remains a production DDL change and will not run without your explicit go.

---

## 6. The gap analysis — it is in the shared folder

`MAMSA-BACKEND-CONTRACT-GAP-ANALYSIS.md` — 277 lines, dated 2026-08-12, in the same Downloads folder
as every other document in this exchange (that is the only channel available from here; nothing can be
attached to a message directly). It contains **all 11 items of §9 verbatim** and **§12 in full**
(per-phase dev-days, dependency order, parallelism, calendar translation).

The seven you have not read, by number: **§9.2** (auth framing — Spatie already exists; the real gap is
BFF authz), **§9.4** (`PriceBreakdown` casing per surface — `/api/v1` is snake_case), **§9.6**
(`PartnerStatus` is `approved` + `is_active`, not a single field), **§9.7** (bank details — a frontend
limitation, not storage), **§9.8** (concurrency + immutability mechanism), **§9.9** (mamsa-owned units
must skip wallet writes), **§9.10** (three error envelopes, not one).

If the file still does not reach you, say so and its full text will be pasted inline in the next
message rather than referenced.

---

## 7. Files delivered (all in the shared Downloads folder)

| File | Covers |
|---|---|
| `MAMSA-BACKEND-CONTRACT-GAP-ANALYSIS.md` | §9 all 11 items, §12 effort/sequencing |
| `MAMSA-BACKEND-RESPONSE-STUB-ACCESS-AND-STAGING-ADMIN.md` | stub URLs, credentials, triggers, host table |
| `MAMSA-FRONTEND-STUB-WIRING-CLAUDE.md` | endpoint shapes + wiring guide (live-access block at top) |
| `MAMSA-PRODUCTION-DATA-AUDIT-AND-TEST-RESULTS.md` | production counts, VAT §10.1 wording, test results |
| `MAMSA-FRONTEND-PENDING-PAYMENT-RENAME.md` | the rename (corrected: §2/§6 contradiction closed) |

---

## 8. Open on the backend side

| Item | Waiting on |
|---|---|
| `pending_payment` rename deploy | **Your merge day** |
| Unifying the `/api/v1` count keys | **Vue admin lifespan** — under ~3 months: leave them; ~a year: fix Vue and unify in a follow-up PR |
| `SESSION_SAME_SITE=lax` on staging + dropping `localhost:3002` | Your confirmation that `local.mamsaa.com:3002` works |
| Deploying the wallet tables + stubs beyond staging | Nothing — they are staging-only by design and inert on production |
