# Access log — what it records, and how to read what it doesn't

**Added:** 2026-10-01 · **Code:** `backend/app/Http/Middleware/AccessLog.php`
**Switch:** `ACCESS_LOG_ENABLED=true` in the server `.env` (off by default) · retention `ACCESS_LOG_DAYS` (14)
**File:** `storage/logs/access-YYYY-MM-DD.log` on each server (`~/domains/{api,staging}.mamsaa.com/app_core/`)

## What one line holds — exactly six fields

```json
{"t":"2026-10-01T23:05:12.345Z","ip":"203.0.113.7","path":"/units","status":200,"ms":84,"uid":12}
```

| Field | Meaning |
|---|---|
| `t` | When the response finished, UTC, milliseconds |
| `ip` | Client IP as Laravel resolves it (trusted proxies applied) |
| `path` | Path only, **no query string** |
| `status` | HTTP status sent |
| `ms` | From PHP receiving the request to the response being sent |
| `uid` | Authenticated user id, or `null` |

**Scope approved by the owner for production on 2026-10-01: these six and nothing else.**
No query string (signed document links carry their signature there), no body, no headers.
**Adding any field needs a new approval.**

## Privacy — why we keep IPs, and for how long

**The IP address and the user id are personal data.** We keep them for **one purpose only: diagnosing
faults**. Examples: was a failing request ever received, how long did it take, which account saw it
when a partner reports a problem. Nothing else reads them. No analytics, no profiling, no marketing,
and they're never sent to any third party.

- **Retention is 14 days**, enforced by the log channel itself (`ACCESS_LOG_DAYS`). Older daily files
  are deleted automatically, with no manual step that could be forgotten.
- **Minimised by design:** no query string, body or headers (so no tokens, passwords, OTPs or user
  agent), and the path only.
- **Not reachable from the web.** The files live in `app_core/storage/logs`, outside the docroot.
  Verified by real HTTP requests on both servers on 2026-10-01: `/storage/logs/access-2026-10-01.log`,
  `/storage/logs/access.log`, `/app_core/storage/logs/…`, `/../app_core/…`, `/logs/…` and `/access-….log`
  returned 404, 403 or 400, **none with log content**. On the server, following every symlink, no
  `access-*.log` is reachable under `public_html` (the only link is `storage → storage/app/public`). The
  403s come from Hostinger's filter (`x-rasp-block: 1`); safety doesn't depend on that filter.
- **Who can read them:** only someone with SSH access to the hosting account.
- **Switching it off** is one line: `ACCESS_LOG_ENABLED=false`, then `php artisan config:cache`.

## 🔴 The limit: it only sees requests that reach PHP

The line is written by the application. A request that never reaches PHP leaves **no line at all**.
That includes a connection timeout, a TLS failure, the host's web server or firewall refusing it,
and a PHP process that never started.

<a id="reading-an-absence"></a>
**So read an absence as evidence, not as "nothing happened":**

| What you see for the minute in question | What it means |
|---|---|
| Lines from the failing client, with 5xx or a large `ms` | The app received it and was slow or failed. Look in the code or `laravel.log` |
| No line from that client, **but lines from others in the same minute** | The request died **before** the app: network, host, or web server. Not the code |
| No lines from anyone for that minute | The app received nothing: the host or PHP was down. Check whether `ACCESS_LOG_ENABLED` was on before concluding that |

Example: the 30/09 23:05–23:11 UTC `CONNECT_TIMEOUT`s on staging. Under this log they would have
shown up as the second row.

## ⚠️ Known risk: the host keeps no access log we can read

Hostinger shared hosting gives **no web-server access log on disk**. There's only what hPanel shows.
**This is a property of the platform, not a gap we forgot.** Failures before PHP can be inferred
from absence, as described above, but never *seen*.

**If we ever need to see them** (decision recorded 2026-10-01; nothing is being implemented now):
- **A higher hosting tier, or a VPS**, where the web server's own access and error logs are available. Or
- **A CDN or proxy in front of the API** (e.g. Cloudflare) that logs every connection attempt,
  including the ones that never reach the origin.

Either one goes through an owner decision, because it changes hosting or DNS.

## Related: the daily Arabic-collation check

`ops:check-collation --alert` runs daily at 03:10 Riyadh time on both servers (staging and production
since 2026-10-01; production tag `prod-2026-10-01-collation`). If ICU loads anything
other than `ar` for `Collator('ar')`, it emails the operations recipients. These are the same
recipients as the complaint and licence alerts: `config('complaints.alert_recipients')`, falling
back to active SuperAdmins. The email states the locale that actually came back (for example `root`).
Run it by hand: `php artisan ops:check-collation`.

## Linked from

Wherever a fault report is handled, so the absence table is found when it's needed:
`docs/ops/HOST-NOTES-hostinger-shared.md` (incident questions) · `docs/UAT-TEST-PLAN.md` §0
(reporting a failure) · `backend/DEPLOY.md` (troubleshooting) · `README.md` · the docblock of
`backend/app/Http/Middleware/AccessLog.php`.
