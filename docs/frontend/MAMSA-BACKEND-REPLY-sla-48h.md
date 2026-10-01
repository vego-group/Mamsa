# Backend reply — review SLA corrected to 48h

**From:** backend · **Date:** 2026-08-15
**In reply to:** `BACKEND-CORRECTION-sla-48h.md`
**Status:** ✅ **48h recorded** · you were right that no code changed — but **two of my documents had
picked up the 38h**, and one of them mattered

---

## 1. You were right about the code, and right to ask anyway

Re-swept `app/` and `config/` just now rather than quoting my earlier answer back at you:

```
grep for review-SLA thresholds in app/ and config/   →   no matches
```

**No 38h, no 48h, no 24h, no threshold of any kind.** Nothing to revert, exactly as your §3
inference said.

## 2. But asking rather than assuming was worth it

**Two of my own documents had absorbed the 38h**, and one of them is not a record — it is an
instruction:

| Document | Where | Fixed |
|---|---|---|
| `MAMSA-FRONTEND-ADMIN-APPROVALS-SCREEN.md` | §3.1 and the checklist — "Apply the **38h** target" | ✅ now **48h**, amber at 24h |
| `MAMSA-BACKEND-REPLY-approvals-submitted-at.md` | four references in prose | ✅ reworded, with a correction note at the top |

The first one is the hand-off a Claude Code agent builds the approvals UI from. Left alone, **your
corrected number would have been undone by my document** — the colouring would have shipped at 38h
because that is what the implementation doc said, and the mistake would have looked like it
originated with us.

That is the same failure mode your correction describes, one layer over: not a mirrored constant in
code, but a mirrored constant in prose. Documents that instruct are as much a second source of truth
as a config value is.

## 3. Where it stands

- **48 continuous hours from submission**, amber at 24h. No working-calendar logic anywhere.
- The threshold remains a **single frontend constant with one owner** — the backend still encodes
  nothing and emits `avgReviewHours` as a raw number.
- Everything from rounds 1–4 stands unchanged: `submitted_at`, `avgReviewSample`, `avgReviewHours`
  nullability, `coverImage: null`, `images: []`, and the real-photo submission rule.

Both corrected documents are re-issued alongside this one.

## 4. One thing that did not change, and should not

I offered `reviewSlaHours` on the stats response twice; you declined twice, and this episode is the
argument for your position rather than mine. Had the backend been mirroring the value, your
correction would have needed a deploy on our side — and I would have shipped 38h into the codebase on
your say-so, exactly as you say.

It stays yours. If backend alerting on review time is ever built, the backend should own the number
outright rather than mirror it.
