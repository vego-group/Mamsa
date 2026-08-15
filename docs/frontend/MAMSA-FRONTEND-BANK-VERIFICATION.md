# Task: bank account verification (Claude Code — Next.js admin panel)

**For:** a Claude Code agent working in the **admin panel**, wallet detail screen
(`/admin/wallets/{partnerId}`).
**Backend status:** ✅ **live on staging AND production**, verified 2026-08-15 ~19:35 UTC.
**Why it matters:** this is the switch that lets a partner be paid. Until an account is verified, the
partner never appears in the payout run — no matter how much they have earned.

---

## 1. The two endpoints

```
POST /admin/wallets/{partnerId}/bank/verify                → { "ok": true }
POST /admin/wallets/{partnerId}/bank/reject   { "reason" } → { "ok": true }
```

Root-mounted, **no `/api/v1`**. Cookie session, `credentials: "include"`, same as every other
`/admin/*` call. `partnerId` is `prt_{id}`, exactly as it arrives from `/admin/wallets`.

```ts
await fetch(`${API}/admin/wallets/${partnerId}/bank/verify`, {
  method: 'POST',
  credentials: 'include',
});

await fetch(`${API}/admin/wallets/${partnerId}/bank/reject`, {
  method: 'POST',
  credentials: 'include',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ reason: 'اسم صاحب الحساب لا يطابق اسم الشريك' }),
});
```

Both return `{ ok: true }`. Neither returns the updated account — **re-fetch
`/admin/wallets/{partnerId}`** after a successful call, or optimistically update from what you sent.

---

## 2. ⚠️ Finance cannot verify — gate the controls, don't just handle the 403

These routes require **`wallets.adjust`**. The **finance role does not have it**, while recording a
transfer (`payouts.execute`) — which finance *does* have.

**This is deliberate.** Finance moves the money; a superadmin approves *where* it can go. Without the
split, one compromised finance session could point a payout at its own account and then pay it.

```ts
// /admin/me already returns the permission list
const canVerify = me.permissions.includes('wallets.adjust');
```

- [ ] **Hide or disable** the verify/reject controls when `wallets.adjust` is absent — a finance user
      seeing a button that always 403s is a worse experience than not seeing it.
- [ ] Still handle `403 INSUFFICIENT_PERMISSION` defensively; permissions can change mid-session.

If you would rather finance owned this too, say so — it is a product decision, not a technical limit.
But it should be made deliberately rather than by default.

---

## 3. Where it goes: the wallet detail screen

`GET /admin/wallets/{partnerId}` → `bankDetails`. **One field is new** (`verifiedBy`):

```jsonc
"bankDetails": {
  "iban": "SA4420000001234567891234",
  "accountHolderName": "شركة الأفق للعقارات",
  "bankName": "بنك الرياض",              // server-derived, may be null
  "verified": true,
  "verifiedAt": "2026-08-15T19:33:27Z",
  "verifiedBy": "محمد أشرف",             // ← NEW: who approved this destination
  "rejectionReason": null,
  "updatedAt": "2026-08-15T16:35:14Z"
}
```

`bankDetails` is **`null`** when the partner has never saved an account.

### The three states to render

| State | Condition | Show |
|---|---|---|
| **No account** | `bankDetails === null` | "لم يضف الشريك حساباً بنكياً بعد" — **no** verify/reject controls |
| **Awaiting review** | `verified: false`, `rejectionReason: null` | the account + **Verify** and **Reject** |
| **Rejected** | `verified: false`, `rejectionReason` set | the reason prominently + **Verify** (they may have fixed it) |
| **Verified** | `verified: true` | ✅ + `verifiedBy` / `verifiedAt` + **Reject** (to revoke) |

- [ ] Show `verifiedBy` and `verifiedAt` on a verified account — it is the audit trail for a
      destination money was sent to, and the reason it exists is that a disputed transfer needs to
      name who approved it.
- [ ] The IBAN is returned **in full** here (admin surface). The partner-facing payout list only ever
      gets `••••7519` — do not copy this component onto a partner-facing screen.

---

## 4. The reject dialog — the reason is the product, not a form field

`reason` is **required**, 3–500 characters, Arabic.

It reaches the partner **verbatim** on their own account screen. It is the only channel that tells
them why they are not being paid — so the copy has to say **what to fix**, not just that something
was wrong.

- [ ] Reject must open a dialog with a required text field. No one-click reject.
- [ ] Consider offering pre-written reasons the reviewer can pick and edit, e.g.
  - `اسم صاحب الحساب لا يطابق اسم الشريك`
  - `الآيبان لا يخص بنكاً سعودياً`
  - `صورة الحساب البنكي غير واضحة`
- [ ] Warn on the **verified → reject** path: it revokes an approved destination and stops payouts.

**What the partner sees**, live from staging after a rejection:

```jsonc
// GET /me/bank-details  (partner dashboard)
{"iban":"SA4420000001234567891234","accountHolderName":"شركة الأفق للعقارات",
 "bankName":"بنك الرياض","verified":false,"verifiedAt":null,
 "rejectionReason":"اسم صاحب الحساب لا يطابق اسم الشريك",
 "updatedAt":"2026-08-15T16:35:14Z"}
```

---

## 5. What each action actually changes

**Verify** → `verified: true`, stamps `verifiedAt` and `verifiedBy`, **clears any `rejectionReason`**.
**Reject** → `verified: false`, clears `verifiedAt` and `verifiedBy`, sets `rejectionReason`.

Two consequences worth building around:

1. **Verification is what unblocks the payout run.** The partner moves out of
   `/admin/payouts/ineligible` (reason `bank_unverified`) and into `/admin/payouts/eligible` with
   their amount attached. Live proof, on a partner holding 100,190 SAR:

   ```
   BEFORE verify: reason=bank_unverified        (excluded from the run)
   VERIFY:        {"ok":true}
   AFTER verify:  amount=100190  bookings=23  bank=بنك الرياض
   AUDIT:         verified_by=محمد أشرف  at=2026-08-15 19:33:27
   ```

   - [ ] After a successful verify, invalidate any cached payout-run queries — the run changes.

2. **The partner changing their IBAN resets verification automatically**, server-side. An account you
   verified yesterday can be back in "awaiting review" today without any admin action.
   - [ ] Never cache `verified` across a session as if it were stable.

---

## 6. Errors

Flat admin envelope, `{ message, code }`. All messages are Arabic and user-facing — render
`message`, branch on `code`.

| Status | `code` | When | Handle |
|---|---|---|---|
| `404` | `NOT_FOUND` — `"لا يوجد حساب بنكي لهذا الشريك"` | partner exists, no account saved | shouldn't happen if you hide controls on `bankDetails === null` |
| `404` | `NOT_FOUND` — `"الشريك غير موجود"` | unknown or non-partner id | bad id / stale list — refresh |
| `422` | `VALIDATION_ERROR` — `"سبب الرفض مطلوب"` | reject with no/short reason | show under the reason field |
| `403` | `INSUFFICIENT_PERMISSION` | caller lacks `wallets.adjust` | §2 — gate the control |
| `401` | `UNAUTHENTICATED` | session expired | bounce to OTP login |

Note the **two different 404 messages** — same code, different meaning. Distinguish on `message` if
you need to, or simply refresh the detail view, which resolves both.

---

## 7. Checklist

- [ ] Verify / Reject controls on `/admin/wallets/{partnerId}`, beside `bankDetails`
- [ ] Controls keyed on `wallets.adjust` from `/admin/me`; hidden for finance (§2)
- [ ] Four states rendered, including `bankDetails === null` with no controls (§3)
- [ ] `verifiedBy` + `verifiedAt` shown on verified accounts (§3)
- [ ] Reject opens a dialog; reason required; copy says what to fix (§4)
- [ ] Confirmation when rejecting an already-verified account (§4)
- [ ] Re-fetch the wallet detail after either action — neither returns the account (§1)
- [ ] Invalidate payout-run queries after a verify (§5)
- [ ] Full IBAN stays on the admin surface only (§3)

---

## 8. Testing it

**Staging** has a usable fixture right now: partner `prt_5` (شركة الأفق للعقارات) has a **verified**
account and ~100,190 SAR, so it appears in `/admin/payouts/eligible`. Reject it to watch it drop into
`/admin/payouts/ineligible` as `bank_unverified`, then verify it to bring it back.

`prt_9` is a partner with a balance and **no account at all** — use it for the `bankDetails: null`
state and the `"لا يوجد حساب بنكي لهذا الشريك"` 404.

**Production** has no verified accounts yet, so the payout run is legitimately empty there. This
endpoint is the first step in changing that.

Backend suite covering this: **190 passed, 1079 assertions**, including that verification moves a
partner into the run, that the approving admin is recorded, that a rejection reaches the partner, and
that the finance role is refused.
