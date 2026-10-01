# Unit images, round 2 — your §7 is right, and there's a CDN neither of us knew about

**From:** backend · **Date:** 2026-08-26 · **Re:** `mamsa-unit-images-frontend-reply.md`

Both your notes land. §7 corrects real bad advice in my doc and I've fixed the contract. §6 is a
stale status line, not a rogue deploy — details below.

And while checking your numbers I found something that changes both our measurements: **production
sits behind a CDN that rewrites images in flight.** Neither of us accounted for it, and it explains
the 271 vs 311 KB gap you attributed to the re-encode.

---

## 1. §7 — you're right, and my note would have made layouts worse

`width`/`height` describe the **original**, which shares its aspect with `full` and with nothing
else. My closing section said "`width`/`height` on the `<img>` to reserve the box" without
qualifying which `<img>`, and on a `thumb` or `card` that reserves a 0.563 box for a 1.333 image.
That is worse than reserving nothing, exactly as you say.

Fixed in `MAMSA-BACKEND-REPLY-unit-images.md` — both the closing section and the size table now say
it explicitly, with a dated note so the change is visible.

### Your 4:3 assumption is safe — verified, not assumed

You're now depending on "`thumb` and `card` are always 4:3", so I checked it across thirteen shapes
rather than take it on trust:

```
   432x768   thumb 400x300 (1.3333)   card 432x324 (1.3333)
   433x769   thumb 400x300 (1.3333)   card 433x325 (1.3323)
  1024x576   thumb 400x300 (1.3333)   card 768x576 (1.3333)
  3024x4032  thumb 400x300 (1.3333)   card 800x600 (1.3333)
  3000x301   thumb 400x300 (1.3333)   card 401x301 (1.3322)
   301x3000  thumb 301x226 (1.3319)   card 301x226 (1.3319)
```

Worst deviation across every realistic shape: **0.0014** — sub-pixel, invisible under
`object-cover`. It only breaks at 1×1, which the minimum-resolution rule now refuses at upload.

So a fixed 4:3 container is correct for both cropped sizes, and this is a property you can rely on
rather than a coincidence of the sizes we happen to hold.

### On your §3 note about the floor

Good observation, and I hadn't spotted it: a 9:16 portrait at long edge 1024 has short edge exactly
576. The floor does sit precisely on the commonest portrait shape. That was luck rather than
design, but it's a better-chosen number than I realised when I proposed it.

---

## 2. §6 — the status line was stale, not a deploy without sign-off

Both halves of your worry, separately:

**Is it live?** Yes. Production got it on **2026-08-26**, after I wrote that doc. The line was true
when written and stale by the time you read it. I've corrected the header.

**Did the upload-side rules go out unapproved?** No — the opposite. Those two rules were the *only*
things I stopped and asked the owner about before building, precisely because they change what
partners can upload:

- minimum resolution → owner chose **1024/576** over the 1280/720 in your request
- aspect ratio → owner chose **not to enforce** it

They then approved the production deploy explicitly, with the 432×768 consequence spelled out in
front of them. So the rules partners are being validated against are the ones the owner picked, not
a default that slipped out.

Fair challenge though — a doc that says "awaiting go-ahead" while the endpoint behaves differently
is exactly the sort of thing that should get questioned.

---

## 3. The thing neither of us knew: production has a CDN

You wrote that our 271 KB and your 311 KB *"differ only because I counted after the re-encode."*
That isn't it. **The backfill never touched those files.**

```
on disk, unit 35's six originals    277,551 bytes = 271 KiB
                                    byte-identical to pre-deploy (md5 verified)
```

None of the twelve carried a metadata block, so the no-inflate rule kept every original exactly as
uploaded. The re-encode you're crediting never ran.

The real cause is that **`api.mamsaa.com` sits behind Hostinger's edge**:

```http
server: hcdn
x-hcdn-cache-status: HIT
cache-control: public, max-age=604800
```

And it rewrites what it serves:

| | on disk | over the wire | md5 |
|---|---|---|---|
| a JPEG original | 64,213 B | **74,636 B** | different |
| its `_thumb.webp` | 13,052 B | 13,052 B | identical |

The CDN re-encodes JPEG — inflating it ~16% for clients that don't accept WebP — and passes our
WebP derivatives through untouched. Dimensions are preserved (1024×576 either way).

It also **content-negotiates**. The same `.jpg` URL with a browser `Accept` header:

```
curl, no Accept header      →  74,636 B  image/jpeg
browser, accepts webp       →  59,114 B  image/webp
```

**Staging has no CDN** (`server: LiteSpeed`, direct). So staging numbers are disk numbers, and
production numbers are not.

### What a browser actually downloads

Your 311 KB is the curl figure. Measured with a real browser `Accept` header:

| | curl (no Accept) | **browser (accepts webp)** |
|---|---|---|
| originals (`url`) | 311 KiB | **235 KiB** |
| `thumb` | 65 KiB | 65 KiB |
| `card` | 135 KiB | 135 KiB |
| `full` | 207 KiB | 207 KiB |

So the honest numbers for the thumbnail strip are **235 KiB → 65 KiB, a 72% cut** — not the 79% we
both calculated from a baseline no browser ever sees.

And `full` at 207 KiB against a 235 KiB original is only a **12%** saving, because the CDN was
already doing WebP conversion for that one. The real win from `full` is the 2048 cap on large
uploads, not the format.

None of this changes the code or the contract. It does mean:

- **§8 "CDN — parking it" is moot.** There already is one, with a 7-day cache. What doesn't exist is
  a *dedicated image* CDN with resizing parameters, which is a different (and now clearly
  unnecessary) thing.
- **Cache invalidation is now a real concern.** `max-age=604800` on immutable-named derivatives is
  fine — a new upload gets a new `fileId` and therefore a new URL. But if we ever reprocess in place
  (`images:process --force` keeps the same filename), the edge would serve the old bytes for up to a
  week. Worth knowing before anyone runs that against production.
- Measure against production with a browser `Accept` header, or you'll be measuring the CDN's JPEG
  path instead of the one your users take.

---

## 4. Your other points

**§10 `sort_order` / hoisting `is_main` in your mapper** — fine, and the right place for it. The
raw response keeps position and cover independent; what your gallery does with that is a
presentation decision. If a partner reorder UI ever lands, `sort_order` is already there to carry
it.

**§8 `alt`** — agreed, it lands with the partner form field, not before. Server-generated captions
would just relocate your placeholder.

**§8 `400` vs `422`** — noted, staying at `400`. Nothing moves.

**§1** — for the record, your original request being off by 50× cost nothing: the mechanism was
real, the fix was the same one either way, and it's now preventative rather than reactive. Finding
the EXIF leak came out of the same investigation.

---

## 5. Nothing needed from you

No API change, no redeploy. The only thing worth acting on is the measurement note in §3, so your
future numbers describe what your users actually download.
