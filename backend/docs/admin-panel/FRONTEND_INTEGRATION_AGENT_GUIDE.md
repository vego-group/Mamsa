# Mamsa Admin (Next.js) — Backend Integration Guide for the Coding Agent

**Audience:** an AI coding agent (Claude Code) working **inside the Next.js admin repo**.
**Goal:** connect the finished admin dashboard to the real Laravel backend and make it
production-ready — without changing the API contract (the backend already implements
`BACKEND_SPEC.md` exactly; it has been built and tested, 89 backend tests green, and the
§9 acceptance checklist passes live).

> **Golden rule:** the backend is the source of truth and it matches `BACKEND_SPEC.md`
> byte-for-byte. Do **not** rename fields, change casing, or "adapt" the API. If a screen
> disagrees with a response, fix the **frontend**. If you believe the backend is wrong,
> STOP and report it — do not invent a workaround.

---

## 0. TL;DR — the switch-on

The app currently runs on a mock layer. Turning it onto the real API is **two env vars**:

```env
# .env.local
NEXT_PUBLIC_USE_MOCK=false
NEXT_PUBLIC_API_BASE_URL=https://api.mamsaa.com     # or your dev API origin
```

```bash
pnpm install
pnpm dev        # http://localhost:3002
```

Everything else in this guide is about (a) verifying each screen against the real
endpoints, (b) the auth/session + CORS wiring that a real cookie API requires, and
(c) the production deploy shape.

---

## 1. The contract (memorize this)

| Aspect | Rule |
|---|---|
| Base path | all endpoints are **`{API_BASE}/admin/...`** (no `/api/v1`) |
| Auth | httpOnly **session cookie**, set by `verify-otp`. **No tokens, no `Authorization` header, nothing in `localStorage`.** |
| Every request | must send cookies: `credentials: "include"` (fetch) / `withCredentials: true` (axios) |
| Casing | `camelCase` everywhere; dates are ISO-8601 strings; money is a plain SAR number |
| IDs | strings; each entity also carries a human `code` (`USR-0007`, `PTR-001`, `UNT-014`, `BKG-0231`, `REQ-005`) |
| List responses | `{ items, total, page, pageSize }` (defaults `page=1`, `pageSize=10`) |
| List query params | `page`, `pageSize`, `search`, `sortBy`, `sortDir` (`asc`/`desc`) + per-resource filters |
| Action responses | `{ "ok": true }` |
| Error responses | flat `{ "message": "<Arabic, user-facing>", "code": "<MACHINE_CODE>" }` |

### Error codes to branch on
`UNAUTHENTICATED` (401) · `FORBIDDEN` / `FORBIDDEN_ORIGIN` (403) · `NOT_FOUND` (404) ·
`VALIDATION_ERROR` (422) · `CONFLICT` (409) · `USER_HAS_ACTIVE_BOOKINGS` (409) ·
`OTP_INVALID` / `OTP_EXPIRED` / `OTP_MAX_ATTEMPTS` · `RATE_LIMITED` (429) ·
`REFUND_FAILED` (502) · `SERVER_ERROR` (500).

**Global handling the API client MUST implement:**
- On **401** → clear any client auth state and redirect to `/login`.
- On any error → show `error.message` (already Arabic) as the toast; branch behavior on `error.code`.
- Never assume a body shape on error other than `{ message, code }`.

---

## 2. API client — required shape

Centralize one fetch wrapper. Minimum viable version:

```ts
// lib/api.ts
const BASE = process.env.NEXT_PUBLIC_API_BASE_URL!;

export class ApiError extends Error {
  constructor(public code: string, message: string, public status: number) {
    super(message);
  }
}

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const res = await fetch(`${BASE}/admin${path}`, {
    ...init,
    credentials: "include",                       // <-- REQUIRED (cookie session)
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      ...init.headers,
    },
  });

  if (res.status === 204) return undefined as T;

  const body = await res.json().catch(() => ({}));
  if (!res.ok) {
    if (res.status === 401) redirectToLogin();      // your router
    throw new ApiError(body.code ?? "SERVER_ERROR", body.message ?? "خطأ", res.status);
  }
  return body as T;
}
```

Notes:
- `unread-count` returns a **bare number** (e.g. `5`), *not* `{ count: 5 }`. Parse the body as a number.
- Build the query string from only the *active* filters. The backend already ignores
  `''` / `null` / `"all"`, but sending a clean query keeps URLs readable.

---

## 3. Auth & session lifecycle

```
POST /admin/auth/request-otp   { phone }          → { ok: true }              (403 if phone isn't an admin)
POST /admin/auth/verify-otp    { phone, code }     → { ok: true, admin }  + Set-Cookie (httpOnly session)
GET  /admin/me                                     → AdminProfile             (401 if no/expired session)
POST /admin/auth/logout                            → { ok: true }         + clears cookie
```

Frontend responsibilities:
1. **Login screen** posts `request-otp`, then `verify-otp`. On success, the cookie is set
   by the browser automatically — you just store the returned `admin` in memory/context and
   route to Overview.
2. **App bootstrap / route guard**: call `GET /admin/me`. 200 → hydrate session; 401 → `/login`.
   This is what makes "refresh keeps you logged in" work.
3. **Logout** posts `logout` and clears in-memory state.
4. **Phone format**: send `+9665XXXXXXXX` (the backend also tolerates `05XXXXXXXX` / `5XXXXXXXX`).
5. **OTP UX**: 6 digits, resend cooldown 60s, max 3 attempts (surface `OTP_MAX_ATTEMPTS`).

### Dev OTP code — important
The frontend **mock** uses fixed OTP `123456`. Against the **real** API the code comes from
the backend: in non-production it is `OTP_FIXED_CODE` (the sample env ships `111222`) and no
SMS is sent when `SMS_DRIVER=log`. To make the two match while developing, ask the backend to
set `OTP_FIXED_CODE=123456`, or just use whatever the backend's value is. In production the
code is a real SMS — there is no fixed code.

---

## 4. Endpoint reference by screen (verify each against the real API)

For the exact JSON field lists, read `BACKEND_SPEC.md §6`. Below is the wiring map. The
backend is confirmed to return these shapes.

### Overview / Dashboard — `GET /admin/dashboard/summary`
Returns: `totalUsers, platformCommission, totalBookings, activePartners, pendingRequests,
monthlyGrowth, avgBookingValue`, `deltas{...}` (% vs previous month, negatives allowed),
`revenueSeries` (12 × `{label,revenue,commission}`), `bookingStatusSlices` (4 ×
`{status,count}`), `revenueByCity`, `weeklyBookings` (7 × `{label,value}`),
`latestPendingRequests` (≤5 ApprovalRequest, newest first), `recentHostCancellations`
(≤5 Cancellation, newest first). Wire KPI cards, both charts, and the two mini-lists.

### Users — `GET /admin/users` · `/users/stats` · `/users/{id}`
- List filters: `status` (`active|disabled|pending_activation`), `city`. Sort keys:
  `totalSpent, bookingsCount, joinedAt, name`.
- Actions: `PATCH /users/{id}/status { status }`, `DELETE /users/{id}`
  (**409 `USER_HAS_ACTIVE_BOOKINGS`** if the user has live bookings — show the Arabic message,
  keep the row), `POST /users/invite { phone, name? }` (creates `pending_activation`).

### Partners — `GET /admin/partners` · `/partners/stats` · `/partners/{id}`
- List filters: `type` (`individual|company`), `status` (`active|pending|suspended|rejected`).
- Detail includes `documents[]` (KYC), `documentsComplete`, money (`commissionPaid`,
  `partnerEarning`, `avgPerBooking`), `iban`, `nationalId`.
- Actions: `POST /partners/{id}/approve|reject|suspend|verify|revoke-verification`
  (`reject`/`suspend` need `{ reason }`), per-doc
  `POST /partners/{partnerId}/documents/{documentId}/verify`, `POST /partners/invite
  { phone, type, name? }`. **`verified` is an independent badge** — `verify`/`revoke-verification`
  only toggle the badge, they don't change `active/suspended` status.

### Units — `GET /admin/units` · `/units/stats` · `/units/{id}`
- List filters: `status` (`draft|pending_review|approved|rejected`), `type`, `city`, `partnerId`.
- Actions: `POST /units` (UnitDraft → creates a **Mamsa-owned** unit, `mamsaOwned:true`,
  starts `draft`), `POST /units/{id}/unpublish { reason }` (approved → rejected; **409** if not approved).

### Approvals — `GET /admin/approvals` · `/approvals/stats` · `/approvals/{id}`
- A request is a unit in `pending_review`. Default order oldest-first (SLA). Filters:
  `requestType` (`new|resubmission|reapproval_after_edit`), `partnerType`.
- Detail embeds a full `UnitDetail` under `unit`, plus `partnerVerified`, `partnerRating`.
- Actions: `POST /approvals/{id}/approve`, `POST /approvals/{id}/reject { reason, notes? }`.
  **409 `CONFLICT`** if the unit is no longer pending — refetch the queue on that.
- SLA badge: compute from `submittedAt` (warn ≥24h, breached ≥48h).

### Bookings (read-only) — `GET /admin/bookings` · `/bookings/counts` · `/bookings/stats` · `/bookings/{id}`
- Filters: `status`, `city`, `partnerId`, `unitId`, `userId`, `from`, `to` (ISO dates on check-in).
- `counts` → `{ all, pending_payment, confirmed, completed, cancelled }`.
- Detail: `policySnapshot` (frozen at payment — render its `tiers`) + `timeline`
  (events with `state ∈ done|current|cancelled`). `commission` = 2%, `partnerShare` = 98%.

### Cancellations — `GET /admin/cancellations` · `/cancellations/stats` · `/cancellations/high-risk-partners`
- Filters: `cancelledBy` (`guest|host`), `refundStatus` (`none|partial|refunded|failed`), `partnerId`.
- `impact` is **negative** (platform loss). `stats.financialImpact` is the **positive** total.
- Action: `POST /cancellations/{id}/retry-refund` — re-attempts a **failed** refund;
  **409** if there's no failed refund. (In dev without Moyasar keys this succeeds in
  simulation mode.)

### Reports — `GET /admin/reports/summary?range=6m|1y|all`
Series cover the requested range; wire the 6m/1y/all switch. `avgMonthlyRevenue` and
`occupancyAverage` are precomputed. `topPartners` = top 5 by revenue. CSV/PDF export stays
client-side (the frontend already builds it).

### Notifications — `GET /admin/notifications` · `/unread-count` · `POST /read-all` · `POST /{id}/read`
- Feed: `NotificationItem[]`, newest first, capped ~50, not paginated.
- `unread-count`: **bare number** for the header badge.
- Mark one / mark all read return `{ ok: true }`.
- `entity` (`{type,id}|null`) is the deep-link target — route the click to that record.

---

## 5. Cross-cutting frontend rules

- **Optimistic vs refetch:** after any mutation, refetch the affected list/detail (or the
  stats tiles) so derived numbers stay correct. On a `409 CONFLICT` from an approval/unit
  action, always refetch — the state moved under you.
- **Empty ≠ broken:** a freshly-seeded backend can have 0 pending approvals / 0 cancellations.
  Render empty states, don't error.
- **Arabic content is server-owned:** error messages, notification title/body, document
  labels, activity labels all come from the API already in Arabic. Don't hardcode Arabic
  copies of server strings.
- **Numbers:** money can serialize as `18` or `18.0` — treat as `number`, don't string-compare.
- **Dates:** everything is ISO-8601; format on the client (the frontend already has this).

---

## 6. Production readiness checklist (do these before "done")

Because the session is a **cookie**, cross-origin needs credentialed CORS and same-site cookies.

1. **Deploy topology:** the API must be reachable at `{API_BASE}/admin/*`. Put the admin app
   and the API on the **same registrable domain**, e.g. `admin.mamsaa.com` (frontend) ↔
   `api.mamsaa.com` (backend). This keeps the `SameSite=Lax` session cookie first-party.
   > ⚠️ The API's `/admin/*` routes must be served by Laravel, **not** shadowed by any SPA
   > that also owns `/admin`. In the shared dev docker, nginx only proxies `/api/*` to
   > Laravel and everything else to a Vue SPA (which also uses `/admin`) — so the BFF is
   > reached on its own API host/port, never through that SPA nginx. Ensure production
   > routes `admin.mamsaa.com/admin/*` (or `api.mamsaa.com/admin/*`) straight to Laravel.
2. **Backend env (coordinate with backend owner):**
   ```env
   CORS_SUPPORTS_CREDENTIALS=true
   CORS_ALLOWED_ORIGINS=https://admin.mamsaa.com    # exact origin, never '*' with credentials
   SESSION_DOMAIN=.mamsaa.com
   SESSION_SECURE_COOKIE=true
   APP_ENV=production
   APP_DEBUG=false
   ```
3. **Frontend env:** `NEXT_PUBLIC_USE_MOCK=false`, `NEXT_PUBLIC_API_BASE_URL=https://api.mamsaa.com`.
4. **Every request** goes through the `credentials:"include"` client. Verify there are **no
   CORS errors** in the console (the classic symptom of a missing `CORS_SUPPORTS_CREDENTIALS`
   or a `*` origin).
5. **Real SMS**: backend sets `SMS_DRIVER=fgc|taqnyat` + creds so OTP/invite messages send.
6. **Real refunds**: backend sets `MOYASAR_SECRET_KEY` so `retry-refund` hits the gateway.

---

## 7. Acceptance checklist (BACKEND_SPEC §9 — how you know you're done)

Run against the real API and confirm each — all are backed by working endpoints:

- [ ] Login with a seeded admin phone + the backend's OTP lands on Overview; refresh keeps
      you logged in (`/admin/me`); logout returns to login (401 afterward).
- [ ] Overview shows KPIs, both charts, latest pending requests, recent host cancellations.
- [ ] Every list page (Users, Partners, Units, Approvals, Bookings, Cancellations) loads,
      paginates, searches, sorts, and filters by its tabs.
- [ ] Every detail view/drawer opens with full data (partner documents, booking policy
      snapshot + timeline, unit gallery).
- [ ] All actions work and persist: user disable/delete/invite, partner
      approve/reject/suspend/verify/document-verify, approval approve/reject, unit
      create/unpublish, retry-refund, notifications read/read-all.
- [ ] Header unread badge shows the number from `/admin/notifications/unread-count`.
- [ ] Reports page switches between 6m / 1y / all.
- [ ] Wrong OTP shows the Arabic error from the envelope; expired session redirects to login (401).
- [ ] No CORS errors in the browser console with `credentials: include`.

*(Backend status at handoff: all 8 endpoint groups implemented, 89 backend tests green, and
this checklist verified end-to-end against a running instance.)*

---

## 8. Definition of done for this task
1. `NEXT_PUBLIC_USE_MOCK=false` and the app runs entirely on the real API (mock layer unused).
2. The API client sends `credentials:"include"` on every call and handles 401 → login + the
   flat `{message,code}` errors globally.
3. Every screen in §4 is wired and verified; no field renamed on the client to "match".
4. The §7 checklist passes against a real backend instance.
5. Production env (§6) documented/coordinated; no CORS or cookie errors.

If any endpoint's real response contradicts this guide or `BACKEND_SPEC.md`, **stop and report
the specific field + endpoint** rather than adapting around it.
