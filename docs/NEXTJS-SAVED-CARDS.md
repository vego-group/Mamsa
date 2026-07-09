# Mamsa — Saved Cards Guide (Next.js ↔ Laravel API)

> **Instructions for the AI assistant (Claude) working on the Next.js codebase:**
> Your task is to implement (or update) the **saved cards** feature in this project to match the Mamsa backend API described here. This spec was generated directly from the live backend source code and from the production frontend (`testvue.mamsaa.com`) — treat it as the **single source of truth**. Where existing Next.js code disagrees with this document, this document wins.
>
> Work plan:
> 1. **Discover** what exists first. Search the repo for: `cards`, `saved_card`, `wallet`, `payment method`, `moyasar`. Read before changing.
> 2. **Implement** the wallet "payment methods" UI (§3) and the quick-pay path on the payment page (§4) against the endpoints in §2.
> 3. Reuse the project's existing HTTP client, auth-token handling, routing and UI components — do **not** invent a new stack or redesign the UI.
> 4. **Verify** against the acceptance checklist in §6.
>
> Hard rules:
> - The raw card number (PAN) must **never** be sent to the Mamsa API — it goes only to `https://api.moyasar.com/v1/tokens`, directly from the browser.
> - Only the Moyasar **token id** is sent to the backend (`POST /user/cards/from-token`).
> - Never store card data in localStorage/cookies/state beyond the form's lifetime.
> - All card/Moyasar code is client-side only (`"use client"`) — no SSR access to `window`.
> - This document complements `NEXTJS-PAYMENT-INTEGRATION.md` (checkout flow). Read that first if the payment page doesn't exist yet.

- **API base URL (production):** `https://api.mamsaa.com/api/v1`
- **Auth:** Sanctum Bearer token on every request (`Authorization: Bearer <token>`, `Accept: application/json`)
- **Response envelope:** card endpoints return **plain JSON** (array/object, no `{success,data}` wrapper) except `/payments/*` which use the `{ success, message, data }` wrapper — read shapes carefully below.

---

## 1. How saved cards work

The backend stores card **metadata only** (brand, last 4, expiry) plus a Moyasar **token** that allows charging the card again. The PAN never touches Mamsa servers.

A card becomes saved in one of two ways:

1. **Automatically during checkout** — the Moyasar hosted form shows a "save card" checkbox (`save_card: true` in `Moyasar.init`). When ticked and the payment succeeds, `POST /payments/verify` persists the returned token server-side. Nothing extra to build — it already works if checkout follows `NEXTJS-PAYMENT-INTEGRATION.md`.
2. **Manually from the wallet page** — the flow this document adds:

```
GET /payments/config                        → { publishable_key, test_mode }
        │
        ├─ test_mode = true (no gateway keys, staging only)
        │     POST /user/cards/from-token { brand, last4, exp_month, exp_year }
        │
        └─ test_mode = false (real keys — test OR live)
              browser → POST https://api.moyasar.com/v1/tokens   (publishable key)
                        { name, number, cvc, month, year, callback_url }
              → returns { id: "token_…", brand, last_four, month, year, … }
              POST /user/cards/from-token { token: "token_…" }
              (backend re-fetches the token with the secret key — nothing
               client-supplied is trusted beyond the token id)
```

A saved card with a token is **chargeable**: the payment page can offer "quick pay" — charge it with just the CVC, no card re-entry (§4).

---

## 2. Endpoints

### 2.1 `GET /payments/config` — gateway flags (auth)

For pages that tokenise outside checkout. Wrapped response:

```json
{ "success": true, "message": "", "data": {
  "publishable_key": "pk_test_…",
  "test_mode": false,
  "currency": "SAR"
} }
```

- `test_mode: true` → no gateway keys (simulate mode, staging only). Skip Moyasar, send metadata directly (§2.3 variant B).
- `publishable_key` starting with `pk_test` → real Moyasar **test** gateway: show a hint "بطاقة تجريبية: 4111 1111 1111 1111".
- Note: `/payments/*` are throttled to 20 req/min per user.

### 2.2 `GET /user/cards` — list (auth)

Plain array (no wrapper), default card first:

```json
[ {
  "id": 7,
  "brand": "visa",            // "visa" | "mastercard" | "mada"
  "last4": "1111",
  "exp_month": 12,
  "exp_year": 2028,
  "is_default": true,
  "chargeable": true          // has a gateway token → can quick-pay
} ]
```

Cards with `chargeable: false` (legacy metadata-only rows) can be shown and deleted but never offered for quick pay.

### 2.3 `POST /user/cards/from-token` — manual save (auth)

**Variant A — live/test keys (`test_mode: false`), the normal path:**

First tokenise in the browser (publishable key — safe to expose):

```ts
const res = await fetch("https://api.moyasar.com/v1/tokens", {
  method: "POST",
  headers: {
    "Content-Type": "application/json",
    Authorization: "Basic " + btoa(`${publishableKey}:`),
  },
  body: JSON.stringify({
    name,                                  // cardholder name
    number,                                // digits only
    cvc,
    month,                                 // "12"
    year,                                  // "2028" (4 digits)
    callback_url: `${window.location.origin}/account/wallet`, // REQUIRED by Moyasar
  }),
});
const tok = await res.json();              // { id: "token_…", brand, last_four, … } or { message, errors }
```

Then register it with the backend:

```json
POST /user/cards/from-token
{ "token": "token_ZEiKdg2rkCjMYVmtVbwHS3iihv" }
```

**Variant B — simulate mode (`test_mode: true`):**

```json
POST /user/cards/from-token
{ "brand": "visa", "last4": "1111", "exp_month": 12, "exp_year": 2028 }
```

**Response `201` (both variants)** — the saved card in the §2.2 shape.

**Errors:**

| HTTP | When | Body |
|---|---|---|
| 422 | Moyasar doesn't know the token | `{ "message": "رمز البطاقة غير صالح" }` |
| 422 | unsupported scheme (not visa/mastercard/mada) | `{ "message": "نوع البطاقة غير مدعوم" }` |
| 422 | validation | `{ "message": "…", "errors": { … } }` |
| 500 | Moyasar unreachable | `{ "message": "تعذر التحقق من البطاقة، حاول مرة أخرى" }` |

Saving the same physical card again (same brand + last4) **updates** the existing row (refreshes token/expiry) — no duplicates. The first card automatically becomes the default. Display Moyasar's own validation errors (`tok.message`) when tokenisation fails — they're already localised.

### 2.4 `DELETE /user/cards/{id}` (auth)

`204 No Content`. If the default card was deleted the backend promotes the most recent remaining card — **refetch the list** afterwards rather than patching local state. `403` if the card belongs to another user.

### 2.5 `POST /user/cards/{id}/default` (auth)

`204 No Content`. Exactly one default per user.

---

## 3. Wallet page — reference implementation

The testvue wallet page (`/account/wallet`) renders: list of cards (default badge, set-default + delete actions) and an **add-card form** (name / number / MM / YYYY / CVC) that runs the §2.3 flow. Mirror it:

```tsx
"use client";

async function addCard(form: CardForm) {
  const cfg = (await api.get("/payments/config")).data.data;   // wrapped

  let payload;
  if (cfg.test_mode) {
    payload = {
      brand: detectBrand(form.number),                          // ^4→visa, ^5[1-5]|^2[2-7]→mastercard, else mada
      last4: form.number.slice(-4),
      exp_month: Number(form.month),
      exp_year: Number(form.year),
    };
  } else {
    const res = await fetch("https://api.moyasar.com/v1/tokens", {
      method: "POST",
      headers: { "Content-Type": "application/json",
                 Authorization: "Basic " + btoa(`${cfg.publishable_key}:`) },
      body: JSON.stringify({
        name: form.name, number: form.number.replace(/\D/g, ""),
        cvc: form.cvc, month: form.month, year: form.year,
        callback_url: `${window.location.origin}/account/wallet`,
      }),
    });
    const tok = await res.json();
    if (!res.ok || !tok.id) throw new Error(tok.message ?? "بيانات البطاقة غير صحيحة");
    payload = { token: tok.id };
  }

  await api.post("/user/cards/from-token", payload);            // 201 → card object
  return (await api.get("/user/cards")).data;                   // plain array — refetch
}
```

UI notes (match testvue): card rows show `•••• last4`, brand uppercase, expiry `MM/YY`, an «افتراضية» chip on the default; actions are «تعيين كافتراضية» and «حذف» (with confirm). Show the test-card hint under the form whenever `test_mode` is true **or** the key starts with `pk_test`.

---

## 4. Quick pay with a saved card (payment page)

On the checkout payment page, when `initiate` returned `test_mode: false`, fetch `GET /user/cards` and offer chargeable cards above the hosted form. Charging needs only the CVC:

```json
POST /payments/pay
{ "payment_id": 55, "saved_card_id": 7, "cvc": "123" }
```

Wrapped response:

```json
{ "success": true, "data": {
  "status": "initiated",            // "paid" | "initiated" | "failed"
  "payment_id": 55,
  "transaction_url": "https://…",   // present when status = "initiated"
  "message": null
} }
```

- `status: "paid"` → done, show success.
- `status: "initiated"` → **3-D Secure is always forced on token charges.** Redirect the browser to `transaction_url`; the bank page then returns to `/payment/callback`, which verifies via `POST /payments/verify` (already specified in `NEXTJS-PAYMENT-INTEGRATION.md` — same callback page handles both flows, no changes needed).
- `status: "failed"` → show `message`.
- `422 { "message": "البطاقة المحفوظة غير صالحة للدفع" }` → the card has no token (not chargeable); hide it from quick pay.

---

## 5. Test cards (Moyasar test gateway — `pk_test`/`sk_test` keys)

| Card | Number | Result |
|---|---|---|
| Visa | `4111 1111 1111 1111` | success |
| Mastercard | `5105 1051 0510 5100` | success |
| Any | expiry in the past | declined |

CVC: any 3 digits; expiry: any future date. 3-DS pages on the test gateway have an "Authenticate" button — click through.

---

## 6. Acceptance checklist

- [ ] Wallet page lists cards from `GET /user/cards`, default first, with brand/last4/expiry.
- [ ] Add-card form tokenises at `api.moyasar.com` — the PAN appears in **no** request to `mamsaa.com` (verify in DevTools Network).
- [ ] `callback_url` is included in the Moyasar token request (Moyasar 422s without it).
- [ ] Saving the same card twice doesn't duplicate the row.
- [ ] Delete refetches the list (backend may promote a new default).
- [ ] Set-default updates the badge; only one default at a time.
- [ ] Quick pay sends `{ payment_id, saved_card_id, cvc }` and handles `paid` / `initiated` (redirect to `transaction_url`) / `failed`.
- [ ] Non-chargeable cards are never offered for quick pay.
- [ ] Simulate mode (`test_mode: true`) still works end-to-end with metadata-only save.
- [ ] Card form state is cleared after save/cancel; no card data persisted anywhere.
