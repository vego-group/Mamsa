# Deploy hold-backs — production runs LESS than `main`, on purpose

**Last verified: 2026-09-01** against `api.mamsaa.com` at `origin/main` = `78b3910`.

Production's working tree deliberately differs from `main`. Every difference below
is a decision, not drift. `git status` on that server is expected to be dirty.

> ## ⚠️ Do not run `git pull`, `git checkout .`, or `git restore` on production
>
> Production has **no `unit_group_id` column** — the migration is held back too.
> Pulling `main` would deploy code that queries that column on every listing
> request, so `GET /api/v1/units` would 500 for every visitor, immediately, with
> no staged rollout to catch it.
>
> Production is deployed **file-by-file over scp**, not by pulling. The git
> checkout there is a reference, not the deploy mechanism. See `DEPLOY.md`.

---

## 1. Multi-unit buildings — held back entirely

One listing standing for many identical apartments. Live on staging; **off
production** by agreement while the tourism-permit question is open (does a
permit issued for a building cover each apartment, or not?). Until that is
answered we cannot let an admin approve 100 apartments on one licence.

Production keeps the **older, single-unit** version of:

| File | What production is missing |
|---|---|
| `app/Http/Controllers/Api/V1/BookingController.php` | group allocation — picks a free apartment out of the building. Production still has the `lockForUpdate` race fix and both guest-facing messages; only the sibling loop is absent. |
| `app/Http/Controllers/Api/V1/UnitController.php` | collapsing a building to one card; the dated `available_count` |
| `app/Http/Controllers/Api/V1/Partner/UnitController.php` | the `POST /units/{id}/apartments` endpoint. **Production's copy is hand-built** — it is `main`'s file minus multi-unit, plus the submit gate. Do not overwrite it with either version wholesale. |
| `app/Http/Controllers/Api/V1/Partner/UnitImageController.php` | reference-counted file deletion. Clones share photo paths, so `main` checks whether another row still points at a file before unlinking. Production deletes outright — which is why anything seeded there must own its image files. |
| `app/Http/Controllers/Api/V1/User/FavoriteController.php` | favourites keyed to the building rather than one apartment |
| `app/Http/Resources/UnitResource.php` | `available_count`, `listing_id` |
| `app/Models/Unit.php` | the group relationship |
| `app/Support/Booking/Availability.php` | per-night tally across apartments; a night closes only when every apartment is taken |
| `app/Support/Sql.php` | `groupKey()` |
| `routes/api.php` | the `apartments` route |

Absent from production entirely:

- `app/Support/Units/UnitCloner.php`
- `database/migrations/2026_08_30_000002_add_unit_group_to_units.php` — **never run there**

**Ends when:** the permit question is answered and multi-unit is approved for
production. Deploy the migration FIRST, then the files.

---

## 2. The invoice link in the confirmation email

| File | What production is missing |
|---|---|
| `resources/views/emails/booking-confirmed-guest.blade.php` | the «عرض الفاتورة الضريبية» button |

`app/Notifications/BookingConfirmed.php` **is** deployed and already computes
`invoiceUrl`. It is inert: the blade has no `@isset($invoiceUrl)` block, so
nothing renders. Shipping this one blade turns the button on.

**Ends when:** the Next.js storefront stops hanging on 401 at
`/my-reservations/{id}`. A guest opening that link from their inbox is signed
out by definition, so today they would meet an infinite «جاري التحميل…».
See `docs/frontend/MAMSA-FRONTEND-RESERVATION-401-HANDLING.md`.

---

## 3. Not hold-backs — expected noise

- **72 `D` entries**: 37 `docs/`, 33 `tests/`, plus the two multi-unit files
  above. Production does not deploy docs or tests; git will keep reporting them
  as deleted. This is the category most likely to hide a real difference, so
  read `M` and `??` first.
- **`??` entries**: `.bak-*` files left beside a deploy as a rollback copy.
  Safe to delete once the deploy they backstop is trusted.

---

## How to check this file is still true

```bash
ssh mamsa 'cd ~/domains/api.mamsaa.com/app_core && git fetch origin main --quiet && \
  git status --short -- app routes resources config database | grep -v "^ D"'
```

Every `M` line should appear in the tables above. **A line that does not is
either an undocumented hold-back or a change that exists only on production** —
find out which before deploying anything else.

To prove a specific file matches what is committed:

```bash
md5sum backend/<path>                        # local
ssh mamsa 'md5sum ~/domains/api.mamsaa.com/app_core/<path>'
```
