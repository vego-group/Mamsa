# Runbook — documents step two to production

**Window:** 2026-09-13, 06:00 Asia/Riyadh
**Duration:** ~30 min
**Precondition:** both consoles confirmed green on staging

Step two flips two things in one deploy: compliance documents are written to the
vault instead of the public disk, and every document URL becomes a signed link
through `/documents/{uploadId}`. They cannot be separated — writes alone leave
the review screen pointing at a path the file is no longer on; reads alone sign
links to documents still sitting in the open.

Unit photos are not affected and must stay on the public disk.

---

## 0. Files in this deploy

```
config/documents.php
app/Support/Documents/DocumentStorage.php
app/Http/Controllers/DocumentController.php
app/Http/Controllers/Dashboard/UploadController.php              writer
app/Http/Controllers/Api/V1/Auth/PartnerAuthController.php       writer
app/Http/Controllers/Api/V1/Partner/UnitDocumentController.php   writer
app/Http/Resources/UnitResource.php                              reader
app/Http/Controllers/AdminPanel/PartnersController.php           reader
app/Http/Controllers/Dashboard/ProfileController.php             reader
app/Http/Controllers/Api/V1/Admin/RequestController.php          reader
app/Support/AdminPanel/UnitPresenter.php                         reader
app/Models/DashboardUpload.php                                   signedUrl()
```

`routes/dashboard.php` is NOT in this list — the `/documents` route went to
production with step one. Confirm, do not re-copy the file: the branch version
carries complaints routes production has no controllers for.

---

## 1. Snapshot

Copy the twelve files to `~/backup-docstep2-<stamp>/`, plus `route:list --json`
and an md5 manifest. Record the path in `~/.last-docstep2`.

## 2. Inventory BEFORE — this is the number to send

Count public files under `config('documents.sensitive_dirs')`.

**The count at deploy time is the number that moves at step three** — not the
six counted on staging, not the five counted on 11/09. Send it before
proceeding.

## 3. Deploy

`tar` the twelve files, then `config:clear && config:cache`,
`route:clear && route:cache`, and `php -l` each file.

## 4. Verify — in this order

1. `/api/v1/units` 200 · `/me` 401 · `/admin/me` 401 · `/units` 401
2. An existing document's admin URL is now `/documents/…?expires=…&signature=…`
3. That link **anonymous → 403** (the session check is live)
4. The old `/storage/dashboard/license_pdf/<same file>.pdf` **still 200** —
   unmigrated documents keep working; step three moves them
5. A document uploaded now lands in the vault and **not** on the public disk
6. A photo uploaded now lands on the public disk and **not** in the vault
7. Inventory AFTER — unchanged apart from anything just uploaded

## 5. Rollback — and its one hazard

Reverting the twelve files sends reads back to `/storage`. **Any document
uploaded between deploy and rollback is in the vault, so it becomes
unreachable.** Rollback therefore has two parts, in this order:

```
a. Move vault files written during the window back to the public disk
b. Restore the twelve files from the snapshot, then config:cache + route:cache
```

The reverse move was rehearsed on staging on 12/09 — copy, verify size, then
delete — rather than improvised at 06:00:

```php
$root = 'secured-documents/';
foreach (Storage::disk('local')->allFiles($root.'dashboard') as $f) {
    $public = substr($f, strlen($root));
    Storage::disk('public')->put($public, Storage::disk('local')->get($f));
    if (Storage::disk('public')->size($public) === Storage::disk('local')->size($f)) {
        Storage::disk('local')->delete($f);
    }
}
```

> Scope it to files newer than the deploy timestamp. **The nine moved on 11/09
> are supposed to be in the vault and must NOT come back out.**

**Rollback stops being clean once real documents are in the vault**, which is
why verification step 5 runs immediately and why the window is 06:00.

## 6. After

- Tell the consoles it is live.
- Step three stays unscheduled. Its count is computed at the time and approved
  before anything moves.
