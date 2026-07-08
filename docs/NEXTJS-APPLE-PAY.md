# Mamsa — Apple Pay Guide (Next.js ↔ Laravel API)

> Companion to `NEXTJS-PAYMENT-INTEGRATION.md` — read that first for the general
> payment flow (initiate → pay/hosted-form → verify). This document covers only
> Apple Pay: what the backend provides, what the Next.js app must implement, and
> the domain-verification plumbing that makes the button appear at all.
>
> Backend source of truth: `PaymentController` + `MoyasarService`
> (`POST /payments/apple-pay/validate-merchant`, `apple_pay_token` charge path).
> Verified against the deployed API on 2026-07-08.

---

## 0. Conventions (same as the main guide)

- Base URL: `https://api.mamsaa.com/api/v1` (prod) / `https://staging.mamsaa.com/api/v1` (dev).
- Every response is wrapped: `{ "success": bool, "message": string, "data": {…} }` —
  all shapes below describe `data`.
- All `/payments/*` endpoints require the Sanctum bearer token
  (`Authorization: Bearer <token>`) and share a **20 req/min/user** rate limit.
- Amounts are **halalas** (integer) on the wire to Moyasar; the API gives you
  `amount_halalas` from `POST /payments/initiate` — never recompute it.

---

## 1. Choose your path

| | **A. Hosted form** (recommended) | **B. Native `ApplePaySession`** |
|---|---|---|
| Effort | ~0 extra code — Apple Pay is a config flag | Custom button + session handling |
| Merchant validation | Moyasar does it internally | You call our backend endpoint |
| Charge | Form → Moyasar directly, then `POST /payments/verify` | `POST /payments/pay` with `apple_pay_token` |
| UX | Moyasar-styled button inside the form | Your own `<ApplePayButton>` anywhere (e.g. sticky checkout bar) |
| Use when | Payment page already renders the hosted form | You want one-tap Apple Pay **outside** the form |

**testvue (current production) uses path A only.** Path B is fully supported by
the backend but has never been exercised in production — test it on staging first.

Both paths require §2 (domain verification) — without it the button silently
never renders.

---

## 2. Domain verification (required for BOTH paths)

Apple only shows the button on **verified domains over HTTPS**. Repeat this for
every domain the Next.js app serves payments from (e.g. `app.mamsaa.com`):

1. **Moyasar dashboard** → Settings → Apple Pay → Web → add the domain.
2. Download the **domain-association file** from that screen.
3. Commit it to the Next.js repo at exactly:

   ```
   public/.well-known/apple-developer-merchantid-domain-association
   ```

   No file extension — Apple rejects `.txt`/`.data`.
4. It must be reachable at
   `https://<domain>/.well-known/apple-developer-merchantid-domain-association`
   returning **HTTP 200 with the raw file body — no redirect of any kind**.
   Apple's crawler does not follow redirects. This bit the backend team already
   (www→apex and HTTP→HTTPS redirects both break it), hence:

   ```js
   // next.config.js — make sure no middleware/rewrite touches .well-known
   async headers() {
     return [{
       source: '/.well-known/apple-developer-merchantid-domain-association',
       headers: [{ key: 'Content-Type', value: 'application/octet-stream' }],
     }];
   }
   ```

   If you use `middleware.ts` (auth, locale prefixing, trailing-slash rules),
   **exclude `/.well-known/…` from its matcher** — a locale redirect to
   `/en/.well-known/...` is the classic silent killer.
5. Back in the Moyasar dashboard, click **Verify**. Status must show verified
   before any device will display the button.

Sanity check (must print the file, status 200, no `location:` header):

```bash
curl -sD - https://app.mamsaa.com/.well-known/apple-developer-merchantid-domain-association | head
```

---

## 3. Path A — hosted form (what testvue does)

Nothing beyond the main guide §3. The relevant config lines:

```ts
Moyasar.init({
  // …everything from NEXTJS-PAYMENT-INTEGRATION.md §3.2…
  methods: ['creditcard', 'applepay'],
  apple_pay: {
    country: 'SA',
    label: 'Mamsa',                     // shown on the Apple Pay sheet
    validate_merchant_url: 'https://api.moyasar.com/v1/applepay/initiate',
  },
});
```

- `validate_merchant_url` points at **Moyasar**, not our API — the form handles
  merchant validation itself. Do **not** point it at our backend.
- After the user authorizes, the form redirects to your
  `/payment/callback?pid=…&id=…&status=…` page exactly like a card payment →
  call `POST /payments/verify` as usual. **No Apple-Pay-specific callback code.**
- The button renders only when: Safari (or iOS WebKit), device with Apple Pay
  set up, HTTPS, and the domain verified per §2. On Chrome/desktop the form
  just shows card fields — that's expected, not a bug.

---

## 4. Path B — native `ApplePaySession` (custom button)

The backend contract, then the component.

### 4.1 `POST /payments/apple-pay/validate-merchant`

Called from `session.onvalidatemerchant`. The backend forwards to Moyasar's
`/applepay/initiate` using the **secret** key (this is why the frontend can't
do it alone) and returns Apple's opaque merchant-session object.

```jsonc
// request
{ "validation_url": "https://apple-pay-gateway.apple.com/paymentservices/startSession" }

// response data = the merchant session object, pass it straight to
// session.completeMerchantValidation(data)
```

Errors: backend throws 500 with a localized message if Moyasar rejects
(unverified domain is the usual cause — recheck §2).

### 4.2 `POST /payments/pay` with `apple_pay_token`

Called from `session.onpaymentauthorized`. Send the **raw
`PKPaymentToken.paymentData` object** (not stringified, not base64):

```jsonc
// request
{
  "payment_id": 123,                         // from POST /payments/initiate
  "apple_pay_token": { /* event.payment.token.paymentData — as-is */ }
}

// response data
{
  "status": "paid" | "failed",               // Apple Pay never triggers 3-DS,
  "payment_id": 123,                         // so no "initiated" in practice
  "transaction_url": null,
  "message": "…gateway message on failure…"
}
```

**On `status: "paid"` you are done** — the backend has already confirmed the
booking, frozen the cancellation policy, and notified partner/admin. Do **not**
call `POST /payments/verify` (that endpoint is for the hosted-form/redirect
flow and requires a `moyasar_id` you don't have here). Route straight to your
success screen.

### 4.3 Component

```tsx
'use client';

// Requires: npm i -D @types/applepayjs   (types only — the API is built into WebKit)

import { useEffect, useState } from 'react';
import { api } from '@/lib/api';            // your authed axios/fetch wrapper

interface Props {
  paymentId: number;
  amount: number;                            // SAR, float — info.amount from initiate
  label?: string;
  onPaid: () => void;                        // navigate to success page
  onError: (msg: string) => void;
}

export function ApplePayButton({ paymentId, amount, label = 'Mamsa', onPaid, onError }: Props) {
  const [available, setAvailable] = useState(false);

  useEffect(() => {
    // canMakePayments() = Safari + configured device; domain check happens later.
    setAvailable(
      typeof window !== 'undefined' &&
      'ApplePaySession' in window &&
      ApplePaySession.canMakePayments(),
    );
  }, []);

  if (!available) return null;               // graceful: card flow remains

  const start = () => {
    const session = new ApplePaySession(3, {
      countryCode: 'SA',
      currencyCode: 'SAR',
      supportedNetworks: ['mada', 'visa', 'masterCard'],   // mada first — KSA
      merchantCapabilities: ['supports3DS'],
      total: { label, amount: amount.toFixed(2) },         // string, 2 decimals
    });

    session.onvalidatemerchant = async (event) => {
      try {
        const { data } = await api.post('/payments/apple-pay/validate-merchant', {
          validation_url: event.validationURL,
        });
        session.completeMerchantValidation(data.data);     // unwrap envelope
      } catch {
        session.abort();
        onError('تعذر التحقق من Apple Pay');
      }
    };

    session.onpaymentauthorized = async (event) => {
      try {
        const { data } = await api.post('/payments/pay', {
          payment_id: paymentId,
          apple_pay_token: event.payment.token.paymentData, // raw object
        });
        const paid = data.data.status === 'paid';
        session.completePayment(
          paid ? ApplePaySession.STATUS_SUCCESS : ApplePaySession.STATUS_FAILURE,
        );
        paid ? onPaid() : onError(data.data.message ?? 'لم يكتمل الدفع');
      } catch {
        session.completePayment(ApplePaySession.STATUS_FAILURE);
        onError('فشلت عملية الدفع، حاول مرة أخرى');
      }
    };

    session.oncancel = () => { /* user dismissed the sheet — no-op */ };

    session.begin();
  };

  // Apple's HIG-compliant button — WebKit renders it natively.
  return (
    <button
      onClick={start}
      style={{
        WebkitAppearance: '-apple-pay-button',
        // @ts-expect-error -- vendor property
        applePayButtonType: 'pay',
        applePayButtonStyle: 'black',
        width: '100%',
        height: 44,
      }}
      aria-label="Apple Pay"
    />
  );
}
```

Wire-up on the payment page: call `POST /payments/initiate` first (main guide
§2.1), then render `<ApplePayButton paymentId={data.payment_id} amount={data.amount} …/>`
alongside (or instead of) the hosted form.

---

## 5. Testing

- **You need real Apple hardware** — Safari on macOS or iOS, signed into an
  Apple ID with a card in Wallet. Simulators/Chrome never show the button.
- **Staging** (`staging.mamsaa.com` API, `pk_test` keys): use an
  [Apple sandbox tester account](https://developer.apple.com/apple-pay/sandbox-testing/)
  with Apple's test cards in Wallet. The staging **frontend** domain you test
  from must itself be verified in Moyasar (test-mode dashboard) per §2 —
  verification is per-domain, testvue's doesn't carry over.
- `localhost` can never work (no HTTPS-verified domain). Use a tunneled or
  deployed HTTPS preview domain, verified in Moyasar, for local testing.
- Backend test mode (`data.test_mode: true` from initiate) only applies to the
  simulate path (`POST /payments/pay` with `payment_id` alone) — the Apple Pay
  sheet always goes through Moyasar for real (test keys → test charge).

---

## 6. Go-live checklist (per frontend domain)

- [ ] Domain added **and verified** in Moyasar dashboard (Apple Pay → Web).
- [ ] Association file served: `curl -sD - https://<domain>/.well-known/apple-developer-merchantid-domain-association` → 200, raw body, **no redirect**.
- [ ] Middleware/rewrites exclude `/.well-known/*` (check locale + auth matchers).
- [ ] Domain present in backend `CORS_ALLOWED_ORIGINS` (backend team — same
      ask as the main guide; without it every API call fails before Apple Pay even starts).
- [ ] Path A: button appears in the hosted form on a verified device; payment →
      callback page → `POST /payments/verify` → `status: "paid"`.
- [ ] Path B (if used): sheet opens, merchant validation succeeds
      (no §4.1 500s), `POST /payments/pay` returns `paid`, **no** verify call made.
- [ ] Tested with **mada** and Visa/MC cards — mada is the majority network in KSA.
