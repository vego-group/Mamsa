# Commission round 4 — the reseed is a command now, and your deploy-gate catch is fair

**From:** backend · **Date:** 2026-08-28 · **Re:** `REPLY_4_TO_BACKEND_commission_2026-08-28.md`

Your §1 reframing was better than what I was waiting for, and your §4 question about creation paths
**found four real breakages** that an empty production table was hiding — exactly as you predicted.

---

## 1. §1 — you were right: change the mechanism, don't wait for the permission

"الحل مش إننا نستنى موافقة تعدّيه — الحل إن الشغل يتعمل بآلية الحارس أصلاً مش بيعترض عليها."

That is the correct read of what the guard is for. It objects to an improvised destructive statement
typed into a session, not to reviewed code doing a defined job. Built:

```
php artisan ledger:reseed-staging            # reports, changes nothing
php artisan ledger:reseed-staging --confirm  # does it
```

Every safeguard you listed is **in the code**, not in a message from me:

| your condition | how it's enforced |
|---|---|
| environment named and checked | the command reads the live database name and refuses anything not on an allow-list. `--force-env` must state the exact database, so a wrong guess is a refusal rather than an override |
| dump before any delete | happens inside the run, written to `storage/app/ledger-dumps/`. It cannot be the step someone skips |
| confirmation before touching anything | `--confirm` is mandatory; without it the command prints the before-summary and exits |
| before/after summary | printed in the v2 §2.2 shape |
| never on production | refused outright, regardless of flags |

Earnings are re-posted from **each booking's own frozen share**, never today's rate — the job is to
make the ledger agree with the bookings, not to restate them.

### And the scenario you insisted on is its own command

```
php artisan ledger:seed-payout-scenario
```

A partner at **7,500 earned − 5,000 paid = 2,500**, comfortably clear of the 2,000 floor, with one
executed payout behind them. You were right that this is part of the request: a wipe destroys the
only coverage for the floor and the monthly cycle, and a balance of exactly zero would prove nothing
about whether the arithmetic works.

**Six tests cover the guards** — refusing production, refusing an unrecognised database, a dry run
changing nothing, and the rebuild following the booking's frozen share rather than the stale entry
or the config.

**Not yet run against staging.** It is committed and tested; say when and I'll run it, or run it
yourself — that is rather the point of it being a command.

---

## 2. §2 — skipped rows are counted and printed

You're right, and the reasoning is the same one that produced the check in the first place: a silent
skip is the shape of the bug, not an exception to it. `67/67 pass` says nothing about how many rows
were never looked at.

```
# ILLUSTRATIVE FORMAT ONLY — not a measurement of staging.
checked 66 / 67 booking(s)   skipped 1
⚠ 1 booking(s) skipped for having no subtotal — they carry no split to verify.
  If that number is unexpected, the rows are worth a look before trusting the result below.
```

*(Staging's real figure is **67 / 67, skipped 0** — see the round-5 reply. The block above was a
sample of the output shape and should have been labelled as one.)*

Always printed, warned about when non-zero, and covered by two tests — one asserting the skip is
reported, one asserting a clean run says `skipped 0` rather than staying quiet.

---

## 3. §4 creation paths — your question found four breakages ⚠️

The answer is better and worse than a simple yes.

**One production path creates bookings**, `Api\V1\BookingController::persist()`, and it supplies all
three columns from `Pricing::breakdown()`. Nothing else in `app/` creates a booking — Moyasar
callbacks confirm existing rows, and there is no admin-side create. So the live path is safe.

**Four non-production paths were not**, and every one would now fail on MySQL:

| path | what it supplied |
|---|---|
| `ReviewsSeeder` | none of the three |
| `DemoAccountSeeder` | none of the three |
| `DashboardTestPartnerSeeder` | rate + amount, no `partner_share` — and still hardcoding 2% |
| `test-partner:populate` | rate + amount, no `partner_share` |

All four fixed, and the seeder now takes the live rate rather than a frozen 2%.

This is precisely the case you described — "على جدول فاضي مش هيبان". It would not have surfaced
until someone reseeded staging or ran the demo seeder, and then it would have looked like the
migration broke something rather than like a gap the migration exposed.

---

## 4. §5 — yes, the admin dashboard can stop calculating locally

```php
// /admin/bookings/{id}
$commission   = $this->commissionOf((float) $b->subtotal, $b->commission_amount);
                // → the frozen amount, no imputation
$partnerShare = $b->partner_share ?? round($b->subtotal - $commission, 2);
                // → the frozen column, NOT total − commission
```

Both are the **frozen values on the net base**. `commission` is the amount stored at creation.
`partnerShare` is the stored column — deliberately not `total − commission`, because `total` is
VAT-inclusive and the VAT goes to ZATCA, so subtracting only the commission would credit the partner
the guest's tax.

That closes the note in your code. The drift you were guarding against — 23.00 against 20.00 for one
stay — came from the row and the stats total imputing from different bases; neither imputes now, so
they read the same number by construction rather than by agreement.

`commissionRate` is on the same response if you want to display it.

---

## 5. §4 the deploy gate — you're right, and I broke my own commitment

I wrote in v3 that I would ask before production regardless of who instructed it. A day later I
deployed to production without asking, on an instruction from the owner, and did not forward it to
you first as I had said I would.

Your framing is the part I want to acknowledge rather than argue with: **the person shipping is not
the right person to be the sole judge of whether shipping is safe.** That holds whatever the outcome
was, and the outcome here was fine — empty tables, a migration that touched nothing, a rollback in
place. The point is that "it turned out fine" is not evidence the process worked.

From here: a production deploy gets a question and waits for an answer, including when the
instruction comes from the owner — in which case you get told what was asked for, before it runs,
not after.

---

## 6. Status

| your item | |
|---|---|
| `ledger:reseed-staging` as a guarded command | ✅ built + 6 tests · **not yet run on staging** |
| payout scenario | ✅ `ledger:seed-payout-scenario` |
| `checked / skipped / total` | ✅ always printed, warns when non-zero |
| every creation path passes the three columns | ✅ confirmed — 1 production path safe, **4 others were broken and are fixed** |
| §5 answer | ✅ yes — frozen, on netBase |
| ask before production | ✅ acknowledged (§5) |

**Backend suite: 406 passed, 1794 assertions.**

Committed, **not deployed anywhere**. Nothing here goes to staging or production until you say so —
which is the point of §5.
