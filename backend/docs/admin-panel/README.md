# Admin-Panel BFF (Next.js admin) — `/admin/*`

Backend-for-frontend that serves the **finished Next.js 14 admin dashboard**
byte-for-byte against `BACKEND_SPEC.md`. It is a thin, additive layer over the
existing Laravel app — it reuses the same models, services and business rules,
and does **not** touch `/api/v1` or the Vue "testvue" admin.

> There are two admin frontends. The Vue one talks to `/api/v1/admin/*`
> (password + Bearer token, snake_case). **This** one (Next.js) talks to
> root-mounted `/admin/*` (OTP + httpOnly cookie session, camelCase). They use
> **separate auth guards** and can be logged in at the same time in one browser.

---

## 1. Contract at a glance

| Concern | Rule |
|---|---|
| Base path | `/admin/*` (no `/api/v1` prefix) |
| Auth | OTP → httpOnly **session cookie** (guard `admin-panel`). No passwords. |
| Casing | `camelCase` everywhere; ISO-8601 dates; SAR as plain numbers |
| IDs | strings; each entity also has a human `code` (`USR-0007`, `PTR-001`, `UNT-014`, `BKG-0231`, `REQ-005`) |
| Lists | `{ items, total, page, pageSize }` (defaults `page=1`, `pageSize=10`) |
| Common list params | `page`, `pageSize`, `search`, `sortBy`, `sortDir` + per-resource filters |
| Actions | `{ "ok": true }` |
| Errors | flat `{ "message": "<arabic>", "code": "<MACHINE_CODE>" }` |

**Error codes:** `UNAUTHENTICATED` (401), `FORBIDDEN` / `FORBIDDEN_ORIGIN` (403),
`NOT_FOUND` (404), `VALIDATION_ERROR` (422), `CONFLICT` (409),
`USER_HAS_ACTIVE_BOOKINGS` (409), `OTP_INVALID` / `OTP_EXPIRED` /
`OTP_MAX_ATTEMPTS`, `RATE_LIMITED` (429), `REFUND_FAILED` (502),
`SERVER_ERROR` (500).

---

## 2. Where the code lives

| Path | Purpose |
|---|---|
| `routes/admin-panel.php` | all `/admin/*` routes (mounted in `bootstrap/app.php`) |
| `app/Http/Middleware/AdminPanelApi.php` | flags the request, forces JSON, Origin/CSRF gate |
| `app/Http/Controllers/AdminPanel/*` | one controller per resource; `Controller` base owns the envelopes |
| `app/Http/Controllers/AdminPanel/Concerns/MapsSpec.php` | enum/date/money mappers + driver-aware SQL helpers |
| `app/Support/AdminPanel/{UnitPresenter,CancellationPresenter,Analytics}.php` | shared shapes & time-series |
| `app/Http/Resources/AdminPanel/AdminProfileResource.php` | `/admin/me` + profile |
| `app/Exceptions/AdminPanelException.php` | throws the flat `{message,code}` envelope |
| `config/auth.php` | `admin-panel` session guard |
| `bootstrap/app.php` | `admin-panel` middleware group + flat-envelope render branch |

---

## 3. Setup

```bash
# from backend/
cp .env.example .env
php artisan key:generate
php artisan migrate --seed        # creates roles + the dev admin accounts
```

**Dev admin login (OTP by phone):**

| Role | Phone (login) | OTP in dev |
|---|---|---|
| SuperAdmin | `+966500000000` | `OTP_FIXED_CODE` from `.env` |
| Admin | `+966500000001` | `OTP_FIXED_CODE` from `.env` |

In non-production, every OTP equals `OTP_FIXED_CODE` (no SMS is sent when
`SMS_DRIVER=log`). The Next.js **mock** uses `123456`; when you point it at the
real API (`NEXT_PUBLIC_USE_MOCK=false`) the code is whatever the backend issues,
so set `OTP_FIXED_CODE=123456` if you want them to match while testing.

---

## 4. Auth flow

```
POST /admin/auth/request-otp   { phone }              -> { ok:true }        (403 for non-admins)
POST /admin/auth/verify-otp    { phone, code }        -> { ok:true, admin } + Set-Cookie (session)
GET  /admin/me                                        -> AdminProfile        (401 if no session)
POST /admin/auth/logout                               -> { ok:true }         + clears cookie
```

Only registered `Admin` / `SuperAdmin` phones may request an OTP. The cookie is
`httpOnly`, `SameSite=Lax`. The frontend must send requests with
`credentials: "include"`.

---

## 5. Endpoint surface (all under `/admin`)

| Group | Endpoints |
|---|---|
| Profile §5.2 | `GET/PATCH profile`, `GET profile/sessions`, `DELETE profile/sessions/{id}` |
| Dashboard §5.3 | `GET dashboard/summary` |
| Admins (super-admin mgmt) | `GET admins`, `POST admins` `{ phone, name? }` — grant super-admin to a phone. **SuperAdmin-only** (403 for a plain `Admin`); creates or promotes the account and returns `{ ok:true, admin }` (201). 409 if already a super-admin. |
| Users §5.4 | `GET users`, `GET users/stats`, `GET users/{id}`, `PATCH users/{id}/status`, `DELETE users/{id}` (409 if active bookings), `POST users/invite` |
| Partners §5.5 | `GET partners`, `GET partners/stats`, `GET partners/{id}`, `POST partners/{id}/{approve,reject,suspend,verify,revoke-verification}`, `POST partners/{partnerId}/documents/{documentId}/verify`, `POST partners/invite` |
| Units §5.6 | `GET units`, `GET units/stats`, `GET units/{id}`, `POST units` (Mamsa-owned draft), `POST units/{id}/unpublish` |
| Approvals §5.7 | `GET approvals`, `GET approvals/stats`, `GET approvals/{id}`, `POST approvals/{id}/{approve,reject}` |
| Bookings §5.8 | `GET bookings`, `GET bookings/counts`, `GET bookings/stats`, `GET bookings/{id}` (read-only) |
| Cancellations §5.9 | `GET cancellations`, `GET cancellations/stats`, `GET cancellations/high-risk-partners`, `POST cancellations/{id}/retry-refund` |
| Reports §5.10 | `GET reports/summary?range=6m\|1y\|all` |
| Notifications §5.11 | `GET notifications`, `GET notifications/unread-count` (**bare number**), `POST notifications/read-all`, `POST notifications/{id}/read` |

`php artisan route:list --path=admin` prints the live list.

### Business rules honored
- Commission = frozen **2%** of a booking's total; `partnerShare` = 98%.
- Occupancy = confirmed booked-nights / (approved units × window), 0–100.
- `verified` is an **independent badge** (`partner_details.verified_at`), separate
  from KYC-approved status — `verify` / `revoke-verification` toggle only the badge.
- `pending_activation` = an invited user that hasn't activated (`users.invited_at`).
- Payments have no `refunded` status (enum `pending|paid|failed`); refunds live in
  the `refunds` table (`pending|succeeded|failed`) and `payments.refunded_amount`.
  `retry-refund` re-attempts a **failed** refund row (409 otherwise).

---

## 6. Turning the frontend on

The Next.js admin needs **zero code changes** — only two env vars:

```env
NEXT_PUBLIC_USE_MOCK=false
NEXT_PUBLIC_API_BASE_URL=https://<your-api-host>
```

---

## 7. Running the tests

The suite runs on an in-memory sqlite DB. Force the hermetic drivers so a run
can never touch your dev MySQL/Redis:

```bash
docker compose exec -T \
  -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: \
  -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync \
  -e OTP_STORE=array -e REDIS_HOST=127.0.0.1 -e MAIL_MAILER=array \
  backend php vendor/bin/phpunit --testdox tests/Feature/AdminPanel
```

Coverage: `AuthTest`, `ReadEndpointsTest`, `ApprovalsTest`, `MutationsTest`,
`DashboardReportsTest`, `NotificationsTest` (40 tests / 455 assertions).

---

## 8. Production checklist

The BFF is cookie-credentialed, so cross-origin requires **explicit** CORS
(browsers reject credentials with a `*` origin):

```env
CORS_SUPPORTS_CREDENTIALS=true
CORS_ALLOWED_ORIGINS=https://admin.mamsaa.com     # the admin app origin, no '*'
SESSION_DOMAIN=.mamsaa.com                         # share cookie across api./admin. subdomains
SESSION_SECURE_COOKIE=true                         # https only
APP_ENV=production
APP_DEBUG=false
```

- The admin origin must be same registrable domain as the API (e.g.
  `admin.mamsaa.com` ↔ `api.mamsaa.com`) so the `SameSite=Lax` cookie is sent;
  `AdminPanelApi` already trusts `*.mamsaa.com` plus anything in
  `CORS_ALLOWED_ORIGINS`.
- Real (non-simulated) `retry-refund` needs `MOYASAR_SECRET_KEY`. With it blank
  in dev, refunds are simulated (marked succeeded locally).
- SMS: set `SMS_DRIVER=fgc` (or `taqnyat`) + creds so invite/OTP messages send.

## 9. Admin notification fan-out (implemented)
Live platform events write into each admin's DB feed via **model observers**
(`app/Observers/AdminPanel/*`, registered by `AdminNotificationServiceProvider`):
new/resubmitted unit review → `approval`, host cancellation → `cancellation`,
failed refund → `refund`, new partner application → `partner`. Delivery is
best-effort (`NotifiesAdmins` trait) and never breaks the triggering write.
It is strictly additive — no `/api/v1` or partner/customer flow changes.

## 10. Known follow-ups
- CSV/PDF report export (`§5.10`) is intentionally left client-side per the spec.
