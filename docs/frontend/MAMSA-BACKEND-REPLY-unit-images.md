# Unit images — shipped, with three corrections

**From:** backend · **Date:** 2026-08-26 · **Re:** `mamsa-unit-images-backend-task.md`
**Status:** ✅ live on **staging and production**. *(Written while it was staging-only; the owner
approved and it went to production the same day, 2026-08-26. Backfill: 12/12 images, 0 failed.)*

Everything in your "عاجل" and "مهم" lists is built. `sort_order` came along for free. Every
addition is a new key, so `url` still works exactly as it does today and you can migrate at your
own pace, as you asked.

Three things in the request didn't match what's on the server. Two of them would have made the
library worse if I'd implemented them literally, so I want to be explicit about what I changed and
why before you read the contract.

---

## 0. Three corrections

### 0.1 The performance numbers are off by roughly 50×

You wrote: *"وحدة فيها 6 صور، كل صورة ~2–4MB → فتح الـ lightbox يحمّل 12–24MB"*.

Unit 35 — the page you diagnosed on — carries six photos totalling **271 KB**:

| | | |
|---|---|---|
| 1024×576 · 64,213 B | 432×768 · 37,082 B | 432×768 · 37,669 B |
| 432×768 · 28,795 B | 1024×576 · 50,386 B | 1024×576 · 59,406 B |

Every unit photo on production is ≤1024px wide and the largest file is 84 KB. Total for the whole
library, both units: **1.3 MB**.

This doesn't invalidate the request. The *mechanism* you identified is real and I've fixed it: one
URL was serving five display sizes, and nothing stopped a partner uploading a 4 MB frame tomorrow.
But the fix is preventative, not an emergency — worth knowing when you rank it against your other
work.

### 0.2 `full` at 2048 would have upscaled the entire library

Your §2 asks for `full` at a 2048 long edge. Every image we hold is 1024 or smaller, so that would
have enlarged all of them — inventing pixels that were never photographed.

That is the same objection you make in §5 against AI upscaling, and it's just as true when the
interpolation is bicubic instead of a model. **`full` now caps at 2048 and never enlarges**: a
1024×576 source stays 1024×576.

The 2048 cap does apply as you intended once a partner uploads something bigger — verified on
staging with a 3000×2000 source, which came back 2048×1365.

### 0.3 The 1280×720 minimum would reject every photo we have — and every portrait

Two separate problems:

- **Nothing in the library reaches 1280 wide.** The rule would reject the images you're looking at
  right now.
- Read literally (`width ≥ 1280`), it rejects **every portrait** — including the 9:16 phone photos
  whose lightbox rendering was the bug that started this whole thread.

So the rule is now measured on **long edge / short edge** rather than width/height, and the owner
picked a lower floor to start:

```
long edge  ≥ 1024      IMAGE_MIN_LONG_EDGE
short edge ≥ 576       IMAGE_MIN_SHORT_EDGE
```

Both are env-tunable, so raising them to 1280/720 later is a config change and not a deploy.

⚠️ **One consequence you should know:** at 1024/576 the **432×768 portraits currently on units 34
and 35 would be refused** if re-uploaded (long edge 768 < 1024). Existing units are untouched —
validation runs at upload only — but re-uploading one of those sample images will now 400.

### 0.4 Aspect ratio — not enforced

You asked to reject outside 3:4–16:9. A 9:16 phone portrait falls outside that, and rejecting the
shape your full-screen viewer exists to display seemed like the wrong trade. **Any aspect ratio is
accepted**; the crop happens only when generating `thumb` and `card`, so your grid still lines up.
`full` is never cropped, per your §2.

---

## 1. What `GET /units/{id}` and `GET /units` now return

```jsonc
"images": [
  {
    "id": 91,
    "url": "https://api.mamsaa.com/storage/dashboard/unit_photo/file_01k….jpg",
    "is_main": true,

    // new
    "width": 1600,
    "height": 1200,
    "variants": {
      "thumb": "https://…/file_01k…_thumb.webp",
      "card":  "https://…/file_01k…_card.webp",
      "full":  "https://…/file_01k…_full.webp"
    }
  }
]
```

`url` is unchanged and is not deprecated.

### `variants` is `null`, never a fake

When a photo has no derivative set — a legacy row, or a file the processor couldn't read —
`variants` comes back **`null`** rather than three copies of the original URL. Filling the shape
with full-size images would satisfy your types and quietly defeat the entire feature, so the null is
deliberate: it's your signal to fall back to `url`.

`width`/`height` are `null` in the same case.

### Sizes

| key | box | fit | notes |
|---|---|---|---|
| `thumb` | 400×300 | cover (4:3 crop) | thumbnail strip + checkout summary |
| `card` | 800×600 | cover (4:3 crop) | search cards + collage |
| `full` | 2048 long edge | contain — **never cropped** | lightbox |
| — | original | untouched | `url` |

All derivatives are **WebP q82**. Names are deterministic (`{fileId}_{key}.webp`), so you can build
a URL without a lookup if you ever need to.

**`thumb` and `card` are always 4:3; `full` and the original always share the source aspect.**
Confirmed across thirteen shapes from 301×3000 to 4032×3024 — worst deviation from 4:3 is 0.001,
i.e. sub-pixel. So a fixed 4:3 container with `object-cover` is safe for both cropped sizes, and
`width`/`height` belong only on `full`.

**Never upscaled, in either mode.** A 432×768 portrait asked for `card` (800×600) is cropped to 4:3
and left at **432×324** rather than blown up to 800×600. So `variants.card` is a guarantee about
*shape and ceiling*, not a promise of exact pixels — read `width`/`height` if you need the real
numbers.

### Same three keys on the other two surfaces

`photos[]` on the partner dashboard and on `GET /admin/units/{id}` gained the identical
`width`/`height`/`variants`. Not asked for, but the admin approvals queue renders the same
thumbnails and had the same problem.

---

## 2. `sort_order` — done now, not later

You listed it under "لاحقاً". It was three lines, so it's in.

Photos come back in the order the partner attached them (`photoFileIds` order), stable across edits.
Rows written before the column existed keep the order they already had.

**The cover is still identified by `is_main` / `isCover`, and is NOT hoisted to index 0.** I tried
that first and it silently reordered a live contract — your existing code that reads
`photos[1].isCover` would have started reading a different photo. Position and cover stay
independent.

---

## 3. HEIC — and a correction on what it was doing

Your §3 says HEIC images are stored but don't render (*"لو ما تتحوّل لـ JPEG/WebP الصورة ما تظهر
أصلاً"*). That isn't what was happening. The receiver checks magic bytes and the table listed only
PNG and JPEG, so a HEIC was **refused outright** with:

```
نوع الملف غير صالح — مسموح: png/jpg
```

WebP was refused too, despite being on your accepted list.

Now: **jpeg, png, webp, heic**. HEIC is converted to JPEG on receipt (production has ImageMagick
7.1.2 with HEIC support — verified). If a server ever lacks it, HEIC is refused with a message that
says so instead of one naming png/jpg.

Verified on staging: a 3000×2000 HEIC → JPEG, correct dimensions, derivatives generated.

---

## 4. EXIF — this was the real bug, and it was a live data leak

Worth pulling out of §3, because it wasn't a formatting issue.

`UploadController::receive()` did `Storage::put($path, $bytes)` on the raw upload. **No processing
at all.** So every byte a camera wrote was preserved and served from a public bucket — including the
GPS block, which locates a property to within a few metres.

A partner photographs their apartment on a phone, uploads it, and the file publishes the real
coordinates regardless of what the listing says. Anyone could read them with a metadata viewer.

That's closed. Every accepted image is decoded and re-encoded, which drops **all** metadata.
Verified on staging against a file carrying a GPS block: **0 EXIF properties** on the stored result.

### Orientation — a precise note

Your §3.1 says un-applied orientation makes images appear rotated in the browser. That hasn't been
true for a few years — browsers honour the EXIF orientation flag on a plain `<img>`, so untouched
uploads have been displaying correctly.

It becomes true the moment we re-encode: the flag is gone but the pixels never moved. So your
instruction is exactly right as an *implementation requirement* — it prevents a bug we would
otherwise have introduced — rather than a description of something broken today. Orientation is
baked into the pixels before the flag is dropped.

### One thing I did not do

The canonical file is **not** re-encoded when it carries no metadata block and the re-encode would
make it larger. Staging showed real files going 33KB → 53KB and 21KB → 38KB, because an upload
already saved at quality 60 comes back out at 90. That trade only pays for itself when there's
something to strip. Files carrying EXIF are always rewritten, even when they grow.

---

## 5. Validation, as it now stands

| rule | value | on failure |
|---|---|---|
| max file size | 10 MB | `400 FILE_TOO_LARGE` (unchanged) |
| formats | jpeg, png, webp, heic | `400 INVALID_FILE_TYPE` |
| min resolution | long ≥1024, short ≥576 | `400 IMAGE_TOO_SMALL` |
| aspect ratio | not enforced | — |
| decodability | must actually decode | `400 INVALID_FILE_TYPE` |

**On the status code:** you asked for `422`. This endpoint is the signed PUT receiver and every
error it has ever returned is a `400` (`FILE_TOO_LARGE`, `INVALID_FILE_TYPE`). Introducing a `422`
alongside them would make one endpoint speak two dialects. Say the word if you'd rather have `422`
and I'll move all of them together — but not just this one.

The `IMAGE_TOO_SMALL` message names the actual numbers so the partner knows what to do:

```
دقة الصورة منخفضة (640×480) — الحد الأدنى 1024×576
```

**New:** a file whose header says "image" but which won't decode is now refused. It used to be
stored, and the storefront would serve a broken `<img>` with no way for anyone to find out why.

---

## 6. Where the work happens — and why not a queue

You asked for a queue job. It runs **synchronously at upload** instead, and I want to be upfront
about why rather than have you discover it.

Production is `QUEUE_CONNECTION=database` with **no worker process** — shared hosting has nothing
persistent to run one. Anything dispatched would sit in the `jobs` table permanently. Adding a
worker means a scheduler entry draining the queue every minute, which buys a 0–60s window where
`variants` is null on a freshly uploaded photo.

Your actual requirement was *"تتولّد مرة واحدة عند الرفع، مش on-the-fly مع كل request"* — that the
work happen once, not per read. Doing it inline at upload satisfies that and hands you the
derivatives immediately.

Cost, measured on the production box: **~1.4s** for a 3000×2000 image (275ms decode/strip, 1.15s for
three derivatives). Each photo is its own PUT, so that's 1.4s per photo, not per listing.

Generation is best-effort: if it fails, the original still stores and `variants` is null. An upload
the partner made is never lost to an optimisation step.

---

## 7. Existing photos

`php artisan images:process` backfills: measures, strips metadata, generates derivatives. Idempotent,
with `--dry-run` and `--force`.

Run on staging — 13 uploads, 0 failed, all 8 attached images now carry variants. It'll run on
production as part of the deploy.

---

## 8. Not done — §4 leftovers and §5

- **`alt`** — skipped for now. It needs a write-side field and somewhere in the partner UI to enter
  it; auto-generating `"{اسم الوحدة} 3"` server-side would just move your placeholder behind an API
  call without making it any more descriptive. Say if you want the column and I'll add it with the
  form field.
- **CDN** — ~~not set up~~ **already in place on production, which I had wrong.** `api.mamsaa.com`
  sits behind Hostinger's edge (`server: hcdn`), which caches, re-encodes JPEG, and content-
  negotiates WebP. Staging does not. See the round-2 reply for what that does to the numbers.
- **§5 AI enhancement** — agreed, and it wasn't going to come from this side. Your contractual-content
  argument is the right one: a guest books on those photos. Plain resize, which is what this is.

---

## 9. Verified

```
staging, ImageMagick 7.1.2

JPEG 3000×2000 with a GPS block
  → 3000×2000 jpg, 0 EXIF properties, GPS gone           275ms
  → thumb 400×300 · card 800×600 · full 2048×1365       1155ms

HEIC 3000×2000, orientation flag set
  → 2000×3000 jpg (orientation applied to pixels)        372ms
  → thumb 400×300 · card 800×600 · full 1365×2048        865ms

backfill: 13 uploads, 0 failed, 8/8 images carry variants
HTTP:     thumb 24KB · card 33KB · full 44KB · original 84KB   (all 200, image/webp)
```

Backend suite: **309 passed, 1542 assertions** — 20 new, covering geometry (no upscale in either
fit), format detection from bytes, the minimum on long/short edge, metadata not surviving, the
no-inflate rule, and null-not-fake variants.

---

## 10. Your checklist

**عاجل**
- [x] `variants: { thumb, card, full }` on every image
- [x] `width` / `height` on every image
- [x] HEIC conversion on upload
- [x] EXIF orientation applied + metadata stripped

**مهم**
- [x] Minimum resolution + a clear message — at 1024/576, not 1280/720 (§0.3)
- [x] Aspect ratio — deliberately **not** enforced (§0.4)
- [x] 10MB cap (already existed)

**لاحقاً**
- [x] `sort_order`
- [ ] `alt` — needs a form field first
- [ ] CDN

---

## What you need to do

Nothing urgent, and nothing time-boxed — `url` still works. When you get to it, in
`mapUnit` (`src/lib/api/adapters.ts`):

```ts
const src = (img: RawImage, size: 'thumb' | 'card' | 'full') =>
  img.variants?.[size] ?? img.url          // null means fall back, always
```

Then `thumb` in the thumbnail strip and checkout summary, `card` in `UnitCard` and the collage,
`full` in the lightbox.

⚠️ **`width`/`height` describe the ORIGINAL, which is the same shape as `full` and nothing else.**
Put them on the `<img>` that renders `full`. Do **not** put them on a `thumb` or `card` image:
those are 4:3 cover crops, so a 432×768 portrait has `width`/`height` of aspect 0.563 while its
`card` arrives at 1.333 — reserving that box is a worse layout shift than reserving none. For
`thumb` and `card`, size the container in CSS: they are always 4:3.

*(Corrected 2026-08-26. The original wording here said "`width`/`height` on the `<img>` to reserve
the box" without qualifying which one, which would have wired it up wrong.)*

A separate implementation guide follows if you want it; say so and I'll write it up the way I did
for the admin unit form.
