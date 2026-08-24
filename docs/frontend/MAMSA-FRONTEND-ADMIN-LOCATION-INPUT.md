# The map pin bug — location input (Next.js admin console)

**From:** backend · **Date:** 2026-08-24 · **For:** the Next.js **admin console**
**Backend changes:** none. This one is entirely on the client side.

Two identical listings were created for the same address and they land **150.4 km apart** on
mamsaa.com. The pin on unit 34 (`MRN5XDRL`) is wrong. This is what I found, why it isn't a backend
problem, and what to change.

---

## 1. The evidence

```
35  MRNXDX5D  lat=24.854463  lng=46.658672   ← correct (Al-Narjis, Riyadh)
34  MRN5XDRL  lat=23.854463  lng=47.658672   ← 150.4 km southeast

Δlat = -1.0000000     Δlng = +1.0000000
```

Two things matter here:

1. The offset is **exactly 1.0000000** on each axis — not 0.9998, not 1.03.
2. **All seven decimals are identical** between the two units: `.854463` and `.658672`.

### It is not the backend

Please don't spend time there. `lat`/`lng` are pass-through:

```php
// backend/app/Support/Units/UnitWriter.php:130
'lat' => fn ($v) => ['lat' => $v],
'lng' => fn ($v) => ['lng' => $v],
```

No arithmetic, no normalisation, no clamping. The column is `decimal(10,7)` so there is no rounding,
and I read both units back from the public API on production — the values match the database
digit for digit. Whatever the console sent is what is stored.

### The stored address proves the value was already wrong in the console

`address` is the reverse-geocode result, and on unit 34 it **agrees with the wrong pin**:

| Unit | `address` as stored |
|---|---|
| 35 | `شارع الفضائل, النرجس, محافظة الرياض, منطقة الرياض, 11543, السعودية` |
| 34 | `محافظة الخرج, منطقة الرياض, السعودية` |

Al-Kharj governorate — that is your own geocoder answering. So the bad coordinate existed in the
form **before** the address was resolved, and the address simply followed it.

---

## 2. The likely mechanism — please confirm it in your code

**This part is inference, not something I observed.** I don't have the admin console repo, so I
can't point at a line. But the signature is specific:

Two pin drags on a map cannot produce identical 7-decimal fractions. A **stepped number input**
can — it changes the integer part by exactly the step and leaves the fraction untouched.

`<input type="number">` steps by 1 on:

- **↑ / ↓ arrow keys** while focused
- **the mouse wheel** while focused — this is the one that bites, because the user is scrolling
  the form, not the field

Scroll down over `lat` → `-1`. Scroll back up over `lng` → `+1`. That is exactly the delta we have.
And if the form re-geocodes when lat/lng change, the address follows — which is what happened.

### 30-second check

```bash
rg -n 'type="number"' --glob '*.tsx' | rg -i 'lat|lng|coord|location'
```

Then, in the running form: focus the lat field and **scroll the mouse wheel**. If the value changes,
that's the bug.

If the location is map-pick only and there are no numeric inputs, say so — then I need the create
payload your console sent, because something else is rewriting the value.

---

## 3. The fix

### 3.1 Stop the stepping

```tsx
// ✗ before
<input type="number" step="any" value={lat} onChange={…} />

// ✓ after — a coordinate is not a quantity you nudge
<input
  type="text"
  inputMode="decimal"
  value={lat}
  onChange={(e) => setLat(e.target.value)}
/>
```

If you must keep `type="number"`, block both entry points:

```tsx
<input
  type="number"
  step="any"
  onWheel={(e) => e.currentTarget.blur()}
  onKeyDown={(e) => {
    if (e.key === 'ArrowUp' || e.key === 'ArrowDown') e.preventDefault()
  }}
  …
/>
```

**Do not rely on `step="any"` alone.** Browser behaviour for arrows and wheel under `step="any"` is
not consistent enough to be your only defence — block the events explicitly.

### 3.2 The guard that would actually have caught this

You already have both halves of the check and aren't comparing them: the admin **selected**
`الرياض / النرجس`, and the geocoder **returned** `محافظة الخرج`. Those disagree.

```tsx
// after the reverse geocode resolves
const resolved = geo.address           // "محافظة الخرج, منطقة الرياض, السعودية"

if (!resolved.includes(form.cityLabel)) {
  // block save, or at minimum warn:
  // "الموقع المحدد يقع في محافظة الخرج، بينما المدينة المختارة هي الرياض"
}
```

This is worth more than the input fix on its own. The input fix stops one way of producing a wrong
pin; this catches a wrong pin **however** it was produced — a mistyped digit, a stale map centre, a
geocode that landed on the wrong side of town.

Substring matching on the Arabic label is crude but sufficient here. If you'd rather compare
structurally, `GET /admin/cities` gives you the canonical list and `cityKey` on the unit gives you
the slug (see `MAMSA-FRONTEND-ADMIN-UNIT-EDIT.md` §6).

### 3.3 Show the pin before saving

A small read-only map preview of the resolved coordinate, next to the resolved address, in the
review step. 150 km is obvious to a human eye and invisible in a text field.

---

## 4. Fixing unit 34 — read this before you edit it

The owner is **keeping both listings**. Unit 34 needs its pin corrected to match unit 35:

```
lat      24.854463
lng      46.658672
address  شارع الفضائل, النرجس, محافظة الرياض, منطقة الرياض, 11543, السعودية
```

**But editing it through the console has a cost.** Unit 34 is `approved` and live. Any successful
`PATCH /admin/units/34` flips it back to `pending_review` and takes it off the public site until
someone approves it again — that rule is in `UnitsController::update()` and applies to every field,
including a pin correction:

```php
if ($wasApproved) {
    $columns['approval_status'] = 'pending';
}
```

So the choice is:

- **Fix it in the console** — costs one review cycle, and unit 34 is off mamsaa.com in the
  meantime. Fine if someone is on hand to re-approve.
- **Ask backend to fix it in place** — I can correct the two columns directly and leave
  `approval_status = approved`, so the listing never leaves the public site. Say the word and I'll
  do it; it's a two-column update on a unit with **0 bookings**.

The second is the better option here given that nothing about the listing is actually under review —
only a data-entry error is being repaired. Your call.

---

## 5. What the backend does not check (so you can't lean on it)

`Maps::insideSaudi()` validates a **national** bounding box only:

```
lat  16.0 – 32.5
lng  34.0 – 56.0
```

Al-Kharj is inside Saudi Arabia, so the submit gate passed it. There is **no** server-side check
that the pin is anywhere near the declared city or district. I've suggested adding one — reject a
submit whose coordinate is more than ~50 km from the declared city centroid — but it isn't built,
so until it is, §3.2 is the only thing standing between a slipped digit and a live wrong pin.

I'll flag it here if that guard ships, since it would change your error handling (a new `422` on
`lat`/`lng` at submit).

---

## Checklist

**Input**

- [ ] Wheel no longer changes lat/lng (`onWheel` blur, or `type="text" inputMode="decimal"`)
- [ ] ↑/↓ arrows no longer change lat/lng
- [ ] `step="any"` is not the only guard

**Validation**

- [ ] Reverse-geocoded address compared against the selected city; mismatch blocks or warns (§3.2)
- [ ] Pin preview shown next to the resolved address before save (§3.3)

**Data**

- [ ] Unit 34 pin corrected — decide console-edit vs backend in-place fix first (§4)
- [ ] Confirm back whether the numeric-input theory was right, so this doesn't get re-litigated
