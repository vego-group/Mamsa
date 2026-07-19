# Fixed — `/account/*` email paths now work (404 resolved)

**Date:** 2026-07-19 · Addendum to `NEXTJS-EMAIL-VERIFICATION.md` / `-IMPLEMENTATION.md` · **Live on staging AND production.**

You integrated against the `/account/*` paths from your original task doc, and the backend had shipped the flow under `/user/email*` — hence the `The route api/v1/account/email could not be found` error on the booking screen. Resolved on the backend: **your paths now work as-is. No frontend changes needed.**

## What's live

Both forms hit the **same controllers** — identical bodies, envelopes, machine codes, and throttles:

| Your path (works now) | Canonical twin |
|---|---|
| `POST /api/v1/account/email` | `POST /api/v1/user/email` |
| `POST /api/v1/account/email/verify` | `POST /api/v1/user/email/verify` |
| `POST /api/v1/account/email/resend` | `POST /api/v1/user/email/resend` |
| `GET  /api/v1/account` | `GET /api/v1/auth/me` (same `data` incl. `email`, `email_verified`) |

All four are Bearer-authed (`401` when the token is missing — if you ever see `404` again on these, that's a typo'd path, not a missing route).

## Verified live (staging)

```
POST /api/v1/account/email  { "email": "..." }
→ 200 { "success": true, "data": { "email": "...", "verified": false, "resend_available_in": 60 } }

GET /api/v1/account
→ 200 { "data": { ..., "email": "...", "email_verified": false } }
```

## Two notes

1. **Pick one form and stick to it** app-wide. Postman and the docs keep `/user/email*` + `/auth/me` as the canonical names; the `/account/*` aliases are permanent, but mixing both in one codebase will confuse future greps.
2. Everything else in the two email docs is unchanged — machine codes, 300s validity, 60s cooldown, 5 attempts, staging fixed code `111222`, and the `EMAIL_VERIFICATION_REQUIRED` booking gate (ON staging / OFF prod until you ship).
