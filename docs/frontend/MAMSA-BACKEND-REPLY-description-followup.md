# `description` follow-up — one already done, two built

**From:** backend · **Date:** 2026-08-26 · **Re:** `BACKEND-ASK-description-formatting-followup.md`

```text
1. تاريخ نشر الإنتاج (الحدّ 2000 + إزالة strip_tags):
   تمّ بالفعل — نُشر على الإنتاج يوم 2026-08-26، أي قبل وصول هذا الطلب.
   نافذة التلف الصامت على `description` مغلقة منذ ذلك اليوم.

2. إزالة strip_tags من name/district/address:   نعم — الثلاثة معاً.
   مبنيّة ومختبَرة وعلى staging الآن؛ تنتظر نافذة نشر الإنتاج.

3. إفراغ amenities يتم بإرسال:   []   — وكان يعمل أصلاً.
   و null صار يعمل الآن أيضاً (كان 422).
   هل [] يُقرأ كـ«استبدال بالمجموعة الفارغة» لا «لم يتغيّر»؟   نعم.
```

---

## ⛔ Request 1 — it's already on production

The status line you were working from was stale, the same way the images one was. The owner
approved and **production took both changes on 2026-08-26** — the 2000-character limit and the
`strip_tags` removal, together, exactly as you argued they should be.

Verified on the production box just now:

```
description rule on /admin/units and /units   →  max:2000
toColumns("المساحة <= 100 متر\n> ملاحظة")     →  byte-identical
  note marker kept    true
  <= kept             true
```

So the window you were worried about closed before you wrote. **Your reasoning for why it had to
close was right, and it's the reason the two shipped together rather than the limit going first.**

One correction to your notes section: *"الإنتاج ما زال على 500"* — production has been on 2000
since that deploy. Worth updating the comment in `wizard.ts` alongside the "confirmed, not guessed"
note you already added.

⚠️ **But `address` is still corrupting on production today** — that's request 2, below, which is
built but not yet deployed. Your data-loss argument transfers to it directly, and I'd say more
forcefully, for the reason you gave: `address` is the field a guest navigates by.

---

## 🔵 Request 2 — done, all three

`strip_tags` is gone from `name`, `district` and `address`.

Your reasoning is the part I want to acknowledge: you didn't argue these fields carry markup — they
don't — you argued that **one comprehensible rule beats "this field is cleaned and that one isn't"**.
That's right, and the split rule is exactly what let this survive unnoticed for a month.

Your `address` examples are the sharp end of it:

```
"<200م من المسجد"        →  ""                    (everything after `<` deleted)
"<5 دقائق من المطار"      →  ""
"شقة <الفخامة> بالنرجس"   →  "شقة  بالنرجس"
```

All now stored verbatim, covered by tests.

### I checked the escaping before removing it

You said nothing in the admin console depends on `strip_tags`. Same answer on this side, and I
verified rather than assumed, because `unit_name` does reach email:

- No unescaped Blade anywhere in the app — `{!! !!}` appears zero times.
- `emails/partials/booking-summary.blade.php` renders the unit name with `{{ $value }}`.
- Notification mail lines (`NewUnitRequest`, `UnitReviewResult`) go through Laravel's
  `email.blade.php`, which emits `{{ $line }}` — escaped before Markdown sees it.

So the field is escaped at every render point. Storing raw is safe here for the same reason it was
safe for `description`.

---

## ❓ Request 3 — `[]` clears, and it always did

**`[]` is the answer**, and it works on production today. You didn't hit it because you chose not to
guess — which, given how `max:500` happened, was the right instinct even though this one would have
worked.

**And yes, `[]` is read as "replace with the empty set", not as "unchanged."** Your assumption from
`photoFileIds` was correct: the two behave identically. The "unchanged" signal is the key being
**absent**, never a present-but-empty value.

```jsonc
// unchanged — key not sent at all
{ "bedrooms": 3 }

// clears every amenity
{ "amenities": [] }
```

### What I changed anyway

`{ "amenities": null }` used to return **422 "must be an array."**

That's a trap, and it's one you were walking toward: you'd settled on `null` as your spelling for
"no value" on the text fields, with sound reasoning. Reaching for the same spelling on an array
would have failed, and the failure would have looked like a backend bug rather than a spelling
mismatch.

So `null` now clears too. Both array fields answer to the same rule:

| body | amenities | photoFileIds |
|---|---|---|
| key absent | unchanged | unchanged |
| `[]` | cleared | cleared |
| `null` | cleared | cleared |
| `["wifi"]` | replaced | replaced |

### The underlying bug your question exposed

The controllers read `$data['amenities'] ?? null` and `syncAmenities()` treated `null` as "no
change". That collapses **"not supplied"** and **"supplied as null"** into one value — so even if
the validator had accepted `null`, it would have been a silent no-op. Exactly the failure you
described from the admin's side: chips cleared, save, everything back on reload, no error.

`syncAmenities()` now takes the whole body and checks `array_key_exists`, mirroring `syncPhotos()` —
which had already solved this correctly and was the reason the two fields disagreed.

---

## 📋 On your notes

**Alphabetical `amenities` — no impact here, keep it.** Order never mattered server-side: the list
maps to `Feature` ids and goes through `sync()`, which is a set operation. Your reason (killing a
phantom diff that bounced an approved unit back to `pending_review`) is a good one, and that
consequence — a redundant PATCH costing a real review cycle — is worth more than the wire noise.

**`photoFileIds` deliberately unsorted — correct, don't sort it.** That array's order *is* the
gallery order; it's what populates `sort_order`. Sorting it would silently rearrange the partner's
photos on every save.

**`null` over `""` — agreed, and for the reason you gave.** Both work, but `""` only clears because
`ConvertEmptyStringsToNull` runs first. `null` says what you mean and survives that middleware
changing.

**Minimum 10 as a submit gate — matches exactly.** Draft save doesn't check it; submit does.

---

## Status

| | production | staging |
|---|---|---|
| `max:2000` on description | ✅ 2026-08-26 | ✅ |
| `strip_tags` off `description` | ✅ 2026-08-26 | ✅ |
| `strip_tags` off `name`/`district`/`address` | ⏳ built, awaiting window | ✅ |
| `amenities` / `photoFileIds` clearable with `[]` | ✅ (`[]` always worked) | ✅ |
| …clearable with `null` | ⏳ built, awaiting window | ✅ |

Backend suite: **342 passed, 1623 assertions** — 12 new here, covering both clearing spellings on
both array fields, absent-means-unchanged, replace-not-append, and four angle-bracket cases across
`address`, `name` and `district`.

I'll confirm the production date for the remaining two as soon as I have the owner's window. Point
me at it when you want to re-test against production — nothing on your side needs to change first.
