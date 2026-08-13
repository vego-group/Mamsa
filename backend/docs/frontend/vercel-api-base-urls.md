# Frontend API base URLs on Vercel (per project) — CORRECTED

**Audience:** the Next.js frontend team — this is what to change on **your side** (Vercel
env vars per project, then redeploy). No backend change is needed.
**Verified on production:** 2026-08-04.
**Rule in one line:** the bare host **root** (`https://api.mamsaa.com`) serves the two
cookie-session BFFs (**partner dashboard** + **admin**); the **`/api/v1`** prefix is only
the Sanctum **consumer/mobile** API. Set each Vercel project's API base accordingly.

---

## Corrected table — what to set in Vercel

| Vercel project | Domain | `NEXT_PUBLIC_API_BASE_URL` (Production) | Notes |
|---|---|---|---|
| Consumer | `mamsaa.com` / `www` | **`https://api.mamsaa.com/api/v1`** | Sanctum `/api/v1/*` — **includes** `/api/v1` |
| **Partner dashboard** | `partner.mamsaa.com` | **`https://api.mamsaa.com`** | root — **NO `/api/v1`** |
| Admin | `admin.mamsaa.com` | `https://api.mamsaa.com` | root — `/admin/*`; already on prod |

> ⚠️ **Do NOT put `/api/v1` on the partner or admin base.** It makes **every** call 404.

---

## Why (the three backend surfaces)

The backend exposes three distinct APIs on the same host:

| Surface | Mount | Auth | Consumed by |
|---|---|---|---|
| `/api/v1/*` | prefixed | Bearer (Sanctum) | mobile + **consumer web** (`mamsaa.com`) |
| **`/*` (root)** — `routes/dashboard.php` | **root** | cookie (`auth:dashboard`) | **Next.js partner dashboard** (`/me`, `/units`, `/overview`, `/auth/otp/*`) |
| `/admin/*` (root) — `routes/admin-panel.php` | root | cookie (`admin-panel`) | Next.js admin BFF |

The partner dashboard was built against the **root-mounted `dashboard` API**, not the
`/api/v1/partner/*` Bearer routes. Those `/api/v1/partner/*` routes exist, but they are a
**different** API (different auth, different paths) that this dashboard never calls — the
easy trap is to conflate the two.

---

## Proof (tested on production today)

Root works; `/api/v1` 404s for the dashboard endpoints:

| Endpoint | `https://api.mamsaa.com` (root) | `https://api.mamsaa.com/api/v1` |
|---|---|---|
| `GET /me` | ✅ `401` (exists, needs login) | ❌ `404` |
| `GET /notifications` | ✅ `401` | ❌ `404` |
| `POST /auth/otp/request` | ✅ `400/405` (exists) | ❌ `404` |

Also confirmed by the backend's own Postman collection
(`docs/backend/Mamsa-API.postman_collection.json`): two separate bases —
`base_url = …/api/v1` (mobile/consumer) and `dashboard_url = <root>` (dashboard). All
dashboard requests use `dashboard_url`.

### Reproduce it yourself
```bash
curl -s -o /dev/null -w '%{http_code}\n' https://api.mamsaa.com/me          # 401
curl -s -o /dev/null -w '%{http_code}\n' https://api.mamsaa.com/api/v1/me   # 404
```

---

## How to set it in Vercel (per project)

1. Vercel → **the project** → **Settings → Environment Variables**.
2. Set `NEXT_PUBLIC_API_BASE_URL` (Production) to the value from the table above.
   Consumer also: `NEXT_PUBLIC_USE_MOCK=false`.
3. **Redeploy** (Deployments → Redeploy, uncheck build cache) — `NEXT_PUBLIC_*` is baked
   at build time, so a value change only takes effect after a rebuild.

CORS is already fine: prod `api.mamsaa.com` reflects `Access-Control-Allow-Origin` for
`www.mamsaa.com`, `partner.mamsaa.com`, and `admin.mamsaa.com` with credentials.

---

## Message you can forward

> The Next.js **partner dashboard** does not use the `/api/v1/partner/*` namespace. It was
> built against the **root-mounted dashboard API** (`dashboard_url` in the backend Postman
> collection — `/me`, `/units`, `/overview`, `/auth/otp/*`, no prefix). Verified on
> production: `api.mamsaa.com/me → 401`, but `api.mamsaa.com/api/v1/me → 404`. So the Vercel
> value for **partner.mamsaa.com must be `https://api.mamsaa.com` (no `/api/v1`)**, otherwise
> every request 404s. Consumer (`mamsaa.com`) stays `https://api.mamsaa.com/api/v1`. Admin is
> already on the root prod base. Everything else in the switch steps is unchanged.
