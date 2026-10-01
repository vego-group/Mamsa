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

## 🔴 The limit: it only sees requests that reach PHP

The line is written by the application. A request that never reaches PHP leaves **no line at all**.
That includes a connection timeout, a TLS failure, the host's web server or firewall refusing it,
and a PHP process that never started.

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

`ops:check-collation --alert` runs daily at 03:10 Riyadh time on both servers. If ICU loads anything
other than `ar` for `Collator('ar')`, it emails the operations recipients. These are the same
recipients as the complaint and licence alerts: `config('complaints.alert_recipients')`, falling
back to active SuperAdmins. The email states the locale that actually came back (for example `root`).
Run it by hand: `php artisan ops:check-collation`.
