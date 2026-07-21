# Done — `beds` + `bathrooms` on the partner unit wizard

**Date:** 2026-07-21 · Reply to `backend-request-beds-baths.md` (partner.mamsaa.com) · **Status:** ✅ live on staging + prod.

Short version: both fields are already implemented and deployed — you can wire the wizard now. This doc gives you the exact contract, the submit rules you asked for, and answers your four questions. Surface: partner dashboard, root-mounted, cookie session (**not** `/api/v1`).

---

## 1. What's live

| Requirement | Status |
|---|---|
| `beds` + `bathrooms` columns on `units` | ✅ (existing rows backfilled) |
| `POST /units` accepts both (optional on draft) | ✅ |
| `PATCH /units/{id}` accepts both | ✅ |
| `POST /units/{id}/submit` **requires** both | ✅ NEW |
| `GET /units` (list) returns both | ✅ |
| `GET /units/{id}` returns both | ✅ |
| Public site `GET /api/v1/units/{id}` returns both | ✅ |

## 2. Contract

Keys are `beds` and `bathrooms` (integers), alongside `bedrooms`:

```jsonc
// POST /units  or  PATCH /units/{id}
{ "name": "...", "type": "apartment", "pricePerNight": 480,
  "bedrooms": 2, "beds": 2, "bathrooms": 2, "capacity": 4,
  "city": "riyadh", "cancellationPolicy": "moderate" }

// GET /units/{id}
{ "id": "u_12", "bedrooms": 2, "beds": 2, "bathrooms": 2, "capacity": 4, ... }
```

### Validation (matches your §3.3)
```
beds:      integer, min 1, max 20
bathrooms: integer, min 1, max 10
```
- **Draft** (`POST`/`PATCH`): optional — omit freely; if sent, must be in range, else the dashboard **VALIDATION** envelope (`400`, `error.code: "VALIDATION"`, `error.fields.beds` / `.bathrooms`).
- **Submit** (`POST /units/{id}/submit`): both **required**. Missing → `400` with:

```json
{ "error": { "code": "VALIDATION", "message": "بيانات غير مكتملة",
  "fields": { "beds": "عدد السراير مطلوب", "bathrooms": "عدد دورات المياه مطلوب" } } }
```
(Same envelope as every other submit field — your existing error handling already covers it. Note: this dashboard uses `error.code: "VALIDATION"` at HTTP **400**, which is the established shape here — not the `422 validation_failed` your draft doc guessed.)

## 3. Your four questions

1. **Aggregate number or by bed type (`{king, single}`)?** → **Aggregate integer**, exactly as you preferred. Simple and sufficient for the public page. (If you ever want the breakdown, that's a future additive field — say the word.)
2. **Half-baths (½ bath)?** → **No — integer**, per your ranges. Supporting `2.5` baths means `decimal(3,1)` + step 0.5 across the stack; not worth it unless you confirm the product needs it. Tell us and we'll switch.
3. **Backfill values for existing units?** → Confirmed and **already applied**: `beds = bedrooms` (min 1). `bathrooms` was already populated from an earlier migration (`bedrooms > 1 ? bedrooms : 1`) — better than a flat 1, so we kept those real values rather than resetting to 1. Every existing unit now returns real numbers.
4. **ETA?** → **Shipped.** Live on staging and production now.

## 4. One design note (nullable vs. default 1)

Your §3.1 suggested `NOT NULL, default 1`; your §3.3 said "optional on draft." Those conflict, so we went with **§3.3**: the columns stay nullable so a half-filled draft doesn't *lie* ("1 bed" before the partner entered anything), and **submit enforces** them. Net effect for you:

- **Approved / public units:** `beds` and `bathrooms` are **always** real numbers (they passed submit validation). Safe to type as `number`.
- **Drafts (dashboard only):** may be `null` until the partner fills them — type the dashboard draft as `number | null`, or default to empty in the stepper.

This is strictly better than default-1, which would show wrong counts on new drafts.

## 5. Your frontend steps (unblocked)

Go ahead with all five from your §5:
1. Add **Beds** + **Baths** steppers to wizard step 2 (next to Bedrooms/Guests).
2. `unitSchema`: `beds` 1–20, `bathrooms` 1–10 — required on the submit path, optional on save-draft.
3. Pass both in `buildInput()`.
4. Render them on the unit detail page + preview modal (public data is always present).
5. Types: on the **public** `Unit`, `beds: number` / `bathrooms: number`; on the **draft** create/edit model, `number | null`.

No breaking changes — every other field is untouched.
