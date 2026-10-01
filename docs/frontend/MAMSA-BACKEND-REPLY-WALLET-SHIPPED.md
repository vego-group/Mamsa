# Backend reply — Wallet, Payouts & Bank Details: shipped

**From:** backend · **Date:** 2026-08-15
**In reply to:** `NEXTJS-DASHBOARD-WALLET.md`
**Status:** all six endpoints **live on staging AND production** · the §0 CORS issue **fixed** ·
your three questions answered in §1 · **§5 is a gap you need to know about before you demo `/wallet/payouts`**

The 404s are gone. `GET /wallet` — the one causing a visible error in production — is live and
database-backed, not fixtures.

---

## 1. Your three questions

### (a) VAT basis — confirmed, and **reports is the side that's wrong**

The payout engine uses **your preferred basis**, exactly as written in your §5:

```
netBase      = gross / 1.15
commission   = netBase × 0.02
partnerShare = netBase − commission     ← what the wallet pays
```

VAT is remitted to ZATCA and was never the partner's money, so it cannot be in a payout.

**You were right to ask, because `/reports/summary` currently disagrees.** Two defects there, both
real:

1. Its commission **fallback** computes `total_amount × 0.02` — commission on **gross**, which
   overstates it by 15% on any booking without a frozen `commission_amount`.
2. `netProfit = gross − commission` — which hands the partner the **VAT as profit**.

On one booking of gross 3450:

| | wallet (correct) | reports (today) |
|---|---|---|
| commission | 60.00 | 69.00 |
| partner's net | **2940.00** | **3381.00** |

A partner comparing the two screens sees a 441 SAR difference on a single booking. **I have not
changed reports** — `netProfit` is a live field on a screen you own, and silently changing what it
means is worse than the disagreement. Confirm and it is a small change, aligning to the wallet basis
as you proposed (and probably renaming `netProfit` → `partnerShare` at the same time, which was
already on our list).

### (b) `minPayoutAmount` — yes, 2000 SAR and server-owned

Served from config, tunable per environment via `WALLET_MIN_PAYOUT`, so moving the threshold never
needs a frontend release. The client should keep rendering whatever the field says.

### (c) Suspended partner — **200 + `ineligibleReason`**, as you preferred

No 403. A suspended partner still sees their balance; only eligibility changes. Verified by test.

---

## 2. What is live

| Endpoint | Notes |
|---|---|
| `GET /wallet` | summary + eligibility |
| `GET /wallet/ledger?limit=&before=` | bare array, newest first, cursor on `createdAt` |
| `GET /payouts?limit=` | bare array, newest `paidAt` first |
| `GET /payouts/{id}` | + the bookings the transfer was made of |
| `GET /me/bank-details` | **200 + literal `null`** when never saved |
| `PUT /me/bank-details` | validates, derives bank, resets verification |

Real staging response, a partner with 50 backfilled stays:

```jsonc
{"availableBalance":87800,"pendingBalance":766.96,"lifetimeEarnings":87800,"lifetimePaidOut":0,
 "currency":"SAR","minPayoutAmount":2000,"payoutEligible":false,"ineligibleReason":"bank_missing",
 "paidThisMonth":false,"bankVerified":false,"lastPayoutAt":null,"lastPayoutAmount":null}
```

```jsonc
// GET /wallet/ledger
{"id":"led_48","type":"earning","amount":1350,"balanceAfter":87800,"refType":"booking",
 "refId":"b_44","refCode":"44","description":"حصتك من الحجز 44 — شقة مودرن بإطلالة على الواجهة",
 "createdAt":"2026-07-29T21:00:00Z"}
```

**Your §5 invariants are enforced, not just intended.** Balances are written by one service, in a
row-locked transaction that appends the ledger row and moves the wallet by the same amount — they
cannot drift apart. Checked across every partner on staging after the backfill:

```
partner 4  available=87,800.00  newestBalanceAfter=87,800.00  lifetime=87,800.00  sumEarnings=87,800.00
partner 5  available=100,190.00 newestBalanceAfter=100,190.00 lifetime=100,190.00 sumEarnings=100,190.00
partner 9  available=5,659.50   newestBalanceAfter=5,659.50   lifetime=5,659.50   sumEarnings=5,659.50
ALL INVARIANTS HOLD
```

---

## 3. Deviations from the spec — three, all deliberate

**3.1 `ineligibleReason` evaluation order.** Your table lists `below_minimum` first, but your §7 also
wants a suspended partner to see `partner_suspended`. Under the table's order a suspended partner with
a small balance would be told "earn X more" — advice that cannot help them, because earning more
changes nothing while they are suspended. Order used:

```
partner_suspended → negative_balance → bank_missing → bank_unverified → below_minimum
```

Blocking conditions outrank arithmetic ones. (Your own TS union lists them in a third order, so the
document was not self-consistent here.)

**3.2 `refId` is prefixed** — `b_41` for bookings, `po_7` for payouts, matching the prefixed-ID
convention. `GET /payouts/{id}` accepts the id with or without the `po_`.

**3.3 `GET /me/bank-details` returns the literal bytes `null`**, not `{}`. Laravel's default
JSON helper serialises a null body as an empty object, which your client would read as "an account
with blank fields" rather than "no account". Pinned by a test on the raw response body.

---

## 4. The §0 CORS side issue — fixed

You were right about the cause and it was worth reporting: a path with no route never reached the CORS
middleware, so the 404 came back bare and the browser blocked it — a missing endpoint presenting as an
infrastructure fault.

Verified on production just now, against a route that deliberately **does not exist**:

```
OPTIONS /wallet          → 204 + access-control-allow-origin: https://partner.mamsaa.com
OPTIONS /does-not-exist  → 204 + access-control-allow-origin: https://partner.mamsaa.com
```

Done by widening `paths` to `*`. That is not a widening of access — `allowed_origins` is the control
and remains an explicit allowlist. From now on a missing endpoint reads as a plain, readable 404.

---

## 5. ⚠️ `/payouts` will be empty on production — nothing can create a payout yet

This is the one thing that will surprise you, so it is not buried.

The partner-side **read** endpoints are complete. But the **write** side — finance recording an
executed transfer — is still a fixture stub (`/admin/payouts/record`), and stubs are gated out of
production. So on production today:

- `GET /payouts` → `[]`, permanently, until that is built
- `wallet.lifetimePaidOut` → `0`
- `wallet.lastPayoutAt` / `lastPayoutAmount` → `null`
- `paidThisMonth` → `false`

Earnings accumulate correctly and the balance grows; nothing ever leaves it. **`/wallet/payouts` will
render an empty state rather than an error** — correct behaviour, but not what a demo wants.

The remaining work is the admin payout run: select eligible partners, record the transfer, write the
`payout` ledger debit and link the covered bookings. The partner-side contract you specified does not
change when it lands — the same endpoints start returning rows. **Tell us if you want that
prioritised** and it is the next thing we build.

---

## 6. Bank names — the map needs finance to verify it

`bankName` is derived server-side from the IBAN's SAMA bank code, as you asked, and an **unknown code
returns `null`** rather than a guess. A neutral state is harmless; a *wrong* bank name against a
partner's payout account is not — they would reasonably think the money is going elsewhere.

**Only code `80` (مصرف الراجحي) is confirmed** — from the example IBAN in your own spec. The other
nine entries are the commonly published mapping and have **not** been checked against SAMA's register.
Finance should verify them. Correcting the map is a config edit, not a deploy.

---

## 7. Backfill — stays that finished before the wallet existed

Without it, a partner with completed bookings would open a zero-balance wallet while `/reports` showed
the revenue those same stays earned.

| Env | Credited |
|---|---|
| staging | **50 bookings · 193,649.50 SAR** |
| production | 0 bookings (no completed stays yet) |

Entries are dated at the stay's **checkout**, so the ledger reads in the order the money was actually
earned. Re-running is safe — crediting is idempotent per booking.

Related fix: the nightly `bookings:complete` used a mass `UPDATE`, which fires no model events. Left
as-is, **every finished stay would have gone unpaid**. It now saves rows individually.

---

## 8. `NEXT_PUBLIC_ENABLE_BANK_DETAILS` — you can flip it on

The endpoint exists on both environments, so the flag is no longer gating anything real.

Worth knowing before you do: bank details now apply to **both** account types, as you specified. The
IBAN written here is also written through to `partner_details.iban`, so the admin KYC screen and
`documentsComplete()` keep working unchanged — an individual saving an IBAN here now counts toward
their KYC completeness, which it never could before.

IBANs are validated by **ISO 7064 mod-97**, not just shape: a single mistyped digit keeps the shape and
fails the checksum, and that is exactly the case that would otherwise send a transfer into the void.
Rejected as `422 INVALID_IBAN` with `error.fields.iban`.

Any change of account number resets `verified` to false server-side, as you specified. Re-saving the
**same** IBAN with a different holder name keeps verification — only the account number invalidates it.

---

## 9. Deploy state — 2026-08-15, ~16:15 UTC

| | staging | production |
|---|---|---|
| `GET /wallet` | ✅ live | ✅ live |
| `GET /wallet/ledger` | ✅ live | ✅ live |
| `GET /payouts` · `GET /payouts/{id}` | ✅ live | ✅ live (empty — §5) |
| `GET`/`PUT /me/bank-details` | ✅ live | ✅ live |
| CORS on unmatched routes | ✅ | ✅ |
| Earnings backfill | ✅ run | ✅ run (no-op) |
| Fixture stubs | ❌ removed | ❌ removed |

Suite: **169 passed, 987 assertions**, including the four §5 invariants, payout ownership returning
404 rather than 403, and the IBAN checksum cases.

**Open on our side:** the admin payout run (§5), and your call on aligning `/reports/summary` to the
wallet basis (§1a).
