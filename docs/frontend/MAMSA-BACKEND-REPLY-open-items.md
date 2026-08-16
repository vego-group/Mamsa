# Backend reply — open items, admin panel (part 1)

**From:** backend · **Date:** 2026-08-16
**In reply to:** `BACKEND-REQUEST-open-items.md`
**Status:** your three 🔴 priorities are answered in full · **§2 is not an authorization problem at
all** · part 2 follows for §6–§8, §10, §11, §13

You asked that if we answer only three things, they be §1, §2 and §5.1. Those are answered from the
code and from live probes against staging, not from memory. Sections I have **not** verified yet are
marked ⏳ rather than guessed at — a confident wrong answer here is worse than a late one.

---

## 2. 🔴 `/storage/*` — there is no 403 to fix. The URL is wrong.

This one first, because it is the one where the fix is entirely on your side and takes minutes.

```
/storage/file_01kxr0mvdntxqwswwhf9vsrjfm                            → 403   ← what you tested
/storage/dashboard/license_pdf/file_01kxr0mvdntxqwswwhf9vsrjfm.pdf  → 200   ← what the API returns
```

`file_01kx…` is an **upload id, not a path**. Unit 20's `tourism_permit_file` column holds that id;
`permitFileUrl` resolves it through the uploads table into the second URL, which serves fine. Verified
live on staging just now, on that exact unit.

So the payload is not what you are requesting. Either you are reading a `…FileId` field and prefixing
`/storage/` yourselves, or `permitFileUrl` is being dropped somewhere before the `<iframe>`. **Use
`permitFileUrl` verbatim and never construct a storage URL from an id** — ids and paths are different
namespaces and the mapping (kind directory, extension chosen from the file's real bytes) only exists
server-side.

### 2.1 Why it looked like authorization

A **missing** file under `/storage/*` falls through to PHP and renders Laravel's own styled **403**
page — not a 404. That is what produced an app-level-looking 403 with `X-Powered-By: PHP` on a path
that simply does not exist. Your inference was reasonable; the signal was misleading.

### 2.2 Your three questions

| | |
|---|---|
| **Authorization** | None. Plain static files, no policy, no session, no admin check. Nothing to allow. |
| **URL type** | **Permanent and public.** Not signed, no TTL. Cache them freely. |
| **Embeddability** | **No `X-Frame-Options`, no `frame-ancestors`.** Only `Content-Security-Policy: upgrade-insecure-requests`. `<iframe>` works — keep it. |

Worth naming: these URLs are public and unguessable only by ULID. That is a deliberate trade for
shared hosting with no object store, but it means a leaked permit URL stays valid. Flagging it as a
known property, not asking you to do anything.

---

## 1. 🔴 Partner KYC documents

### 1.1 The badge is **derived**, and you are right to distrust it

```php
$default = match ($d->status) {          // ← partner-level KYC status
    STATUS_APPROVED => 'verified',
    STATUS_REJECTED => 'rejected',
    default         => 'pending_review',
};
'status' => in_array($kind, $d->verified_documents ?? [], true) ? 'verified' : $default,
```

So a row reads `verified` if **either** an admin explicitly verified that document **or** the partner
is approved at KYC level. For an approved partner, all five rows go green at once and the badge tells
you nothing about that document.

**Your per-document Verify button is real** — `verified_documents` is a genuine per-document decision
list and the endpoint writes to it. Keep the button. What is wrong is the *default*: it pre-marks
documents as verified before anyone reviewed them, exactly as you suspected.

### 1.2 Which kinds carry files, and which never can

| kind | backing | can ever have a file? |
|---|---|---|
| `commercial_registration` | `cr_number` | ❌ **never — hardcoded `null`** |
| `iban` | `iban` | ❌ never — a string, not a document |
| `national_id` | `national_id` + `national_id_file` | ✅ value **and** file |
| `vat_certificate` | `vat_certificate_file` | ✅ file |
| `operator_license` | `operator_license_file` | ✅ file |
| `authorization_letter` | `authorization_letter_file` | ✅ file |

So `PTR-024` showing "No file attached" on **commercial registration** and **IBAN** is correct and
permanent; on the other three it means the partner has not uploaded them.

The CR is the uncomfortable one: it is the document that proves the company exists and states what it
is licensed to do, and there is nowhere to put the scan. **A `cr_file` column plus the presign flow is
built and tested** — it was reverted for unrelated reasons and can be restored on request. Say the
word and `commercial_registration.fileUrl` stops being permanently null.

### 1.3 `documentsComplete` vs `documents[]` — confirmed drift, two different sources

```php
// documentsComplete
$required = $d->type === 'company' ? ['cr_number', 'iban'] : ['national_id', 'national_id_file', 'iban'];
return collect($required)->every(fn ($c) => filled($d->{$c})) && $d->status === STATUS_APPROVED;
```

It reads **columns**; `documents[].status` reads the **KYC status**. Both can therefore be right about
different things and contradict each other on screen. In your `PTR-024` payload the partner is
approved (hence five greens) but `iban` is empty, so `documentsComplete` is `false`.

Note it is **not** "all required docs are verified" as `BACKEND_SPEC.md:184` says — it is "the
required *fields* are filled **and** the partner is approved". Neither the spec line nor the field name
matches the behaviour.

**You were right not to recompute it client-side.** Fixing the source is ours. Two candidates and I
would rather agree the semantics with you than pick:

- **(a)** `documentsComplete` = every document in `documents[]` whose kind can carry a file has one,
  and every value-backed kind has a value. Self-consistent with the list beneath it.
- **(b)** Drop `documentsComplete` and let you derive from `documents[]`, which you already render.

(a) keeps your header, (b) removes a field that has never meant what it says. **Tell me which** and it
ships with part 2.

### 1.4 `id` vs `kind` — they are identical

```php
$mk = fn ($kind, …) => ['id' => $kind, 'kind' => $kind, …];
```

`id` **is** the slug. `document.id` and `document.kind` are the same string on every row, so what you
send today is correct and the Postman collection is consistent with it. No change needed.

### 1.5 Per-document reject — not built, and worth building

Confirmed 404. You are right that "wave through, or reject the whole partner and lose the reason" is
the only choice today. It is the natural counterpart to a `verified_documents` list and I would like
to build it. Not in part 1; flagging it as accepted rather than declined.

---

## 5.1 🔴 `retry-refund` keys on the **booking id** — you are correct

```php
public function retryRefund(string $id): JsonResponse
{
    $booking = Booking::with(['payment', 'refunds'])->where('status', 'cancelled')->find($id);
```

It resolves a **Booking**, filtered to `status = cancelled`. Your Postman-derived `{{booking_id}}` is
right and **no retry has ever hit the wrong record.** The path segment being named `{id}` under
`/cancellations/` is the misleading part — that is our naming, not a different identifier space.

A wrong id fails closed with `404 NOT_FOUND` (`الإلغاء غير موجود`), so this could not have silently
mis-refunded even if it had been wrong.

---

## 3. Exports — cap is **100**, and it is enforced

```php
'pageSize' => min(100, max(1, (int) $request->query('pageSize', '10'))),
```

Every list endpoint clamps to **100**, silently. Sending `pageSize=5000` returns 100 rows with
`pageSize: 100` in the envelope — so a client-side "fetch everything then export" strategy would
quietly export the first 100 of 4,000 and look like it worked. **Do not build against a higher cap.**

`GET /admin/reports/export.{csv,pdf}` do not exist — delete them from `endpoints.ts` as you planned.

Server-side `export.csv` on bookings/partners/cancellations with the same filter params is the right
answer and I would like to build it (the partner dashboard already has exactly this for `/reports`).
Until then, your "current page only" caveat needs to be on the button, because 100 is a plausible
enough number that nobody notices it is a limit.

---

## 4. Auth, roles, permissions

### 4.1 `/admin/me` sends **both** `role` and `permissions`

`permissions` is a flat `string[]` resolved server-side per role, and it is the intended gate — the
role→permission mapping lives in `App\Support\AdminPermissions` precisely so the client never
reimplements it. Your "explicit array always wins" rule is exactly right; keep it.

### 4.2 The full role vocabulary is **`superadmin` and `finance`** — both real

`finance` exists server-side today with a genuinely narrower set:

```
finance: partners.view, bookings.view, cancellations.view, wallets.view,
         payouts.view, payouts.execute, reports.financial,
         notifications.view, profile.view
```

Note what finance **cannot** do: `wallets.adjust`. Approving a payout *destination* is deliberately
withheld from the role that records transfers, so both halves of a payment are never in one pair of
hands. No support / ops / read-only roles exist or are planned.

⚠️ **Your unknown-role fallback is the right instinct but it is dangerous here**, and you flagged it
yourself. Anything not `finance` currently resolves to the **superadmin** set server-side (`match
($role) { 'finance' => FINANCE, default => ALL }`). So a typo'd role would be *unrestricted* on the
server while your client locks it to the narrowest set — the two fail in opposite directions. I will
tighten the server to deny-by-default in part 2. **You will get any new role string before it ships.**

### 4.3 ⏳ 403 semantics — not verified, part 2

I will not confirm from memory that `403` never means a dead session. Both `FORBIDDEN` (permission
middleware) and the 401 path exist; which is canonical needs a read of the exception handler.

### 4.4 The `code` vocabulary — every literal the admin API emits

```
VALIDATION_ERROR  NOT_FOUND  CONFLICT  FORBIDDEN
USER_HAS_ACTIVE_BOOKINGS  REFUND_FAILED
NOT_ELIGIBLE  ALREADY_PAID_THIS_MONTH  DUPLICATE_BANK_REFERENCE
```

That is the complete set of explicit codes — nine. Note **`INSUFFICIENT_PERMISSION` is never emitted**;
drop it and branch on `FORBIDDEN` alone. `UNAUTHENTICATED` is likewise not in this list (⏳ §4.3 will
confirm what the 401 body carries).

### 4.5 ⏳ OTP lockout — part 2

Rate limiters are named (`throttle:ap-otp` on request, `throttle:10,1` on verify) but I have not read
their resolved limits or whether a `Retry-After` reaches you. Not answering until I have.

---

## 9. Lists, filters, sorting

### 9.1 Default order — and **approvals is oldest-first** ✅

| endpoint | default when no `sortBy` |
|---|---|
| **approvals** | **`submitted_at` ASC — oldest first** ✅ |
| users | `created_at` DESC |
| partners | `created_at` DESC |
| units | `created_at` DESC |
| bookings | `created_at` DESC |
| cancellations | `cancelled_at` DESC |
| wallets | `id` ASC |

The one you cared about is correct: the SLA queue is not inverted.

### 9.2 Accepted `sortBy` — exact sets

```
users:         name, bookingsCount, totalSpent, joinedAt
partners:      name, rating, revenue, unitsCount, bookingsCount, joinedAt
units:         name, pricePerNight, rating, occupancyRate, revenue, bookingsCount, createdAt
bookings:      total, checkIn, createdAt
cancellations: at, bookingTotal
approvals:     submittedAt          ← the only one
wallets:       partnerName
payouts:       paidAt, amount, periodMonth
```

Your `users` and `partners` strings match exactly. **`bookings` does not** — you send `commission`,
which is **not accepted**; it is a computed expression, not a column.

**An unrecognised `sortBy` is silently ignored**, falling back to the default order. No `422`. So your
`commission` sort has been rendering `created_at` DESC and looking like it worked. `sortDir` is
`asc`/`desc`, anything else → `desc`.

### 9.3 Omitted means unfiltered ✅

Confirmed. And you are double-covered: the server also treats `''`, `null` and the literal `'all'` as
"no filter", so sending `status=all` is harmless. Never required.

### 9.4 `search` fields per resource

```
users:         name, phone, email
partners:      name, phone, email
units:         unit_name, code, city
wallets:       name, phone
payouts:       reference, bank_reference
bookings:      — none —
cancellations: — none —
approvals:     — none —
```

⚠️ **`search` is accepted and silently ignored on bookings, cancellations and approvals** — the param
is parsed, no columns are searched, and a full unfiltered page comes back. If your UI shows a search
box on those three, it currently lies. Either hide it or tell me which columns you want and I will add
them. This is the highest-impact item in §9.

Matching is `LIKE %term%`, OR'd across the listed columns, case-insensitive per MySQL collation.

### 9.5 / 9.6 ⏳ City filter keys and rate limits — part 2

The admin group is `throttle:240,1` (240 requests/minute/session), so per-keystroke search is not
currently being rejected — but I want to check the city column's actual values before telling you
whether English names match.

---

## 12. `avgReviewSample` — already shipped ✅

It is on `GET /admin/approvals/stats` today, and is `0` whenever `avgReviewHours` is `null`, exactly
as specified. Build the "averaged over 3 of 7 decisions" caption against it.

### The 38h → 48h doc fix ✅ done 2026-08-15

`MAMSA-FRONTEND-ADMIN-APPROVALS-SCREEN.md` §3.1 and its checklist both read **48h, amber at 24h**.
You have a stale copy; the corrected file is re-issued alongside this reply.

---

## What is left — part 2

⏳ **§4.3** 403 semantics · **§4.5** OTP lockout · **§5.2** single-cancellation read · **§5.3** sign
conventions · **§6** bookings (`no_cancel`, snapshot freezing, `mamsaOwned`, fees/VAT, amount
encoding) · **§7** units (no update/delete, draft sufficiency, unpublish target) · **§8** users ·
**§9.5–9.6** · **§10** notifications · **§11** reports/dashboard semantics · **§13** audit trail and
CSRF.

Two of those I can pre-empt as *likely* but will still verify rather than assert: `§6.5` amounts are
almost certainly major-unit floats (every money field in this API is `round($v, 2)`), and `§13.2`
CSRF — the admin surface is cookie-session, so this needs a real answer, not an assumption.

**Three things I need back from you:**

1. **§1.3** — semantics (a) or (b) for `documentsComplete`.
2. **§1.2** — restore the built-and-tested `cr_file` so the CR row can carry a scan? yes/no.
3. **§9.4** — which columns should `search` cover on bookings, cancellations and approvals?
