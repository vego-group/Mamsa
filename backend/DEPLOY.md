# Deployment — Hostinger Shared Hosting (`api.mamsaa.com`)

How the Laravel API is deployed to Hostinger **shared** hosting via SSH.

> ⚠️ Shared hosting has **no Redis, Supervisor, Docker, or Nginx**. The app must
> run on `file`/`database`/`sync` drivers. For the full documented stack
> (Docker + Redis + Supervisor + Nginx) use a **VPS** instead.

> ⚠️ Production is deployed **file-by-file over scp**, never by pulling — the
> git checkout there is a reference, not the deploy mechanism. Before deploying,
> check [`DEPLOY-HOLDBACKS.md`](DEPLOY-HOLDBACKS.md) for anything production is
> deliberately not running (as of 2026-09-01: nothing).

## Server layout

```
~/domains/api.mamsaa.com/
├── app_core/        # Laravel app (clone of the backend-api repo)
├── public_html/     # web docroot — contents of app_core/public live here
└── DO_NOT_UPLOAD_HERE
```

The document root is `public_html`. Because Hostinger manages `public_html`
(symlinking it is unreliable), we **copy** `public/` into it and repoint the
bootstrap paths.

## Toolchain paths (shared hosting quirk)

CLI PHP and web PHP are configured **separately**. Always run artisan/composer
with the explicit 8.4 binary — the default `php` on the box is 8.2 and fails
the `>= 8.4.1` platform check.

| Tool     | Path                                   |
| -------- | -------------------------------------- |
| PHP 8.4  | `/opt/alt/php84/usr/bin/php`           |
| Composer | `/usr/local/bin/composer`              |
| SSH port | `65002` (see hPanel → SSH Access)      |

Optional convenience aliases in `~/.bashrc` (use literal paths, **no** `$(...)`):

```bash
alias php='/opt/alt/php84/usr/bin/php'
alias composer='/opt/alt/php84/usr/bin/php /usr/local/bin/composer'
```

Also set the web PHP version to **8.4** in hPanel → Advanced → PHP Configuration.

## First-time setup

```bash
cd ~/domains/api.mamsaa.com

# 1. Clone the backend-only repo as app_core
git clone git@github.com:vego-group/mamsaa-backend-api.git app_core

# 2. Wire the docroot to Laravel's public/
rm -rf public_html/* public_html/.htaccess 2>/dev/null
cp -r app_core/public/. public_html/        # trailing dot copies .htaccess too

# 3. Point public_html/index.php at the app one level up
#    require __DIR__.'/../app_core/vendor/autoload.php';
#    $app = require_once __DIR__.'/../app_core/bootstrap/app.php';
nano public_html/index.php

# 4. Storage symlink (artisan storage:link points to the wrong place with this split)
ln -s ~/domains/api.mamsaa.com/app_core/storage/app/public ~/domains/api.mamsaa.com/public_html/storage

# 5. Permissions (suEXEC rejects world-writable files — never 777)
cd public_html && find . -type d -exec chmod 755 {} \; && find . -type f -exec chmod 644 {} \;
cd ../app_core && chmod -R 775 storage bootstrap/cache

# 6. Environment
cp .env.example .env
/opt/alt/php84/usr/bin/php /usr/local/bin/composer install --no-dev --optimize-autoloader
/opt/alt/php84/usr/bin/php artisan key:generate
nano .env                                    # see required values below
```

## Required `.env` overrides (no Redis on shared hosting)

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.mamsaa.com

# DB from hPanel → Databases → MySQL (host is localhost)
DB_HOST=localhost
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...

# Drivers must NOT be redis on shared hosting
CACHE_STORE=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync          # nothing is queued; sync avoids needing a worker
# REDIS_HOST=...               # leave unset — the Docker name "redis" won't resolve
```

> OTP codes use a configurable cache store: `config/otp.php` → `OTP_STORE`
> (defaults to `CACHE_STORE`). For higher concurrency safety prefer
> `OTP_STORE=database` (`php artisan cache:table && php artisan migrate`).

## Redeploy (every release)

From the monorepo, publish the `backend/` subtree to the backend-only repo:

```bash
# local monorepo
git add backend/ && git commit -m "..."
git subtree push --prefix=backend backend-api main
```

Then on the server:

```bash
cd ~/domains/api.mamsaa.com/app_core
git pull origin main
/opt/alt/php84/usr/bin/php /usr/local/bin/composer install --no-dev --optimize-autoloader
/opt/alt/php84/usr/bin/php artisan migrate --force
/opt/alt/php84/usr/bin/php artisan config:clear
/opt/alt/php84/usr/bin/php artisan config:cache
/opt/alt/php84/usr/bin/php artisan route:cache

# If you changed public/ assets, re-copy them into the docroot:
cp -r public/. ../public_html/
```

> Always re-run `config:cache` after editing `.env` — the cached config holds a
> stale copy otherwise (this is what caused the "redis" errors on first deploy).

### If `git subtree push` is rejected (non-fast-forward)

`git subtree split` only reproduces the same commit SHAs on a clone that still
holds the original subtree **cache** (`.git/subtree-cache`). From a fresh clone
(or CI) the split recomputes **different** SHAs for byte-identical content, so
`git subtree push --prefix=backend backend-api main` is refused as
non-fast-forward — you'll see `5ecda5f` on `backend-api/main` but a fresh split
labels the same commit `4239536`.

**Do not force-push.** That rewrites the backend repo's history, which the server
tracks (its `git pull` would then need a hard reset) and which breaks every other
clone. Instead ship a safe **fast-forward overlay** — one commit whose *tree* is
the fresh split, parented on the current `backend-api/main`:

```bash
# local monorepo, on the ref you want to ship (e.g. main with the merge)
git fetch backend-api
git subtree split --prefix=backend --branch=deploy-split HEAD

# review the delta — it must be only what you intend to release
git diff --name-status backend-api/main deploy-split

TREE=$(git rev-parse deploy-split^{tree})
NEW=$(git commit-tree "$TREE" -p backend-api/main \
        -m "chore(deploy): sync backend subtree from monorepo main")
git push backend-api "$NEW:main"          # fast-forward, +1 commit, no rewrite
git branch -D deploy-split
```

> The overlay commit is not in the subtree cache of whatever machine normally
> runs `git subtree push`, so **that machine's next push may also report
> non-fast-forward** — reconcile it the same way (overlay), or on that machine
> reset the local split base to `origin/main` after `git fetch backend-api`.

### If the server pull is blocked by local edits

When a hotfix was applied **directly on the server** — edited tracked files or
new untracked files — `git pull` refuses ("local changes / untracked files would
be overwritten by merge"). Revert the tracked edits and remove the untracked
files first; the pull restores them from the release (verify the release adds the
same files, so nothing is lost):

```bash
cd ~/domains/api.mamsaa.com/app_core
git checkout -- <edited tracked files>       # drop the in-place hotfix
rm -f <untracked files the release also adds>
git pull --ff-only origin main               # --ff-only = fail loudly, never merge-commit
# then the usual: config:clear && config:cache && route:cache
```

## Troubleshooting

| Symptom | Cause / fix |
| --- | --- |
| `Could not open input file: /usr/...` | Two binary paths concatenated. Run **one** php binary + script: `php84 artisan ...`. |
| `Composer dependencies require PHP >= 8.4.1` | Web PHP < 8.4. Set web PHP to 8.4 in hPanel, or run composer with `php84`. |
| 404 on base URL | Docroot not wired to `public/`. Verify `public_html/index.php` exists and paths point to `../app_core`. |
| 403 Forbidden | Permissions. Dirs `755`, files `644`, never `777`; owner must be your user. |
| 405 "GET method not supported" on a POST | http→https redirect downgrades POST→GET. Use `https://` in `base_url`, no trailing slash. |
| `getaddrinfo for redis failed` / `Connection refused` | A Redis driver is still active. Set `CACHE_STORE/SESSION_DRIVER=file`, `QUEUE_CONNECTION=sync`, then `config:cache`. |
| `git subtree push` rejected (non-fast-forward) | Fresh clone lacks the subtree cache → different split SHAs. **Don't force.** Use the fast-forward overlay (see [If `git subtree push` is rejected](#if-git-subtree-push-is-rejected-non-fast-forward)). |
| `git pull` on server: "local changes would be overwritten" | An in-place hotfix. `git checkout --` the edited files + `rm` the untracked ones the release adds, then `git pull --ff-only` (see [If the server pull is blocked](#if-the-server-pull-is-blocked-by-local-edits)). |
| `tail laravel.log` | `~/domains/api.mamsaa.com/app_core/storage/logs/laravel.log` |
