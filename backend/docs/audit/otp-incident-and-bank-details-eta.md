# Mamsa — OTP Exposure: Incident Report + `bank_details` Estimate

**From:** backend · **Date:** 2026-08-13
**Status:** credential **rotated and verified** on production and staging. **The new value is NOT in this
file** — it was sent separately, as requested.

---

## 1. Incident summary

| Question | Answer |
|---|---|
| Is the repository still public? | **Yes — still public.** The file is readable anonymously right now (HTTP 200) |
| Was the credential live while published? | **Yes**, for roughly **6–7 days** on production |
| Does staging hold real data? | **Partly** — see §1.3. **No IBANs (0), no bank data** |
| Evidence of outside access? | **None found — but see §1.4 for what could not be checked** |
| New value | **Rotated + verified.** Old code now rejected (422), new accepted (200) |

### 1.1 Exposure window

The value was published in `backend/docs/admin-panel/FRONTEND_INTEGRATION_AGENT_GUIDE.md`, first
committed **2026-07-28** (`69d0a44`) — so it has been publicly readable for **16 days**.

But publication alone is not exposure; the code only worked while test mode was **enabled on
production**:

| Period | Test mode on prod | Published | Actually exploitable |
|---|---|---|---|
| 2026-07-28 → 08-05 | off | yes | **no** |
| **2026-08-05 → 08-11** | **on** | yes | **YES (~6 days)** |
| 2026-08-11 → 08-13 | off (code blanked) | yes | no |
| **2026-08-13 (today)** | **on** | yes | **YES (hours, now closed)** |

So the real exposure is **~6 days in early August, plus a few hours today**, during which
`+966555000003` + the published code was a working **SuperAdmin** login on `api.mamsaa.com`.

### 1.2 Repository status

**Still public** — `visibility: PUBLIC`, 0 forks, 0 stars, last push 2026-08-11. The leaked file returns
**HTTP 200** to an unauthenticated fetch. Rotation closes the credential, but the *old* value remains in
git history permanently; only its usefulness has been removed.

### 1.3 Staging data sensitivity — this is the reassuring part

| Metric | Value |
|---|---|
| Users | 22 |
| Partners | 7 |
| Bookings | 58 |
| Payments | 15 (3 with a real Moyasar id) |
| **IBANs stored** | **0** |

Email domains present: `gmail.com` ×7, `vego.sa` ×2, `mamsaa.sa` ×3, `mamsaa.com` ×1, plus `.test`
domains. The `vego.sa` and `mamsaa.*` addresses are the development company and internal accounts; the
`gmail.com` ones match the pattern of team/personal test accounts seen in the production audit, where
**every** booking account proved to be internal.

**Critically: zero IBANs and zero bank details exist on staging.** So even under the worst assumption,
no partner banking data was reachable. This is closer to routine rotation than to a data-breach
scenario — but §1.4 is the honest limit of that statement.

### 1.4 Evidence of access — what was checked, and what could not be

**Checked:**
- **Session files on production** (`SESSION_DRIVER=file`): 7 admin sessions, all timestamped between
  2026-08-12 21:59 and 2026-08-13 08:53 UTC. All fall inside windows of this project's own automated
  verification work (env-parity changes and the rename smoke tests). Nothing sits outside them.
- **Partner sessions on production: 0.**
- **Database `sessions` table:** empty (the file driver is in use), so no IP/user-agent history there.

**Could not be checked — and this bounds the conclusion:**
- **No web-server access logs are exposed** on this hosting account (`~/logs` does not exist; no
  per-domain access log). So there is **no request-level record of who called
  `/admin/auth/verify-otp`, from which IP, during the 2026-08-05 → 08-11 window.**
- File-based sessions are garbage-collected, so sessions from that earlier window no longer exist to
  inspect.

**Therefore the honest finding is: no surviving evidence of unauthorised access, and every surviving
session is accounted for — but absence of logs means it cannot be positively ruled out for the early
August window.** Anyone stating "confirmed no access" would be overstating what the data supports.

### 1.5 Rotation — done and verified

Applied to **both** environments, with `.env` backups taken first:

- Production: `TEST_OTP_CODE` rotated (the scoped test-mode path).
- Staging: `OTP_FIXED_CODE` rotated (staging uses the non-production fixed-code path).
- Config caches rebuilt; `/up` returns 200 on both.

**Verified on production:**

| Attempt | Result |
|---|---|
| Old published code | **422 — rejected** |
| New code | **200 — accepted** |

### 1.6 Recommended follow-ups

1. **Consider making the repository private**, or at minimum accept that anything committed is public
   by default. Rotation does not remove the old value from history.
2. **Scrub the remaining four files** so the *next* value does not leak the same way:
   `backend/.env.example:97` (an actual `OTP_FIXED_CODE=` default), `backend/config/otp.php:7`,
   `backend/database/seeders/DashboardTestPartnerSeeder.php:27,104`, and
   `backend/postman/Mamsa-API.postman_collection.json` (6 occurrences, including a request body).
3. **Invalidate the 7 existing admin sessions** on production if you want a clean cut — this logs out
   anyone currently signed in, so it is your call rather than something to do silently.
4. **Turn test mode off when testing ends** (`TEST_OTP_MODE=false`) — a standing fixed-code SuperAdmin
   login is a permanent risk surface regardless of how good the code is.
5. **Consider enabling access logging** if the host supports it; the inability to answer "who called
   this endpoint" is the weakest part of this report.

---

## 2. `bank_details` — estimate

**Total: ~4 developer-days**, deliverable in two phases so your screen unblocks sooner.

### 2.1 Phase A — unblocks the screen and companies (~2.5 days)

| Work | Days |
|---|---|
| Table, model, migration | 0.5 |
| Real `GET`/`PUT` replacing the stub, registered in **all** environments | 0.5 |
| mod-97 validation (custom rule + tests) | 0.5 |
| Re-point completeness at `bank_details`, with fallback to the legacy column | 0.5 |
| Backfill existing `partner_details.iban` → `bank_details` (`verified = false`) | 0.5 |

At the end of Phase A: the bank-details screen works in production, individuals and companies can both
store an IBAN, and completeness no longer depends on the legacy column.

`bankName` returns **`null`** during this phase — which is contract-legal ("null if unknown") and is
exactly why §3 of the frontend hand-off asks you to handle null rather than assume a string.

### 2.2 Phase B — `bankName` derivation (~1 day, plus an external dependency)

| Work | Days |
|---|---|
| SAMA bank-code map + derivation from IBAN positions 5–6 + tests | 1.0 |

**The coding is trivial; sourcing the table is the real constraint.** It has to come from SAMA/IBAN
registry documentation rather than recollection — publishing an unverified map would recreate the
silent-drift problem in a place that looks authoritative. If you can supply an authoritative table,
Phase B lands the same day.

### 2.3 Also included, not in your list

Admin verify/reject for bank details (contract §5.3) is part of this resource — **+0.5 day** — since a
partner cannot be payout-eligible until a superadmin verifies the account.

### 2.4 ⚡ The individual-partner blocker does not need to wait

You noted the individual blocker agreed a week ago is still live in production. **It can be lifted
today, with zero backend work.**

`PUT /me/company-docs` **has no partner-type gate** — it accepts and stores `iban` for *any* partner,
individual or company (`ProfileController.php:96` validation, `:113-119` persist). The restriction was
always client-side: the dashboard only rendered the IBAN field when `accountType === 'company'`.

**So: render the IBAN field for individual partners and send it on `PUT /me/company-docs`.** Individuals
can store a bank account immediately, without waiting for Phase A. The endpoint is badly *named* for
that use, which is exactly what Phase A fixes — but it works today.

---

## 3. Summary

| Item | Status |
|---|---|
| Credential rotated | ✅ both environments, old value verified dead |
| Repository | **Still public** — the file is anonymously readable |
| Exposure | ~6 days (Aug 5–11) + hours today, on production |
| Staging data | 22 users / 58 bookings, **0 IBANs** — no banking data at risk |
| Unauthorised access | **No surviving evidence; cannot be positively ruled out** (no access logs) |
| New value | Sent separately — deliberately not written to any file |
| `bank_details` | **~4 dev-days**: Phase A ~2.5 (unblocks prod), Phase B ~1 (needs a SAMA table), +0.5 admin verify |
| Individual blocker | **Liftable today, frontend-only** — §2.4 |
