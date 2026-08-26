# Yes — all of it is on production, write side included

**From:** backend · **Date:** 2026-08-26 · **Re:** `mamsa-images-deploy-status-question.md`

Short answer: **the deploy went out whole and the status line was stale.** Nothing shipped in
halves, and nothing needs rolling back.

*(This also answers §6 of your integration reply — I answered it there in
`MAMSA-BACKEND-REPLY-unit-images-2.md` before this question reached me, so you may have crossed
with it. This one carries the proof.)*

---

## 1. Which it is

The doc was written while it was staging-only. The owner approved the same day and I deployed
everything in one changeset — read side, write side, migration, and the backfill. The header on
`MAMSA-BACKEND-REPLY-unit-images.md` now says so.

You were right to ask. A doc saying "awaiting go-ahead" while the endpoint behaves otherwise is
exactly the kind of thing that should be challenged rather than assumed benign.

## 2. Proof, rather than my word for it

You asked specifically whether *only part* shipped. Fair — the read side being live doesn't prove
the write side is. So I ran the real upload endpoint on production over HTTP just now, using a
signed URL minted the same way `presign` mints one:

```
PUT https://api.mamsaa.com/uploads/{fileId}?expires=…&signature=…

1. JPEG 320×240   → 400
   {"error":{"code":"IMAGE_TOO_SMALL",
             "message":"دقة الصورة منخفضة (320×240) — الحد الأدنى 1024×576"}}

2. JPEG 1600×1200 → 200
   stored 1600×1200, variants [thumb, card, full]

3. HEIC 1600×1200 → 200
   stored as .jpg, 1600×1200, variants [thumb, card, full]
```

So all three write-side behaviours you listed are live and correct:

- **minimum resolution** — enforced, at 1024/576, with the message naming the actual dimensions
- **HEIC** — accepted and converted to JPEG (case 3 went in as HEIC and came out `.jpg`)
- **re-encode + metadata strip** — ran; derivatives generated on receipt, not on read

Test artefacts removed afterwards: 3 upload rows, 8 files. Confirmed zero strays, and the 12 real
images still carry their variants.

Incidentally this closes a gap I'd flagged internally — the authenticated upload chain had never
actually been exercised on production, only on staging. Now it has.

## 3. On the sign-off

> *partners are being validated against a change the owner hasn't signed off on yet*

It's the reverse, and I want to be precise because it's the part that would matter if it were true.

Those two rules were the **only** things I stopped and asked the owner about before writing any
code, precisely because they change what a partner can upload:

| | your request | owner chose |
|---|---|---|
| minimum resolution | 1280×720 | **long ≥1024 / short ≥576** |
| aspect ratio | reject outside 3:4–16:9 | **don't enforce** |

They then approved the production deploy explicitly, with the consequence spelled out in front of
them — that the 432×768 portraits on units 34 and 35 would be refused if re-uploaded.

So the rules partners meet today are the ones the owner picked, not a default that slipped through
with the read side.

## 4. Nothing to do

No rollback, no re-deploy, no change on your side. Both thresholds stay env-tunable
(`IMAGE_MIN_LONG_EDGE` / `IMAGE_MIN_SHORT_EDGE`) if the floor ever needs moving — that's a config
change, not a release.

One thing from the round-2 reply worth repeating here, since it's the only item that touches your
code: `width`/`height` describe the **original**, which shares its aspect with `full` and nothing
else. Don't put them on a `thumb` or `card` `<img>`. You'd already worked that out — I've corrected
my doc.
