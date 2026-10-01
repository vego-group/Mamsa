# Editing a unit — implementation guide (Next.js)

**From:** backend · **Date:** 2026-08-24 · **For:** the Next.js **admin console**
**Companion to:** `MAMSA-BACKEND-REPLY-unit-read-side.md` (the contract). This is the work on your
side.
**Backend status:** live on **staging and production**.

Everything is additive — no key changed shape, so nothing you render today breaks and this can land
as one PR whenever you like. Four things get **deleted** as a result, which is most of the value.

| Delete | Because |
|---|---|
| the default-value fallbacks (`'moderate'`, `'15:00'`, `'12:00'`, `beds` from `bedrooms`) | the real values are readable now |
| the grey thumbnails + "سيتم استبدالها" badges + replacement banner | an edit can merge |
| the Arabic-label → amenity-key lookup table | `amenityKeys` is returned |
| the three-way city match on `key` / `en` / `ar` | `cityKey` is returned |

---

## 1. Types

```ts
// src/lib/types/units.ts
export type UnitPhoto = {
  /** The upload id — send back in `photoFileIds`. Null for a pre-upload-flow row. */
  id: string | null
  url: string
  isCover: boolean
}

export type UnitDetail = {
  // …everything you already have, unchanged…
  images: string[]            // display-only, still here, not deprecated

  // new
  address: string | null
  beds: number | null
  checkIn: string | null              // "16:00"
  checkOut: string | null             // "11:00"
  cancellationPolicy: CancellationPolicyKey   // never null — see §4
  tourismLicenseFileId: FileId | null
  photos: UnitPhoto[]
  amenityKeys: AmenityKey[]
  cityKey: string | null              // "riyadh"
}
```

---

## 2. Prefill from the record, not from defaults

This is the whole point of the change. Replace every fallback with the returned value.

```ts
// ✗ before — states a default as though it were the unit's value
const form = {
  cancellationPolicy: 'moderate',
  checkIn:  '15:00',
  checkOut: '12:00',
  beds:     unit.bedrooms,
  address:  '',
}

// ✓ after
function toForm(unit: UnitDetail): UnitWriteBody {
  return {
    name:                 unit.name,
    type:                 unit.type,
    city:                 unit.cityKey ?? unit.city,
    district:             unit.district,
    pricePerNight:        unit.pricePerNight,
    bedrooms:             unit.bedrooms,
    bathrooms:            unit.bathrooms,
    capacity:             unit.capacity,
    sizeSqm:              unit.sizeSqm,
    beds:                 unit.beds ?? undefined,
    description:          unit.description,
    address:              unit.address ?? '',
    lat:                  unit.lat,
    lng:                  unit.lng,
    checkIn:              unit.checkIn ?? undefined,
    checkOut:             unit.checkOut ?? undefined,
    cancellationPolicy:   unit.cancellationPolicy,
    amenities:            unit.amenityKeys,
    tourismLicenseNumber: unit.tourismPermitNo ?? undefined,
    tourismLicenseFileId: unit.tourismLicenseFileId ?? undefined,
    photoFileIds:         unit.photos.map(p => p.id).filter((x): x is string => !!x),
    coverFileId:          unit.photos.find(p => p.isCover)?.id ?? undefined,
  }
}
```

### Re-baseline the diff — this is the one that bites

Your `PATCH` sends only dirty fields by diffing against what you loaded. That still works, but the
baseline must now be the **server's** values, and it must be **reset after every successful save**.

`PATCH` returns the full updated unit, so use it — do not refetch:

```ts
const [baseline, setBaseline] = useState<UnitWriteBody>(() => toForm(unit))
const [form, setForm]         = useState<UnitWriteBody>(baseline)

async function save() {
  const dirty = diff(baseline, form)          // your existing helper
  if (Object.keys(dirty).length === 0) return

  const updated = await patchUnit(unit.id, dirty)
  setBaseline(toForm(updated))                // ← reset from the response
  setForm(toForm(updated))
}
```

Skipping the reset leaves the baseline holding pre-save values, so the next save re-sends fields
that are already correct. Harmless in isolation — but on an **approved** unit every save bounces it
back to `pending_review` (§6), so a redundant PATCH is a real cost.

---

## 3. Photos — merge instead of replace

```ts
// ✗ before — sends only the new one, deleting the rest
await patchUnit(id, { photoFileIds: [newId] })

// ✓ after
const existing = unit.photos.map(p => p.id).filter((x): x is string => !!x)

await patchUnit(id, {
  photoFileIds: [...existing, newId],                  // order is preserved
  coverFileId:  unit.photos.find(p => p.isCover)?.id ?? existing[0],
})
```

Removing a photo is the same call with that id filtered out — `photoFileIds` is still authoritative,
which is what makes removal work at all.

Verified end to end on staging: 2 photos → PATCH with the union → 3 photos, cover unchanged.

### Now delete the warning UI

The grey thumbnails, the **"سيتم استبدالها"** badges and the replacement banner all come out. You
were right to ship them rather than let an edit quietly destroy a gallery — they just aren't true
any more.

### The one case where the warning should survive

`id` is `null` for a photo written before the upload flow existed. Such a photo has no re-sendable
identity: it cannot be carried through a `photoFileIds` edit and will be dropped.

**There are zero such rows on staging and zero on production**, so this is a guard, not a live
problem. If you want it airtight:

```ts
const unmergeable = unit.photos.some(p => p.id === null)
// keep the replacement banner for this unit only when `unmergeable`
```

If you'd rather not carry the branch, skipping it is defensible — just don't assume `id` is a
`string` in the type.

### Cover

`coverFileId` must be one of `photoFileIds` or the write is refused (`422`,
`fields.coverFileId`). Send it explicitly on every photo edit; if you omit it the first photo in the
list becomes the cover, which silently moves the cover when the admin only meant to append.

Placeholder rows never appear in `photos` or `images`, so a unit with no real photography reads as
`[]` on both.

---

## 4. `cancellationPolicy` is never null

A unit that never chose one inherits the platform default, and the field reports **what the engine
would actually apply** — today `moderate`.

```ts
// no fallback needed, and no "unset" state to render
<PolicySelect value={unit.cancellationPolicy} />
```

`"moderate"` means either "explicitly moderate" or "never chose, inherits moderate". Those are
deliberately indistinguishable: what a reviewer needs is the policy that will be enforced. Don't
build an "inherited" badge — there's nothing on the wire to drive it, and inventing one from a null
check would be wrong now that null never appears.

---

## 5. Amenities — delete the lookup table

```ts
// ✗ before — breaks silently as an unchecked box the day a label is reworded
const keys = unit.amenities.map(ar => ARABIC_TO_KEY[ar]).filter(Boolean)

// ✓ after
const keys = unit.amenityKeys
```

`amenities` still returns the Arabic labels and is fine for read-only display. `amenityKeys` is what
the form and the `PATCH` body want.

A stored label the platform no longer recognises is simply omitted from `amenityKeys` — it degrades
to an unchecked box, not a crash. If an admin then saves, that amenity is dropped, which is the
correct outcome for a label that is no longer part of the vocabulary.

---

## 6. City — match on `cityKey`

```ts
// ✗ before
const city = cities.find(c => c.key === unit.city || c.en === unit.city || c.ar === unit.city)

// ✓ after
const city = cities.find(c => c.key === unit.cityKey)
```

`city` remains the stored Arabic label (`"الرياض"`) and is what to display when you want the stored
form. `cityKey` (`"riyadh"`) is the stable slug and the same vocabulary `GET /admin/cities` returns.

Match on the slug, not the label: matching on `ar` breaks the day a label is reworded, and it fails
as "city not found" rather than as an error.

Send `cityKey` back in the write body — the endpoint accepts slug, English or Arabic, so either
works, but the slug is the one that can't drift.

---

## 7. Editing an approved unit

Unchanged rules, restated because §2's re-baseline interacts with them:

- **An approved unit returns to `pending_review` on any successful PATCH** and leaves the public
  site. Keep the confirmation you already show.
- **A `pending_review` unit is locked** — `409 CONFLICT`. Keep the form disabled.
- The PATCH response carries the **new** `status`, so re-render from it. An admin who saves an
  approved unit should see it flip to "قيد المراجعة" immediately, not after a refresh.

```ts
const updated = await patchUnit(id, dirty)
setUnit(updated)                    // status is now "pending_review"
setBaseline(toForm(updated))
```

Because every save costs a review cycle here, the "no dirty fields → don't call" guard in §2 is not
just an optimisation on this screen.

---

## 8. `beds` at create — keep sending it

Confirmed: no conflict. The server default (`bedrooms`) only fills in when the key is **absent**, so
your stepper always wins. Range 1–20, and `≥ 1` is required at submit.

---

## Checklist

**Prefill**

- [ ] `toForm()` maps every field from the record; no `'moderate'` / `'15:00'` / `'12:00'` fallbacks
- [ ] `address` prefilled — no more blank required field on a published unit
- [ ] Baseline reset from the **PATCH response** after every successful save (§2)
- [ ] Empty-diff guard before calling PATCH (§2, §7)

**Photos**

- [ ] Merge: `[...existing, newId]` (§3)
- [ ] `coverFileId` sent explicitly on every photo edit (§3)
- [ ] Grey thumbnails, "سيتم استبدالها" badges and replacement banner removed (§3)
- [ ] `UnitPhoto['id']` typed `string | null`; optional `unmergeable` guard (§3)

**Cleanups**

- [ ] Arabic-label → amenity-key table deleted; use `amenityKeys` (§5)
- [ ] City matched on `cityKey`; three-way branch deleted (§6)
- [ ] `tourismLicenseFileId` included in the write body so the licence survives an edit (§1, §2)
- [ ] No "unset policy" state — `cancellationPolicy` is always present (§4)

**Regression**

- [ ] Approved-unit edit still warns, still flips to `pending_review`, re-rendered from the response (§7)
- [ ] `pending_review` unit still 409s and keeps the form disabled (§7)
