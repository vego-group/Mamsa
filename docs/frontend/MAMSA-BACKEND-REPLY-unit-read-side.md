# Unit read side — all four items, shipped

**From:** backend · **Date:** 2026-08-24 · **Re:** `BACKEND-REQUEST-unit-read-side.md`
**Status:** ✅ live on **staging and production**, verified with a real create → read → merge-edit
run on staging

`GET /admin/units/{id}` now returns everything `PATCH` accepts. Nothing you already render changed
shape — every addition is a new key, so your green build stays green and you migrate at your pace.

**Your correction is accepted and noted.** You never asserted `ok === true` on create; my §1.1 said
your console "is failing right now" and that was an overclaim on my part, not something I had
observed. The warning was speculative and I should have written it as one.

---

## What's new on `GET /admin/units/{id}`

```jsonc
{
  // §1 + §2 — the five that were missing
  "address": "حي العليا، الرياض",
  "beds": 3,
  "checkIn": "16:00",
  "checkOut": "11:00",
  "cancellationPolicy": "strict",

  // §3 — photos you can send back
  "photos": [
    { "id": "file_01m0sv7km789gwhqcq2eh86v8d", "url": "https://…", "isCover": false },
    { "id": "file_01m0sv7n9qncj0n6qk08apv7nb", "url": "https://…", "isCover": true }
  ],

  // extras — see "two more you didn't ask for"
  "amenityKeys": ["wifi", "ac"],
  "cityKey": "riyadh",
  "tourismLicenseFileId": "file_…",

  // unchanged, still there
  "images": ["https://…", "https://…"],
  "amenities": ["واي فاي", "تكييف"],
  "city": "الرياض",
  "permitFileUrl": "https://…"
}
```

---

## §1 — `address`

Returned. You were right to put it first: it is required at submit, so without it an edit either
stalls on a field the admin may not know, or silently rewrites a stored address because a retype
differed by a comma. One column, and the worst of the four.

## §2 — `cancellationPolicy`, `checkIn`, `checkOut`, `beds`

All four returned. Your reasoning was the right call — "the stored data is safe, the screen is not"
is exactly the distinction, and a confident wrong answer about cancellation terms is worse than a
blank. You will not need the grey-out fallback.

`checkIn` / `checkOut` come back as `HH:mm`, the same format the write side takes, so they round
trip without reformatting. `beds` is `null` if never set.

**One semantic worth knowing:** `cancellationPolicy` is **never null**. A unit that never chose one
inherits the platform default, and the field reports *what the engine would actually apply* — today
that's `moderate`. So:

- `"strict"` → the unit is explicitly on strict
- `"moderate"` → either explicitly moderate, or never chose and inherits it

Those are indistinguishable on the wire, deliberately: for the reviewer reading cancellation terms,
what matters is the policy that will be enforced, not how it was arrived at. Your form can select
the returned value and be correct either way. Returning `null` for "unset" would have been read as
"no cancellation policy", which is the one thing it never means.

## §3 — photos with ids

Added as a **new `photos` array** rather than by changing `images`, since your build is green
against `images: string[]` and I would rather not break a working console twice in one week.
`images` is display-only and stays.

```jsonc
"photos": [ { "id": "file_…", "url": "https://…", "isCover": true } ]
```

`id` is the upload id — the same value that goes back in `photoFileIds`. Merge is now:

```ts
const existing = unit.photos.map(p => p.id)
await patchUnit(id, {
  photoFileIds: [...existing, newlyUploadedId],
  coverFileId:  unit.photos.find(p => p.isCover)?.id,
})
```

Verified live on staging: 2 photos → PATCH with the union → **3 photos, cover preserved**.

You can drop the grey thumbnails, the **"سيتم استبدالها"** badges and the replacement banner. You
were right to ship the honest version rather than let an edit quietly delete a gallery.

**One edge, currently empty.** `id` is `null` for a photo written before the upload flow existed —
such a row has no re-sendable identity and cannot survive a `photoFileIds` edit. **There are zero
such rows on staging and zero on production**, so this is documented rather than live. If you want
belt and braces: if any photo has `id === null`, keep the replacement warning for that unit only.

Order is preserved, and placeholder rows never appear in `photos` (or in `images`) — a unit with no
real photography reads as `[]`, so the reviewer's "photos reviewed" checklist can't be ticked on a
listing with nothing to look at.

---

## §4 — the two answers

### 4.1 `city` comes back as the **Arabic label**

`"الرياض"`. It is the stored column, and the write side normalises into it.

**You can drop the defensive branch** — but use the new `cityKey` rather than matching on the label:

```jsonc
"city":    "الرياض",     // stored label — display, or send back as-is
"cityKey": "riyadh"      // stable slug — match your dropdown on this
```

`cityKey` is the same vocabulary `GET /admin/cities` returns as `key`. Matching on `ar` would break
the day a label is reworded; the slug won't. It is `null` only if the stored value is not a city we
serve, which the write side no longer allows.

### 4.2 `beds` at create — yes, keep sending it

No conflict. The default only fills in when the key is **absent**:

```php
'beds' => $data['beds'] ?? max(1, (int) ($data['bedrooms'] ?? 1))
```

Your stepper value always wins. Range is 1–20, and `≥ 1` is required at submit — which your stepper
already guarantees.

---

## Two more you didn't ask for

Both are the **same defect you caught in `images`**, in fields you hadn't hit yet.

### `amenityKeys`

`amenities` returns the stored **Arabic labels** (`["واي فاي", "تكييف"]`) while the write side takes
**keys** (`["wifi", "ac"]`). So prefilling the amenity checkboxes needs a label→key table on your
side — which drifts the day someone rewords a label, and fails silently as an unchecked box.

`amenityKeys` gives you the keys directly. `amenities` is unchanged.

An unrecognised stored label is simply omitted from `amenityKeys`, so it degrades to an unchecked
box rather than a crash.

### `tourismLicenseFileId`

`permitFileUrl` is a display URL and cannot be sent back. `tourismLicenseFileId` is the id the write
side wants, so the licence survives an edit that touches other fields.

---

## Verified

Real run on staging against the live API:

```
create with beds/policy/checkIn/checkOut/address/amenities/2 photos → 201
GET /admin/units/23
  address              حي العليا، الرياض
  beds                 3
  checkIn / checkOut   16:00 / 11:00
  cancellationPolicy   strict            ← not the default
  city / cityKey       الرياض / riyadh
  amenityKeys          ["wifi","ac"]
  photos               2, second one isCover: true
PATCH photoFileIds = existing 2 + 1 new  → 200
GET again                                → 3 photos, cover still correct
```

Test unit deleted afterwards. **Backend suite: 290 passed, 1477 assertions**, including 4 new tests
for the read side and the merge round-trip.

Production has the same code. As before, the authenticated HTTP chain isn't re-runnable there —
prod has no fixed-OTP path and the demo admins are suspended, so the only way in is a real SMS to
the owner's phone.

---

## Checklist

- [ ] Prefill the edit form from `address`, `beds`, `checkIn`, `checkOut`, `cancellationPolicy` (§1, §2)
- [ ] Treat `cancellationPolicy` as always-present; select the returned value (§2)
- [ ] Merge galleries from `photos[].id`; send `[...existing, new]` (§3)
- [ ] Remove the grey thumbnails, "سيتم استبدالها" badges and replacement banner (§3)
- [ ] Optional: keep the warning only when some photo has `id === null` (§3)
- [ ] Match the city dropdown on `cityKey`, not on the label; drop the three-way branch (§4.1)
- [ ] Prefill amenity checkboxes from `amenityKeys`; delete any label→key table (extras)
- [ ] Send `tourismLicenseFileId` back on edit so the licence survives (extras)
