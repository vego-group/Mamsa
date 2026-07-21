# Done — `beds` (number of beds) + `bathrooms` on units

**Date:** 2026-07-21 · **Status:** ✅ backend live (staging + prod), payloads below verified. For the partner-dashboard team, plus the user-site display note in §4.

You asked for **number of beds** on the add-unit form. Note the distinction — these are two separate fields:

| Field | Meaning (Arabic) | Example |
|---|---|---|
| `bedrooms` | عدد **الغرف** | a studio = 0–1 |
| `beds` | عدد **الأسرّة** | that studio can still sleep 2 → `beds: 2` |
| `bathrooms` | عدد **دورات المياه** | — |

`bathrooms` already existed on the dashboard contract; `beds` is new. Both are now settable and returned everywhere.

---

## 1. Partner dashboard — create / edit a unit

`beds` and `bathrooms` join the existing unit body (root-mounted dashboard API, cookie session, camelCase):

```
POST  /units          { ..., "beds": 2, "bathrooms": 2 }
PATCH /units/{id}      { "beds": 4 }          ← partial edit, same as any field
```

- **Type:** integer, `0`–`255`, **optional** (drafts can omit; a unit with no beds set returns `null`).
- Invalid values (negative, non-integer) → the dashboard **VALIDATION** envelope (`400`, `error.code: "VALIDATION"`), same as every other field.
- Editing `beds`/`bathrooms` on an **approved** unit behaves like any edit — the unit returns to review (`status: "pending"`).

## 2. What the dashboard returns

`GET /units` and `GET /units/{id}` now include both, next to `bedrooms`:

```json
{
  "id": "u_42",
  "bedrooms": 1,
  "beds": 2,
  "bathrooms": 2,
  "capacity": 4,
  ...
}
```

Add the two inputs to your unit wizard/edit form. Suggested labels: **عدد الأسرّة** (`beds`), **عدد دورات المياه** (`bathrooms`), distinct from the existing **عدد الغرف** (`bedrooms`).

## 3. TypeScript

```ts
interface DashboardUnit {
  // ...existing
  bedrooms: number | null;
  beds: number | null;        // NEW — number of beds (عدد الأسرّة)
  bathrooms: number | null;
}
```

## 4. User-site display (public storefront)

The public unit endpoint the user site renders also exposes both — use them in the specs row on the unit card / detail page:

```
GET /api/v1/units/{id}   (Bearer / public)
→ { ..., "bedrooms": 1, "beds": 2, "bathrooms": 2, "area": 45, "capacity": 2 }
```

**Existing catalog is backfilled:** every unit already in the DB was given `beds = bedrooms` (min 1) by the migration, and `bathrooms` was already populated — so nothing renders empty. New units carry whatever the partner enters.

## 5. Nothing else changed

Same envelopes, same auth, same lifecycle. This is purely two additive numeric fields — no breaking changes to any existing payload.
