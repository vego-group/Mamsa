# Mamsa Admin — Answers to BACKEND_CONFIRMATION_NEEDED + How to Switch

**Read this first — it changes the plan.** Everything the team found "missing" or
"different from the spec" is because testing hit the **wrong API surface**. The
admin API that matches `BACKEND_SPEC.md` exactly — camelCase, `{message, code}`
errors, `{items,total,page,pageSize}` lists, and **all 8 endpoint groups** — is
live under the **`/admin/*`** prefix, now deployed on **both** environments:

| Environment | Base URL | Admin API |
|---|---|---|
| **Staging** (use for dev) | `https://staging.mamsaa.com` | `…/admin/*` ✅ live |
| **Production** | `https://api.mamsaa.com` | `…/admin/*` ✅ live |

What you were testing (`/api/v1/auth/*` + `/api/v1/admin/*`) is the **old Vue
"testvue" admin API** — Bearer token, `{success,data}` envelope, `{message,errors}`
errors, and it's missing most of the spec endpoints (that's why
`/api/v1/admin/dashboard/summary` 404s but `/api/v1/admin/users` "works"). **Stop
using `/api/v1/...` for the admin app.** Use `/admin/*`.

---

## 1. The switch (staging → production, and off `/api/v1`)

Two env values drive everything — nothing else changes between environments:

```env
# .env (dev / staging)
NEXT_PUBLIC_USE_MOCK=false
NEXT_PUBLIC_API_BASE_URL=https://staging.mamsaa.com

# .env.production
NEXT_PUBLIC_USE_MOCK=false
NEXT_PUBLIC_API_BASE_URL=https://api.mamsaa.com
```

Then all calls are `` `${NEXT_PUBLIC_API_BASE_URL}/admin/...` ``. **Remove the
`/api/v1` segment** from the admin client. To go prod ⇄ staging you only change
`NEXT_PUBLIC_API_BASE_URL`.

---

## 2. Point-by-point answers to your doc

| Your finding (on `/api/v1`) | The truth on `/admin/*` |
|---|---|
| Base URL is `…/api/v1` | Admin base is `…/admin` (no `/api/v1`). |
| Auth is Bearer token | Spec auth is an **httpOnly session cookie** (see §3). |
| Auth paths `/auth/*` (no `admin/`) | `/admin/auth/request-otp`, `/admin/auth/verify-otp`, `/admin/me`, `/admin/auth/logout`. |
| Success wrapped `{success,message,data}` | Flat: actions `{ "ok": true }`, resources returned directly, lists `{items,total,page,pageSize}`. |
| Error `{message}` / `{message,errors}`, **no `code`** | **`{ "message": "<ar>", "code": "<CODE>" }`** — the `code` you asked for **is** there (`UNAUTHENTICATED`, `VALIDATION_ERROR`, `CONFLICT`, `NOT_FOUND`, `OTP_*`, `USER_HAS_ACTIVE_BOOKINGS`, …). Branch on `code`. |
| CORS completely missing | **Fixed** on both envs: credentialed CORS with `Access-Control-Allow-Origin` + `Allow-Credentials: true` for `http://localhost:3002`. Verified. |
| Which of the 8 groups are live? | **All of them**, under `/admin/*` (see §5). |
| Refresh token / `expires_in`? | Not applicable — cookie sessions don't use `access_token`/`refresh_token`. The session is the cookie; on `401` just send the user to login. (No 1-hour logout problem.) |

You can verify the error shape yourself right now:
```bash
curl https://staging.mamsaa.com/admin/me
# → 401 {"message":"يجب تسجيل الدخول للمتابعة","code":"UNAUTHENTICATED"}
curl -X POST https://staging.mamsaa.com/admin/auth/request-otp -H 'Content-Type: application/json' -d '{"phone":"123"}'
# → 422 {"message":"رقم جوال غير صالح","code":"VALIDATION_ERROR"}
```

---

## 3. Auth = session cookie (and why it works now)

```
POST /admin/auth/request-otp  { phone }        → { ok:true }            (403 if phone isn't an admin)
POST /admin/auth/verify-otp   { phone, code }   → { ok:true, admin }  + Set-Cookie (session)
GET  /admin/me                                  → AdminProfile           (401 if no session)
POST /admin/auth/logout                         → { ok:true }        + clears cookie
```

Client: send the cookie on **every** request — `credentials: "include"` (fetch) /
`withCredentials: true` (axios). No token, no `localStorage`, no `Authorization`
header, no refresh logic. On `401 {code:"UNAUTHENTICATED"}` → redirect to login.

**Cross-origin cookie — why staging works for local dev:** the cookie is only
sent cross-site if the API allows it. **Staging is set to `SameSite=None`**, so
`http://localhost:3002 → https://staging.mamsaa.com` works out of the box (CORS +
cookie). **Develop against staging.** For production, host the admin app on a
`*.mamsaa.com` subdomain (e.g. `admin.mamsaa.com`) — it's then same-site with
`api.mamsaa.com` and the cookie works with prod's stricter `SameSite=Lax`.

> **If your team would rather keep the Bearer-token flow you already built**
> (localStorage + `Authorization` header), we can enable Bearer on `/admin/*`
> too — `verify-otp` would then also return an `access_token`. Say the word and
> we'll add it; otherwise cookie is the default and works now.

---

## 4. Response shapes still hold (they're already correct on `/admin/*`)
- camelCase keys, ISO-8601 dates, money as plain SAR numbers.
- String IDs + human `code` (`USR-0007`, `PTR-001`, `UNT-014`, `BKG-0231`, `REQ-005`).
- Lists: `{ items, total, page, pageSize }` (defaults page=1, pageSize=10).
- Common list params: `page, pageSize, search, sortBy, sortDir` + per-resource filters.
- Actions: `{ ok: true }`. `notifications/unread-count` → a **bare number**.

---

## 5. Endpoint checklist — all live on `/admin/*`

| Group | Endpoints (all under `{BASE}/admin`) | Status |
|---|---|---|
| Auth | `POST auth/request-otp`, `POST auth/verify-otp`, `GET me`, `POST auth/logout` | ✅ |
| Profile | `GET/PATCH profile`, `GET profile/sessions`, `DELETE profile/sessions/{id}` | ✅ |
| Dashboard | `GET dashboard/summary` | ✅ (was 404 only under `/api/v1`) |
| Users | `GET users`, `GET users/stats`, `POST users/invite`, `GET users/{id}`, `PATCH users/{id}/status`, `DELETE users/{id}` | ✅ |
| Partners | `GET partners`, `GET partners/stats`, `POST partners/invite`, `GET partners/{id}`, `POST partners/{id}/{approve,reject,suspend,verify,revoke-verification}`, `POST partners/{partnerId}/documents/{documentId}/verify` | ✅ |
| Units | `GET units`, `GET units/stats`, `POST units`, `GET units/{id}`, `POST units/{id}/unpublish` | ✅ |
| Approvals | `GET approvals`, `GET approvals/stats`, `GET approvals/{id}`, `POST approvals/{id}/{approve,reject}` | ✅ |
| Bookings | `GET bookings`, `GET bookings/counts`, `GET bookings/stats`, `GET bookings/{id}` | ✅ |
| Cancellations | `GET cancellations`, `GET cancellations/stats`, `GET cancellations/high-risk-partners`, `POST cancellations/{id}/retry-refund` | ✅ |
| Reports | `GET reports/summary?range=6m\|1y\|all` | ✅ |
| Notifications | `GET notifications`, `GET notifications/unread-count`, `POST notifications/read-all`, `POST notifications/{id}/read` | ✅ |

Per-screen request/response field details: see `FRONTEND_INTEGRATION_AGENT_GUIDE.md`
in this folder (all paths under the `/admin` prefix).

---

## 6. TL;DR for the frontend team
1. Change the admin API base to `https://staging.mamsaa.com` (dev) / `https://api.mamsaa.com` (prod), prefix **`/admin`** (drop `/api/v1`).
2. Auth: cookie — `credentials:"include"`, drop the localStorage/Bearer/refresh logic. (Or ask us to enable Bearer.)
3. Errors already carry `code`; lists are already `{items,total,page,pageSize}`; all 8 groups are live.
4. Develop against **staging** (cookie works from `localhost:3002`). Prod admin → host on a `*.mamsaa.com` subdomain.
