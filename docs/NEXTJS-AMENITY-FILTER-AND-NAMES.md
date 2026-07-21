# Done — amenity slug filter + first/last name

**Date:** 2026-07-21 · Reply to `mamsa-reply-decisions-and-amenity-filter.md` · **Status:** ✅ all three items live on staging + prod, verified.

---

## 1. `discount_percent` — dropped ✅
Confirmed, and we never added it — no dormant column exists on our side either. `Offer.discount_percent` on `/offers` is untouched (separate marketing feature). Nothing to build.

## 2. 🔴 Amenity filter now takes slugs — FIXED

`GET /units?features[]=` accepts the **slug** (the same vocabulary you get in `amenities[].key`) and internally expands it to every stored spelling, so both `تكييف` and `مكيف` count as `ac`. AND semantics preserved. Verified on staging just now:

```
GET /units                              → 12
GET /units?features[]=ac                → 11   (was 0)
GET /units?features[]=wifi              → 12   (was 0)
GET /units?features[]=kitchen           →  8
GET /units?features[]=pool              →  4
GET /units?features[]=smart_tv          →  2
GET /units?features[]=ac&features[]=wifi → 11   (AND — both required)
```

- **Send slugs** from the published vocabulary — every checkbox now returns the right set.
- **Raw Arabic labels still work** as a fallback (we didn't break the old path), but you don't need them.
- **Stored labels normalized too:** we ran a one-off merge of spelling variants (`مكيف`→`تكييف`, `تلفزيون ذكي`→`شاشة ذكية`), so `amenities[].label` is now consistent across units as well. Filtering keys off slugs regardless, so this was just data hygiene.

Vocabulary (unchanged): `wifi · ac · kitchen · parking · pool · security · self_checkin · family_friendly · smart_tv · garden · bbq · elevator · washer · private_beach · event_hall`.

## 3. `first_name` / `last_name` — added ✅

Two real columns; `name` stays the concatenation so anything reading it keeps working. **Compound Arabic names survive** — send `first_name: "عبد الله"` and it comes back exactly, never re-split.

### Accept (send the parts)
```
POST /auth/complete-profile   { "first_name": "عبد الله", "last_name": "محمد", "email"?: "..." }
PUT  /user/profile            { "first_name": "...", "last_name": "..." }
```
- Parts are authoritative → `name` is set to `"first last"` automatically.
- Backward-compatible: sending only `name` still works (we naively split it to keep the columns in sync). Prefer sending the parts.
- On `complete-profile`, provide either the parts **or** `name` (at least one).

### Return (read the parts)
`GET /auth/me` and `GET /user/profile` now include both:
```json
{ "name": "عبد الله محمد", "first_name": "عبد الله", "last_name": "محمد", ... }
```

### Backfill
Existing rows were split on first-whitespace (`first_name` = first token, `last_name` = the rest) and are live now — e.g. `"محمد الشريك الفردي"` → `first_name: "محمد"`, `last_name: "الشريك الفردي"`. It's a naive split for legacy data; users can correct theirs in account settings. **All new writes that send the two inputs are lossless.**

### TypeScript
```ts
interface User {
  name: string;           // display concatenation (unchanged)
  first_name: string | null;
  last_name:  string | null;
}
```
Drop the split-on-read; read `first_name`/`last_name` directly. Keep joining into `name` on write if you like — or just send the parts and let us build `name`.

---

## Note on `beds`
It's a fully independent column — settable per unit, not derived. The staging catalogue shows `beds === bedrooms` only because that was the backfill seed; edit any unit's beds and it diverges immediately. Nothing pending.
