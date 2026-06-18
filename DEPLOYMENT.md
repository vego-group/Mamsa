# Deployment Guide — Mamsa

Two supported topologies:

- **A. Single VPS with Docker Compose (recommended, unified).** One server runs
  the whole stack (Nginx + Vue SPA + Laravel + MySQL + Redis + queue worker)
  behind one domain. SPA and API share an origin → **no CORS**. See **§A**.
- **B. Decoupled.** Static SPA on Vercel + Laravel API on a PHP host (different
  origins → CORS required). See **§1–§5**.

```
A) Browser ─HTTPS─▶ VPS Nginx ─┬─ /         → Vue SPA (static)
                               └─ /api,/sanctum → Laravel (php-fpm) ─ MySQL/Redis

B) Browser ─HTTPS─▶ Vercel (SPA) ─HTTPS,Bearer─▶ api.your-domain.com (Laravel)
```

---

## A. Deploy on a single VPS (Docker Compose)

The repo ships a full stack: `docker-compose.yml` (mysql, redis, backend
php-fpm, queue **worker**, frontend, **nginx** reverse-proxy) + `docker-compose.prod.yml`
(production overlay). Target: a clean **Ubuntu 22.04+** VPS.

### A.1 — Server prerequisites

```bash
# Install Docker Engine + Compose plugin
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER && newgrp docker      # run docker without sudo

# Firewall: SSH + HTTP + HTTPS only
sudo ufw allow 22,80,443/tcp && sudo ufw enable
```

### A.2 — Clone

```bash
git clone <repo-url> mamsa && cd mamsa
```

### A.3 — Configure environment (TWO files)

| File | Read by | Holds |
|------|---------|-------|
| `./.env` | docker-compose (variable substitution) | MySQL creds, `APP_KEY`, `PORT`, domain |
| `./backend/.env` | Laravel at runtime (mounted into the container) | **everything app-level**: APP_*, DB, Redis, **FGC SMS**, Moyasar, Mail, CORS |

> ⚠️ The DB credentials in `./.env` **must match** `backend/.env` (`DB_DATABASE`,
> `DB_USERNAME`, `DB_PASSWORD`) — the first provisions MySQL, the second is what
> Laravel connects with.

```bash
cp .env.example .env
cp backend/.env.example backend/.env

# Generate one APP_KEY and put the SAME value in BOTH files:
docker compose run --rm --no-deps backend php artisan key:generate --show
#   → base64:....   (paste into APP_KEY= in ./.env AND backend/.env)
```

Edit **`./.env`** (compose substitution):
```dotenv
APP_ENV=production
APP_KEY=base64:...            # same as backend/.env
APP_URL=https://your-domain.com
PORT=8080                     # compose nginx binds here; host TLS proxy fronts it (A.6)
DB_DATABASE=mamsa
DB_USERNAME=mamsa
DB_PASSWORD=<strong-password>
DB_ROOT_PASSWORD=<strong-root-password>
FRONTEND_DOMAIN=your-domain.com
```

Edit **`backend/.env`** (Laravel runtime — the important one):
```dotenv
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...            # same as ./.env
APP_URL=https://your-domain.com

DB_HOST=mysql                 # compose service name — keep as-is
DB_DATABASE=mamsa
DB_USERNAME=mamsa
DB_PASSWORD=<strong-password> # must match ./.env
REDIS_HOST=redis              # compose service name — keep as-is

# Same origin → CORS effectively unused, but lock it anyway:
FRONTEND_URL=https://your-domain.com
CORS_ALLOWED_ORIGINS=https://your-domain.com
TRUSTED_PROXIES=*             # behind the host TLS proxy

# ── SMS / OTP via FGC (powers OTP login AND all transactional SMS) ──
SMS_DRIVER=fgc
SMS_SENDER_ID=Mamsa
FGC_SMS_USERNAME=<your-fgc-username>
FGC_SMS_PASSWORD=<your-fgc-password>
FGC_SMS_SENDER=Mamsa          # approved FGC sender/header

# Email (notifications) — real SMTP, not 'log'
MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=no-reply@your-domain.com

# Payments — Moyasar LIVE keys
MOYASAR_PUBLISHABLE_KEY=pk_live_...
MOYASAR_SECRET_KEY=sk_live_...
MOYASAR_WEBHOOK_SECRET=<random-token-also-set-on-moyasar-webhook>
```

### A.4 — Build & start

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

> Always pass **both** `-f` files in production. This excludes the auto-loaded
> `docker-compose.override.yml` (dev-only bind-mounts + root user).

Migrations run automatically on backend start (Dockerfile entrypoint). Seed roles
& permissions **once** on first deploy:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
  exec backend php artisan db:seed --class=RolesAndPermissionsSeeder --force
```

Sanity check (compose nginx is on `PORT`, e.g. 8080 — only `/api` & `/sanctum`
reach Laravel; everything else is the SPA):
```bash
curl -s -o /dev/null -w 'SPA  %{http_code}\n' http://localhost:8080/             # → 200 (Vue)
curl -s -o /dev/null -w 'API  %{http_code}\n' http://localhost:8080/api/v1/units  # → 200 (Laravel JSON)
```

### A.5 — Verify FGC SMS / OTP

```bash
# Trigger an OTP and watch the backend logs for the FGC call/result:
curl -X POST http://localhost:8080/api/v1/auth/request-otp \
  -H 'Content-Type: application/json' -d '{"phone":"05XXXXXXXX"}'

docker compose -f docker-compose.yml -f docker-compose.prod.yml logs -f backend
```
FGC errors (auth/sender) are logged via `Log::error('FGC SMS …')`. A successful
send returns the `E001` code. If `SMS_DRIVER=log`, the SMS is only written to the
log (no real send) — make sure it is `fgc`.

### A.6 — HTTPS (required for Moyasar, Apple Pay, production OTP)

The compose stack serves plain HTTP on `PORT`. Terminate TLS with the host's
Nginx + Let's Encrypt in front of it:

```nginx
# /etc/nginx/sites-available/mamsa  (host Nginx, NOT the container)
server {
    listen 80;
    server_name your-domain.com;
    location / {
        proxy_pass http://127.0.0.1:8080;          # compose nginx (PORT)
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;  # so Laravel builds https URLs
    }
}
```
```bash
sudo ln -s /etc/nginx/sites-available/mamsa /etc/nginx/sites-enabled/
sudo apt install certbot python3-certbot-nginx -y
sudo certbot --nginx -d your-domain.com            # auto-adds 443 + auto-renew
```
`TRUSTED_PROXIES=*` (set above) makes Laravel honour `X-Forwarded-Proto`.

### A.7 — Updates / redeploy

```bash
git pull
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
# migrations re-run automatically; if you changed roles/perms, re-seed.
```

### A.8 — Moyasar webhook

In the Moyasar dashboard add `https://your-domain.com/api/v1/payments/callback`
and set its secret token equal to `MOYASAR_WEBHOOK_SECRET`.

### A.9 — Apple Pay (web)

Apple Pay rides on the Moyasar hosted form (it already lists `applepay` in its
methods), but Apple only renders the button once the domain is verified:

1. Moyasar Dashboard → **Settings → Apple Pay → Web** → add your production
   domain and make sure the Apple Pay certificate is activated.
2. Click **Download Association** and save the file — with **no extension** — to:
   ```
   frontend/public/.well-known/apple-developer-merchantid-domain-association
   ```
   Vite ships it to the build output and nginx serves it verbatim (see the
   dedicated `location` block in `frontend/nginx.conf`). After deploy, confirm
   `https://your-domain.com/.well-known/apple-developer-merchantid-domain-association`
   returns the raw file (not the SPA `index.html`).
3. Back in the dashboard, click **Verify**. The button then appears
   automatically on Safari / Apple devices over HTTPS — no app code change.

> Requires HTTPS (see A.6). Apple Pay never renders over plain HTTP.

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
