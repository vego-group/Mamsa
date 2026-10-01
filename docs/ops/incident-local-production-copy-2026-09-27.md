# Facts: a copy of the production database on a developer machine (27/09 → 01/10/2026)

**Purpose:** the facts a legal decision on notification can rest on. **The decision is not ours.**
Ahmed is asking the responsible person at VEGO.
**Written:** 2026-10-01 by the backend session. **No personal data in this file.**

## Timeline (UTC)

| When | What | Source |
|---|---|---|
| **2026-09-27 09:33:45** | Full production database dump pulled from the production server to a developer machine (`/root/rehearsal/prod-2026-09-27.sql`, 45 tables, 127 KB, sha256 `cc33b0f0525088e5…`). Purpose: a pre-release rehearsal on a restored production copy | file timestamp; rehearsal report `REPORT-rehearsal-2026-09-27.md` |
| 2026-09-27 09:34:00 | Production `app_core` archive copied (code + its `.env`, so likely production's live secrets) | file timestamp |
| 2026-09-27 09:34:09 | Dump restored into a local MariaDB container `mamsa_rehearsal_db` (Docker volume) | container metadata |
| 2026-09-27 09:35:14–22 | 4 failed DB logins as `prodcopy@localhost`, **before** the database finished starting (09:35:26): the setup script retrying, inside the container | container log |
| 2026-09-27 20:50:05 | Both rehearsal containers stopped. Never started again | container metadata |
| 2026-09-27 → 2026-10-01 | Files stayed on disk, **readable by every local account** (`-rw-r--r--`) | file permissions |
| **2026-10-01 18:08:26** | The code archive was read once, by the backend session, **listing file names only**, to check whether a `.env` was inside | file access time; session log |
| 2026-10-01 ~18:35 | `chmod 600` on the remaining files | session log |
| **2026-10-01 18:35:39** | **Deleted:** the dump, `app_core` with its `.env`, the archive, both rehearsal containers, and the data volume. A search found **no other production dumps** on the machine | session log; post-deletion check |

## Who had access

- **Accounts on the machine that can log in:** `root`, `acl`, `jenkins`. The files were readable by all three.
- **The Windows user of the host machine:** WSL2's filesystem is reachable from Windows (`\\wsl$`).
- **The backend Claude Code session**, which runs as `root` on that machine.
- **Network:** both rehearsal containers had **no published ports**, so the restored database was not
  reachable from the network. The files were not served by any web server.

## Evidence of unauthorised access

**None found.** What that rests on, and where it stops:
- **The dump and the `.env` were not read after 2026-09-28.** This filesystem (`relatime`) updates a file's
  read time whenever it's read more than 24 h after its previous recorded read. Both files still show
  2026-09-27 09:33:45 and 09:46:01. So any read after that window would have changed them.
- **The database was never reachable from the network**, and it ran only on 27/09, 09:34–20:50.
- **Limits:**
  - `relatime` can't rule out a read **within** the first 24 h, on 27/09.
  - `root` can reset access times.
  - The machine has no audit logging (`auditd`).
  - We can't see what the Windows side did.

## What the copy contained

Production's users (a small number of real accounts), their bookings, payments and the related records,
as of 2026-09-27. Plus production's `.env`, likely holding the live payment, SMS, email and database secrets.
**Note:** if anything below is ever in doubt, the secrets-rotation order is in `known-risks-local-copies.md`.
