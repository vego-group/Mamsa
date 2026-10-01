# Mamsa — Frontend Integration Notes (from the backend gap analysis)

**Audience:** the Next.js frontend team (guest `www`, partner dashboard, admin panel)
**Companion to:** `MAMSA-FRONTEND-NEXTJS-VAT-WALLET-PAYOUTS.md` (the feature spec) and
`MAMSA-BACKEND-CONTRACT-GAP-ANALYSIS.md` (the backend audit).
**Purpose:** this is **not** a re-spec. It is the short list of things the backend audit surfaced that
**change what the frontend must do right now** — mostly environment/auth setup that will break on
deploy day, one error-code fix, the integration order, and a few type reality-checks so you don't
build against a shape that won't ship.

---

## 0. TL;DR — do these first

1. Run the dev app on **`http://localhost:3002`** (not 3000); every API call uses `credentials: 'include'`.
2. Auth is a **cookie session**. A cookie-name / SameSite mismatch between environments looks like
   "randomly logged out" — know the symptom; it is a **backend env fix**, not your bug (§1).
3. Accept **both** `FORBIDDEN` and `INSUFFICIENT_PERMISSION` for a 403 (§2).
4. The wallet / payout / VAT-inclusive / finance-role scope is **not built yet**. Keep mocking it; wire
   per phase as each lands (§3). The base platform (auth, bookings, payments, cancellations,
   notifications) **is** live.
5. Fix your TS types for the backend's **real** enum literals and casing (§4).

---

## 1. Environment & session — the "breaks on deploy day" stuff

### 1.1 CORS — the dev origin is `:3002`

- Your Next.js dev server **must** run on `http://localhost:3002`. The backend allowlists that origin,
  **not** `:3000`.
- All calls to `https://api.mamsaa.com` are credentialed (cookie session) → `fetch(url, { credentials: 'include' })` / axios `withCredentials: true`.
- If you get a CORS failure on a credentialed request, it's a backend env setting: `CORS_ALLOWED_ORIGINS`
  must list your **exact** origin and `CORS_SUPPORTS_CREDENTIALS=true`. A `*` wildcard is **rejected** by
  the browser for credentialed requests — so "it works uncredentialed but fails with cookies" = that.

### 1.2 Session cookie — the name must be identical across environments

- Login sets an **httpOnly** session cookie. Today its **name differs by environment**:
  `mamsaa-session` in production vs `mamsa-session` in staging (one letter). It's derived from the
  backend's app name and is being pinned on the backend side.
- **Frontend symptom if it drifts:** users appear logged out right after a deploy, or when moving
  between envs, even with a valid session. That is **not a frontend bug** — flag it; the fix is a
  backend env var (`SESSION_COOKIE`).
- Do **not** read or hardcode the cookie name anywhere (it's httpOnly and unreadable in JS regardless).
  Rely purely on `credentials: 'include'`.

### 1.3 SameSite — staging ≠ prod today, so **staging can lie to you**

- Production cookie is **`SameSite=Lax`**; staging is **`SameSite=None`**. That difference means a
  cross-site flow that **passes on staging can fail on prod**.
- **The one that bites: the Moyasar payment return.** Under `Lax` the session cookie rides a
  **top-level GET navigation** (a full-page redirect back to your domain) but **not** a cross-site
  `POST`/`fetch`/XHR. Under `None` it rides everything — so a return handler that works on staging can
  drop the session on prod.
- **Frontend implication:** handle the Moyasar return as a **top-level browser redirect** back to your
  own origin, then let the page re-read the session same-site. Do **not** rely on a cross-site
  background `fetch` carrying the session cookie on the way back.
- Ask the backend to make **staging match prod** (same SameSite) so staging is an honest test of the
  checkout return and any other cross-site path.

### 1.4 Verify per environment

| Env | Frontend origin | Cookie name | SameSite | Credentials |
|---|---|---|---|---|
| Local dev | `http://localhost:3002` | (pin it) | `None`+`Secure` if cross-site, else `Lax` | include |
| Staging | (staging URL) | **must equal prod's** | **must equal prod's** | include |
| Prod | `*.mamsaa.com` | `mamsaa-session` (pinned) | `Lax` (+ `SESSION_DOMAIN=.mamsaa.com`) | include |

If frontend and API are **same-site subdomains** of `mamsaa.com`, `Lax` + `SESSION_DOMAIN=.mamsaa.com`
is the intended model and everything "just works." If any surface is a **different site**
(vercel.app, localhost), it must be `None` + `Secure` **in every env**.

---

## 2. Error handling — one correction + the envelope reality

- **403 code is `FORBIDDEN` on the live server**, not `INSUFFICIENT_PERMISSION` as the feature spec
  said. **Accept both** in your error mapper.
- The three surfaces return **different error envelopes** — your parser must handle both shapes:

| Surface | Envelope |
|---|---|
| `/api/v1` (guest, Bearer) | Laravel default (`{ message, errors? }`) |
| Partner dashboard (root) | `{ "error": { "code", "message", "fields"? } }` (nested) |
| Admin panel (`/admin/*`) | `{ "message", "code" }` (flat) |

```ts
// normalise any Mamsa error to { code, message }
function parseError(body: any): { code: string; message: string } {
  if (body?.error?.code) return body.error;               // dashboard (nested)
  if (body?.code) return { code: body.code, message: body.message }; // admin (flat)
  return { code: 'SERVER_ERROR', message: body?.message ?? 'حدث خطأ' };
}
const isForbidden = (c: string) => c === 'FORBIDDEN' || c === 'INSUFFICIENT_PERMISSION';
```

---

## 3. Integration sequence — what's live vs what to keep mocking

The wallet / payout / VAT-inclusive / finance-role features have **zero backend code today**. Keep them
mocked and wire to real endpoints **as each phase lands**. (Effort per phase and calendar translation
are in the gap analysis §12.)

| Phase | Unblocks (stop mocking) | Until then, keep mocking |
|---|---|---|
| 1 | `/admin/me` real `role`+`permissions[]`, permission gating | permission map |
| 2 | VAT-inclusive `PriceBreakdown` (gross/netBase/vat/partnerShare) | **the whole price/VAT surface** |
| 3 | `/me/bank-details` (both account types) + mod-97 | bank-details screen |
| 4 | Partner wallet `/wallet` + ledger | wallet UI |
| 6 | Admin record-transfer + partner payout history | payouts UI |
| 9 | Tax invoice + QR + credit notes | invoice/receipt screen |

**Ask the backend for contract-shaped stub endpoints first** (real URLs + real auth, static fixtures
matching the §5/§6 shapes). That lets you wire live auth and real routes in ~1 week instead of waiting
for full implementation — then swap fixture→live per phase.

---

## 4. Backend reality checks — align your TS types now

The audit found these differences from the feature spec. Adjust types/labels so you don't build against
a shape that won't ship:

- **Booking status literal is `pending`, not `pending_payment`.** Enum today:
  `pending | confirmed | completed | cancelled`. Use these exact strings.
- **Partner status** is modelled as `approved` (≈ "active") on the partner record **plus** an
  `is_active` flag for suspension — **not** a single `active | suspended` field. For "is this partner
  payable," treat it as **approved AND active**.
- **VAT is currently EXCLUSIVE** (`total = subtotal + subtotal×15%`); `PriceBreakdown`,
  `partnerShare`, `commission`, `gross`/`netBase`/`vat` **are not returned yet**. Until Phase 2, the
  money you receive is the **old** shape (`subtotal`, `taxes`, `total`). Don't render the inclusive
  breakdown against live data before Phase 2.
- **Casing differs by surface:** `/api/v1` is **snake_case** (`tax_percent`, `commission_amount`); the
  partner + admin BFFs are **camelCase**. Don't expect a camelCase `PriceBreakdown` on `/api/v1`.
- **No tax invoice / QR endpoint exists** (Phase 9) — keep the invoice screen mocked; when it ships the
  QR is a **server-rendered string** you only draw, never build.
- **IBAN** is **regex-validated only** today (`^SA\d{22}$`); the mod-97 checksum arrives with the new
  `bank_details` resource. Your client regex is fine now; be ready to surface a server `422` when
  mod-97 lands.

---

## 5. Frontend checklist

- [ ] Dev app runs on `:3002`; every API call sends credentials.
- [ ] Error mapper accepts `FORBIDDEN` **and** `INSUFFICIENT_PERMISSION`, and both envelope shapes.
- [ ] Moyasar return handled as a **top-level redirect**, not a cross-site background fetch.
- [ ] TS enums use the real literals (`pending`, `approved`), not the spec's (`pending_payment`, `active`).
- [ ] Money/VAT surfaces stay mocked until Phase 2; invoice until Phase 9.
- [ ] Cookie-name / SameSite mismatches between envs are flagged to backend, not worked around on the client.
