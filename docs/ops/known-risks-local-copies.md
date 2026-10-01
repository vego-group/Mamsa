# Known risk: live secrets and a production copy on one developer machine

**Recorded:** 2026-10-01 · **Machine:** the backend developer's Windows/WSL2 machine (`/root/...`)
**Update 2026-10-01 (Ahmed's written decision):** the rehearsal production copy was **deleted 2026-10-01 18:35:39 UTC**
(dump, `app_core` + `.env`, archive, both containers, data volume), after `chmod 600`. `env.prod` and `backend/.env`
went from world-readable to `chmod 600`; the SSH key and GitHub token were already `600`. Facts for the legal
question: `incident-local-production-copy-2026-09-27.md`.

## What is on the machine

| Path | What it holds | In any git repo? |
|---|---|---|
| `/root/Mamsaa/backend/.env` | Keys for the local Docker setup | No (git-ignored, never committed) |
| `/root/Mamsaa/backend/env.prod` | **Production-style keys** (payment gateway publishable/secret among them) | No (never committed) |
| ~~`/root/rehearsal/prod-2026-09-27.sql`~~ | ~~Full production database dump~~ · **DELETED 2026-10-01 18:35:39 UTC** | No |
| ~~`/root/rehearsal/app_core/.env`~~ | ~~production `.env` copy~~ · **DELETED 2026-10-01 18:35:39 UTC** | No |
| ~~Docker: `mamsa_rehearsal_db` + volume~~ | **DELETED 2026-10-01 18:35:39 UTC** (with `mamsa_rehearsal_app`) | No |
| `~/.ssh/mamsa_deploy` | 🔴 **SSH key with shell access to both servers** (staging + production) | No |
| `~/.config/gh/hosts.yml` | GitHub token for `mohamedashrafdeve-arch` (push access to vego-group repos) | No |
| `/root/claude-strip-backups-2026-10-01/` | Pre-rewrite git bundles of five repos (source code only) | No |

Remaining files are `chmod 600` (owner only) since 2026-10-01. None is reachable from the internet.

## If the machine is lost or compromised: rotate in this order

1. **SSH:** remove `mamsa_deploy`'s public key from the Hostinger account (hPanel → SSH keys). It opens a
   shell on staging and production.
2. **GitHub:** revoke the `gh` token (GitHub → Settings → Applications), and check the org's audit log.
3. **Payments (Moyasar):** roll the secret key and the webhook secret, then update production `.env` +
   `config:cache`.
4. **SMS gateway (FGC) password, Resend API key:** roll, then update both servers' `.env`.
5. **Database passwords** for production and staging (hPanel), then `.env` + `config:cache`.
6. **`TEST_OTP_CODE`** on staging (test-mode code).
7. **`APP_KEY`:** last, and only with a plan. It invalidates sessions and signed URLs, and any value
   encrypted with it.
8. **Personal data:** if a production copy ever leaves a server again, a data-protection notification may be
   required. That's a legal decision, taken by the responsible person at VEGO via Ahmed.

## Rules (Ahmed, written, 2026-10-01)

- **A production copy made for a rehearsal is deleted on the day the rehearsal ends**, and the rehearsal
  report states when it was deleted.
- **No production copy is kept on any machine between rehearsals.**
- **Secrets that have to stay on the machine** (`env.prod`, the SSH key, the GitHub token) are `chmod 600`.
