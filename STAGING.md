# Staging on Hostinger Shared — `staging.mamsaa.com` + `testvue.mamsaa.com`

Goal: a **parallel test environment** that never touches production.

| Subdomain | What runs there | Points at |
|---|---|---|
| `api.mamsaa.com` | **Production** Laravel API (leave untouched) | prod DB + live Moyasar |
| `staging.mamsaa.com` | **Staging** Laravel API (own install + own DB) | staging DB + **test** Moyasar |
| `testvue.mamsaa.com` | **Static Vue SPA** build (`dist/`) | `staging.mamsaa.com/api/v1` |

Both staging pieces are fully isolated: separate subdomain directory, separate
MySQL database, sandbox Moyasar keys, and OTP/email in `log` mode. Production is
not modified by anything here.

Toolchain reminders (same as [`backend/DEPLOY.md`](backend/DEPLOY.md)): SSH port
`65002`; PHP 8.4 = `/opt/alt/php84/usr/bin/php`; docroot is the Hostinger-managed
`public_html` (copy `public/` in, don't rely on symlinks); dirs `755`, files
`644`, `storage`/`bootstrap/cache` `775` — never `777`.

---

## 0. Create the two subdomains (hPanel)

hPanel → **Domains → Subdomains** → create:

- `staging` → document root `domains/staging.mamsaa.com/public_html`
- `testvue` → document root `domains/testvue.mamsaa.com/public_html`

Then hPanel → **SSL** → issue a free Let's Encrypt cert for **each** subdomain.
Set web PHP to **8.4** for `staging.mamsaa.com` (hPanel → Advanced → PHP Config).
`testvue` is static, so its PHP version is irrelevant.

Create a staging DB: hPanel → **Databases → MySQL** → new database + user
(e.g. `uXXXX_mamsa_staging`). Keep these creds for step 1's `.env`.

---

## 1. Staging API — `staging.mamsaa.com`

```bash
cd ~/domains/staging.mamsaa.com

# 1. Clone the backend-only repo (same repo prod uses). To test UNRELEASED code,
#    clone a branch instead: `git clone -b staging <repo> app_core`.
git clone git@github.com:vego-group/mamsaa-backend-api.git app_core

# 2. Wire the docroot to Laravel's public/
rm -rf public_html/* public_html/.htaccess 2>/dev/null
cp -r app_core/public/. public_html/          # trailing dot copies .htaccess

# 3. Point public_html/index.php one level up at ../app_core
#    require __DIR__.'/../app_core/vendor/autoload.php';
#    $app = require_once __DIR__.'/../app_core/bootstrap/app.php';
nano public_html/index.php

# 4. Storage symlink (bundled default image + uploads served at /storage)
ln -s ~/domains/staging.mamsaa.com/app_core/storage/app/public \
      ~/domains/staging.mamsaa.com/public_html/storage

# 5. Permissions
cd public_html && find . -type d -exec chmod 755 {} \; && find . -type f -exec chmod 644 {} \;
cd ../app_core && chmod -R 775 storage bootstrap/cache

# 6. Install + env
/opt/alt/php84/usr/bin/php /usr/local/bin/composer install --no-dev --optimize-autoloader
cp .env.example .env
/opt/alt/php84/usr/bin/php artisan key:generate
nano .env                                      # values below
```

### Staging `.env` (differs from production)

```env
APP_ENV=staging          # NOT production → enables debug_otp + Moyasar test mode
APP_DEBUG=true
APP_URL=https://staging.mamsaa.com

# Dedicated staging DB (from hPanel) — NEVER the production database
DB_HOST=localhost
DB_DATABASE=uXXXX_mamsa_staging
DB_USERNAME=uXXXX_mamsa_staging
DB_PASSWORD=...

# Shared hosting: no Redis / Supervisor
CACHE_STORE=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync

# Safe test channels — OTP/email go to storage/logs, response also returns debug_otp
SMS_DRIVER=log
MAIL_MAILER=log

# Sandbox Moyasar (test keys never charge real cards)
MOYASAR_PUBLISHABLE_KEY=pk_test_...
MOYASAR_SECRET_KEY=sk_test_...
MOYASAR_WEBHOOK_SECRET=

# CORS: the staging SPA is a DIFFERENT origin → must be allowlisted
FRONTEND_URL=https://testvue.mamsaa.com
CORS_ALLOWED_ORIGINS=https://testvue.mamsaa.com

# Bundled default listing image (served via the storage symlink from step 4)
DEFAULT_IMAGE_PATH=defaults/unit-default.avif
```

### Migrate, seed, cache

```bash
cd ~/domains/staging.mamsaa.com/app_core
/opt/alt/php84/usr/bin/php artisan migrate --force
/opt/alt/php84/usr/bin/php artisan db:seed --force      # demo data for a "full" look
/opt/alt/php84/usr/bin/php artisan config:clear
/opt/alt/php84/usr/bin/php artisan config:cache
/opt/alt/php84/usr/bin/php artisan route:cache
```

Verify: `curl https://staging.mamsaa.com/api/v1/units/categories` → 3 categories
with `image_url`, and the default image loads at
`https://staging.mamsaa.com/storage/defaults/unit-default.avif`.

---

## 2. Staging SPA — `testvue.mamsaa.com`

Built **locally** and uploaded (static; no PHP/composer on the box). The
`--mode staging` build bakes in `VITE_API_BASE_URL=https://staging.mamsaa.com/api/v1`
(see `frontend/.env.staging`).

```bash
# On your machine, from the monorepo:
cd frontend
npm ci
npm run build:staging          # → frontend/dist (includes .htaccess)

# Upload the CONTENTS of dist/ into the subdomain docroot.
# Option A — rsync over SSH:
rsync -avz --delete -e "ssh -p 65002" dist/ \
  uXXXX@<host>:~/domains/testvue.mamsaa.com/public_html/

# Option B — hPanel File Manager: zip dist/, upload, extract INTO public_html
# so index.html + assets/ + .htaccess sit at the docroot root.
```

The shipped `.htaccess` forces HTTPS and rewrites deep links to `index.html`
(vue-router history mode). Nothing else to configure.

Verify: open `https://testvue.mamsaa.com`, hard-refresh a deep link
(e.g. `/units`), and confirm the Network tab calls `https://staging.mamsaa.com/api/v1/...`
with no CORS errors.

---

## 3. Redeploy (every staging release)

**Staging API** — push the backend subtree, then pull on the server:

```bash
# local monorepo → backend-only repo (main, or a staging branch)
git subtree push --prefix=backend backend-api main

# on the server
cd ~/domains/staging.mamsaa.com/app_core
git pull origin main
/opt/alt/php84/usr/bin/php /usr/local/bin/composer install --no-dev --optimize-autoloader
/opt/alt/php84/usr/bin/php artisan migrate --force
/opt/alt/php84/usr/bin/php artisan config:clear && \
/opt/alt/php84/usr/bin/php artisan config:cache && \
/opt/alt/php84/usr/bin/php artisan route:cache
cp -r public/. ../public_html/            # only if public/ assets changed
```

**Staging SPA** — rebuild and re-upload:

```bash
cd frontend && npm run build:staging
rsync -avz --delete -e "ssh -p 65002" dist/ uXXXX@<host>:~/domains/testvue.mamsaa.com/public_html/
```

---

## Guardrails (do NOT break production)

- **Never** reuse the production DB name/credentials in the staging `.env`.
- Keep staging on **`sk_test_…`** Moyasar keys — live keys charge real cards.
- `api.mamsaa.com` has its own `app_core`/DB and is not touched by any step here.
- Production stays on `APP_ENV=production` (hides `debug_otp`, blocks fake
  payments). Staging is `APP_ENV=staging` (non-production behaviors on).
- After editing any `.env`, always re-run `config:clear && config:cache` — a
  stale config cache is what caused the earlier "redis"/blank-image issues.
