# Putting a test copy of the Next.js apps on their own subdomain

**From:** backend · **Date:** 2026-08-26 · **For:** the three Next.js apps
**Backend status:** ready — the subdomains below are already authorised on staging, verified live.

You want a second copy of each build, on its own subdomain, pointed at the staging API instead of
production. This is how, with the parts that are specific to our setup rather than generic Vercel
advice.

**Short version:** it's a Vercel branch domain plus one environment variable. The backend needs
nothing from you — I've already authorised the subdomains.

---

## 1. Where things actually live

Worth stating, because the two halves are hosted in different places and the naming doesn't make
that obvious:

| host | where | what |
|---|---|---|
| `mamsaa.com`, `www.mamsaa.com` | **Vercel** | guest app (`mamsa-app`) |
| `partner.mamsaa.com` | **Vercel** | partner dashboard |
| `admin.mamsaa.com` | **Vercel** | admin console |
| `api.mamsaa.com` | Hostinger (behind their CDN edge) | production Laravel API |
| `staging.mamsaa.com` | Hostinger | **staging Laravel API** |
| `testvue.mamsaa.com` | Hostinger | the old Vue bench |

DNS for `mamsaa.com` is managed at **Hostinger** (`ns1/ns2.dns-parking.com`), even though the apps
run on Vercel. So adding a subdomain is a two-step job: create it in Vercel, then add the record in
Hostinger's hPanel.

### ⚠️ The one name you cannot use

**`staging.mamsaa.com` is already taken — it is the staging API itself.** Pointing it at Vercel
would take the staging backend offline and break every environment that talks to it, including the
one you're trying to build. `testvue.mamsaa.com` is likewise in use.

Suggested names, which I have already authorised on the backend:

```
test.mamsaa.com           → guest app bench
test-partner.mamsaa.com   → partner dashboard bench
test-admin.mamsaa.com     → admin console bench
```

Use different names if you prefer — just tell me, because each one needs a line on the backend
(§5).

---

## 2. The recommended shape: one branch, three domains

Keep the same three Vercel projects. Add a long-lived `staging` branch, and give each project a
domain that tracks that branch. You get a stable URL that redeploys on every push to `staging`, and
production is untouched because it tracks `main`.

**Per project, once:**

1. **Create the branch** — `git checkout -b staging && git push -u origin staging`.

2. **Vercel → Project → Settings → Domains → Add.** Enter the subdomain (e.g. `test.mamsaa.com`).
   In the **Git Branch** field, enter `staging`. This is the part that makes it a persistent
   environment rather than a one-off preview.

3. **Add the DNS record.** Vercel shows a CNAME target unique to that domain — it is **not** the
   same as the one `www` uses, so don't copy an existing record. In Hostinger hPanel → Domains →
   DNS Zone, add:

   ```
   Type: CNAME     Name: test     Target: <the value Vercel shows>     TTL: default
   ```

4. **Set the API base for Preview only.** Vercel → Settings → Environment Variables. Add your API
   base variable with the staging value and tick **Preview** only — leave Production alone. Values
   are in §3.

5. **Redeploy** the `staging` branch so the new variable is baked in. Next.js inlines
   `NEXT_PUBLIC_*` at build time, so changing the variable without redeploying does nothing.

That's it. Every push to `staging` refreshes the bench; `main` still ships production.

### If you'd rather have a separate project

Duplicate the project in Vercel (same repo, different project), give it the subdomain, and set the
API base in its **Production** environment. Choose this only if you want the bench to have its own
build settings or deploy on a different cadence — otherwise it's a second thing to keep in sync for
no gain.

---

## 3. What to point each app at

The staging API is one host with two mount points, exactly like production:

| app | production value | **staging value** |
|---|---|---|
| guest (`mamsa-app`) | `https://api.mamsaa.com/api/v1` | `https://staging.mamsaa.com/api/v1` |
| partner dashboard | `https://api.mamsaa.com` | `https://staging.mamsaa.com` |
| admin console | `https://api.mamsaa.com` | `https://staging.mamsaa.com` |

The partner and admin surfaces are **root-mounted** — `/me`, `/units`, `/wallet`, `/admin/units` —
so their base has no `/api/v1` on it. Only the guest app uses that prefix.

I haven't named the variables because I don't have your repos, and I'd rather not guess: the admin
console's `.env.local` already points at staging, so the name is whatever it uses there.

---

## 4. Why cookies work across the subdomain — you don't need to do anything

The partner and admin consoles authenticate with an httpOnly cookie session, and the bench will be
on a different site from the API (`test-admin.mamsaa.com` → `staging.mamsaa.com`). That normally
breaks. On staging it is already configured for it:

```
SESSION_SAME_SITE=none          cookie survives a cross-site XHR
SESSION_SECURE_COOKIE=true      required whenever SameSite=None
CORS_SUPPORTS_CREDENTIALS=true  the browser is allowed to send it
SESSION_DOMAIN=staging.mamsaa.com
```

Two consequences for you:

- **Your bench must be HTTPS.** Vercel gives you that automatically; a plain-http host would have
  the cookie silently dropped.
- **Send credentials on every request** (`credentials: 'include'`, or your client's equivalent).
  Your production code already does this or login wouldn't work — just make sure nothing switches
  it off based on the hostname.

Production uses `SameSite=Lax` instead, because `admin.mamsaa.com` and `api.mamsaa.com` are the
same site there. That difference is deliberate and doesn't affect you.

---

## 5. The backend side — already done

**Nothing is blocking you.**

### The Vercel-assigned URL works right now, with no change at all

Staging's allowlist carries a pattern:

```
CORS_ALLOWED_ORIGINS_PATTERNS = ~^https://mamsa-[a-z0-9-]+\.vercel\.app$~
```

Every one of your projects is named `mamsa-…`, so the URL Vercel hands a branch deployment —
`mamsa-app-git-staging-<team>.vercel.app` — already matches. You can point that at staging and start
testing before any DNS exists.

### The three custom subdomains are authorised

I've added them to staging's `CORS_ALLOWED_ORIGINS` and verified against the live server:

```
Origin: https://test.mamsaa.com                    → allowed
Origin: https://mamsa-app-git-staging-x.vercel.app → allowed  (pattern)
Origin: https://evil.example.com                   → refused  (no allow-origin header)
```

The same list also gates the CSRF origin check on the cookie-session routes, so one entry covers
both CORS and mutations.

**If you pick different subdomain names, tell me** — it is one line on my side, but until it's there
every write request will be refused with no CORS headers, which in the browser looks like a network
failure rather than a permissions one.

---

## 6. What the staging environment gives you

Worth knowing before you point a bench at it:

- **Payments are test-mode.** Staging holds Moyasar **test** keys (`pk_test_…` / `sk_test_…`), so
  nothing charges a real card. You can run the full checkout.
- **Login needs no real SMS.** `TEST_OTP_MODE=true` with a fixed OTP code. Ask for the code — it's
  not written in this file because this repository is public.
- **Test partner:** phone `0512345678`.
- **`APP_DEBUG=true`**, so errors come back with stack traces. Useful for you; also why staging must
  never be exposed as a public-facing environment.
- **Seeded data, refreshed today** specifically so the recent work is exercisable:
  - 23 units with real photos — **84 images, all carrying `variants` and `width`/`height`**
  - source sizes from 260×670 up to 3000×2000, so `full` capping at 2048 and the never-upscale path
    are both visible
  - formatted multi-line descriptions on every unit, one at ~1949 characters
  - the edge cases: an address opening with `<`, a unit with zero amenities, descriptions containing
    `<=`

  Reseed any time with `test-units:enrich` — ask and I'll run it.

---

## 7. Don't do these

- **Don't point `staging.mamsaa.com` at Vercel.** It's the API. §1.
- **Don't set the staging API base on the Production environment** in a shared project — that would
  swing the live site onto staging data.
- **Don't reuse another domain's CNAME target.** Vercel issues one per domain.
- **Don't expect a variable change alone to take effect.** `NEXT_PUBLIC_*` is inlined at build time;
  redeploy.

---

## Checklist

**Per app**

- [ ] `staging` branch pushed
- [ ] Domain added in Vercel with **Git Branch = `staging`**
- [ ] CNAME added in Hostinger hPanel using the target Vercel showed for *that* domain
- [ ] API base set on **Preview** only, from §3
- [ ] Redeployed after setting it

**Verify**

- [ ] Bench loads over HTTPS
- [ ] A guest unit page shows photos and a formatted description (proves it's on staging data)
- [ ] Partner or admin login succeeds — proves the cookie survives the cross-site hop
- [ ] A save succeeds — proves the origin passes the CSRF check
- [ ] `admin.mamsaa.com` and `partner.mamsaa.com` still hit production and are unaffected

**Tell me if**

- [ ] You choose subdomain names other than the three in §1
