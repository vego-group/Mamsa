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
| Production | `VITE_API_BASE_URL=https://api.mamsaa.com/api/v1 npm run build` | `https://api.mamsaa.com/api/v1` |

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

## CORS

The API must allow the SPA origin. On the target API's `.env`:
`CORS_ALLOWED_ORIGINS=https://testvue.mamsaa.com` (then `config:cache`).
Production currently allows `*`.

## Vercel (alternative host)

The split repo `mamsaa-vue` includes `vercel.json`; point a Vercel project at it
and set `VITE_API_BASE_URL` in the project's environment variables per env.
