# Mamsa Platform

Marketplace & booking platform for **units** (listings) with a public/customer side,
a partner side (Individuals & Companies), and a Super-Admin back office.

- **Backend:** Laravel 13 (PHP 8.4) REST API — `backend/`
- **Frontend:** Vue 3 SPA (separate) — `frontend/` *(added in a later phase)*
- **Infra:** MySQL 8, Redis 7, Nginx, Docker Compose

## Architecture

- Token-based API auth via **Sanctum** (Bearer) + a **custom 7-day refresh token**.
- Auth is **phone + OTP over SMS**; partners additionally verify email.
- **RBAC** across 4 roles: `user`, `individual`, `company`, `super_admin`.
- Layering: Controller → Form Request → **Service** → Eloquent model + **Policy**, responses via **API Resources**, routes under `/api/v1`.
- **Redis** backs cache, queues, sessions, and OTP storage. A `worker` container drains the queue (SMS, notifications, payment callbacks).
- Provider abstractions: `SmsGateway` (OTP — FCG in prod, `log` driver in dev) and `PaymentGateway` (Moyasar).
- Unit **status lifecycle** state machine (`Draft → Pending → Approved | Rejected`) with an audit log.

## Running locally (WSL `ubuntu22`)

```bash
cd /root/Mamsaa
cp .env.example .env                 # already present in dev
cp backend/.env.example backend/.env # already present in dev
docker compose up -d --build
```

- API health: `http://localhost/up`
- API base: `http://localhost/api/v1`

Useful:
```bash
docker compose exec backend php artisan migrate
docker compose exec backend php artisan tinker
docker compose logs -f backend
```

## Roadmap

1. **Foundation** *(done)* — Laravel + Sanctum + Docker + MySQL/Redis + nginx.
2. **Auth** — phone+OTP login, access+refresh tokens, RBAC roles & policies, partner email verification.
3. **Domain** — units, bookings, payments, reviews, partner requests, unit-status state machine + audit log. *(folds in the legacy Mamsaa project where useful)*
4. **Integrations** — FCG SMS driver, Moyasar payments.
5. **Frontend** — Vue 3 SPA wired to the API.

> Phase 3 onward is informed by the legacy Mamsaa project and the FCG SMS docs.
