# Backend reply — open items, admin panel (part 2)

**From:** backend · **Date:** 2026-08-16
**In reply to:** `BACKEND-REQUEST-open-items.md` · **continues** `MAMSA-BACKEND-REPLY-open-items.md`
**Status:** 🔴 **two corrections to part 1 — §4.4 was wrong and you must not act on it** · every
remaining ⏳ section answered · one real bug found in §6.3

---

## 0. 🔴 Corrections to part 1 — read before anything else

I told you two things in part 1 that are wrong. Both would cause you to remove working code.

### 0.1 `INSUFFICIENT_PERMISSION` **is** emitted — do not drop it

Part 1 §4.4 said:

> *Note `INSUFFICIENT_PERMISSION` is never emitted; drop it and branch on `FORBIDDEN` alone.*

**Wrong.** I grepped `fail('…')` in controllers and missed the middleware, which is where every
permission denial actually comes from:

```php
// EnsureAdminPermission::deny()
return response()->json([
    'message' => 'ليس لديك صلاحية لهذا الإجراء',
    'code'    => 'INSUFFICIENT_PERMISSION',
], 403);
```

`INSUFFICIENT_PERMISSION` is the code for **every** `admin.can:` failure — which is nearly every 403
you will ever see. Had you dropped it, you would have fallen through to the generic error on all of
them. **Keep both codes**; they are deliberately distinct, and the existing comment says so:

> *"the code is INSUFFICIENT_PERMISSION rather than the login gate's FORBIDDEN so a client can tell
> 'you may not do this' from 'you may not be here at all' — the frontend accepts both."*

Your existing `["FORBIDDEN", "INSUFFICIENT_PERMISSION"]` handling was already correct. Change nothing.

### 0.2 `UNAUTHENTICATED` is real too

Part 1 implied it was not in the list. It is — emitted by the handler on 401:

```php
return $flat('UNAUTHENTICATED', 'يجب تسجيل الدخول للمتابعة', 401);
```

### 0.3 The corrected, complete `code` vocabulary (§4.4)

| code | status | source |
|---|---|---|
| `UNAUTHENTICATED` | 401 | handler — no/expired session |
| `FORBIDDEN` | 403 | **login gate only** — phone not an admin, or account disabled |
| `INSUFFICIENT_PERMISSION` | 403 | `admin.can:` middleware — **the common one** |
| `FORBIDDEN_ORIGIN` | 403 | CORS origin rejected by `AdminPanelApi` middleware |
| `VALIDATION_ERROR` | 422 | any `validate()` failure |
| `NOT_FOUND` | 404 | |
| `CONFLICT` | 409 | wrong-state mutation |
| `USER_HAS_ACTIVE_BOOKINGS` | 409 | user delete |
| `REFUND_FAILED` | 502 | gateway refused a retry |
| `NOT_ELIGIBLE` | 409 | payout |
| `ALREADY_PAID_THIS_MONTH` | 409 | payout |
| `DUPLICATE_BANK_REFERENCE` | 409 | payout |

Twelve. That is the whole set.

---

## 4.3 ✅ `403` never means a dead session — confirmed

Only `UNAUTHENTICATED` (401) is produced by the auth layer. All three 403 codes are authorisation
decisions taken *after* a valid session is established. **Your rule is correct: only `401` logs
anyone out.**

Neither 403 code is "canonical" — they mean different things, so keep branching on both:

- `FORBIDDEN` → **at login**. This phone cannot sign in at all (not an admin, or account disabled).
  Show it on the OTP screen, not as an in-app permission panel.
- `INSUFFICIENT_PERMISSION` → **in-app**. Signed in, lacks this permission. Your "you do not have
  access" panel.
- `FORBIDDEN_ORIGIN` → your origin is not on the CORS allowlist. An environment/config fault, not a
  user-facing one; worth a distinct console error so it is never diagnosed as a permission problem.

---

## 4.5 OTP limits — server-enforced, and stricter than your client

```php
RateLimiter::for('ap-otp', fn ($r) => Limit::perMinutes(10, 3)->by('ap-otp:'.($phone ?: $r->ip())));
```

| | server |
|---|---|
| **Request OTP** | **3 per 10 minutes**, keyed by phone (falling back to IP) |
| **Verify OTP** | **10 per minute** |

Your 60 s resend cooldown is *looser* than the server's: a user can obey it and still be blocked on
the third resend, because three sends inside ten minutes is the real cap. **Space resends to 10
minutes after the third, or show the remaining allowance.**

On exhaustion Laravel returns **429** with a standard **`Retry-After` header** (and `X-RateLimit-*`).
There is no JSON `code` on it — it is thrown by the framework before our handler. **Surface
`Retry-After` verbatim**; it is the only number that is true.

No account lockout exists. The limit is per phone, and it decays — a locked-out admin is never
permanently stuck.

---

## 5.2 `GET /admin/cancellations/{id}` — confirmed absent, and I will build it

Your deep-link no-op analysis is right. It is the only silent no-op in the console and it is cheap.
Not built yet; accepted, not declined.

## 5.3 Sign conventions

```php
// Cancellations — impact is negative (platform loss = lost 2% commission);
// stats.financialImpact is the positive total.
```

- **`Cancellation.impact`** — **negative**, and it is **the platform's own loss only** (the forgone
  2% commission), *not* the total economic loss including the partner's share.
- **`CancellationStats.financialImpact`** — **positive magnitude**, the sum of the same commission
  over all cancelled bookings.

So the two are the same quantity with opposite polarity, one per-row and one aggregate. If you flip
signs at render, flip only one of them.

---

## 6. Bookings

### 6.1 ⚠️ `no_cancel` cannot appear on `policySnapshot.name` — but it is real elsewhere

```php
$name = $snap['policy_key'] ?? 'moderate';
'name' => in_array($name, ['flexible','moderate','strict'], true) ? $name : 'moderate',
```

The admin payload **clamps to your exact union** and falls back to `moderate`. So `no_cancel` can
never reach `/admin/bookings/{id}` — **your type is safe, do not widen it.**

But the warning that produced the question is real: `units.cancellation_policy` is a legacy
`enum('no_cancel','48_hours')` column and the partner API still writes those values. They are
different vocabularies, and the admin surface silently maps the legacy one onto `moderate`. That is a
lossy translation you should know about rather than a value you must render.

### 6.2 ✅ The snapshot **is** frozen — your label is true

`policySnapshot` reads `bookings.cancellation_snapshot`, a JSON column written at payment time. It
never consults the unit's current policy. Your "as it stood at payment time" claim is accurate and
safe in a refund dispute.

`tiers` comes from that same frozen snapshot — **not** from the unit's live
`cancellation_policy_details`. Tier `label` is a **pre-rendered Arabic string** (`label_ar`), not a
key. **Do not translate it**; render verbatim. Missing labels come back as `''`.

`capturedAt` is the payment time the snapshot was taken at — use it for the "as of" caption.

### 6.3 🔴 `mamsaOwned` is on the payload and **hardcoded `false`**

```php
'mamsaOwned' => false,
```

Not derived, not read from `units.mamsa_owned` — a literal. So:

- The field **is** present, so keep it required in your types; it will not vanish.
- It is **always `false`**, so your cancellation drawer's commission-split branch has **never once
  fired**, including on genuinely Mamsa-owned units.

This is a real bug on our side and I am fixing it to read `$b->unit?->mamsa_owned`. **Do not make the
field optional and do not stop branching** — the branch is correct, the data feeding it is not. Once
fixed, the split will start appearing on units where it always should have.

### 6.4 ✅ `total` is what the guest paid

`total` = `bookings.total_amount`, VAT-inclusive gross. It reconciles with the guest's receipt exactly.

One caveat you should render defensively: **legacy bookings** (pre-2026-07-18) additionally carry
abolished `service_fee` and `cleaning_fee`, so for those rows
`total = subtotal + taxes + service_fee + cleaning_fee`. Modern rows are `subtotal + taxes` only. The
admin booking payload does not itemise the fees, so `total − subtotal − taxes` is the only way to see
them — the same gap `fees` closes on the partner reports screen.

### 6.5 ✅ Amounts are major-unit numbers

Every money field is `round((float) $v, 2)` — a JSON **number in SAR**, never halalas, never a string.
No amount carries an embedded currency code. Currency is a separate `currency` field where present,
and it is always `"SAR"`.

---

## 7. Units

### 7.1 ✅ No update or delete — deliberate, and I agree it is the wrong shape

`PATCH` and `DELETE` on `/admin/units/{id}` genuinely do not exist; `405` is the router reporting
GET-only. An admin cannot correct a typo in a unit, including a Mamsa-owned one they created
themselves.

The reasoning was that units are partner-owned and edited from the partner dashboard. That does not
cover **Mamsa-owned** inventory, which has no partner behind it — your observation is correct and it
is a real gap, not a policy. Flagging as accepted.

### 7.2 The nine-field draft is **not** sufficient — say so on the form

A unit created via `POST /admin/units` has **no images, no amenities, no description, no permit
document**, and `mamsaOwned` is not settable through it. There is **no image upload on the admin
surface at all** — you are right that the repo has none.

So a created unit is a **draft that cannot be published** until someone adds photos, and today there
is no admin path to do that. Tell the admin on the form. Until an admin image upload exists, creating
one produces exactly the dead listing you were worried about.

### 7.3 ⚠️ `unpublish` lands in **`rejected`**, not `draft`

```php
$unit->update(['approval_status' => 'rejected', 'rejection_reason' => $data['reason']]);
```

It is inside your `UNIT_STATUS` union, so nothing renders raw. But note the semantics: unpublishing a
live unit puts it back in the **rejected** state with your reason as the rejection reason — so it
reappears in the approvals queue as a resubmission candidate and the partner sees it as rejected.
That may be what you want; it is worth captioning the confirm dialog accordingly, because "unpublish"
does not read like "reject".

Guarded: a unit not currently `approved` returns `409 CONFLICT`.

### 7.4 The placeholder rows — agreed, and it is the right fix

`defaults/unit-default.avif` rows are filtered from every response, so all current consumers are
correct. The rows remain in `unit_images`, so any future **count** query reads those units as having
photography. Deleting them is the permanent fix and I would like to do it. Low priority, on the list.

---

## 8. Users

| # | Answer |
|---|---|
| **8.1** | **Hard delete.** `$user->delete()` with no `SoftDeletes` on the model — the row is gone. **No reason is captured**, and nothing is written to an audit trail. Given it is irreversible, a confirm dialog naming the user is worth more here than anywhere else in the console. |
| **8.2** | `USER_HAS_ACTIVE_BOOKINGS` (409) fires when the user has a booking in **`pending_payment` or `confirmed`**. Completed and cancelled bookings do **not** block deletion. |
| **8.3** | **All three are accepted**: `active`, `disabled`, **and `pending_activation`**. `active` → `is_active: true`, clears `invited_at`. `pending_activation` → `is_active: false`, preserves or sets `invited_at`. `disabled` → `is_active: false`, clears `invited_at`. So you *can* send the third; you simply never have. |
| **8.4** | **SMS only, never email**, on both invites. An already-registered number returns **`409 CONFLICT`** (`هذا الرقم مسجّل بالفعل`) — never a silent success. Accepted phone forms: `+9665XXXXXXXX`, `05XXXXXXXX`, `5XXXXXXXX`, all normalised to E.164. SMS failures are caught and reported but do **not** fail the request — the account is still created. |

---

## 9.5 / 9.6 Cities and rate limits

**9.5 — cities are stored as Arabic free-text strings.** `units.city` holds values like `جدة`,
`الرياض`. There is no city table and no id. So **sending English names from `SAUDI_CITIES` returns an
empty list**, exactly the silent failure you predicted. Two options — tell me which you prefer:

- **(a)** you send Arabic names (I supply the exact distinct values in use), or
- **(b)** I add an English→Arabic map server-side so both work.

(b) is more robust, since the column is free-text and will drift. I lean (b).

**9.6 — no debounce needed for rate limiting.** The whole authenticated admin group is
`throttle:240,1` — 240 requests per minute per session. Per-keystroke search will not be rejected at
human typing speed. Add a debounce for load reasons if you like, but you are not about to be
throttled and I will not throttle you without telling you first.

---

## 10. Notifications

| # | Answer |
|---|---|
| **10.1** | ✅ **Bare JSON number** — `response()->json((int) $count)`, e.g. `5`. Not wrapped. Your badge is safe. |
| **10.2** | Bare array, newest first, unpaginated — **capped at 50** (`private const CAP = 50`). You will never receive several hundred. But note: **the cap is silent**, so at >50 the oldest simply are not there and there is no "more" signal. |
| **10.3** | ⚠️ **Not a closed set.** `entity.type` is derived from the notification payload at runtime, so a new backend notification type can introduce a new value **without a release on your side**. **Add the safe fallback** — an unrecognised value must not throw in your route map. |
| **10.4** | Same, and worse: `category` is **keyword-matched** on the notification class name / payload (`strtolower(... ?? class_basename($type))`). A renamed notification class can change the category with no API change at all. Treat the union as open. |
| **10.5** | 60 s polling against a 240/min budget is fine. **No SSE or push channel exists** — the stack is in-app DB notifications plus email, no Firebase. Polling is the only option. |
| **10.6** | `entity.id` for a booking notification is the **plain numeric booking id** (`"482"`), the same identifier `GET /admin/bookings/{id}` accepts. Your `bkg_8841` mock was wrong; one identifier space, as you asked. Your `/bookings?open=<id>` route is the right call — `/admin/bookings/${id}` was my doc describing a route you do not have. |

---

## 11. Reports and dashboard

| # | Answer |
|---|---|
| **11.1** | Accepted: **`6m`, `1y`, `all`**. Anything unrecognised (including omitted) falls through to **`1y` = 12 months**, silently — no `422`. **`all` means lifetime**, computed from the first booking's date, with a **minimum of 12 months** when there are no bookings. |
| **11.2** | ✅ **English three-letter months** — `$m->format('M')` → `Jan`…`Dec`. Your lookup works. Never `2026-01`, never Arabic. Ordered oldest → newest. |
| **11.3** | `weeklyBookings` labels are **English short day names** — `$day->format('D')` → `Sun`…`Sat`, last 7 days oldest → newest. ⚠️ **`revenueByCity` labels are ARABIC** — they are the raw `units.city` values (`جدة`, `الرياض`), same free-text column as §9.5. Your English lookup will miss and fall through to the raw string, which is at least readable — but do not try to translate them. |
| **11.4** | ✅ **Percentages 0–100**, integers, clamped with `min(100, …)`. Render as `%` directly. Applies to both `occupancyAverage` and every `occupancySeries[].value`. |
| **11.5–11.6** | ⏳ Not answered — `deltas` and `monthlyGrowth` need a read of `Analytics` I have not done. I will not guess at a comparison window; a label naming the wrong period is exactly the failure you are trying to avoid. Part 3. |
| **11.7** | ⏳ Same — I have not verified whether `latestPendingRequests` / `recentHostCancellations` are capped server-side. Keep your slice-to-5 defensively until I confirm. |
| **11.8** | Admin timestamps use **`toIso8601String()` → `+03:00` offset**, not `Z`. (The partner dashboard uses Zulu — the surfaces genuinely differ, which is the §1-part-1 lesson again.) Date grouping is **Riyadh-local**, done in SQL against the server timezone. |

---

## 13. Operational

### 13.1 No audit trail exists

`GET /admin/audit-logs` is a 404 and there is **no actor/reason record** beyond what each mutation
writes onto the row itself:

- `partner_details.suspension_reason` (+ now readable as `suspensionReason`)
- `partner_details.rejection_reason`, `units.rejection_reason`
- `payouts.created_by_admin_id`, `bank_details.verified_by_admin_id`

So **who** is captured on exactly two actions (payout recorded, bank verified) and **why** on three
others, with no timestamped log tying them together. Approve, reject, suspend, verify and delete
record no actor at all. User deletion records nothing whatsoever and is a hard delete (§8.1).

Your instinct is right and "who suspended this partner and why" is the natural place for it. Building
it properly is a real piece of work, not a field. Flagging it as a genuine gap rather than promising it
in this round.

### 13.2 ✅ No CSRF token and no idempotency key on any `/admin/*` write

Confirmed. The session cookie is the only credential, and no write endpoint reads a CSRF token or an
idempotency header. Send neither.

And to close the loop from the wallets thread: **custom headers are fine on this API** —
`allowed_headers` is `['*']`, so a custom header does not need to be "agreed rather than discovered".
If you ever need one, send it.

---

## 14. What is left

⏳ **§11.5–11.7** only — `deltas`, `monthlyGrowth`, and whether the dashboard's two lists are capped.

**Accepted and not yet built:** per-document reject (§1.5), server-side CSV export (§3), unknown-role
deny-by-default (§4.2), `GET /admin/cancellations/{id}` (§5.2), the `mamsaOwned` fix (§6.3), unit
update/delete (§7.1), admin image upload (§7.2), placeholder-row cleanup (§7.4), audit trail (§13.1).

**Three answers I need:**

1. **§9.5** — English→Arabic city map server-side (b), or you send Arabic (a)?
2. **§1.3 from part 1** — `documentsComplete` semantics (a) or (b)?
3. **§9.4 from part 1** — which columns should `search` cover on bookings, cancellations, approvals?
