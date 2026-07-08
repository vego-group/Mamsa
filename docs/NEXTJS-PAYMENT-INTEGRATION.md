# Mamsa — Payment Integration Guide (Next.js ↔ Laravel API)

> **Instructions for the AI assistant (Claude) working on the Next.js codebase:**
> Your task is to update this project's **existing, outdated payment implementation** to match the current Mamsa backend API described in this document. This spec was generated directly from the live backend source code and from the production frontend (`testvue.mamsaa.com`) — treat it as the **single source of truth**. Where the existing Next.js code disagrees with this document, this document wins.
>
> Work plan:
> 1. **Discover** the current payment code first. Search the repo for: `payment`, `moyasar`, `Moyasar.init`, `mysr-form`, `callback`, `initiate`, `verify`, `booking`. Read what exists before changing anything.
> 2. **Compare** it against §1–§3 below and list the differences (endpoints, request/response shapes, callback handling, Moyasar config).
> 3. **Rewrite** the payment page and callback page to match this spec exactly (§3 has reference implementations). Reuse the project's existing HTTP client, auth-token handling, routing conventions, and UI components/styling — do **not** invent a new stack or redesign the UI; only the payment logic and API contract must change.
> 4. **Verify** against the acceptance checklist in §6.
>
> Hard rules:
> - Never mark a payment successful client-side without `status: "paid"` from `POST /payments/pay` or `POST /payments/verify`.
> - Never compute the halala amount yourself — use `amount_halalas` from the API.
> - The callback route must be exactly `/payment/callback`.
> - All Moyasar/browser code is client-side only (`"use client"` + `useEffect`) — no SSR access to `window`.
> - Do not change anything outside the payment flow unless it's required to make it work (e.g., adding the `.well-known` file).

This document describes **exactly** how the production frontend (`testvue.mamsaa.com`) integrates payments with the Mamsa backend, so the Next.js app can replicate it 1:1. Follow it verbatim and payment will work the same way it works on testvue today.

- **API base URL (production):** `https://api.mamsaa.com/api/v1`
- **Gateway:** Moyasar (hosted payment form, `moyasar.js` v**1.14.0**)
- **Auth:** Laravel Sanctum Bearer token — payment endpoints require a logged-in user
- **Currency:** SAR. Moyasar amounts are in **halalas** (SAR × 100) — the API gives you both, never compute it yourself.

---

## 0. Prerequisites / conventions

### HTTP client

Every request must send:

```
Accept: application/json
Content-Type: application/json
Authorization: Bearer <access_token>   ← Sanctum token from login
```

### Response envelope

Every API response is wrapped:

```json
// success
{ "success": true, "message": "…", "data": { … } }

// error (400/401/403/404/422/503)
{ "success": false, "message": "…", "errors": { … } }   // errors only on 422
```

Always read the payload from `response.data.data`.

### Rate limit

`/payments/*` endpoints are throttled to **20 requests/minute per user**. A `429` means back off — do not retry in a loop.

### Backend config required for a NEW frontend domain (one-time, backend team)

The Next.js app runs on a different origin than testvue, so the backend `.env` must be updated **before** payments will work:

1. `CORS_ALLOWED_ORIGINS` — add the Next.js domain (comma-separated list).
2. `FRONTEND_URL` — must point at the domain that hosts the `/payment/callback` page. The backend uses it for its 3-DS safety-net redirect (`GET /payments/callback` → 302 to `{FRONTEND_URL}/payment/callback?…`). Only one URL is supported, so whichever frontend is "live" owns it.

Nothing else changes server-side.

---

## 1. Payment flow overview

There are **three ways a charge happens** — the page decides which UI to show based on the `initiate` response:

```
POST /payments/initiate  ──►  info { test_mode, publishable_key, amount_halalas, … }
        │
        ├─ test_mode = true   →  show "simulate" button → POST /payments/pay { payment_id }
        │                        (staging only — production never returns a fake charge)
        │
        └─ test_mode = false  →  LIVE:
              ├─ (A) Moyasar HOSTED FORM (card + Apple Pay)
              │       moyasar.js renders the form → user pays → Moyasar 3-DS redirect
              │       → /payment/callback?pid=…&id=…&status=…  →  POST /payments/verify
              │
              └─ (B) QUICK PAY with a saved card
                      POST /payments/pay { payment_id, saved_card_id, cvc }
                      → status "paid"  → done
                      → transaction_url present → redirect user to it (3-DS)
                        → lands on /payment/callback → POST /payments/verify
```

**Golden rule: the frontend never decides a payment succeeded on its own.** After any redirect from Moyasar, the callback page **must** call `POST /payments/verify`; the backend re-fetches the payment from Moyasar, checks status + amount, and only then confirms the booking. Query-string `status=paid` from Moyasar is a hint, not a fact.

---

## 2. Endpoints

### 2.1 `POST /payments/initiate` — step 1, always first

Creates (or re-fetches — it's idempotent per booking) the pending payment and returns everything the page needs.

**Request**

```json
{ "booking_id": 123 }
```

`payment_method` is optional (`mada|visa|mastercard|apple_pay|card`) — testvue doesn't send it.

**Constraints:** the booking must belong to the authenticated user and have status `pending`, otherwise **404**. A **503** means the gateway isn't configured (production misconfig — show "try later").

**Response `data`**

```json
{
  "payment_id": 55,
  "booking_id": 123,
  "amount": 1250.0,
  "amount_halalas": 125000,
  "currency": "SAR",
  "description": "حجز وحدة #123 - شاليه الواحة",
  "publishable_key": "pk_live_…",
  "callback_url": "https://…/payment/callback",
  "test_mode": false,
  "booking": {
    "start_date": "2026-07-10",
    "end_date": "2026-07-12",
    "nights": 2,
    "guests": 4,
    "nightly_rate": 500.0,
    "subtotal": 1000.0,
    "service_fee": 150.0,
    "cleaning_fee": 100.0,
    "taxes": 0.0,
    "unit": { "name": "شاليه الواحة", "city": "الرياض", "district": "النرجس", "image_url": "https://…" }
  }
}
```

Notes:

- `booking` powers the order-summary sidebar (unit card, trip dates, itemized price lines, total). The fee lines are **frozen on the booking** — render them as-is, never recompute.
- `test_mode: true` also when the key is a Moyasar **test** key (`pk_test…`). testvue additionally shows the test-card hint (`4111 1111 1111 1111`) only when `publishable_key` starts with `pk_test`.
- Ignore the returned `callback_url` for the hosted form — build your own from `window.location.origin` (see §3) so the user returns to *your* domain.

### 2.2 `POST /payments/pay` — step 2 (simulate / quick pay / manual token)

**Request** — one of these shapes:

```json
// test mode (staging): no token at all
{ "payment_id": 55 }

// quick pay with a saved card
{ "payment_id": 55, "saved_card_id": 7, "cvc": "123" }

// manual moyasar.js token (not used by testvue's main flow — hosted form charges directly)
{ "payment_id": 55, "token": "token_…" }
```

**Response `data`**

```json
{
  "status": "paid",            // "paid" | "initiated" | "failed"
  "payment_id": 55,
  "transaction_url": null,      // present when 3-DS is required
  "message": null               // gateway decline reason, if any
}
```

Handle it exactly like testvue:

```
status === "paid"        → show success screen (booking is already confirmed server-side)
transaction_url present  → window.location.href = transaction_url   (3-DS challenge;
                           Moyasar then redirects to /payment/callback?pid=…)
otherwise                → show `message` as the error, let user retry
```

A `422` with message `البطاقة المحفوظة غير صالحة للدفع` means the saved card can't be charged — fall back to the hosted form.

### 2.3 `POST /payments/verify` — after every Moyasar redirect

**Request**

```json
{ "payment_id": 55, "moyasar_id": "8bd06237-…" }
```

`payment_id` = our `pid` query param (integer). `moyasar_id` = Moyasar's `id` query param (string/UUID).

**Response `data`**

```json
{ "status": "paid", "payment_id": 55, "booking_id": 123, "message": null }
```

- `status === "paid"` → success. Anything else (`failed`, `pending`, …) → show failure with `message`, offer "retry" linking back to the payment page for `booking_id`.
- Verify is **idempotent** — calling it twice is safe.
- Side effect: if the user ticked "save card" in the hosted form, the backend persists the tokenised card here automatically. Nothing for the frontend to do.

### 2.4 `GET /payments/{id}` — optional status check

Returns the payment with its booking + unit. Only the owner can read it (`403` otherwise).

### 2.5 Saved cards (for quick pay)

```
GET    /user/cards               → [{ id, brand, last4, is_default, chargeable }]
DELETE /user/cards/{id}
POST   /user/cards/{id}/default
```

Only offer quick pay for cards with `chargeable: true` (those have a gateway token). Saved-cards failing to load must **never** block the hosted form — it's a bonus, wrap it in try/catch.

---

## 3. The Moyasar hosted form in Next.js

The card form is **not ours** — `moyasar.js` renders a PCI-compliant form inside a `div.mysr-form`, charges Moyasar directly (card data never touches our API), then redirects the browser to `callback_url` with `?id=<moyasar_id>&status=…&message=…` appended. We append our own `pid` to the callback URL so the callback page knows which of *our* payments to verify.

### 3.1 Loading the script (client-side only)

`moyasar.js` touches `window`/`document`, so everything below must live in a `"use client"` component and run in `useEffect` — **never** during SSR.

```tsx
const MOYASAR_VERSION = '1.14.0';

function loadMoyasarAssets(): Promise<void> {
  return new Promise((resolve, reject) => {
    if ((window as any).Moyasar) return resolve();

    if (!document.getElementById('moyasar-css')) {
      const link = document.createElement('link');
      link.id = 'moyasar-css';
      link.rel = 'stylesheet';
      link.href = `https://cdn.moyasar.com/mpf/${MOYASAR_VERSION}/moyasar.css`;
      document.head.appendChild(link);
    }

    const script = document.createElement('script');
    script.src = `https://cdn.moyasar.com/mpf/${MOYASAR_VERSION}/moyasar.js`;
    script.onload = () => resolve();
    script.onerror = () => reject(new Error('فشل تحميل بوابة الدفع'));
    document.body.appendChild(script);
  });
}
```

(Using `next/script` with `strategy="afterInteractive"` is fine too — just make sure `Moyasar.init` runs only after both the script has loaded **and** the `.mysr-form` div is mounted.)

### 3.2 Initialising the form — exact config testvue uses

```tsx
function initMoyasarForm(info: InitiateResponse) {
  // Moyasar redirects here after payment; pid ties it back to OUR payment row.
  const callbackUrl = `${window.location.origin}/payment/callback?pid=${info.payment_id}`;

  (window as any).Moyasar.init({
    element: '.mysr-form',
    amount: info.amount_halalas,          // halalas from the API — never recompute
    currency: info.currency,              // "SAR"
    description: info.description,
    publishable_api_key: info.publishable_key,
    callback_url: callbackUrl,
    save_card: true,                      // shows "save card" checkbox; backend
                                          // persists the token during verify
    methods: ['creditcard', 'applepay'],
    apple_pay: {
      country: 'SA',
      label: 'Mamsa',
      validate_merchant_url: 'https://api.moyasar.com/v1/applepay/initiate',
    },
    metadata: {
      payment_id: info.payment_id,
      booking_id: info.booking_id,
    },
  });
}
```

### 3.3 Payment page — full skeleton (`app/payment/[bookingId]/page.tsx`)

```tsx
'use client';

import { useEffect, useRef, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { api } from '@/lib/api'; // axios/fetch wrapper with Bearer token

export default function PaymentPage() {
  const { bookingId } = useParams<{ bookingId: string }>();
  const router = useRouter();

  const [info, setInfo] = useState<InitiateResponse | null>(null);
  const [savedCards, setSavedCards] = useState<SavedCard[]>([]);
  const [selectedCardId, setSelectedCardId] = useState<number | null>(null);
  const [cvc, setCvc] = useState('');
  const [paying, setPaying] = useState(false);
  const [paid, setPaid] = useState(false);
  const [errorMsg, setErrorMsg] = useState('');
  const [loading, setLoading] = useState(true);
  const mounted = useRef(false);

  useEffect(() => {
    if (mounted.current) return; // guard against React 18 strict-mode double-run
    mounted.current = true;

    (async () => {
      let data: InitiateResponse;
      try {
        const res = await api.post('/payments/initiate', { booking_id: Number(bookingId) });
        data = res.data.data;
        setInfo(data);
      } catch {
        setInfo(null);
        setLoading(false);
        return;
      }
      setLoading(false);

      if (!data.test_mode) {
        // Quick pay is best-effort — never block the form on it.
        api.get('/user/cards')
          .then((res) => {
            const cards = (res.data.data ?? res.data).filter((c: SavedCard) => c.chargeable);
            setSavedCards(cards);
            setSelectedCardId(cards.find((c: SavedCard) => c.is_default)?.id ?? null);
          })
          .catch(() => setSavedCards([]));

        try {
          await loadMoyasarAssets();
          setTimeout(() => initMoyasarForm(data), 0); // wait a tick for .mysr-form in DOM
        } catch (e: any) {
          setErrorMsg(e.message || 'تعذّر تحميل بوابة الدفع');
        }
      }
    })();
  }, [bookingId]);

  // Quick pay with a saved (tokenised) card
  async function payWithSavedCard() {
    if (!info) return;
    setErrorMsg('');
    setPaying(true);
    try {
      const res = await api.post('/payments/pay', {
        payment_id: info.payment_id,
        saved_card_id: selectedCardId,
        cvc,
      });
      const result = res.data.data ?? res.data;
      if (result.status === 'paid') {
        setPaid(true);
      } else if (result.transaction_url) {
        window.location.href = result.transaction_url; // 3-DS challenge
      } else {
        setErrorMsg(result.message || 'تعذّر إتمام الدفع');
        setPaying(false);
      }
    } catch (e: any) {
      setErrorMsg(e.response?.data?.message || 'تعذّر إتمام الدفع');
      setPaying(false);
    }
  }

  // Test-mode simulate (staging only — info.test_mode === true)
  async function simulatePay() {
    if (!info) return;
    setErrorMsg('');
    setPaying(true);
    try {
      const res = await api.post('/payments/pay', { payment_id: info.payment_id });
      if ((res.data.data ?? res.data).status === 'paid') setPaid(true);
      else setErrorMsg('تعذّر إتمام الدفع');
    } catch (e: any) {
      setErrorMsg(e.response?.data?.message || 'تعذّر إتمام الدفع');
    } finally {
      setPaying(false);
    }
  }

  // Render (RTL, dir="rtl"):
  //   loading   → spinner
  //   paid      → success screen + link to "my bookings"
  //   info      → test_mode ? simulate button
  //             : [saved-cards quick-pay block if savedCards.length]
  //               + <div className="mysr-form" />           ← Moyasar renders here
  //               + order summary sidebar from info.booking
  //   !info     → "تعذّر تحميل بيانات الدفع" + retry/home link
  // …JSX omitted — copy layout/labels from the current design.
}
```

**Important:** the `.mysr-form` div must exist in the DOM when `Moyasar.init` runs, and must not be unmounted/remounted afterwards (don't conditionally re-render it).

### 3.4 Callback page — `app/payment/callback/page.tsx`

Moyasar (or the backend safety-net redirect) lands the user on:

```
/payment/callback?pid=55&id=8bd06237-…&status=paid&message=…
```

```tsx
'use client';

import { useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'next/navigation';
import { api } from '@/lib/api';

export default function PaymentCallbackPage() {
  const params = useSearchParams();
  const [state, setState] = useState<'verifying' | 'paid' | 'failed'>('verifying');
  const [message, setMessage] = useState('');
  const [bookingId, setBookingId] = useState<number | null>(null);
  const ran = useRef(false);

  useEffect(() => {
    if (ran.current) return;
    ran.current = true;

    const moyasarId = params.get('id');   // Moyasar payment id
    const pid = params.get('pid');        // OUR payment id
    const moyasarStatus = params.get('status');

    if (!moyasarId || !pid) {
      setState('failed');
      setMessage('بيانات الدفع غير مكتملة');
      return;
    }

    // Surface an obvious failure immediately, but STILL verify server-side.
    if (moyasarStatus && moyasarStatus !== 'paid') {
      setState('failed');
      setMessage(params.get('message') || '');
    }

    (async () => {
      try {
        // Never trust the query string — the backend re-fetches from Moyasar.
        const res = await api.post('/payments/verify', {
          payment_id: Number(pid),
          moyasar_id: String(moyasarId),
        });
        const result = res.data.data ?? res.data;
        setBookingId(result.booking_id ?? null);
        if (result.status === 'paid') {
          setState('paid');
        } else {
          setState('failed');
          setMessage(result.message || '');
        }
      } catch (e: any) {
        setState('failed');
        setMessage(e.response?.data?.message || '');
      }
    })();
  }, [params]);

  // Render: verifying → spinner "جارٍ التحقق من الدفع..."
  //         paid      → success "تم تأكيد حجزك!" + link to my bookings
  //         failed    → error + `message` + "إعادة المحاولة" → /payment/{bookingId}
}
```

Wrap this page in `<Suspense>` (Next.js requires it for `useSearchParams`), and note the user may arrive **unauthenticated-looking** (fresh tab after 3-DS) — the Bearer token must survive the redirect (localStorage does; in-memory state does not).

**The route path must be exactly `/payment/callback`** — the backend's safety-net redirect (`{FRONTEND_URL}/payment/callback`) and the `pid` convention depend on it.

---

## 4. Apple Pay

Nothing extra to build. The hosted form's `methods: ['creditcard','applepay']` + the `apple_pay` block makes the Apple Pay button appear automatically on Safari/Apple devices over HTTPS. Requirements (already true for testvue; must be repeated for the Next.js domain):

1. The domain must be **verified in the Moyasar dashboard** (Apple Pay section).
2. The domain-association file must be served at `https://<domain>/.well-known/apple-developer-merchantid-domain-association` **without redirects** — commit it to `public/.well-known/` in Next.js.
3. Moyasar handles merchant validation internally for the hosted form (`validate_merchant_url` points at Moyasar, not our API). The backend endpoint `POST /payments/apple-pay/validate-merchant` exists only for a custom (non-hosted) Apple Pay button — you don't need it.

---

## 5. Test cards (Moyasar test keys only)

When `publishable_key` starts with `pk_test`:

| Card | Result |
|---|---|
| `4111 1111 1111 1111` | Success (any future expiry, any CVC) |
| `4000 0000 0000 0002` | Declined |

Never show test-card hints when the key is `pk_live…`.

---

## 6. Acceptance checklist

Code-level (the AI assistant must verify each of these in the final code):

- [ ] `POST /payments/initiate` is called with `{ booking_id: <number> }` and the page renders from `response.data.data`
- [ ] `test_mode: true` → simulate button calling `POST /payments/pay { payment_id }`; `test_mode: false` → Moyasar hosted form (+ saved-cards quick pay when available)
- [ ] Hosted form `callback_url` built from `window.location.origin` + `/payment/callback?pid=<payment_id>`
- [ ] `amount` passed to `Moyasar.init` is `amount_halalas` from the API (never computed client-side)
- [ ] `/payment/callback` route exists at exactly that path, reads `pid` + `id` from the query string, and calls `POST /payments/verify { payment_id: Number(pid), moyasar_id: String(id) }`
- [ ] Success UI shown **only** after `pay` or `verify` returns `status: "paid"`; `transaction_url` in a `pay` response triggers a full-page redirect
- [ ] Saved-cards fetch failure does not block the hosted form; only `chargeable: true` cards are offered
- [ ] `.mysr-form` div is mounted before `Moyasar.init` runs and is never conditionally re-rendered
- [ ] Auth Bearer token persists across the 3-DS redirect (localStorage/cookie, not in-memory state)
- [ ] Order summary sidebar renders the `booking` block from initiate (unit, dates, guests, fee lines, total) as-is

Environment-level (needs the backend/DevOps team — flag these to the user, don't attempt them in code):

- [ ] Backend: Next.js domain added to `CORS_ALLOWED_ORIGINS`
- [ ] Backend: `FRONTEND_URL` points at the domain hosting `/payment/callback` (only if Next.js becomes the live frontend)
- [ ] `/payment/callback` route exists at exactly that path and calls `POST /payments/verify`
- [ ] Hosted form `callback_url` built from `window.location.origin` + `?pid=<payment_id>`
- [ ] `amount` passed to Moyasar is `amount_halalas` from the API (never computed client-side)
- [ ] Success UI shown **only** after `pay` or `verify` returns `status: "paid"`
- [ ] Saved-cards fetch failure does not block the hosted form
- [ ] Apple Pay domain verified in Moyasar dashboard + `.well-known` file served for the new domain
- [ ] Auth token persists across the 3-DS redirect (localStorage, not memory)
