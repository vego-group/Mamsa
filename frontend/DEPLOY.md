# Frontend Deploy — Vue SPA

The Vue SPA is a **static build** (`vite build` → `dist/`). It lives in the
monorepo under `frontend/` and is mirrored to a standalone repo via git subtree.

## Repos & remotes

| Remote | URL | Purpose |
|---|---|---|
| `origin` | `vego-group/Mamsa` | monorepo (source of truth) |
| `frontend-vue` | `git@github.com:mohamedashrafdeve-arch/mamsaa-vue.git` | frontend-only split (deploy/Vercel) |

One-time remote setup (already done):

```bash
git remote add frontend-vue git@github.com:mohamedashrafdeve-arch/mamsaa-vue.git
```

## Mirror the frontend subtree (every release)

From the monorepo root, after committing frontend changes to `main`:

```bash
git subtree push --prefix=frontend frontend-vue main
```

If a split isn't a clean fast-forward, use the explicit form:

```bash
SPLIT=$(git subtree split --prefix=frontend main)
git push frontend-vue "$SPLIT":refs/heads/main
```

> The split only includes tracked files under `frontend/` — `node_modules/`
> and `dist/` are git-ignored and never pushed.

## Which API the build talks to

The SPA calls `import.meta.env.VITE_API_BASE_URL` (see `src/api/http.js`). It's
baked at **build time**, so choose the target per build:

| Target | Command | API base |
|---|---|---|
| Local dev | `npm run dev` | empty → Vite proxies `/api` to `localhost:8080` |
| Staging | `npm run build:staging` | `https://staging.mamsaa.com/api/v1` (see `.env.staging`) |
| Production | `npm run build` | `https://api.mamsaa.com/api/v1` (from `.env.production`) |

`dist/` ships `index.html`, `assets/`, `public/decor/*` and `.htaccess`
(SPA history fallback + force-HTTPS, from `public/.htaccess`).

## Deploy to Hostinger shared (e.g. `testvue.mamsaa.com`)

Static upload only — no PHP/composer/DB on the SPA subdomain.

```bash
# build for the chosen API first (see table above), then upload dist/ contents:
rsync -avz --delete -e "ssh -p 65002" /root/Mamsaa/frontend/dist/ \
  <user>@<host>:~/domains/testvue.mamsaa.com/public_html/
# or extract dist into public_html via hPanel File Manager
```

Then issue SSL for the subdomain (hPanel → SSL) and verify:

```bash
curl -sI https://testvue.mamsaa.com/                  # 200
curl -sI https://testvue.mamsaa.com/decor/hero.jpg    # 200 image/jpeg
curl -sI https://testvue.mamsaa.com/units             # 200 (SPA deep-link via .htaccess)
```

## Production — git-based init & pull (build on the server)

Mirrors the API's `app_core` clone/pull flow. Requires **Node ≥ 20** on the box
(hPanel → Advanced → Node.js, or a preinstalled node binary — check `node -v`).
The repo is cloned once into a **build dir** next to the docroot; each release is
`git pull` + `npm run build`, then the built `dist/` is copied into `public_html`.
`.env.production` bakes the prod API automatically, so no env flags are needed.

### First-time init

```bash
cd ~/domains/<prod-subdomain>            # e.g. app.mamsaa.com
git clone git@github.com:mohamedashrafdeve-arch/mamsaa-vue.git app_src
cd app_src
node -v                                  # confirm >= 20
npm ci
npm run build                            # → dist/ (targets api.mamsaa.com via .env.production)

# publish the build into the docroot (trailing dot copies .htaccess + hidden files)
rm -rf ../public_html/* ../public_html/.htaccess 2>/dev/null
cp -r dist/. ../public_html/
```

### Pull / redeploy (every release)

```bash
cd ~/domains/<prod-subdomain>/app_src
git pull origin main
npm ci                                   # only if package-lock changed; else skip
npm run build
rm -rf ../public_html/* ../public_html/.htaccess 2>/dev/null
cp -r dist/. ../public_html/
```

> **No Node on the box?** Don't build on the server — build **locally/CI** and
> upload `dist/` (the rsync/File-Manager flow above). Building Vite on a
> memory-limited shared host can OOM; the upload path is the reliable default.

## CORS

The API must allow the SPA origin. On the target API's `.env`:
`CORS_ALLOWED_ORIGINS=https://testvue.mamsaa.com` (then `config:cache`).
Production currently allows `*`.

## Vercel (alternative host)

The split repo `mamsaa-vue` includes `vercel.json`; point a Vercel project at it
and set `VITE_API_BASE_URL` in the project's environment variables per env.
