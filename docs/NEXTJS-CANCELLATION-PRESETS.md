# Done — Cancellation policy presets + Moyasar partial refunds

**Date:** 2026-07-19 · **Status:** ✅ live on **staging AND production**, payloads below verified live. Reply to `Mamsa-Backend-Cancellation-Policy-Presets.md`.

Good news first: the backend already had the full FR-036 engine (per-unit policy → frozen snapshot → tier lookup → Moyasar **partial** refund in exact halalas). What shipped now: the approved preset table replaced the old draft values, `moderate` became the default, and the partner dashboard can finally *choose* the preset per unit. Answers to your 4 questions at the end.

---

## 1. The approved table is live (as data, per NFR-013)

```
days before check-in     flexible    moderate*   strict
7+   (>=168h)              100%        100%        75%
3–7  (>=72h)                75%         50%        25%
<3   (>=0h)                 50%         25%         0%
after check-in            locked      locked      locked     * = default
```

Stored in DB (`cancellation_policies` + `policy_tiers`), not code — verified on prod. Changing a percentage later = a data re-seed, zero business-logic deploys. **Note:** tiers are expressed in `min_hours_before_checkin` (168/72/0), which is exactly your 7/3/0 days — render days by dividing by 24. Hours were kept because the check-in moment is a timestamp (date + unit `checkIn` time), so "3 days" is measured to the hour, not the calendar day.

## 2. `cancellationPolicy` on the dashboard unit contract

```
POST  /units          body may include: "cancellationPolicy": "flexible" | "moderate" | "strict"
PATCH /units/{id}     same; invalid value → the dashboard VALIDATION envelope (400)
GET   /units, /units/{id} → every unit echoes "cancellationPolicy": "moderate"   (verified)
```

- Optional on create: a unit whose partner never chose gets **moderate** (the response echoes what the engine will actually apply — you'll never see `null`).
- Editing it on an approved unit behaves like any edit (unit returns to review) and **never** touches existing bookings — see §3.
- Public user site: `GET /api/v1/units/{id}` already exposes `cancellation_policy_details: { template: "moderate", name: "متوسطة", tiers: [...] }` — use `template` for the preset badge and `tiers` (with Arabic `label`s ready-made) for the policy card at checkout. Ignore the legacy `cancellation_policy` enum string.

## 3. Snapshot (FR-036) — frozen at payment success

The snapshot freezes when the booking is **paid** (that's when there is money to ever refund), same shape you referenced:

```json
// booking.policySnapshot (dashboard camelCase view)
{ "name": "moderate", "rules": "متوسطة", "tiers": [
  { "min_hours_before_checkin": 168, "refund_percent": 100, "label": "أكثر من 7 أيام" },
  { "min_hours_before_checkin": 72,  "refund_percent": 50,  "label": "من 3 إلى 7 أيام" },
  { "min_hours_before_checkin": 0,   "refund_percent": 25,  "label": "أقل من 3 أيام" } ] }
```

(User-site: `cancellation_snapshot` with `policy_key`/`policy_name`/`checkin_at`/`tiers`, null until paid.)
After payment, the engine reads **only** the snapshot. Proven in prod right now: the preset table was re-seeded to the new values yesterday, and existing bookings' snapshots still carry the exact old tiers they were sold under. A partner switching the unit's preset later has zero effect — regression-tested.

## 4. Refund execution — fully server-side, partial, in halalas

Exactly your 5 steps, already wired:

```
guest cancel → quote from frozen snapshot → hours-before-check-in → tier %
  → refundAmount = round(total_paid × % / 100, 2)
  → Moyasar POST /payments/{id}/refund { amount: refundAmount × 100 }   (partial, exact halalas)
```

Plus behaviors worth showing in UI:
- **Preview before confirm:** `GET /api/v1/bookings/{id}/cancellation-preview` returns the applicable tier + exact refund NOW — render it in the confirm dialog; never compute percentages client-side.
- **Full-refund optimization:** a 100% refund within ~2h of payment is executed as a Moyasar *void* (reversal) instead of a refund — invisible to the guest, faster money back.
- **After check-in:** cancel returns **422** with a reason — hide the button when the preview says not cancellable (your "locked" row).
- The refund also lands as a positive entry in the guest wallet ledger (`GET /user/transactions`) and the payment's `refunded_amount` updates.
- **Host-cancel unchanged:** partner cancellation stays a flat 100% guest refund regardless of preset, as you specified.

## Your 4 questions

1. **`cancellationPolicy` on POST/PATCH/GET units?** ✅ Yes — dashboard contract as §2; public unit exposes it via `cancellation_policy_details.template`.
2. **`policySnapshot` in booking responses?** ✅ Yes — both views (guest `cancellation_snapshot`, dashboard `policySnapshot`), populated at payment; null on unpaid bookings (nothing refundable exists yet).
3. **Refund entirely server-side?** ✅ Yes — tier lookup, rounding, halalas conversion, and the Moyasar call are all backend; the cancel endpoint takes no amount at all and ignores one if sent.
4. **Where does the config live?** DB tables (`cancellation_policies`/`policy_tiers`) seeded from `CancellationPolicySeeder` — the natural place to hang a superadmin editor later; a future `PATCH /admin/cancellation-policies/{key}` would be a small addition since everything reads the DB already.

Postman: dashboard unit bodies now include `cancellationPolicy`; `Cancel Booking` + `Cancellation Preview` carry the preset table in their descriptions.
