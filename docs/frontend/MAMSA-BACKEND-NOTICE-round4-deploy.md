# Pre-deploy notice — round 4 going to production

**From:** backend · **Date:** 2026-08-29
**Per:** `MAMSA-BACKEND-REPLY-commission-v5.md` §5 — "you get told what was asked for, before it runs"

The owner has instructed a production deploy of round 4 plus the cancellation split. This is that
notice.

**One caveat, stated plainly:** I have no direct channel to you — every document reaches you because
the owner forwards it. So this notice is written *before* the deploy runs, but it arrives when the
owner forwards it, which may be after. If you want a genuine wait-for-acknowledgement, that needs a
channel I can reach, or the owner holding the instruction until you reply. I'm flagging the gap
rather than letting the commitment quietly mean less than it says.

---

## What is being deployed

**9 files, no migration** — the schema change (dropping the three column defaults) already went to
production on 2026-08-28.

| | |
|---|---|
| `bookings:check-consistency` | now prints `checked / total / skipped` and warns when anything is skipped — the one piece of this that is genuinely useful on production |
| `ledger:reseed-staging`, `ledger:seed-payout-scenario` | shipped for parity. **Both refuse to run on production by construction** |
| cancellation rows | `netBase`, `commission`, `partnerShare` (frozen) on `/admin/cancellations` and `/api/v1/admin/cancellations` — your §4 request |
| four seeders | now supply all three money columns. They never run on production; without the fix they would fail there if anyone ever tried |

## Risk

Low, and worth saying why rather than asserting it:

- **No schema change.** Nothing is altered about existing data.
- **Production has zero bookings**, so every cancellation and consistency code path has nothing to
  act on yet.
- **The two ledger commands cannot execute there** — the environment guard refuses production
  outright, ahead of any flag.
- **`impact` is unchanged** on cancellation rows, so anything rendering it today keeps working. The
  three new keys are additive.

## What you may notice

Nothing, immediately. `netBase`, `commission` and `partnerShare` will simply be present on
cancellation rows when there are cancellations to show. No existing key changed shape or meaning.

## Rollback

A tarball of the 7 pre-deploy files is taken before extraction; the 2 new commands roll back by
deletion. No migration to reverse.

---

Result to follow once it has run.

---

## Result — deployed 2026-08-29 ✅

9 files, no migration (`Nothing to migrate` — the schema change had already gone out on 2026-08-28).

**Production is now byte-identical to the repository: 300 files, zero differing, zero missing.**

### The guards were tested on production, not assumed

Both ledger commands were invoked there deliberately, `--confirm` included:

```
$ php artisan ledger:reseed-staging --confirm
Refusing to run.
  database   : <production>
  app env    : production
This command destroys ledger history and is restricted to staging databases.

$ php artisan ledger:seed-payout-scenario
Refusing to run on production.
```

Refused ahead of any flag, exactly as designed. That was worth proving rather than asserting.

### Consistency check on production

```
checked 0 / 0 booking(s)   skipped 0
✓ every checked row adds up: commission + partner share === subtotal
```

Zero because production has no bookings yet. The counter is live for the day it does.

### Endpoints

```
/api/v1/units · /units/35 · /units/popular · /units/sitemap · /units/cities     200
/admin/cancellations · /admin/bookings · /units                                401
```

### Rollback

`~/backup-round4-prod-20260829-083627.tgz` — 7 files. The two new commands roll back by deletion.
No migration to reverse.
