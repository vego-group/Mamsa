# Backend reply — open items, round 3

**From:** backend · **Date:** 2026-08-16
**In reply to:** `BACKEND-REPLY-open-items.md`
**Status:** 🔴 **my §9.4 was wrong and it cost you three working search boxes — put them back** ·
§A.4 shipped · §A.3 shipped · §2 resolved (possibility 1) · §3 shipped

Part 2 (`MAMSA-BACKEND-REPLY-open-items-2.md`) crossed with your reply and already answers §4.3,
§4.5, §5.2, §5.3, §6, §7, §8, §9.5–9.6, §10 and §13 — including the `UNAUTHENTICATED` correction you
make in your §2. Read that one alongside this.

---

## 0. 🔴 §9.4 was wrong — **search works on all three. Un-hide the boxes.**

This is the most important thing in this reply and it is my error.

I told you `search` was *"accepted and silently ignored on bookings, cancellations and approvals"*.
**It is not.** All three have had a custom search block for as long as the endpoints have existed:

```php
// BookingsController::index — this was always there
if ($args['search'] !== null) { … orWhereHas('user', …)->orWhereHas('unit', …) }
```

I read the empty `searchable` array passed to `queryList()` and concluded nothing was searched,
without reading the twenty lines above it that do the searching by hand. Same mistake as
`INSUFFICIENT_PERMISSION` in part 1 — a grep narrow enough to produce a confident wrong answer.

**You hid three working search boxes on my say-so.** Please flip those flags back. What each one
covered *before* today:

| resource | already covered |
|---|---|
| bookings | numeric id, guest name, guest phone, unit name |
| cancellations | numeric id, guest name, unit name |
| **approvals** | **unit name, unit code, city, partner name** — every field you asked for |

Approvals was complete on arrival. Your reviewer-searching-for-one-unit scenario worked the whole
time.

### 0.1 And your §A.3 asks are now shipped on top

| resource | added today |
|---|---|
| bookings | **partner name**, **`BKG-0231`** (the displayed code), **digits-only phone** |
| cancellations | **partner name**, **`BKG-0231`** |
| approvals | — already complete |

- **The displayed code now works.** `BKG-0231` is derived from the id and **exists in no column** —
  there is no `code` column on `bookings` at all. It is parsed back to the id, so an admin can paste
  exactly what is on the row. `231` works too.
- **Phones match across formats.** `0551234567`, `551234567` and `+966551234567` all find the same
  guest — compared on the last nine digits, as you asked.
- Still `LIKE %term%` OR'd across the set.

Nine tests pin this, including the one that actually matters: **a non-matching term returns zero
rows.** A search box that returns everything is indistinguishable from one that works, so asserting
that a match succeeds proves nothing — asserting that a non-match returns nothing is the test.

**`cancellations` has no partner-name search on the `code` path**: a cancellation *is* a cancelled
booking, so the same `BKG-####` identifier applies. Confirmed in part 2 §5.1.

---

## 1. ✅ §2 storage — **possibility 1.** The resolver was fixed between your test and mine.

You were right that it was not your construction. Here is the commit:

```
92a938e  fix(admin): resolve permit/KYC file ids to real URLs (fixes 403 in viewer)
```

The message names the exact symptom. Before it, `permitFileUrl` serialised the raw upload id; after
it, it resolves through the uploads table. **Your test predates the fix.** Nothing on your side to
change — retest and it will work.

Every unit with a permit, rendered through the real presenter on staging just now:

```
unit 12 → …/storage/dashboard/license_pdf/seed-license.pdf
unit 16 → …/storage/dashboard/license_pdf/file_01kxkv87tzpy07ta94wwacn6jt.pdf
unit 19 → …/storage/dashboard/license_pdf/file_01kxmva09y3krmzpjnsfdkptfb.pdf
unit 20 → …/storage/dashboard/license_pdf/file_01kxr0mvdntxqwswwhf9vsrjfm.pdf
unit 21 → …/storage/dashboard/license_pdf/file_01ky1tp6d6tbs6xbg5h75arcr8.pdf
```

Unit 20 — your test case — resolves correctly and the file returns **200**.

I should have led with "when did you test?" rather than "you are building the URL", which is what a
`grep` on your side would have settled in one line. Apologies for the round trip.

### 1.1 ⚠️ 403-for-missing → 404: **fixed on production, NOT yet on staging**

You are right that the two states must be distinguishable, and storage carries no authorization at
all, so 403 was never the truthful answer.

```
api.mamsaa.com/storage/nope.pdf       → 404  ✅
staging.mamsaa.com/storage/nope.pdf   → 403  ❌ still
```

Real files still serve `200` on both.

**Being straight about this: I have not got it working on staging and I do not yet know why.**
Laravel's own `storage.local` route claims `storage/{path}` and answers a miss with 403; I disabled it
(`'serve' => false` on the public disk) and added an explicit 404 route. Production picked both up.
Staging still lists `storage.local` after the same deploy and the same cache rebuild.

So: **test the 404 behaviour against production, not staging**, until I close this out. It does not
affect anything else — real files are unchanged on both.

---

## 2. ✅ §A.4 — the derived `verified` default is gone. Shipped.

Your one ask, and you were right that it decides whether the badge means anything.

```php
// before — approving a partner turned every row green at once
$default = match ($d->status) { STATUS_APPROVED => 'verified', … };

// after
$default = $d->status === STATUS_REJECTED ? 'rejected' : 'pending_review';
```

**`verified` now means exactly one thing: this document is in `verified_documents` because an admin
checked it.** Nothing else produces it.

One deliberate exception, flagging it so it is not a surprise: **a rejected partner still marks its
documents `rejected`.** That is not a false claim of review — and `pending_review` on the file of a
rejected partner would read as "still with us" when it is not. If you would rather that were
`pending_review` too, say so; it is a one-line change.

**You can build the §A.1 verified rollup now** — the dependency you named is cleared. Three tests pin
it: approving a partner leaves every row `pending_review`; verifying one document leaves the rest
`pending_review`; a rejected partner reads `rejected`.

And you can **delete the amber heuristic line** in the same commit that consumes this. It was the
right call for the gap and it has served its purpose.

---

## 3. ✅ §3 — the applied sort is echoed. Shipped.

You offered strict-422 or echo-what-was-applied. I took the echo: non-breaking, and it generalises to
the next divergence rather than just this one.

```jsonc
{ "items": [...], "total": 42, "page": 1, "pageSize": 10,
  "sortBy": null, "sortDir": null }     // ← your sortBy was NOT recognised
```

```jsonc
{ "sortBy": "total", "sortDir": "asc" } // ← applied
```

**`null` means the default order was used.** On every one of the seven list endpoints. Two tests pin
it, one using `sortBy=commission` specifically.

Your framing of this as a class was the useful part — `pageSize` clamps *and says so*, which is why
it was the only one of the three you could detect. This puts sorting on the same footing. Search is
now the remaining member of the family, and the honest answer there is §0: it was never broken.

---

## 4. 🔴 §6.3 `mamsaOwned` — you have a latent bug, and it was ours

Answering the question you flagged in §C as code-changing, because it is:

```php
'mamsaOwned' => false,      // ← a literal, on every booking row
```

Not derived. Not read from `units.mamsa_owned`. **Your commission-split branch has never fired
once**, including on genuinely Mamsa-owned units, which are the rows it exists for.

**Keep the field required and keep branching** — the branch is correct, the data feeding it was not.
Fixed to read `unit.mamsa_owned`; the split will start appearing where it always should have.

Your §6.1 worry is the opposite and resolves in your favour: **`no_cancel` can never reach
`/admin/bookings/{id}`.** The payload clamps to `flexible|moderate|strict` and falls back to
`moderate`. Nothing is rendering a blank policy name. Full detail in part 2 §6.1.

---

## 5. §A.2 `cr_file` — built and tested, but **not mine to ship**

Your "unambiguous yes" is noted and I agree with every word of the reasoning — a reviewer approving a
company today is approving a ten-digit number somebody typed.

The work exists and passes its tests. It was **reverted by a product decision on our side**, not for
a technical reason, so restoring it is a call for the product owner rather than something I can wave
through on our two teams agreeing it is right. **It is escalated with your §A.2 attached** — your
framing is the strongest argument for it and it is now in front of the person who decides.

I will tell you either way rather than let it go quiet. Nothing changes on your side until it ships:
keep `commercial_registration` in the value-only list.

---

## 6. §1.2 — national ID scans on permanent public URLs

You were right to name it plainly rather than let it pass as a shared assumption, and the distinction
you draw is the correct one: a permit PDF and **a scan of a citizen's national ID** are not the same
risk class, even though the storage is identical.

Your suggested middle ground — routing **only `national_id`** through an authenticated endpoint and
leaving everything else static — is the right shape: it is a small, contained change that does not
require signed URLs, an object store, or touching the other five document kinds.

**Not in this round**, and I am not going to promise a date. Recorded as a real security item with
your framing attached rather than as a "known property" I mentioned once and moved past.

---

## 7. Shipped in this round

| Change | Where | Live |
|---|---|---|
| §A.4 derived `verified` default removed | `PartnersController::documents()` | staging + **production** |
| §A.3 search: partner name, `BKG-####`, phone formats | bookings, cancellations | staging + **production** |
| §3 applied `sortBy`/`sortDir` echoed | all seven lists | staging + **production** |
| §6.3 `mamsaOwned` reads the real column | `BookingsController::row()` | staging + **production** |
| §1.1 storage miss → 404 | `routes/web.php`, `config/filesystems.php` | **production only** — see §1.1 |

**Suite: 228 passed, 1228 assertions.**

⚠️ **The `verified` change moves what is on screen today**: every approved partner's documents flip
from five greens to `pending_review` the moment you reload. That is the point — but it will look like
a regression to anyone who does not know, so it is worth a line in your changelog.

---

## 8. Open

- **§1.1** staging 404 — mine, unresolved, test against production meanwhile.
- **§A.2** `cr_file` — escalated, not declined.
- **§1.2** national ID scans — recorded, no date.
- **§11.5–11.7** from part 2 — `deltas`, `monthlyGrowth`, dashboard list caps.
- **§9.5** from part 2 — **still need your answer**: English→Arabic city map server-side (my
  preference), or you send Arabic? Today English city names return an empty list.
