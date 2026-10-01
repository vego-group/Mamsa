# Secrets scan — 2026-10-01 (read-only)

**Question asked of everything found:** would this value let someone in, today?
**Scope:** `vego-group/Mamsa`, current files **and full git history** (this repo was public), plus the
history of `vego-group/mamsaa-backend-api`.
**Tools:** gitleaks 8.21.2 (`--redact`, so no value was printed), plus targeted patterns gitleaks has no
rule for: Moyasar `sk_/pk_`, Resend `re_`, `APP_KEY`, FGC SMS, DB and AWS credentials, private keys,
GitHub tokens.
**Method for "does it work":** each candidate value was sent to each server over SSH stdin and compared
with `hash_equals` against the secrets actually configured on staging and production. Only yes/no came
back. API tokens were checked with Sanctum's `findToken` on both servers.

**No key was changed.** The owner handles keys; this only records what exists.

## Result: nothing in the repository works today

| Where | What | In git? | Works today? |
|---|---|---|---|
| `backend/PRODUCTION_CHECKLIST.md` (lines 24, 25, 44, 79), in history since 2026-06-30 | Moyasar publishable/secret, FGC password, Resend key | yes | **No.** Placeholders (`…`, `<…>`); match no live secret on staging or production |
| `.env.example`, `backend/.env.example` | `DB_PASSWORD`, `DB_ROOT_PASSWORD` | yes | **No** on both servers. It's the **local Docker** database password only |
| `.claude/settings.local.json`, in history 2026-06-16 (3 commits), untracked since | `curl` commands: an API token (`7\|…`) and the staging admin password | history only | **No.** The token is valid on neither server; the password was **rotated today** |
| `DEPLOYMENT.md`, `STAGING.md`, `docs/frontend/*` | example keys | yes | **No.** Placeholders |
| `backend/vendor/aws/...` (8 files) | "api key" | (vendored) | **No.** False positives (SDK metadata) |
| `backend/.env`, `backend/env.prod` | real keys | **never committed** (0 commits) | Local files on one machine only. Not in any repo |

Already handled earlier today and re-confirmed: the staging admin password (6 files, rotated), and the
old universal OTP code (no longer accepted on any surface).

## Limits

- Pattern-based: a secret in a format no rule knows would be missed.
- The three frontend repos were scanned by the frontend team, not here.
- `backend/.env` and `backend/env.prod` hold live keys on this machine. They are git-ignored and were never
  committed, but anyone with this machine has them.
