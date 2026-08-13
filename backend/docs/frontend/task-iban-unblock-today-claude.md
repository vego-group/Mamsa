# Task: lift the individual-partner IBAN blocker today (Claude Code — Next.js partner dashboard)

**For:** a Claude Code agent working in the **partner dashboard** repo.
**Date:** 2026-08-13
**Source:** backend investigation of company-docs completeness + the `bank_details` estimate.

## TL;DR — two actions, both shippable today

1. **⚡ Render the IBAN field for INDIVIDUAL partners and send it on `PUT /me/company-docs`.** The
   week-old individual blocker needs **zero backend work** — the restriction was always client-side.
2. **🔴 Keep sending `iban` on `PUT /me/company-docs` for COMPANY partners.** Removing it permanently
   blocks companies from submitting units.

Both write to the same endpoint. One IBAN field, both partner types, one payload — that is the whole
interim design.

---

## 1. ⚡ The individual blocker was never a backend limitation

The agreed fix has been waiting a week on a backend change that turns out not to be needed.

`PUT /me/company-docs` **has no partner-type gate**. It validates and stores `iban` for *any* partner —
individual or company (`ProfileController.php:96` validation, `:113-119` persist). The dashboard simply
never rendered the field unless `accountType === 'company'`.

**So an individual partner can store a bank account today.**

```ts
// Render the IBAN input for BOTH account types, then:
await api.put('/me/company-docs', {
  iban,                    // ← works for individuals too
  accountHolderName,       // (see §2.2 — not persisted yet, send it anyway)
  ...(accountType === 'company' ? { cr, authorizationLetterFileId, vatCertificateFileId, operatorLicenseFileId } : {}),
});
```

- [ ] Remove the `accountType === 'company'` condition around the IBAN field.
- [ ] Send `iban` on the company-docs `PUT` for individuals as well as companies.
- [ ] Label it as a bank/payout account rather than a "company document" — the endpoint is badly
      *named* for this use, but it is the one that stores the value.

### 1.1 Why the endpoint name is wrong and that is fine for now

`/me/company-docs` is a legacy name from when only companies had payout details. Phase A (§3) moves the
storage to a properly named `bank_details` resource. Until then, **the name is cosmetic and the
behaviour is correct** — an individual's IBAN saved through it lands in the same column the payout
system will read.

---

## 2. 🔴 Companies: `iban` must stay in the payload

Restating because it is production-blocking and easy to undo by accident.

Company unit submission is gated on company-docs `complete`, and `complete` requires a non-empty
`iban` — read from the **legacy column**, not from bank details. Drop `iban` from the payload and
`complete` can never become true, so **no company can submit a unit**, behind a `409
COMPANY_DOCS_INCOMPLETE` that names the wrong cause.

- [ ] Verify `iban` is present in every company `PUT /me/company-docs` request.
- [ ] Do not remove it "because the bank-details screen handles that now" — **it does not** (§2.1).

### 2.1 `PUT /me/bank-details` stores nothing today

It is a **stub**: it echoes your values back with `verified: false` and persists **nothing**, and it is
**404 on production** (registered outside production only). Use it to verify field names and casing —
never as the write that matters.

| Endpoint | Stores the IBAN? | Exists in production? |
|---|---|---|
| `PUT /me/company-docs` | **Yes** | **Yes** |
| `PUT /me/bank-details` | No (stub) | **No — 404** |

### 2.2 `accountHolderName` has nowhere to live yet

Company-docs has no column for it, so it will not persist until Phase A. Collect it in the UI and send
it to `/me/bank-details` on staging for shape validation, but expect it to be empty on reload in
production. Do not block the form on it.

---

## 3. What changes when `bank_details` lands — and what does not

**Backend estimate: ~4 dev-days, delivered in two phases.**

**Phase A (~2.5 days)** — table, model, migration; real `GET`/`PUT` registered in **all** environments;
mod-97 validation; completeness re-pointed at bank details **with a fallback to the legacy column**;
backfill of existing IBAN values.

After Phase A:
- `PUT /me/bank-details` becomes the real write path, in production.
- Completeness reads bank details, so companies are no longer coupled to the legacy field.
- **Existing values are backfilled**, so nothing a partner saved through the interim path is lost.

**Your migration is then a one-line switch** — point the IBAN write at `/me/bank-details` and stop
sending it on company-docs. Nothing else in your code changes; the field names are already what you
built against.

**Do not make that switch before the backend confirms Phase A is in production.** The fallback exists
precisely so both paths work during the transition, but only one of them stores anything today.

**Phase B (~1 day)** — server-derived `bankName`. Blocked on sourcing an authoritative SAMA bank-code
table; the backend will not publish an unverified one. If you have access to the official table, supply
it and Phase B lands the same day.

---

## 4. `bankName` — handle `null` from the start

Until Phase B, `bankName` is **`null` or a fixture**, never a real derivation.

- [ ] Delete any local IBAN→bank-name map (the backend owns this now — one source of truth).
- [ ] Render `null` as a neutral empty state. **Never** "Unknown Bank" presented as fact, and never a
      blocker on form submission — it is nullable by contract.
- [ ] Do not validate your old mapping against staging: the stub returns the **same** bank name for
      every IBAN, so it proves nothing about the codes.

---

## 5. Credentials — the staging OTP changed

**The fixed staging/test OTP has been rotated.** The previous value was found published in a public
repository and was simultaneously live on production, so it was replaced and verified (old value now
rejected).

- The new value was sent through a private channel — **it is deliberately not written in this or any
  other document.** Ask the backend lead if you do not have it.
- Delete the old value from any local notes, `.env.local`, test fixtures or scripts.
- Partner logins are unchanged: **`500000003`** (Company), `500000002`, `512345678` — 9 digits starting
  with `5`, no country code, no leading zero.
- `request-otp` is throttled to 3 per 10 minutes per phone; a 429 is the throttle, not a broken account.

---

## 6. Checklist

- [ ] IBAN field renders for **individual** partners (§1) — **this lifts the week-old blocker today**
- [ ] `iban` sent on `PUT /me/company-docs` for **both** account types (§1, §2)
- [ ] No company payload drops `iban` (§2) — production-blocking
- [ ] Bank-details screen still flagged off for production; 404 there is expected (§2.1)
- [ ] `accountHolderName` collected but not depended on until Phase A (§2.2)
- [ ] Local bank-name map deleted; `bankName: null` handled as a neutral state (§4)
- [ ] Old OTP purged from local notes/fixtures; new value obtained privately (§5)
- [ ] A test pinning "individual partner can save an IBAN" and "company can submit a unit"

---

## 7. Sequence

| When | Who | What |
|---|---|---|
| **Today** | frontend | Render IBAN for individuals → blocker lifted (§1) |
| **Today** | frontend | Confirm companies still send `iban` (§2) |
| ~2.5 days | backend | Phase A — real `bank_details` in production |
| +1 line | frontend | Switch the IBAN write to `/me/bank-details` |
| Phase B | backend | Server-derived `bankName` (needs a SAMA table) |
