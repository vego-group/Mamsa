# Mamsa — QA test kit

Two ways to verify the platform end-to-end: a human clickthrough and an automated
API sweep. Both cover every role — guest, partner (host), and admin.

## 1. `manual-test-plan.html` — browser clickthrough

A **self-contained** interactive checklist (no server, no external assets — just open
it in a browser). 58 cases across four suites:

| Suite | Scope |
|-------|-------|
| `G`  | Guest / visitor — public browsing, search, unit detail, contact |
| `GA` | Guest account — OTP login, profile, favorites, the book → pay → review lifecycle |
| `P`  | Partner / host — dashboard, unit lifecycle, images, calendar, bookings |
| `A`  | Admin / superadmin — dashboard financials, users, partners, approvals, reports |

Each case has steps, an expected result, and a Pass / Fail / Blocked control. Progress
is saved in the browser's `localStorage`, and **Export results** produces a Markdown
report (with a failures-to-file section) you can paste into an issue.

> Supersedes the static `docs/UAT-TEST-PLAN.html` (Jul 2026). Kept separate for now;
> retire the old one once this is adopted.

## 2. `staging-api-sweep.sh` — automated endpoint sweep

Hits every `/api/v1/*` route against **staging** (the backend `mamsaa.com` currently
talks to) as guest + partner, using the fixed staging OTP. Reads run live; writes are
validation-only, except a reversible favorite add→delete. All credentials used are the
public seeded test accounts (`backend/database/seeders/DevUsersSeeder.php`).

```bash
bash backend/docs/qa/staging-api-sweep.sh
```

## Which backend am I hitting?

| Frontend | Backend API | OTP |
|----------|-------------|-----|
| `mamsaa.com` / `www.mamsaa.com` (consumer) | **staging** `staging.mamsaa.com/api/v1` | fixed `111222` |
| `partner.mamsaa.com` | production `api.mamsaa.com/api/v1` | real SMS |
| `admin.mamsaa.com` | production `api.mamsaa.com/admin` | real SMS |

Always record the target when logging a failure.
