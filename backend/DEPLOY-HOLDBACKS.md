# Production vs `main` — what differs, and why

**Last verified: 2026-09-01** against `api.mamsaa.com` at `origin/main` = `4e164dc`.

> ## ✅ There are currently NO application-code hold-backs.
>
> Production's `app/`, `routes/`, `resources/`, `config/` and `database/` match
> `main` exactly. `git status` there shows **zero** modified application files.
>
> This file previously documented two deliberate hold-backs (multi-unit, and the
> invoice-link blade). **Both shipped to production on 2026-09-01** — see the
> history below. Keep this file accurate: a stale hold-back list is worse than
> none, because it invites someone to "restore" a difference that no longer
> exists.

---

## How production is deployed

**File-by-file over scp, not by pulling.** The git checkout on the server is a
reference for comparison, not the deploy mechanism — see `DEPLOY.md`.

A `git pull` there is no longer catastrophic (the schema now matches the code),
but it is still not the deploy path: it would drag `tests/` and `docs/` onto a
production box that deliberately does not carry them.

---

## Expected, permanent differences

These are not drift and will never clear:

| Kind | Count | What |
|---|---|---|
| `D` | ~71 | `docs/` and `tests/` — in git, never deployed to production |
| `M` | ~11 | stale `tests/`, `postman/`, `DEPLOY.md` copies left on the server from older deploys |

Both categories are noise. **They are also where a real difference would hide**,
so read `M` inside `app/` first and treat anything there as unexplained until
proven otherwise.

---

## The check

```bash
ssh mamsa 'cd ~/domains/api.mamsaa.com/app_core && git fetch origin main --quiet && \
  git status --short -- app routes resources config database | grep -v "^ D"'
```

**Expected output: nothing.** Any line here is either an undocumented hold-back
or a change that exists only on production — find out which before deploying
anything else.

To prove one file matches what is committed:

```bash
md5sum backend/<path>                        # local
ssh mamsa 'md5sum ~/domains/api.mamsaa.com/app_core/<path>'
```

---

## History

**2026-09-01 — production brought level with `main`.**
Before this, production's git checkout sat 112 commits behind with 157 dirty
files, so `git status` was meaningless and only a checksum comparison could say
what was actually running. A `--mixed` reset (HEAD and index only, no working
file touched — verified with a 321-file checksum manifest) cut that to 11 real
differences, all of them deliberate.

Then, by decision, both hold-backs shipped:

- **Multi-unit buildings.** Migration `2026_08_30_000002_add_unit_group_to_units`
  run first, then 11 files plus `UnitCloner`. Ordering mattered: the listing
  query groups on `unit_group_id`, so deploying the code before the column would
  have returned 500 on `GET /api/v1/units` for every visitor.
  The tourism-permit question (does a permit issued for a building cover each
  apartment?) was **still open** at the time of this deploy.
- **The invoice link** in the confirmation email. The storefront's
  `/my-reservations/{id}` still hangs on a 401 for signed-out guests, and a guest
  opening this link from their inbox is signed out by definition. See
  `docs/frontend/MAMSA-FRONTEND-RESERVATION-401-HANDLING.md`.

Contract change published by that deploy:

```
+ ADDED route: POST api/v1/partner/units/{unit}/apartments
+ KEY GET /api/v1/units: data[].available_count
+ KEY GET /api/v1/units: data[].listing_id
+ KEY GET /api/v1/units/popular: data[].listing_id
```

Rollback for that deploy: DB dump at `~/backup-prod-20260901-155532.sql`, code
at `/tmp/app-backup-prefullsync` on the server.
