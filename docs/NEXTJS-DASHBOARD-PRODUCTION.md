# Partner Dashboard — production wiring (`partner.mamsaa.com`)

Backend is now matched to the production dashboard domain. This is everything the Next.js app needs
to talk to production, plus the one gotcha that will bite if you skip it (Vercel previews).

**Backend status:** `https://partner.mamsaa.com` added to the production CORS allowlist and verified.
No code change needed on your side beyond pointing the app at the right API base per environment.

---

## 1. API base URL — per environment

The dashboard API is **root-mounted on the API host** (NOT under `/api/v1` — that path is the public
website's API). Same contract, envelope and endpoints as staging; only the host differs.

| Your environment | `API_BASE` |
|---|---|
| Production (`partner.mamsaa.com` → prod data) | `https://api.mamsaa.com` |
| **QA: `partner.mamsaa.com` → staging data** | `https://staging.mamsaa.com` |
| Local dev / Vercel preview | `https://staging.mamsaa.com` |

> **`partner.mamsaa.com` can point at either backend.** It's allowlisted on **both** `api.mamsaa.com`
> (production) and `staging.mamsaa.com` (QA), so you can flip the same production domain between real
> and test data by changing only `NEXT_PUBLIC_API_BASE_URL` — no backend change either way. Both work
> because `partner.mamsaa.com` is same-site with both hosts (all `mamsaa.com`). Verified end-to-end
> against staging on 2026-07-16: login → session cookie → authenticated `/me`, all from the
> `partner.mamsaa.com` origin.

```
# .env.production   (Vercel: Production env)
NEXT_PUBLIC_API_BASE_URL=https://api.mamsaa.com

# .env / .env.local  (local + Vercel Preview env)
NEXT_PUBLIC_API_BASE_URL=https://staging.mamsaa.com
```

Endpoints are the root paths you already use: `/auth/otp/request`, `/auth/otp/verify`, `/me`,
`/units`, `/bookings`, `/overview`, `/reports/*`, `/notifications`, `/uploads/*`. e.g. production
login = `POST https://api.mamsaa.com/auth/otp/verify`.

## 2. Every request must send cookies

Auth is a **cookie session**, not a Bearer token. `fetch` drops cookies unless you opt in — on
**every** call, not just login:

```ts
await fetch(`${API_BASE}/me`, { credentials: "include" });
// axios: axios.create({ baseURL: API_BASE, withCredentials: true })
```

Login (`POST /auth/otp/verify`) returns `Set-Cookie: mamsaa-session=…` and the browser stores it;
subsequent `credentials: "include"` calls carry it back. No token to store or attach.

## 3. Why production works with `SameSite=Lax` (and previews don't)

This is the part to get right. `partner.mamsaa.com` and `api.mamsaa.com` share the registrable
domain `mamsaa.com`, so browser-wise they are **same-site**. The production session cookie is:

```
mamsaa-session=…; Domain=.mamsaa.com; Secure; HttpOnly; SameSite=Lax
```

`SameSite=Lax` sends the cookie on same-site requests, so `partner.mamsaa.com → api.mamsaa.com`
works — and it's stricter (safer) than `None`. **Hosting is irrelevant here:** `partner.mamsaa.com`
runs on Vercel, but the browser decides same-site from the URL's domain, not where it's served, so
Vercel-hosted `partner.mamsaa.com` is still same-site with `api.mamsaa.com`.

⚠️ **A Vercel *preview* deploy is different.** Preview URLs are `something.vercel.app` — registrable
domain `vercel.app`, which is **cross-site** to `mamsaa.com`. A cross-site request only carries a
cookie if that cookie is `SameSite=None`, and production's cookie is `Lax` by design. So:

> **Point preview deploys at `staging.mamsaa.com`, never at production.** Staging's cookie is
> `SameSite=None; Secure` precisely so cross-site origins (localhost, `*.vercel.app`) can authenticate.
> Wire `NEXT_PUBLIC_API_BASE_URL` to staging in Vercel's **Preview** environment and to production only
> in the **Production** environment. If a preview points at prod, login will appear to succeed
> (200, cookie in the response) but every following request is anonymous — the browser silently drops
> the `Lax` cookie on the cross-site call. That failure looks like a backend bug and isn't one.

CORS allowlists, per environment:

- **Production** (`api.mamsaa.com`): `https://partner.mamsaa.com` (+ the website domains). Preview
  `*.vercel.app` is deliberately **not** allowed here — see above.
- **Staging** (`staging.mamsaa.com`): `localhost:3000/3001/3002`, `localhost:5173/5174`, the mamsaa.com
  domains, and pattern `https://mamsa-*.vercel.app`. Need another preview origin added? Send it — one
  line + deploy.

## 4. Unchanged from staging

Same as the contract you built against; nothing below differs on production.

- **Envelope:** errors `{ error: { code, message, fields? } }`; lists `{ data, meta: { page, limit, total } }`.
- **OTP:** 6-digit, 5-min TTL, 3 attempts; daily cap 10/phone. (Production sends real SMS — there's no
  fixed test code, unlike staging.)
- **Host-cancel idempotency:** send a unique `Idempotency-Key` header on `POST /bookings/:id/host-cancel`;
  a retry with the same key is a no-op, not a double refund.
- **Uploads:** `POST /uploads/presign` → `PUT` to the returned URL → pass the `fileId` in
  `photoFileIds` / `coverFileId` / `tourismLicenseFileId` / company-doc fields. Enums (city/type/
  amenities) per `NEXTJS-DASHBOARD-ENUMS.md` (`makkah`/`madinah`, not `mecca`/`medina`).

## 5. Verified on production (2026-07-16)

From `Origin: https://partner.mamsaa.com` against `https://api.mamsaa.com`:

```
GET /me                         -> Access-Control-Allow-Origin: https://partner.mamsaa.com
                                   Access-Control-Allow-Credentials: true
OPTIONS /auth/otp/verify (pre)  -> ACAO: https://partner.mamsaa.com, ACAC: true, Allow-Methods: POST
Set-Cookie (session)            -> Domain=.mamsaa.com; Secure; HttpOnly; SameSite=Lax
Origin https://evil.example     -> refused (no ACAO)
```

Preflight, credentials, cookie scope, and rejection of unlisted origins all confirmed. You should be
able to log in on `partner.mamsaa.com` against production with no further backend change.

## Checklist for go-live

- [ ] Vercel **Production** env: `NEXT_PUBLIC_API_BASE_URL=https://api.mamsaa.com`
- [ ] Vercel **Preview** env: `NEXT_PUBLIC_API_BASE_URL=https://staging.mamsaa.com`
- [ ] `credentials: "include"` / `withCredentials: true` on the shared HTTP client
- [ ] City/type/amenity values from `NEXTJS-DASHBOARD-ENUMS.md`
- [ ] Tell us any additional origin you'll serve from and we'll allowlist it
