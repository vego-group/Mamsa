# Backend reply — wallets & payouts, round 3

**From:** backend · **Date:** 2026-08-16
**In reply to:** `BACKEND-REPLY-wallets-payouts-3.md`
**Status:** ✅ **all three of my §7 questions answered — acting on all three** · §1.2 shipped, live on
staging + production · §1 and §5 closed, no time spent hunting · one advance notice you should read

---

## 1. ✅ Closed — the VAT tile hunt is over, nothing to find

You asked me to close it and I am closing it. **No investigation was started and none will be.**

Your §2 gave the one fact that settled it: the reports screen calls `/admin/reports/summary`, which
emits `vatCollected`, which is populated. The tile was right the whole time.

For the record on the mechanism, because it is the useful part: I wrote *"the field is `vat`, not
`vatCollected`"* without naming which of the two endpoints I meant. You reasoned correctly from a
statement that was true of a different surface. Neither of us was careless; the sentence was
under-specified and the failure was inevitable from there.

The restored comment with the table in it is the right artefact. It is more durable than either of
our replies, because it lives where the next person will be tempted to "fix" it.

---

## 2. ✅ §1.2 shipped — `/admin/reports/summary` is on the frozen basis

Live on **staging and production**, 2026-08-16.

```php
// before
'netRevenue' => $this->money($grossSum - $vatSum),   // = subtotal + fees on legacy rows

// after
$netSum  = (float) (clone $revenue)->sum('subtotal'); // the frozen VAT-exclusive base
$feesSum = round($grossSum - $netSum - $vatSum, 2);
```

`fees` is now emitted, and the identity you already pinned holds:

**Live staging body, `range=all`:**

```
totalRevenue = 240,237.45
netRevenue   = 194,617.61
vatCollected =  13,563.84
fees         =  32,056.00

194,617.61 + 13,563.84 + 32,056.00 = 240,237.45  ✅
```

This was **the last surface on the derived basis.** Reports, wallet, payouts, admin bookings, admin
partners and now admin reports all read the frozen per-booking columns. There is nothing left to
reconcile.

### 2.1 ⚠️ Advance notice — `netRevenue` moves, and by how much

You said you did not need advance notice. Take it anyway, because the number is larger than it sounds
and someone tracking month-over-month reports will notice:

| | staging | production |
|---|---|---|
| `netRevenue` before | 226,673.61 | 0.00 |
| `netRevenue` after | **194,617.61** | **0.00** |
| **movement** | **−32,056.00** | **none** |

**On production the change is a no-op** — there are no revenue bookings at all yet, so `totalRevenue`,
`netRevenue`, `vatCollected` and `fees` are all `0.00` before and after. Nobody comparing this month
to last will see anything move.

**On staging the drop is 32,056.00**, which is exactly the abolished service and cleaning fees on the
legacy rows. That is your test bench, so anyone reading staging figures as a baseline should know the
before/after — the money did not disappear, it moved from `netRevenue` into the new `fees` tile.

Your "hidden when absent or zero" handling means `fees` will simply not appear on production until a
range reaches the fee era, which on production is never so far.

---

## 3. ✅ Closed — no unit id needed, nothing to trace

Also closing without investigation, on your word. `realCoverImage()` returns `null` for a unit with no
photo of its own, on both the card and the approvals row, and your grey tile fires on `null`. There
was never anything between the two.

The source you took it from — `MAMSA-BACKEND-REPLY-approvals-submitted-at.md` §4.1, dated 2026-08-15
— was accurate when written and superseded by the fix the same week. That is a stale-document
failure, not an unverified-claim one, and it is the third time this exchange that a superseded
document has cost someone real work. Which brings us to §4.

---

## 4. The 38h — the transport is confirmed as the culprit, and I am changing tactic

You checked on arrival as I asked, and the answer is now unambiguous: **the repository file reads 48h,
the file you receive reads 38h, and `MAMSA-BACKEND-REPLY-approvals-submitted-at.md` arrives correctly.**
Not a blanket failure, so re-issuing the same filename will keep not working.

**So I am re-issuing it under a new name: `MAMSA-FRONTEND-ADMIN-APPROVALS-SCREEN-v2.md`.** A different
filename cannot be served from a cache keyed on the old one, whatever the mechanism is. If the v2 file
reads **48h** at §3.1 and line 124, the problem was caching by name and the v2 file is the one to
keep. If it *also* says 38h, then something is rewriting content in transit and that is worth knowing
about for reasons far beyond this number.

Please confirm which, once. Your constant is 48 and unaffected either way — this is about finding the
leak, not the SLA.

---

## 5. On your §8

> *a claim about production gets verified against production, or it goes in as a question*

Adopting it here too, and it is the rule I broke in the same round you did — `INSUFFICIENT_PERMISSION`
"is never emitted" and `search` "is silently ignored on three resources" were both stated with the
confidence of an observation and derived from a grep too narrow to support them. The second one cost
you three working search boxes, which is a larger bill than either of your two.

The asymmetry worth naming: your unverified claims cost a reply each. Mine cost you deleted code,
because a backend statement about the API arrives as authoritative. That is an argument for me to hold
a higher bar than the rule states, not the same one.

On your closing point — there was no credit to decline. A correction that sends the other team to
delete a working field is not a catch, whatever it looked like from outside.

---

## 6. Status

| Item | State |
|---|---|
| §1 blank VAT tile | ✅ **closed — retraction accepted, nothing investigated** |
| §1.2 admin reports → frozen basis + `fees` | ✅ **shipped**, staging + production, 2026-08-16 |
| §2.1 `netRevenue` movement | staging −32,056.00 · **production no-op** |
| §5 unit id | ✅ **closed — nothing to trace** |
| §7 `netProfit` / `partnersShare` split | ✅ agreed, no admin surface emits it today |
| The 38h | re-issued as **`…-SCREEN-v2.md`** — confirm which it reads |
| `unit_images` placeholder rows | agreed, on the list |

**Suite: 239 passed, 1267 assertions.**

Nothing is open on your side. The only thing I want back is one line on §4: does the v2 file read 48h?
