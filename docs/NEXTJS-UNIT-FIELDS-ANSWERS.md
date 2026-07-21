# Reply — missing API fields (Unit / Booking / User)

**Date:** 2026-07-21 · Reply to `mamsa-unit-fields-backend-task.md` · **Status:** ✅ most items live on staging + prod; payloads below verified. Surface: user app, Bearer, `https://api.mamsaa.com/api/v1`.

Everything additive — **no existing key changed shape**, so nothing you already read breaks. New keys sit alongside old ones. Per-item status below, then the amenity key list you asked for, then the two documentation-only answers.

---

## §1 — Wrong data shown now

### 1.1 `beds` ✅ DONE
`beds` (integer) is on the unit resource and settable by the partner. Stop mirroring `bedrooms`:

```ts
bedrooms: Number(u.bedrooms ?? 0),
beds:     Number(u.beds ?? 0),   // ← real value now
```
Existing catalog was backfilled (`beds = bedrooms`, min 1); new units carry what the partner enters. (Full detail: `NEXTJS-UNIT-BEDS-BATHROOMS.md`.)

### 1.2 `owner.type` (+ `is_verified`, `avatar_url`) ✅ DONE
The unit's `owner` object is enriched:

```json
"owner": {
  "id": 3,
  "name": "شركة الضيافة",
  "type": "individual" | "company",   // companies no longer show as "مالك فردي"
  "is_verified": true,                 // partner application approved
  "avatar_url": null                   // no avatar storage yet — keep initials fallback
}
```
`avatar_url` is `null` everywhere for now (no upload pipeline); when we add it, this key fills in — no shape change.

### 1.3 `features` → structured `amenities` ✅ DONE
`features` (Arabic-string array) is **kept** for backward-compat; use the new **`amenities`** array of `{ key, label }`:

```json
"amenities": [
  { "key": "wifi", "label": "واي فاي" },
  { "key": "ac",   "label": "مكيف" },      // spelling variants resolve
  { "key": null,   "label": "مسبح خاص جداً" } // outside vocabulary → generic icon
]
```
`key` is a stable slug from the closed list (§ end). `key: null` = amenity not in the vocabulary yet — render a generic icon and **send us the label** to add a slug. Spelling variants (`مكيف`↔`تكييف`, `واي-فاي`↔`واي فاي`) already normalize to the same key.

---

## §2 — Fields the UI is ready for

### 2.1 Unit

| Field | Status | Notes |
|---|---|---|
| `tax_percent` | ✅ DONE | Uniform `15` on every unit — stop hardcoding. |
| `is_featured` | ✅ DONE | `bool`. Home section: **`GET /units?featured=1`**. Admin sets it via `PATCH /admin/units/{id}/featured {is_featured}`. A few units are seeded featured so the section isn't empty. |
| `approval_status` | ✅ already live | Returns all four: `draft`\|`pending`\|`approved`\|`rejected`. |
| `rejection_reason` | ✅ already live | Present when `approval_status === "rejected"`. |
| `discount_percent` | ⏸️ **DEFERRED — needs a decision** | Showing a discount badge that doesn't change the charged price would mislead the guest. Confirm: does a discount actually reduce `total` (real pricing change), or is it purely a marketing badge? Tell us which and we wire it. |
| `country` | ⏸️ DEFERRED | Registered. Keep the `"السعودية"` constant until we expand outside KSA; we'll add `country` to the resource then. |
| `area` | ✅ already returned | It's on the resource (`area`, m²). Use it whenever you want the specs row — no backend change needed. |

### 2.2 User (`GET /auth/me`)

| Field | Status | Notes |
|---|---|---|
| `partner_type` | ✅ DONE | `"individual"` \| `"company"` (present when the user is a partner) — same source as `owner.type`. |
| `avatar_url` | ✅ DONE | `null` for now (no storage yet), key is stable. |
| `first_name` / `last_name` | ⏸️ **DEFERRED — needs a decision** | We only store a single `name`; splitting it server-side hits the exact `"عبد الله محمد"` problem you flagged. To do this right we'd collect first/last **at registration**. Want us to add those form fields + columns? Until then, `name` is the only reliable value. |

### 2.3 Booking

| Field | Status | Notes |
|---|---|---|
| `guests` split | ✅ DONE | `guests` stays the **total**; new `guests_detail: { adults, children }`. Send `children` (optional, `≤ guests`) on `POST /bookings`; `adults` is derived as `guests − children`. |
| `guest_name` | ✅ DONE | Present when the booking's user is loaded (partner dashboard list/detail). |
| `user_id` | ✅ DONE | Top-level scalar — stop hardcoding `'CURRENT_USER'`. |
| `review` shape | ✅ DONE / documented | `review: { id, rating, comment, created_at, user_avatar_url } \| null` (`user_avatar_url` null for now). |

```jsonc
// POST /bookings
{ "unit_id": 1, "start_date": "2026-09-10", "end_date": "2026-09-12", "guests": 3, "children": 1 }
// booking resource
"guests": 3,
"guests_detail": { "adults": 2, "children": 1 }
```

---

## §3 — Fragile derivations

### 3.1 `cancellation-preview` money ✅ DONE
No more reverse-division. `GET /bookings/{id}/cancellation-preview` now returns explicit figures:

```json
{
  "cancellable": true,
  "refund_amount": 500.0,
  "forfeited_amount": 500.0,   // NEW — correct even when refund_percent = 0
  "total_amount": 1000.0,      // NEW — the booking total
  "refund_percent": 50,
  "tier_label": "من 3 إلى 7 أيام",
  "tier": { "min_hours_before_checkin": 72, "refund_percent": 50, "label": "من 3 إلى 7 أيام" },
  "hours_before_checkin": 120,
  "reason": null
}
```
At `refund_percent: 0`, `forfeited_amount` is the full total (no more "مخصوم: ٠").

### 3.2 structured `tier` ✅ DONE
`tier` (above) is the matched object, same shape as one element of `cancellation_policy_details.tiers` — use it to highlight the active row / translate the label. `tier_label` stays for convenience. `tier` is `null` when not cancellable.

### 3.3 `cancelled_by` — allowed values 📄 DOCUMENTED
Closed set, exactly these four strings (no aliases — we do **not** use `guest`/`host`):

```
"customer" | "partner" | "admin" | "system"
```
`customer` = the guest, `partner` = the host, `admin` = back-office, `system` = automated (e.g. payment expiry). Safe to cast.

### 3.4 legacy `cancellation_policy` 📄 DOCUMENTED
Still returned; keep using `cancellation_policy_details` as primary and the enum as your unpaid-booking fallback. **We will not remove it without notice** — when we schedule removal you'll get a heads-up with a date in advance.

---

## Amenity key list (closed vocabulary)

`key` is one of these slugs, or `null` (unknown → generic icon):

```
wifi · ac · kitchen · parking · pool · security · self_checkin · family_friendly ·
smart_tv · garden · bbq · elevator · washer · private_beach · event_hall
```
Prepare an icon per slug. Anything you see come back as `key: null`, send us the `label` and we'll assign a slug.

---

## Summary

**Done & live:** beds, owner.type/is_verified/avatar_url, amenities `{key,label}`, tax_percent, is_featured (+ `?featured=1` + admin toggle), guests split + children, guest_name, user_id, review shape, cancellation-preview `total_amount`/`forfeited_amount`/`tier`. approval_status/rejection_reason/area were already there.

**Need a decision from you:** `discount_percent` (marketing badge vs real price cut), `first_name`/`last_name` (collect at registration?).

**Documented:** `cancelled_by` values, legacy-policy removal will be pre-announced, `country` deferred to expansion.
