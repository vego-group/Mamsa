# CORS on staging — fixed (and what actually happened)

**Status:** fixed, live now on `staging.mamsaa.com`. No frontend code change needed — retry and it works.
**Cause:** ours. Details below, because the diagnosis in the report is half right and the difference matters.

---

## What was actually wrong

**CORS was never "completely closed."** It was working the whole time — for origins on the allowlist.
Your origin wasn't on it.

On 2026-07-14 we replaced `CORS_ALLOWED_ORIGINS=*` with an explicit allowlist (needed for the
partner dashboard's cookie auth — see below). That list included `http://localhost:3000` but **not
`http://localhost:3001`**, which is what you're running on. Our miss, and it cost you a morning —
sorry.

Proof, taken before the fix — same endpoint, same second, only the `Origin` differs:

```
Origin: http://localhost:3000  ->  access-control-allow-origin: http://localhost:3000   ✅
Origin: http://localhost:3001  ->  (no header)                                          ❌
```

### The tell you can reuse

Your capture actually contained the answer:

```
Vary: Origin, Access-Control-Request-Method     <- CORS middleware RAN
(no Access-Control-Allow-Origin)               <- and rejected the origin
```

`Vary: Origin` present + no `ACAO` = **the origin isn't allowlisted**. If CORS were disabled or the
middleware were gone, you'd see neither header. A 200 with no `ACAO` never means "the server ignored
CORS" — it means "the server considered your origin and said no." Worth keeping in the toolkit: it
tells you it's an allowlist problem, not an outage.

## Fixed

`http://localhost:3001` and `:3002` are now allowed. Verified on the exact endpoints you named:

```
GET  /api/v1/units/popular          Origin :3001 -> access-control-allow-origin: http://localhost:3001 ✅
GET  /api/v1/units/{id}             Origin :3001 -> access-control-allow-origin: http://localhost:3001 ✅
OPTIONS /api/v1/payments/initiate   Origin :3001 -> access-control-allow-origin: http://localhost:3001 ✅
                                                    access-control-allow-credentials: true
```

Current staging allowlist:

```
https://www.mamsaa.com, https://mamsaa.com, https://testvue.mamsaa.com,
http://localhost:5173, http://localhost:5174,
http://localhost:3000, http://localhost:3001, http://localhost:3002
+ https://mamsa-partner-dashboard.vercel.app + regex ~^https://mamsa-[a-z0-9-]+\.vercel\.app$~
```

## `Access-Control-Allow-Origin: *` is not coming back — here's why

The request was "رجّعوا `*` بأسرع وقت". We can't, and you don't want us to.

Staging now serves the partner dashboard with **cookie-based sessions**
(`Access-Control-Allow-Credentials: true`). The CORS spec forbids pairing credentials with `*` —
and where an implementation tolerates it by echoing the origin, the result is that **any website on
the internet can make authenticated requests carrying a logged-in partner's session cookie** and
read the response. That's a data-exfiltration hole, not a config nicety. `*` was safe only while
everything was Bearer-token and stateless; that stopped being true on 2026-07-14.

An explicit allowlist costs one line per origin and closes that hole. Verified still enforced:

```
Origin: https://evil.example  ->  (no ACAO)  ✅ refused
```

## What we need from you

**Tell us every origin you'll serve from** and we'll add it — it's a one-line change plus a deploy,
usually minutes:

- other local dev ports (we've pre-added `:3000`–`:3002`),
- your staging/preview URL — any `https://mamsa-*.vercel.app` is covered by the regex pattern; anything
  else needs listing,
- your production domain.

If you hit `Failed to fetch` again, run this first — it tells us instantly whether it's an allowlist
miss or something real:

```bash
curl -sD- -o /dev/null -H "Origin: http://localhost:3001" \
  https://staging.mamsaa.com/api/v1/units/popular | grep -i access-control
```

Header present → CORS is fine, look elsewhere. Header absent → send us the exact `Origin` string and
we'll add it.
