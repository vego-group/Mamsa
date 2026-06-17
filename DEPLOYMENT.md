# Deployment Guide — Mamsa

Mamsa is a **decoupled** app: a static **Vue SPA** (deploy to Vercel/Netlify/any
static host) talking to a **Laravel API** (deploy to any PHP host) over HTTPS with
**Bearer-token** auth. They live on different origins, so CORS must be configured.

```
Browser ──HTTPS──▶ Vercel (Vue SPA)
   │
   └──HTTPS, Bearer token──▶ api.your-domain.com (Laravel + MySQL + Redis)
```

---

## 1. Frontend → Vercel

The failing deployments are because Vercel builds from the repo root, but the app
is in `frontend/`. Fix it once in the dashboard:

1. **Project → Settings → General → Root Directory** → set to **`frontend`**.
   (Framework preset auto-detects **Vite**; `frontend/vercel.json` adds the SPA
   rewrite + cache/security headers.)
2. **Settings → Environment Variables** → add:
   | Name | Value |
   |------|-------|
   | `VITE_API_BASE_URL` | `https://api.your-domain.com/api/v1` |
3. Redeploy. The SPA build is `npm run build` → `dist/`.

> Local dev needs **no** env var — `VITE_API_BASE_URL` defaults to `/api/v1`, which
> Vite proxies to the local backend.

---

## 2. Backend → any PHP host (Forge / Render / Railway / VPS)

Requirements: **PHP 8.3+**, **MySQL 8**, **Redis 7**, Composer.

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env          # then edit (see below)
php artisan key:generate
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force   # roles & perms
php artisan config:cache route:cache view:cache
php artisan storage:link
```

Run the queue worker (refresh-token cleanup, future jobs):
```bash
php artisan queue:work --tries=3 --max-time=3600   # via supervisor/systemd
```

### Production `.env` (key changes from the example)
```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.your-domain.com

# Lock CORS to the deployed SPA origin(s) — never leave '*' in production
CORS_ALLOWED_ORIGINS=https://mamsa.vercel.app,https://mamsa.com
CORS_ALLOWED_ORIGINS_PATTERNS=mamsa-.*\.vercel\.app      # optional: previews
FRONTEND_URL=https://mamsa.com

# Trust the proxy/LB so https URLs + client IPs are correct
TRUSTED_PROXIES=*

DB_CONNECTION=mysql           # real host/credentials
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

SMS_DRIVER=taqnyat            # real SMS gateway (not 'log')
TAQNYAT_API_KEY=...

MOYASAR_PUBLISHABLE_KEY=pk_live_...   # live keys for real payments
MOYASAR_SECRET_KEY=sk_live_...
MOYASAR_WEBHOOK_SECRET=...            # set the same token on the Moyasar webhook
```

---

## 3. Payments (Moyasar)

- The frontend loads the **Moyasar hosted form** and posts results to
  `/payment/callback`, which calls `POST /api/v1/payments/verify` (re-fetches the
  payment server-side — client status is never trusted).
- **Webhook:** in the Moyasar dashboard add `https://api.your-domain.com/api/v1/payments/callback`
  and set a secret token equal to `MOYASAR_WEBHOOK_SECRET`. The callback rejects
  any call whose `secret_token` doesn't match.
- **Apple Pay:** verify your production domain in the Moyasar dashboard (host the
  `apple-developer-merchantid-domain-association` file). Only shows on Safari/Apple
  devices over HTTPS.
- With no keys set the API runs in **simulation mode** (test bookings confirm
  without a real charge) — never deploy production without live keys.

---

## 4. Production checklist

- [ ] `APP_DEBUG=false`, `APP_ENV=production`, `APP_KEY` generated
- [ ] `CORS_ALLOWED_ORIGINS` restricted to the real SPA origin(s)
- [ ] `TRUSTED_PROXIES` set; HTTPS forced (handled in `AppServiceProvider`)
- [ ] MySQL + Redis provisioned; `migrate --force` run
- [ ] Queue worker running under supervisor/systemd
- [ ] Real SMS driver + Moyasar **live** keys + webhook secret
- [ ] `config:cache`, `route:cache`, `view:cache` after every deploy
- [ ] Vercel `VITE_API_BASE_URL` points at the live API
- [ ] CI green (`.github/workflows/ci.yml` builds frontend + runs backend tests)

---

## 5. CI

`.github/workflows/ci.yml` runs on every push/PR:
- **Backend:** `php artisan test` (sqlite in-memory, array cache/session).
- **Frontend:** `npm ci && npm run build`.
