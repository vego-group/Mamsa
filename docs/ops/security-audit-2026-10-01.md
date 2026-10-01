# Security audit — staging + production, 2026-10-01 (read-only)

**Scope:** both Hostinger shared-hosting servers (`api.mamsaa.com` = production, `staging.mamsaa.com`), from the
outside (HTTP/TLS) and the inside (files, configuration, keys), plus the dependency tree.
**Nothing was changed.** Each fix needs the operator's OK; production fixes also need Ahmed's written approval.
**Limits of shared hosting:** no root, no firewall, no OS patching. What we control is the app, its files,
configuration, headers, SSH keys and backups.

## Already solid (verified)

| Check | Result |
|---|---|
| Sensitive files over HTTP (`.git`, `.env*`, `composer.*`, logs, `artisan`, `phpinfo`, backups: 24 paths × 2 hosts) | **None served** (404/403/400, no content) |
| TLS | **TLS 1.3**. Certificates valid until 2026-11-26 (prod) and 2026-11-29 (staging) |
| http → https | **301** on both |
| Docroot | Only `index.php` + the `storage` link. **No stray PHP files** (no web shells) |
| Production `APP_DEBUG` / env | `false` / `production`. Staging also `false` (since today) |
| Session cookies | `secure` + `HttpOnly` on both |
| Test mode on production | OTP bypass and payment bypass **off** |
| Production code integrity | md5-identical to `release/production` at today's last deploy |
| `display_errors` | Off on both |
| Home directory | `drwx--x---`: other hosting accounts can't list or read inside |

## Findings, ranked

| # | Risk | Finding | Where | Proposed fix |
|---|---|---|---|---|
| **H1** | High (low exposure) | **25 dependency advisories, 10 high:** `league/commonmark` ×9 (DoS/XSS), `guzzlehttp/guzzle` ×1 (host-check bypass), plus medium `guzzle`/`psr7`, low `laravel/framework`, `flysystem`. Our code doesn't call commonmark, and guzzle only gets our own fixed URLs | both (same lockfile) | `composer update` of those packages → full suite → staging → production |
| **M1** | Medium | **Production CORS trusts `http://localhost:3002` with credentials** | production | Remove `localhost` from production's allowed origins (`.env`/config) |
| **M2** | Medium | **`.env` is `644`** (readable by other local accounts; mitigated by the home dir's `710`) | both | `chmod 600 .env` (PHP runs as the same user), then a real request to confirm |
| **M3** | Medium | **`~/backups`**: 30 files, mostly world-readable, including **3 `.env` copies** (live keys) and the personal-data backups | account | `chmod 600` everything. Delete old `.env` copies (decision); personal-data backups are due for deletion 2026-10-08 |
| **M4** | Medium | **Staging `app_core` holds 2 old `.env.bak-*` copies** | staging | Delete (or move into `~/backups` with `chmod 600`) |
| **M5** | Medium | **No security headers:** HSTS, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` | both | A Laravel middleware (in git, tested) → staging → production |
| ~~**M6**~~ | ✅ resolved | Two SSH keys: `mamsa-deploy-wsl` (the backend machine) and `ashraf@DESKTOP-LMURV69` (RSA) | account | **Operator confirmed 2026-10-01: both are theirs and needed.** No change |
| **L1** | Low | **`expose_php` on** → `x-powered-by: PHP/8.4.19` tells attackers the exact version | both | Strip the header in the same middleware as M5 |
| **L2** | Low | **Logs:** `laravel.log` 2.5 MB (prod) / 6.3 MB (staging), single file with no rotation, and may hold personal data. Old `error_log` files (last written July) | both | Daily rotation with a retention period (as the access log has); archive or remove the old `error_log` |
| **L3** | Info | `trusted_proxies` unset; production sits behind Hostinger's CDN (`server: hcdn`). The access log shows real client IPs, so no change is needed now | production | Watch only |
| **L4** | Info | Staging `SameSite=None` (needed for the cross-site test frontends) and `OTP_DEBUG_RESPONSE=true` (by decision, for UAT) | staging | Revisit after UAT |

## Order proposed

1. **M2, M3, M4** (file permissions and stray secrets): no code, minutes, staging first.
2. **M6** (SSH key): operator's answer.
3. **M1** (production CORS): one config line. Production, so Ahmed.
4. **M5 + L1** (headers): one middleware, tested, staging → production.
5. **H1** (dependencies): update, full suite, staging → production.
6. **L2** (log rotation).
