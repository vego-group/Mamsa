# Next.js Admin ↔ Mamsa API — Environment Setup (Prod / Staging)

This resolves the integration confusion and shows how to point the Next.js admin
at **production** or **staging** with one env change.

---

## 0. The one fix that unblocks 90% of the issues

The admin API you should call is:

```
BASE = https://api.mamsaa.com
PREFIX = /admin          ✅   (e.g. https://api.mamsaa.com/admin/dashboard/summary)
```

**NOT** `/api/v1/admin/...`. That path is a *different, older* admin API (the Vue
"testvue" dashboard — Bearer token, `{message, errors}` errors). Everything the
frontend team saw — Bearer tokens, `{message, errors}`, `/admin/users` working but
`/admin/dashboard/summary` returning 404, `Unauthenticated.` without a `code` — is
because requests were going to `/api/v1/admin/*`. The API you were built against
(BACKEND_SPEC.md) lives at **`/admin/*`** and is fully deployed on production now.

Quick proof (run these — all return `401 {message, code:"UNAUTHENTICATED"}`, not 404):
```bash
curl https://api.mamsaa.com/admin/me
curl https://api.mamsaa.com/admin/dashboard/summary
curl https://api.mamsaa.com/admin/users
curl https://api.mamsaa.com/admin/notifications/unread-count
```

### Two admin APIs — use the right one

| | ✅ USE — new admin BFF | ❌ DO NOT USE — old testvue admin |
|---|---|---|
| Base path | `https://api.mamsaa.com/admin/*` | `https://api.mamsaa.com/api/v1/admin/*` |
| Auth | httpOnly **session cookie** (OTP) | Bearer token (password) |
| Errors | flat `{ message, code }` (Arabic) | Laravel `{ message, errors }` |
| Casing | camelCase | snake_case |
| Lists | `{ items, total, page, pageSize }` | varies |

---

## 1. Environment switching (prod / staging / local)

Everything is driven by two public env vars — no code changes to switch:

```env
# .env.production        → the live API
NEXT_PUBLIC_USE_MOCK=false
NEXT_PUBLIC_API_BASE_URL=https://api.mamsaa.com

# .env.staging           → the staging API   (confirm the exact host with backend)
NEXT_PUBLIC_USE_MOCK=false
NEXT_PUBLIC_API_BASE_URL=https://staging-api.mamsaa.com

# .env.local             → local mock, no backend needed
NEXT_PUBLIC_USE_MOCK=true
```

All requests are then `` `${NEXT_PUBLIC_API_BASE_URL}/admin/...` ``. Switching envs =
changing `NEXT_PUBLIC_API_BASE_URL`. Nothing else in the app changes.

> **Note:** the BFF is currently deployed to **production only**. If you want it on
> staging too, tell the backend team and give the staging API host.

---

## 2. Auth model — it's a COOKIE, not a Bearer token

The new admin API uses an **httpOnly session cookie**. There is no `access_token`
in the response and no `Authorization` header to manage. This is intentional and
final for `/admin/*`. (The Bearer token you saw belongs to the old `/api/v1/admin`
API — ignore it.)

```
POST /admin/auth/request-otp   { phone }          → { ok: true }               (403 if phone isn't an admin)
POST /admin/auth/verify-otp    { phone, code }     → { ok: true, admin }  + Set-Cookie (session)
GET  /admin/me                                     → AdminProfile              (401 if no session)
POST /admin/auth/logout                            → { ok: true }         + clears cookie
```

`verify-otp` response body (no token field — the session is the cookie):
```json
{ "ok": true, "admin": { "id": "1", "name": "…", "email": "…", "phone": "+9665…", "role": "superadmin", "preferredLocale": "ar", … } }
```

**Client requirement:** every request must send the cookie:
```ts
fetch(`${BASE}/admin${path}`, { credentials: "include", headers: { "Content-Type": "application/json" }, ... })
// axios: withCredentials: true
```

- 401 handling: on any `401 { code: "UNAUTHENTICATED" }`, redirect to login.
- The header unread badge reads `GET /admin/notifications/unread-count` → **a bare number** (e.g. `5`), not `{ count: 5 }`.

---

## 3. ⚠️ The cookie + cross-origin rule (read this before wiring auth)

A session cookie is only sent by the browser when the app and the API are
**same-site** (same registrable domain) OR the cookie is `SameSite=None`. Current
production is `SameSite=Lax` (secure). That means:

| Admin app is served from | Cookie sent to api.mamsaa.com? | Works? |
|---|---|---|
| `https://admin.mamsaa.com` (a `*.mamsaa.com` subdomain) | yes (same-site, Lax) | ✅ works as-is |
| `http://localhost:3002` | **no** (cross-site, Lax blocks it) | ❌ login won't persist |

So **CORS being fixed is not enough** for `localhost` — the cookie still won't be
sent. Pick one of these for local development:

- **(Recommended) Point local dev at staging**, and have the backend set
  `SESSION_SAME_SITE=None` on **staging only** (dev-friendly, prod stays secure).
- **Local same-site host:** add `127.0.0.1 admin.local.mamsaa.com` to your hosts
  file, serve the app over https at `https://admin.local.mamsaa.com`. It's then
  same-site with `api.mamsaa.com`, so the Lax cookie works with **no prod change**.
  (Ask backend to add that origin to CORS.)
- **Last resort:** backend sets `SESSION_SAME_SITE=None` on production. Enables
  `localhost` but loosens the cookie policy for all cookie apps — not recommended.

**Production deployment (the real target):** host the admin app on a
`*.mamsaa.com` subdomain (e.g. `admin.mamsaa.com`). Then it's same-site with the
API and the cookie works with the current config. Just tell backend the exact
origin so it's added to the CORS allow-list.

---

## 4. CORS (already configured)

Production now returns credentialed CORS for the dev origin — verified:
```
access-control-allow-origin: http://localhost:3002
access-control-allow-credentials: true
access-control-allow-methods: GET, POST, PATCH, DELETE, OPTIONS
access-control-allow-headers: content-type
```
When you deploy the admin app to its real origin, backend adds that origin to the
allow-list too (credentialed CORS cannot use `*`).

---

## 5. Your questions — answered

- **"Is Bearer the official auth now, or cookies?"** → **Cookies**, for `/admin/*`.
  The Bearer you saw is the old `/api/v1/admin` API. Don't use it.
- **"Where is the token in verify-otp?"** → There is none. Auth = the httpOnly
  cookie set by `verify-otp`. Send `credentials:"include"`; no token to store,
  no refresh token.
- **"Which of the 8 endpoint groups are live?"** → **All of them**, on `/admin/*`
  (dashboard, users, partners, units, approvals, bookings, cancellations, reports,
  notifications). `/admin/dashboard/summary` returns 401 today (not 404) — it 404's
  only under `/api/v1/admin/...`.
- **"Will the 401 error be `{message, code}`?"** → On `/admin/*`, **yes** — it's
  already `{ "message": "…", "code": "UNAUTHENTICATED" }`. The `Unauthenticated.`
  (no code) and `{message, errors}` shapes are from `/api/v1/*`, which you should
  stop calling.
- **"CORS?"** → Enabled and verified for `http://localhost:3002`. (But re-read §3:
  cookies still need same-site or `SameSite=None`.)

---

## 6. Endpoint reference

Full per-screen endpoint map, request/response shapes, and rules are in
`FRONTEND_INTEGRATION_AGENT_GUIDE.md` (same folder). All paths there are under the
`/admin` prefix on `NEXT_PUBLIC_API_BASE_URL`.

---

## 7. What backend needs from you to finish production
1. The **exact origin** the admin app will be served from in production
   (e.g. `https://admin.mamsaa.com`) — to add to CORS. A `*.mamsaa.com` subdomain
   makes the cookie work with zero further changes.
2. Whether you want the BFF **deployed to staging** too (and the staging API host),
   so local dev can point there with `SameSite=None`.
