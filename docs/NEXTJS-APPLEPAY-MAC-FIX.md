# Task brief — Apple Pay button missing on macOS (Next.js www checkout)

**For:** the AI agent working in the **Next.js `mamsaa.com`** repo · **Date:** 2026-07-21
**Type:** bug fix (frontend only) · **Priority:** medium-high (blocks a payment method on desktop)

You are fixing a payment-UI bug in the Next.js user site. Read this whole brief before changing code. The backend is **not** involved — do not touch or blame it.

---

## 1. Symptom

On the checkout page, paying with **Moyasar**:
- **iPhone (Safari):** the Apple Pay button appears and works. ✅
- **Mac:** the Apple Pay button does **not** appear. ❌

Only the card form shows on the Mac.

## 2. What is already ruled out (do not re-investigate)

- **Backend:** the payment API only creates the Moyasar payment and returns `publishable_key`, `amount_halalas`, `currency`, `callback_url`. These are **identical** for every device — nothing server-side can change Apple Pay visibility. Confirmed.
- **Moyasar domain verification:** it works on iPhone on this same domain, so the Apple Pay domain is registered in the Moyasar dashboard. An unverified domain would let the button **show and then fail on tap** (merchant validation) — not hide it. The symptom is "hidden," so domain is not the cause.
- **The Vue reference app (testvue):** its integration is correct and shows Apple Pay on capable Macs. Use it as the spec (config in §5).

## 3. Background: how Apple Pay visibility actually works on the web

The Apple Pay button should be gated **only** by Apple's own capability check, which Moyasar.js runs internally:

```
window.ApplePaySession && ApplePaySession.canMakePayments()
```

This returns **true** on iPhone with a Wallet card, and on the web it is **true only in Safari** — and on a Mac only when the Mac can authorize (Touch ID + Wallet card, or signed into iCloud with a paired iPhone/Apple Watch nearby). So even a perfect integration will legitimately hide Apple Pay on a Mac that is in Chrome/Firefox, or has no Touch ID and no paired device.

**Therefore, before assuming a code bug, confirm the test conditions** (see §6). If the button is also missing from the Vue reference app on the same Mac+Safari, the Mac simply isn't Apple Pay-capable and there is nothing to fix.

## 4. The likely code cause (this is what to hunt for)

A "works on iPhone, hidden on Mac" symptom, when the Mac *is* capable, is almost always the frontend **gating Apple Pay behind device / user-agent detection** instead of letting Moyasar's `canMakePayments()` decide.

Search the repo for any of these patterns and remove the Apple-Pay-related gating:

```
grep -rniE "isMobile|isIOS|isIPhone|userAgent|navigator\.platform|touch|mobile" src | grep -i "apple\|pay\|method"
grep -rniE "applepay|apple_pay|methods\s*[:=]" src
```

Look specifically for:
1. `applepay` being **conditionally** added to Moyasar's `methods` array (e.g. only when `isMobile`/`isIOS`). It must be **unconditional**.
2. A **custom** Apple Pay button component that only mounts on touch/mobile devices.
3. Moyasar's Apple Pay assets/config being loaded only on mobile.
4. Any `if (isIOS)` / user-agent branch around the payment method list or Apple Pay init.

## 5. The fix — match the reference config, no device conditionals

Initialize Moyasar with both methods **unconditionally**. Let Moyasar/Apple decide visibility:

```js
window.Moyasar.init({
  element: '.mysr-form',
  amount:  info.amount_halalas,      // from backend
  currency: info.currency,           // from backend
  description: info.description,
  publishable_api_key: info.publishable_key,   // from backend
  callback_url: `${window.location.origin}/payment/callback?pid=${info.payment_id}`,
  save_card: true,
  methods: ['creditcard', 'applepay'],   // ← both, ALWAYS. No isMobile / isIOS check.
  apple_pay: {
    country: 'SA',
    label: 'Mamsa',
    validate_merchant_url: 'https://api.moyasar.com/v1/applepay/initiate',
  },
  metadata: { payment_id: info.payment_id, booking_id: info.booking_id },
})
```

Rules:
- **Never** add/remove `applepay` from `methods` based on user-agent, `isMobile`, `isIOS`, screen size, or touch support. Moyasar hides it automatically where unsupported.
- Ensure the Moyasar CSS + JS (and thus its Apple Pay handling) load on **all** devices, not only mobile.
- Do not add your own `ApplePaySession` gating on top — the hosted form already does it.

## 6. Verify (acceptance criteria)

Test on a **Touch-ID Mac in Safari, signed into iCloud, with a Wallet card** (a genuinely Apple-Pay-capable Mac):

- [ ] Apple Pay button **renders** in the Moyasar form on that Mac.
- [ ] Tapping it opens the Apple Pay sheet and a test payment completes (Moyasar test keys on staging).
- [ ] iPhone still works (no regression).
- [ ] Card form still works on all browsers (Chrome/Firefox on Mac show the card form, correctly no Apple Pay — that is expected, not a bug).
- [ ] No `isMobile`/`isIOS`/user-agent conditional remains around the payment methods or Apple Pay.

**Isolation test if unsure:** open the same checkout on the Vue reference (testvue) and on mamsaa.com in the *same* Mac Safari. If Apple Pay shows on testvue but not mamsaa.com → it's this frontend (apply the fix). If it shows on neither → that Mac isn't Apple-Pay-capable; there is no bug to fix.

## 7. Out of scope

- Backend / API changes — none needed.
- Moyasar dashboard / domain association — already done (works on iPhone).
- The Vue app — reference only, don't modify.
