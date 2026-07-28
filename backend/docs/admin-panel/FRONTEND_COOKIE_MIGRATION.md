# Mamsa Admin — Frontend Migration: Bearer/localStorage → Session Cookie

The admin API uses an **httpOnly session cookie** (per `BACKEND_SPEC.md`). The
Bearer/localStorage flow you built was an adaptation to the *old* `/api/v1` API —
this guide removes it and wires the cookie flow. It's less code, and more secure
(no token in JS = no XSS token theft).

**Target API:** base = `NEXT_PUBLIC_API_BASE_URL`, prefix = **`/admin`** (drop `/api/v1`).

| Env | `NEXT_PUBLIC_API_BASE_URL` | Cookie works from `localhost:3002`? |
|---|---|---|
| Staging (dev) | `https://staging.mamsaa.com` | ✅ yes (`SameSite=None` there) |
| Production | `https://api.mamsaa.com` | only from a `*.mamsaa.com` origin (see §6) |

---

## 1. Delete this (the Bearer flow)

- ❌ `localStorage.setItem('access_token', …)` / `getItem` / `removeItem`
- ❌ the `Authorization: Bearer ${token}` header on every request
- ❌ `refresh_token` handling and any `/auth/refresh` calls
- ❌ `expires_in` timers / "token expired" logic
- ❌ reading the token out of the `verify-otp` response
- ❌ the `/api/v1` prefix and the `/auth/*` (no-`admin`) paths

**The cookie is set and sent by the browser automatically.** You never touch it in JS.

---

## 2. API client — before → after

### fetch
```ts
// lib/api.ts
const BASE = process.env.NEXT_PUBLIC_API_BASE_URL!;

export class ApiError extends Error {
  constructor(public code: string, message: string, public status: number) { super(message); }
}

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const res = await fetch(`${BASE}/admin${path}`, {
    ...init,
    credentials: "include",                 // ✅ ADD — sends/receives the session cookie
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      // ❌ REMOVE: Authorization: `Bearer ${localStorage.getItem('access_token')}`
      ...init.headers,
    },
  });

  if (res.status === 204) return undefined as T;
  const body = await res.json().catch(() => ({}));

  if (!res.ok) {
    if (res.status === 401) redirectToLogin();     // no refresh — just re-login
    throw new ApiError(body.code ?? "SERVER_ERROR", body.message ?? "خطأ", res.status);
  }
  return body as T;                                 // flat payload — no {success,data} unwrap
}
```

### axios
```ts
export const http = axios.create({
  baseURL: `${process.env.NEXT_PUBLIC_API_BASE_URL}/admin`,
  withCredentials: true,          // ✅ ADD — the cookie equivalent of credentials:"include"
});

// ❌ REMOVE the request interceptor that added Authorization: Bearer
// ❌ REMOVE the response interceptor that refreshed the token on 401

http.interceptors.response.use(
  (r) => r,
  (err) => {
    if (err.response?.status === 401) redirectToLogin();
    return Promise.reject(err.response?.data ?? err);   // { message, code }
  },
);
```

> `withCredentials: true` / `credentials: "include"` must be on **every** admin
> request (list, detail, mutation, `/me`) — not just login. Otherwise the cookie
> isn't sent and you get 401s.

---

## 3. Auth flow — before → after

```ts
// Login screen
await api("/auth/request-otp", { method: "POST", body: JSON.stringify({ phone }) });
// → { ok: true }   (403 if the phone isn't an admin)

const { admin } = await api<{ ok: true; admin: AdminProfile }>(
  "/auth/verify-otp",
  { method: "POST", body: JSON.stringify({ phone, code }) },
);
// ✅ The Set-Cookie header is applied by the browser automatically.
// ❌ Do NOT read/store a token — there isn't one.
setAdmin(admin);        // keep the profile in memory/context (Zustand/Context), NOT a token
router.push("/");       // Overview
```

```ts
// App bootstrap / route guard — this is what keeps you logged in on refresh
try {
  const me = await api<AdminProfile>("/me");   // 200 if the cookie is valid
  setAdmin(me);
} catch (e) {
  if (e.status === 401) router.replace("/login");   // no session → login
}
```

```ts
// Logout
await api("/auth/logout", { method: "POST" });   // clears the cookie server-side
setAdmin(null);
router.replace("/login");
```

Auth paths (note the `admin/` segment — different from the old `/auth/*`):
```
POST {BASE}/admin/auth/request-otp   { phone }
POST {BASE}/admin/auth/verify-otp    { phone, code }   → sets cookie, returns { ok, admin }
GET  {BASE}/admin/me
POST {BASE}/admin/auth/logout
```

---

## 4. Error handling

Errors are flat `{ message, code }` (message is Arabic, ready to toast; `code` is
for branching):

```ts
try { await api(`/users/${id}`, { method: "DELETE" }); }
catch (e) {
  if (e instanceof ApiError) {
    if (e.code === "USER_HAS_ACTIVE_BOOKINGS") { /* keep row, show e.message */ }
    else toast.error(e.message);
  }
}
```
Common codes: `UNAUTHENTICATED` (→ login), `FORBIDDEN`, `NOT_FOUND`,
`VALIDATION_ERROR` (422), `CONFLICT` (409), `USER_HAS_ACTIVE_BOOKINGS` (409),
`OTP_INVALID` / `OTP_EXPIRED` / `OTP_MAX_ATTEMPTS`, `RATE_LIMITED` (429).

---

## 5. Response shapes (already spec-correct)
- camelCase keys, ISO-8601 dates, money as plain SAR numbers.
- Lists: `{ items, total, page, pageSize }` (defaults page=1, pageSize=10).
- List params: `page, pageSize, search, sortBy, sortDir` + per-resource filters.
- Actions: `{ ok: true }`.
- `GET /admin/notifications/unread-count` → **a bare number** (`5`), not `{count:5}`.

Per-endpoint field lists: `FRONTEND_INTEGRATION_AGENT_GUIDE.md` (same folder).

---

## 6. The cookie + origin rule (don't skip)

A session cookie is only sent by the browser when allowed by SameSite:

- **Dev:** point at **staging** (`https://staging.mamsaa.com`). It's `SameSite=None`,
  so the cookie is sent from `http://localhost:3002`. CORS for `localhost:3002` is
  already enabled. → login persists locally, no extra setup.
- **Production:** prod is `SameSite=Lax`. Host the admin app on a **`*.mamsaa.com`
  subdomain** (e.g. `admin.mamsaa.com`) so it's same-site with `api.mamsaa.com` and
  the cookie is sent. Serving it on `www.mamsaa.com/admin` also works (same site).
  Give backend the exact prod origin so it's added to the CORS allow-list.

If login "works" but the next request is 401, it's almost always: missing
`credentials:"include"`, wrong env (cross-site to prod from localhost), or the
origin not in CORS.

---

## 7. Migration checklist
- [ ] `NEXT_PUBLIC_API_BASE_URL` = staging (dev) / prod (prod); remove `/api/v1`; prefix `/admin`.
- [ ] Every admin request sends `credentials:"include"` / `withCredentials:true`.
- [ ] Deleted: localStorage token, `Authorization` header, refresh logic, `expires_in`.
- [ ] Login stores `admin` (profile) only — no token.
- [ ] Bootstrap calls `GET /admin/me`; 401 → `/login`.
- [ ] Errors branch on `code`; 401 → login.
- [ ] Dev runs against staging and login persists across refresh.
