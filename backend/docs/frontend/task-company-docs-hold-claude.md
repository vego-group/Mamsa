# Task: hold the company-docs IBAN change + bank-details constraints (Claude Code — Next.js frontend)

**For:** a Claude Code agent working in the **partner dashboard** repo.
**Date:** 2026-08-13
**Source:** backend investigation of company-docs completeness, bank-name derivation, and partner test access.

## 🔴 Read this before touching anything

**Do NOT ship the "IBAN moved out of company-docs" change to production.** If it lands before the
backend builds the `bank_details` resource, **every company partner is permanently unable to submit a
unit**, and the error blames the wrong thing (`COMPANY_DOCS_INCOMPLETE`).

The backend is worse off than the frontend assumed:

| Assumption | Reality |
|---|---|
| Completeness reads bank details | ❌ It reads the **legacy `partner_details.iban`** column |
| A `bank_details` table exists | ❌ **No table, no model, no migration** — never built |
| `PUT /me/bank-details` stores the IBAN | ❌ It is a **stub**; it echoes values back and **persists nothing** |
| `/me/bank-details` works in production | ❌ **404 on production** — the stub is registered only outside production |

So today the **only** way an IBAN reaches storage is `PUT /me/company-docs`.

---

## 1. Required change: keep writing `iban` to company-docs

Whatever the UI looks like, the **network call must still include `iban`** in the company-docs payload
for company partners.

```ts
// Collect the IBAN wherever the new UX puts it (bank-details screen is fine),
// but keep persisting it through the endpoint that actually stores it.
await api.put('/me/company-docs', {
  cr,
  iban,                          // ← REQUIRED. Removing this blocks unit submission.
  authorizationLetterFileId,
  vatCertificateFileId,
  operatorLicenseFileId,
});
```

- [ ] Confirm `iban` is present in every `PUT /me/company-docs` request a **company** partner makes.
- [ ] If the field was removed from the payload, restore it — the UI may keep it hidden, the payload may not.
- [ ] Do not gate this behind the new bank-details screen shipping.

**Why:** `complete` requires all five company-docs values to be non-empty, and unit submission for
companies is gated on `complete`. A blank IBAN ⇒ `complete === false` ⇒ **409 `COMPANY_DOCS_INCOMPLETE`**
forever, with no visible cause.

### 1.1 Optional dual-write on staging

If you want to exercise the new endpoint too, `PUT /me/bank-details` on **staging** is harmless — but
understand it is a no-op: nothing is stored, and it does **not** make `complete` true. Treat it as a
shape test only, never as the write that matters.

---

## 2. The bank-details screen is staging-only today

Build it — but it **cannot ship to production yet**, because the endpoints do not exist there (404).

- [ ] Put the bank-details screen behind a feature flag, or keep it out of the production build.
- [ ] Do not link to it from any production navigation.
- [ ] Expect **404** if it is ever called against production — that is the current correct behaviour, not
      a regression to debug.

It becomes real when the backend ships the `bank_details` resource (contract §2.5): a real table, real
`GET`/`PUT` registered in **all** environments, mod-97 validation, and completeness re-pointed at it
with a fallback so existing companies are not broken mid-migration. That work is now the blocking item
for your change.

---

## 3. `bankName` — delete the local derivation, with two guards

The backend has **no SAMA bank-code table**, and deliberately will not send an unverified one — guessing
codes `55`/`80` from memory would reproduce exactly the silent-drift problem you are removing, while
looking authoritative.

**Agreed instead: the server derives `bankName` from the IBAN and returns it on both `GET` and
`PUT /me/bank-details`.** One source of truth, no client table.

```jsonc
{
  "iban": "SA0380000000608010167519",
  "accountHolderName": "…",
  "bankName": "البنك الأهلي السعودي",   // server-derived; may be null
  "verified": false,
  "verifiedAt": null,
  "rejectionReason": null,
  "updatedAt": "…"
}
```

- [ ] Delete the local IBAN→bank-name map and read `bankName` from the response.
- [ ] **Handle `null`.** It is nullable by contract ("null if unknown") — render a neutral state, never
      "Unknown Bank" as if authoritative, and never block the form on it.
- [ ] **Do not validate your mapping against staging.** The stub returns the *same* bank name for every
      IBAN, so it proves nothing about the codes. Real derivation arrives with the `bank_details` build.

Because the round-trip is now the source of the value, expect a brief empty state between typing the
IBAN and the server responding — design for it rather than pre-filling from a guess.

---

## 4. Partner test access (staging)

Dashboard login takes **9 digits starting with 5** — no country code, no leading zero.

| Login phone | Type | Use for |
|---|---|---|
| **`500000003`** | **Company** | company-docs + bank-details work |
| `500000002` | Individual | individual-partner IBAN case |
| `512345678` | Individual | general |

```bash
BASE=https://staging.mamsaa.com
curl -s -c jar -b jar -X POST $BASE/auth/otp/request \
  -H 'Content-Type: application/json' -d '{"phone":"500000003"}'
curl -s -c jar -b jar -X POST $BASE/auth/otp/verify \
  -H 'Content-Type: application/json' -d '{"phone":"500000003","code":"<fixed OTP>"}'
curl -s -b jar "$BASE/me/bank-details"
```

**The fixed staging OTP is being rotated** — the previous value was found published in the public
repository. Request the current value from the backend lead rather than reusing an older document.

Verified live: `GET /me/bank-details` as `500000003` returns exactly **seven** fields in this casing —

```
iban · accountHolderName · bankName · verified · verifiedAt · rejectionReason · updatedAt
```

`request-otp` is throttled to 3 per 10 minutes per phone; a 429 is the throttle, not a broken account.

---

## 5. Checklist

- [ ] `iban` restored to every company `PUT /me/company-docs` payload (§1) — **this is the production-blocking one**
- [ ] Bank-details screen flagged off for production; 404 there is expected (§2)
- [ ] Local IBAN→bank-name map deleted; `bankName` read from the response (§3)
- [ ] `bankName: null` handled as a neutral state, not a hard failure (§3)
- [ ] No attempt to verify bank-code mapping against staging fixtures (§3)
- [ ] Seven field names/casing verified against staging with `500000003` (§4)
- [ ] A test pinning "company can submit a unit" so this cannot silently regress again

---

## 6. What the backend owes you

1. `bank_details` resource — real table, real `GET`/`PUT` in **all** environments, mod-97 validation.
2. Completeness re-pointed at bank details, with a fallback to the legacy column during migration.
3. Backfill of existing `partner_details.iban` values into the new store.
4. Server-side `bankName` derivation.
5. A backend test pinning: a company with bank details and a **null** legacy IBAN can submit a unit.

Until (2) lands, §1 is what keeps companies working in production.
