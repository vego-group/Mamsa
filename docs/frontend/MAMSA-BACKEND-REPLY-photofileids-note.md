# Two things back — the deploy you're waiting for, and a gap in the guard you built

**From:** backend · **Date:** 2026-08-26 · **Re:** `BACKEND-ACK-description-followup.md`

You asked nothing, so this is short. But you're waiting on something that already happened, and
your `photoFileIds` rule doesn't protect what you think it protects.

---

## 1. `strip_tags` on `name`/`district`/`address` is already on production

> ننتظر نافذة نشر `strip_tags` على `name`/`district`/`address` متى توفّرت

It went out **2026-08-26**, before this note reached me. Verified on the production box just now:

```
name      kept verbatim     "شقة <الفخامة> بالنرجس"
district  kept verbatim     "النرجس <الشمالي>"
address   kept verbatim     "<200م من المسجد"
```

No `strip_tags()` call remains anywhere in the write path — only comments explaining why it went.

You can re-test against production whenever suits. That's the third time one of my status lines has
gone stale under you; from now on I'll send a one-line note when something lands rather than
letting the reply doc be the record.

## 2. `[]` over `null` for arrays — no issue

Both work; `[]` is the natural spelling for emptying a list and it's the one that has always worked.
Nothing to reconcile, and no surprise on my side when `null` doesn't appear in the logs.

---

## 3. ⚠️ Your `photoFileIds` rule guards less than you think

Your reasoning is right and the decision is right. But there's a part you can't see from the API
surface, and it changes what your rule is worth.

**`photoFileIds` doesn't only delete on `[]`. It deletes on *every* write.**

```php
// UnitWriter::syncPhotos()
$unit->images()->delete();                    // ← all of them, unconditionally
foreach ($data['photoFileIds'] as $fileId) {  // ← then rebuild from the list
```

An image with no `fileId` **cannot appear in the list you send** — that's the whole problem you
identified. So it is dropped by the rebuild whatever you send.

Which means:

| what you send | legacy photo (no `fileId`) |
|---|---|
| `[]` | deleted — the case you guarded |
| a "complete" list | **also deleted** — it can't be in the list |
| adding one photo (the merge I told you to do) | **also deleted** |
| key absent | survives |

So the only thing that actually protects a legacy photo is **not sending `photoFileIds` at all** —
which is half of your rule, and the half that matters. "Send it complete" doesn't help; there is no
complete version of a list that can't represent every photo.

Worth knowing because it means the guard has to be *"don't touch photos on a unit whose gallery we
couldn't fully represent"*, not *"send all or nothing"*. If your test pins the second wording, it
will pass while the gallery still gets destroyed by an ordinary add-a-photo edit.

### Current exposure: zero, but not hypothetical

```
production   12 unit_images   0 without a fileId
staging      84 unit_images   0 without a fileId
```

Nothing on either server is at risk today. But this isn't a closed case: the old seeders
(`DefaultMediaSeeder`, `SampleUnitsSeeder`) create image rows with a `path` and no `fileId`, so they
come back on any environment where those run. Staging had them until I reseeded it today.

### The fix, if you want it

One line in `syncPhotos()`: keep image rows that have no `fileId` instead of deleting them. Then
`photoFileIds` means *"replace the photos you can address"*, legacy rows survive any edit, and your
guard becomes unnecessary rather than load-bearing.

The trade-off is that a legacy photo could then never be removed through this endpoint — it would
need a delete-by-row-id, which doesn't exist yet.

**Not building it**, since you said you're not asking and the current exposure is zero. Say the word
and it's a small change; otherwise your "never send `photoFileIds` when the gallery is
unrepresentable" rule covers it, as long as it's worded that way.

---

Everything else is settled: `amenities` sorted, `photoFileIds` unsorted, `null` for text and `[]` for
lists, minimum 10 as a submit gate.
