# Mamsa — Backend Report: Company-Docs Completeness, Bank Name, Partner Test Access

**From:** backend · **Date:** 2026-08-13
**In reply to:** the three-item frontend request. **Report only — no code changed.**

**Item 1 answer up front: the break exists here, and it is worse than you described.** The check still
reads the legacy column, **there is no `bank_details` table at all**, and **`/me/bank-details` returns
404 on production**. If the frontend ships the "IBAN moved out of company-docs" change against
production, **every company is permanently blocked from submitting a unit.** Detail and lines in §1.

---

## 1. 🔴 Company-docs completeness — confirmed broken-by-design here

### 1.1 Where completeness is computed

`app/Http/Controllers/Dashboard/ProfileController.php:165-180`, verbatim:

```php
public static function docs(User $user): array
{
    $d = $user->partnerDetail;

    $docs = [
        'cr'                        => $d?->cr_number,
        'iban'                      => $d?->iban,          // ← line 171
        'authorizationLetterFileId' => $d?->authorization_letter_file,
        'vatCertificateFileId'      => $d?->vat_certificate_file,
        'operatorLicenseFileId'     => $d?->operator_license_file,
    ];

    $docs['complete'] = ! in_array(null, $docs, true) && ! in_array('', $docs, true);  // ← line 177

    return $docs;
}
```

`complete` is true only when **all five** values are non-null and non-empty.

### 1.2 Which table/column the IBAN part reads

**`partner_details.iban`** — the legacy column — at **`ProfileController.php:171`** (`$d` is
`$user->partnerDetail`, `:167`). Column defined at
`database/migrations/2026_07_14_000001_partner_dashboard_schema.php:69`.

**It is not reading `bank_details`.** It cannot: **no `bank_details` table, model, or migration exists
in this repository.** That resource is specified in contract §2.5 but has never been built —
verified by absence of `app/Models/BankDetail*.php` and any `*bank_details*` migration.

### 1.3 What happens today to a company with bank details but no legacy IBAN

**It cannot submit a unit** — and on production it cannot even *have* bank details.

The gate, `app/Http/Controllers/Dashboard/UnitController.php:249-252`:

```php
// §4 — companies must have complete payout docs before submitting.
if (($user->partnerDetail?->type ?? 'individual') === 'company'
    && ! ProfileController::docs($user)['complete']) {
    $this->fail('COMPANY_DOCS_INCOMPLETE', 'أكمل مستندات الشركة قبل تقديم الوحدة', 409);
}
```

So a blank `partner_details.iban` ⇒ `complete === false` ⇒ **409 `COMPANY_DOCS_INCOMPLETE`**, exactly
the symptom you hit — an error naming company docs when the real cause is a missing IBAN that the UI no
longer collects.

### 1.4 The part that makes this worse than your case

**`/me/bank-details` does not exist on production.** It is served by a **stub** controller registered
only when the app is *not* in production — `routes/dashboard.php:88` (`if (! app()->isProduction())`),
handlers at `:91-92`, backed by `app/Http/Controllers/Dashboard/Stub/BankDetailsStubController.php`.

Verified live: `GET https://api.mamsaa.com/me/bank-details` → **404**, and `php artisan route:list` on
production matches **0** bank-details routes.

Two consequences:

1. **The stub persists nothing.** `PUT /me/bank-details` echoes the submitted values back with
   `verified: false`; it does not write to any table. So even on staging, saving bank details does
   **not** make `docs()['complete']` true.
2. **On production there is no alternative store at all.** The only writer of `partner_details.iban` is
   `PUT /me/company-docs` (`ProfileController.php:96` validation, `:113-119` persist).

**Therefore:** if the frontend ships "IBAN moved out of company-docs" to production, a company can no
longer supply an IBAN by any route, `complete` can never become true, and **no company can ever submit
a unit**. That is a permanent block, not a transient one.

### 1.5 Current production state

| Fact | Value |
|---|---|
| Companies on production | **1** |
| With incomplete docs (cannot submit a unit) | **1** |
| Of those, missing the IBAN specifically | **1** |

So the single company on production is **already** unable to submit a unit today, independent of any
frontend change.

### 1.6 What has to happen before that frontend change can ship

The backend must own this; it is not fixable from the client:

1. **Build the `bank_details` resource** (contract §2.5) — table, model, real `GET`/`PUT` replacing the
   stub, registered in **all** environments, with mod-97 validation.
2. **Point `docs()` at it** — the IBAN element of completeness must read bank details, with a fallback
   to `partner_details.iban` during migration so existing companies are not broken mid-flight.
3. **Backfill** `partner_details.iban` → `bank_details` (contract §10.2), `verified = false`.
4. **Pin it with a test** — a company with bank details and a null legacy IBAN must submit successfully.
   (You added exactly this test on your side; the same one belongs here.)

Until step 2 lands, **please keep sending `iban` on `PUT /me/company-docs`** for company partners, even
if the field is hidden in the UI. That is the only thing keeping companies able to submit on production.

This is contract phase 3 (~3.5 dev-days) and it just became the blocking item for your change.

---

## 2. Bank name — option (b), and (a) is not available

### 2.1 There is no SAMA table here

Searched `app/`, `config/`, `database/` for any bank-code map: **nothing exists.** This was already
recorded in the gap analysis (§8, open question 4). So **option (a) cannot be honoured** — we have no
authoritative SAMA table to send.

**And we will not send an unverified one.** You flagged codes `55` and `80` as least certain; guessing
them from memory would produce exactly the silent-drift problem you are trying to eliminate, with the
added harm of looking authoritative. Anything we hand over has to come from SAMA/IBAN registry
documentation, not from recollection.

### 2.2 Option (b) — yes, and the field already exists in the response shape

`bankName` is already part of both bank-details responses:
`Stub/BankDetailsStubController.php:26` (GET) and `:47` (PUT), commented "server-derived from the IBAN
bank code".

```jsonc
// GET and PUT /me/bank-details
{
  "iban": "SA0380000000608010167519",
  "accountHolderName": "…",
  "bankName": "البنك الأهلي السعودي",   // ← server-derived
  "verified": false,
  "verifiedAt": null,
  "rejectionReason": null,
  "updatedAt": "…"
}
```

**Commitment:** the real implementation derives `bankName` server-side from IBAN positions 5–6 and
returns it on both `GET` and `PUT`. One source of truth, no client table.

**Two honest caveats:**

- **The value is currently a fixture.** The stub returns the same bank name regardless of IBAN, so you
  can wire the *field* now and delete your local derivation, but do not use the stub's value to verify
  your mapping — it proves nothing about the codes.
- **`bankName` is nullable by contract** (§2.5: "null if unknown"). If a code is not in the server map,
  you get `null` — design the UI for that rather than assuming a string.

Real derivation ships with the `bank_details` build in §1.6, so both items land together.

---

## 3. Staging partner accounts

Three partner accounts exist on staging. The dashboard login expects **9 digits starting with 5, no
country code and no leading zero**:

| Account | Login phone | Type | Units |
|---|---|---|---|
| `+966500000002` | **`500000002`** | Individual | 5 |
| `+966500000003` | **`500000003`** | **Company** | 5 |
| `+966512345678` | **`512345678`** | Individual | 8 |

Use **`500000003`** for company-docs and bank-details work, since it is the company one.

```bash
BASE=https://staging.mamsaa.com
curl -s -c jar -b jar -X POST $BASE/auth/otp/request \
  -H 'Content-Type: application/json' -d '{"phone":"500000003"}'
curl -s -c jar -b jar -X POST $BASE/auth/otp/verify \
  -H 'Content-Type: application/json' -d '{"phone":"500000003","code":"<fixed OTP>"}'
curl -s -b jar "$BASE/me/bank-details"
```

**The OTP:** the staging fixed code is being **rotated** — the previously shared value was found
published in the public repository, so please request the current value directly rather than reusing an
older document. Everything else above is stable.

Verified working just now — `GET /me/bank-details` as `500000003` returns exactly the **seven** fields
in this casing:

```
iban · accountHolderName · bankName · verified · verifiedAt · rejectionReason · updatedAt
```

`request-otp` is throttled to 3 per 10 minutes per phone; a 429 is the throttle, not a broken account.

---

## 4. Summary

| Item | Answer |
|---|---|
| **1. Completeness reads bank_details?** | **No.** `partner_details.iban` — `ProfileController.php:171`, `complete` at `:177` |
| **1. Does the latent break exist?** | **Yes, and worse** — no `bank_details` table exists, and `/me/bank-details` is **404 on production** |
| **1. Company with bank details, no legacy IBAN?** | **Blocked** — 409 `COMPANY_DOCS_INCOMPLETE` (`UnitController.php:249-252`). On prod it cannot even store bank details |
| **1. Production impact** | 1 company exists; it is **already** incomplete and cannot submit a unit |
| **1. Action** | Keep sending `iban` on `PUT /me/company-docs` until the `bank_details` resource ships (§1.6) |
| **2. SAMA table** | Not in the repo; will not send an unverified one |
| **2. Return `bankName`?** | **Yes — option (b).** Field already in the shape; real derivation ships with `bank_details`. Nullable when unknown |
| **3. Partner credentials** | `500000003` (Company), `500000002`, `512345678` — OTP being rotated, request current value |
