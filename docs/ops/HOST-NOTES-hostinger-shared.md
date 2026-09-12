# Host notes — Hostinger shared (api.mamsaa.com, staging.mamsaa.com)

Facts about this host where **the obvious check gives the wrong answer**. Each
one cost time to establish, and each is the first thing someone reaches for.

---

## `crontab -l` is empty, and that is not evidence of absence

```
$ ssh mamsa 'crontab -l'
(nothing)
```

`schedule:run` is configured through the **hPanel cron UI**, not the user
crontab, so nothing appears here. Reading that as "the scheduler is not
running" would be wrong — and it is the natural conclusion.

**Verify by output instead.** The scheduler writes to
`storage/logs/schedule.log`:

```bash
ssh mamsa 'cd ~/domains/api.mamsaa.com/app_core
  date -r storage/logs/schedule.log "+%Y-%m-%d %H:%M:%S"   # is it still being written?
  tail -5 storage/logs/schedule.log'
```

A recent mtime with several commands reporting into it is proof the scheduler
runs. Confirmed 2026-09-12: the log was 461 KB with an mtime matching the
minute, while `crontab -l` returned nothing.

**Corollary:** if one scheduled command stops appearing, check whether the
OTHERS still do. All of them going quiet together means `schedule:run` has
stopped; one going quiet alone means that command is failing.

---

## A missing file under `/storage` returns 403, not 404

LiteSpeed answers a non-existent path inside the symlinked `public_html/storage`
with **403**, while a missing application route returns 404.

```
/storage/units/28/<real file>.jpg   → 200
/storage/units/demo/photo.jpg       → 403   (file does not exist)
/storage/nope/nope.jpg              → 403   (directory does not exist)
/definitely-not-a-route             → 404
```

So a 403 on a storage path usually means **the file is not there**, not that
permissions are wrong. Diagnosing it as a storage-configuration problem sends
you to the wrong place — it did once, and the real cause was five database rows
pointing at a file that had never been uploaded.

**Corollary for signed routes:** a document moved off the public disk answers
403 rather than 404 afterwards, which looks identical to "expired link". Check
the disk before the signature.

---

## Web access logs are not on the filesystem

Only `~/.logs/mail.log` exists at shell level. HTTP access logs live in the
**hPanel** UI, with limited retention, and need the account owner.

Consequence for any incident question of the form "was this URL ever fetched":
the honest answer is usually **not provable either way**, and that absence is
part of the answer rather than a gap to apologise for.

---

## Paths

```
~/domains/<host>/app_core      Laravel root
~/domains/<host>/public_html   web root; `storage` is a symlink into app_core
/opt/alt/php84/usr/bin/php     the PHP binary — `php` alone may be an older one
```
