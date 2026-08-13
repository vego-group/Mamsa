# Task: stand up `https://local.mamsaa.com:3002` for local dev (Claude Code — frontend repos)

**For:** a Claude Code agent / developer setting up the **admin panel** locally against **staging**.
**Backend status:** ✅ **Step 1 done** — `https://local.mamsaa.com:3002` is on the staging CORS
allowlist with credentials, verified 2026-08-13. `http://localhost:3002` is **still allowed** and
nothing has been removed.
**This document is step 2:** get the app running on the new origin and confirm the session holds, then
report back so the backend can do steps 3–4.

---

## 0. Why this exists

Staging is currently `SameSite=None` while production is `SameSite=Lax`. That means **staging cannot
honestly test production authentication** — and the case that will bite is the **Moyasar payment
return**, which under `Lax` rides a top-level GET but not a cross-site `POST`/`fetch`.

The fix is not to weaken production; it is to make local development **same-site** with the API:

| Origin | vs `staging.mamsaa.com` | `Lax` cookie sent? |
|---|---|---|
| `http://localhost:3002` | different site | ❌ no |
| **`https://local.mamsaa.com:3002`** | **same site** (`mamsaa.com`) | ✅ yes |

`SameSite` compares the **registrable domain** (`mamsaa.com`), so `local.` and `staging.` are same-site.
Port and scheme do not affect that comparison.

> **Same-site ≠ same-origin.** The CORS allowlist entry is still required (different host *and* port),
> and it is already in place. Being same-site only removes the need for `SameSite=None`.

---

## 1. Rollout order — do not reorder

| Step | Owner | State |
|---|---|---|
| 1. Add `https://local.mamsaa.com:3002` to staging CORS | backend | ✅ **done + verified** |
| **2. Devs switch to the new origin, confirm session holds** | **frontend — this doc** | ⏳ **now** |
| 3. Set staging `SESSION_SAME_SITE=lax` | backend | ⛔ blocked on step 2 |
| 4. Drop `http://localhost:3002` from the allowlist | backend | ⛔ after step 3 |

**Step 3 is the irreversible one.** If it lands before step 2 is confirmed, every developer still on
`localhost` loses their session at once. The backend will not run it until you report step 2 done.

---

## 2. Setup

### 2.1 Hosts entry

Point the name at loopback.

- **macOS / Linux:** `/etc/hosts`
- **Windows:** `C:\Windows\System32\drivers\etc\hosts` (edit as Administrator)

```
127.0.0.1   local.mamsaa.com
```

Verify: `ping local.mamsaa.com` resolves to `127.0.0.1`.

### 2.2 HTTPS is mandatory, not optional

The staging session cookie carries the **`Secure`** attribute, so the browser will not send it over
plain HTTP **even from a same-site origin**. `https://` on the local origin is required.

Generate a locally-trusted certificate with [mkcert](https://github.com/FiloSottile/mkcert):

```bash
mkcert -install                      # once per machine: trust the local CA
mkcert local.mamsaa.com              # creates local.mamsaa.com.pem + -key.pem
```

### 2.3 Serve the app on `:3002` over HTTPS

**Option A — Next.js built-in (Next 13.5+):**
```bash
next dev --experimental-https \
  --experimental-https-key ./local.mamsaa.com-key.pem \
  --experimental-https-cert ./local.mamsaa.com.pem \
  -p 3002
```

**Option B — HTTP dev server behind a TLS proxy** (if your Next version lacks the flags):
```bash
next dev -p 3003                                     # app on plain HTTP
npx local-ssl-proxy --source 3002 --target 3003 \
  --cert local.mamsaa.com.pem --key local.mamsaa.com-key.pem
```

Either way the browser must reach **`https://local.mamsaa.com:3002`** — not `localhost`, not
`127.0.0.1`. The hostname is the entire point; an IP or `localhost` is a different site again.

### 2.4 API base + credentials

```env
NEXT_PUBLIC_API_BASE=https://staging.mamsaa.com
```

Every API call must send credentials — `fetch(url, { credentials: 'include' })` or axios
`withCredentials: true`. Do **not** proxy the API through a Next.js rewrite for this test: a rewrite
makes the call same-origin and hides exactly the behaviour you are trying to prove.

---

## 3. Verify

### 3.1 CORS reaches you (already true server-side)

```bash
curl -s -i -X OPTIONS https://staging.mamsaa.com/admin/me \
  -H "Origin: https://local.mamsaa.com:3002" \
  -H "Access-Control-Request-Method: GET" | grep -i '^access-control-allow-'
```

**Expected (confirmed by backend on 2026-08-13):**
```
access-control-allow-origin: https://local.mamsaa.com:3002
access-control-allow-credentials: true
access-control-allow-methods: GET
```

### 3.2 The session holds — the actual step-2 test

In the browser, from `https://local.mamsaa.com:3002`:

1. Log in: superadmin `+966555000003` or finance `+966555000004`, OTP **`<fixed OTP — request privately>`**.
   (`request-otp` is throttled to 3 per 10 min per phone — a 429 is the throttle, not a bug.)
2. **Reload the page.** You must stay logged in.
3. Navigate to a data screen and confirm `GET /admin/me` returns 200, not 401.
4. In DevTools → Network → any API request → **Request Headers**, confirm the
   `mamsaa-session` cookie is being **sent**.

The old failure signature: login appears to succeed, then bounces to `/login` a second later. That is
the cookie being *stored* but not *sent* — if you see it, the origin or the HTTPS setup is wrong.

### 3.3 ⚠️ What this test does and does not prove

Staging is **still `SameSite=None` today**, which is permissive — so `localhost` and
`local.mamsaa.com` both work right now. **Passing §3.2 does not by itself prove same-site is working.**

What it proves is everything else: DNS, the certificate, the port, CORS, and `credentials: 'include'`.
The same-site property only gets exercised after the backend flips staging to `Lax` in step 3.

That is precisely why the order matters: get every developer onto the new origin **first**, so that
when `Lax` lands, the only thing that changes is the cookie rule — and same-site already covers it.

---

## 4. Report back to the backend

When every developer is on the new origin and §3.2 passes for all of them, send:

> **Step 2 confirmed.** All developers are on `https://local.mamsaa.com:3002`; login, reload, and
> authenticated requests work. Proceed with step 3 (`SESSION_SAME_SITE=lax` on staging), then step 4
> (drop `http://localhost:3002`).

Then the backend will:
- set staging `SESSION_SAME_SITE=lax` → staging finally matches production's cookie rule;
- remove `http://localhost:3002` from the allowlist;
- confirm both with a fresh preflight + `Set-Cookie` probe.

**Immediately after step 3**, re-run §3.2. If a session now fails, you are on the wrong origin — the
fastest rollback is for the backend to set staging back to `None` (one env change, no deploy).

---

## 5. What to test once staging is `Lax` (the real prize)

Staging then reproduces production's cookie behaviour, so these become meaningful for the first time:

- [ ] **Moyasar payment return** — confirm it is handled as a **top-level browser redirect**, not a
      cross-site background `fetch`/`POST`. Under `Lax` a background cross-site request will **not**
      carry the session, so a return handler of that shape drops the user's session. This is a live
      production risk independent of the wallet work, and the frontend already owes the backend an
      answer on it.
- [ ] Any other cross-site `POST`/XHR in the auth flow.

---

## 6. Notes

- **Production is unaffected and stays `Lax`.** `admin.mamsaa.com` is already same-site with
  `api.mamsaa.com`, so the deployed app never needed any of this.
- The session cookie name is pinned to **`mamsaa-session`** in both environments now, so it no longer
  differs between staging and production (it used to be `mamsa-session` on staging — a one-letter drift
  that silently logged people out on promotion).
- Do not add `local.mamsaa.com` to production CORS. Local development belongs on staging, against
  staging data.
