# Known risk: live secrets and a production copy on one developer machine

**Recorded:** 2026-10-01 · **Machine:** the backend developer's Windows/WSL2 machine (`/root/...`)
**Nothing here was changed or deleted.** Deleting anything is irreversible, so Ahmed decides.

## What is on the machine

| Path | What it holds | In any git repo? |
|---|---|---|
| `/root/Mamsaa/backend/.env` | Keys for the local Docker setup | No (git-ignored, never committed) |
| `/root/Mamsaa/backend/env.prod` | **Production-style keys** (payment gateway publishable/secret among them) | No (never committed) |
| `/root/rehearsal/prod-2026-09-27.sql` | 🔴 **Full production database dump** (2026-09-27, 45 tables), including production's real users | No |
| `/root/rehearsal/app_core/.env` | 🔴 The `.env` from the rehearsal copy of production's `app_core`, **likely production's live secrets** (not opened) | No |
| Docker: `mamsa_rehearsal_db` + its volume | The same production data, restored into MariaDB (container stopped) | No |
| `~/.ssh/mamsa_deploy` | 🔴 **SSH key with shell access to both servers** (staging + production) | No |
| `~/.config/gh/hosts.yml` | GitHub token for `mohamedashrafdeve-arch` (push access to vego-group repos) | No |
| `/root/claude-strip-backups-2026-10-01/` | Pre-rewrite git bundles of five repos (source code only) | No |

These files are readable by any local user (`-rw-r--r--`). None of them is reachable from the internet.

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
8. **Personal data:** the production dump means production users' data left the server, so a data-protection
   notification may be required. That's Ahmed's decision.

## Recommendation (pending Ahmed)

- **Delete the rehearsal production copy**: `/root/rehearsal/` (dump + `app_core` with its `.env`), and the
  `mamsa_rehearsal_db` container and its volume. The rehearsal was 2026-09-27 and its report is written.
- **Until then:** `chmod 600` on the dump and both `.env` files, so only the owner can read them.
- **Rule from here on:** a production copy for a rehearsal is deleted when the rehearsal ends, and the
  report says when.
