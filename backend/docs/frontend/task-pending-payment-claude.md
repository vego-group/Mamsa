# Task: adopt `pending_payment` booking status (Claude Code — frontend repos)

**For:** a Claude Code agent working **inside the frontend repos** (admin panel, partner dashboard, guest site).
**Backend status:** ✅ **live on staging** (deployed + verified 2026-08-13) · ⏳ **not on production yet**.
**Because of that split, the code you write must accept BOTH values until production ships — see §2. That is the single most important instruction in this file.**

---

## 1. What changed on the backend

The booking status literal `pending` is now **`pending_payment`**. It is native end to end — the admin
BFF's old translation shim has been deleted, and the DB enum itself was renamed.

```ts
type BookingStatus = 'pending_payment' | 'confirmed' | 'completed' | 'cancelled';
```

Nothing else changed: `confirmed`, `completed`, `cancelled` are untouched.

**Why:** the platform has unit approval and partner approval as separate concepts, so a bare `pending`
read as "awaiting admin approval" when it actually meant "unpaid booking awaiting payment."

---

## 2. ⚠️ Transitional rule — accept both values until production ships

| Environment | `booking.status` returns |
|---|---|
| **Staging** | `pending_payment` ✅ (live now) |
| **Production** | `pending` (until the merge day) |

If you hard-switch to `pending_payment`, **production breaks**. If you never switch, **staging breaks**.
So normalise once at the API boundary and use only the new literal everywhere inside the app:

```ts
// api/normalise.ts — delete the fallback after production ships
const LEGACY_STATUS: Record<string, BookingStatus> = { pending: 'pending_payment' };

export const normaliseBookingStatus = (s: string): BookingStatus =>
  (LEGACY_STATUS[s] ?? s) as BookingStatus;

// apply wherever a booking enters the app
const booking = { ...raw, status: normaliseBookingStatus(raw.status) };
```

Rules:
- **Never** compare against `'pending'` in components, selectors, or tests — compare against
  `'pending_payment'` only, after normalisation.
- Keep the map in **one** file with a `TODO: remove after prod deploy` comment so the cleanup is a
  one-line deletion later.
- Do **not** branch on environment (`if (isStaging)`). Normalise on value, not on host — the same build
  must work against both.

---

## 3. Per-repo work

### 3.1 Admin panel — probably a no-op, verify only
It already asserted `pending_payment` (the backend used to fake it via a shim). Now it is genuine.
- [ ] Confirm the bookings list, status filter, and count tiles still work against staging (§5).
- [ ] `GET /admin/bookings/counts` keys are unchanged: `{ all, pending_payment, confirmed, completed, cancelled }`.

### 3.2 Partner dashboard — **breaking, this is the main work**
- [ ] Apply the normaliser (§2) where bookings are fetched.
- [ ] Replace every `'pending'` comparison for a booking with `'pending_payment'`.
- [ ] Rekey status→label / status→badge maps.
- [ ] Update any status value sent as a filter to `GET /bookings`.

### 3.3 Guest site — **breaking**
- [ ] Same normalisation + comparison updates.
- [ ] If you render the server's `status_label`, note the Arabic string changed:
      `قيد الانتظار` → **`بانتظار الدفع`**. No code change, but snapshot/copy tests may need updating.

### 3.4 All repos
- [ ] Update the `BookingStatus` union (§1).
- [ ] Update fixtures, mocks, MSW handlers, and factories that emit `'pending'`.
- [ ] Search the **raw string**, not just the type: `grep -rn "'pending'" src/` — then check each hit
      against §4 before changing it.

---

## 4. 🚫 Do NOT rename these — same word, different concepts

A blind find-and-replace here breaks partner approval and calendar feeds. All of these legitimately
stay `pending`:

| Concept | Field / values |
|---|---|
| **Unit approval** | `unit.approval_status` = `draft \| pending \| approved \| rejected` |
| **Partner application** | `partner.status` = `pending \| approved \| rejected` |
| **KYC / documents** | `missing \| pending \| verified` |
| **Refund status** | `pending \| succeeded \| failed` |
| **Guest wallet transaction** | `completed \| pending \| failed` |
| **Upload status** | `pending \| stored` |
| **User account state** | `pending_activation` (unrelated word, unrelated meaning) |

Only **booking** status changed.

### 4.1 One deliberate exception on the legacy API

Three `/api/v1` admin endpoints return **counts keyed by status**, and their **response key is still
`pending`** so the legacy Vue admin keeps working — even though the DB value is now `pending_payment`:

- `GET /api/v1/admin/dashboard` → `bookings.pending`
- `GET /api/v1/admin/bookings/stats` → `pending`
- `GET /api/v1/admin/reports/summary` → `booking_status.pending`

If you consume these, read the key `pending` there. It is a **count label**, not a status value. Do not
run it through the normaliser.

---

## 5. Verify against live staging

Host `https://staging.mamsaa.com`, cookie session, `credentials: 'include'`.
Login (OTP is fixed on staging): superadmin `+966555000003`, finance `+966555000004`, code `<fixed OTP — request privately>`.

```bash
BASE=https://staging.mamsaa.com
curl -s -c jar -b jar -X POST $BASE/admin/auth/request-otp \
  -H 'Content-Type: application/json' -d '{"phone":"+966555000003"}'
curl -s -c jar -b jar -X POST $BASE/admin/auth/verify-otp \
  -H 'Content-Type: application/json' -d '{"phone":"+966555000003","code":"<fixed OTP — request privately>"}'

curl -s -b jar "$BASE/admin/bookings/counts"
# → {"all":58,"pending_payment":0,"confirmed":0,"completed":50,"cancelled":8}

curl -s -o /dev/null -w '%{http_code}\n' -b jar "$BASE/admin/bookings?status=pending_payment&pageSize=1"
# → 200
```

Note `request-otp` is throttled to **3 per 10 minutes per phone** — a 429 is the throttle, not a broken
account.

**Current staging data has 0 bookings in `pending_payment`** (50 completed, 8 cancelled), so to exercise
the state visually you will need to create a booking and leave it unpaid.

---

## 6. Acceptance checklist

- [ ] No `'pending'` comparison remains for a **booking** status anywhere (§4 exceptions untouched).
- [ ] `BookingStatus` union updated in all three repos.
- [ ] Normaliser in place, in exactly one file, with the removal TODO.
- [ ] App works against **staging** (`pending_payment`) **and** production (`pending`) from the same build.
- [ ] Fixtures/mocks updated; tests green.
- [ ] Partner dashboard and guest site render the unpaid-booking state correctly.
- [ ] `/api/v1` count consumers still read the `pending` key.

---

## 7. After production ships

The backend will announce the production deploy (the merge day is still to be set by the owner). On
that day:

1. Delete `LEGACY_STATUS` and simplify `normaliseBookingStatus` to a cast — or remove it entirely.
2. Remove any remaining `pending` fixtures.
3. Optionally coordinate with the backend to unify the `/api/v1` count keys (§4.1) — that is a separate
   decision tied to how long the legacy Vue admin stays alive.

**Do not do step 1 before the production deploy is confirmed.**
