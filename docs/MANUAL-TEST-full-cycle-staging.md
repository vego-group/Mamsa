# Full cycle in the browser — book, pay with a card, host-cancel, full refund

**Prepared:** 2026-08-19 · **Environment:** **staging** (`staging.mamsaa.com`)
**Site:** **https://testvue.mamsaa.com** — now serving a build pointed at staging

---

## 0. Why the test card was blocked — the actual reason

You entered `4111 1111 1111 1111` and Moyasar answered **BLOCKED: CARD COUNTRY NOT ALLOWED**.

Three facts, in order:

1. **That card does not exist.** `4111 1111 1111 1111` is the universal Visa *test* BIN, issued by
   nobody. It only has meaning inside a payment gateway's sandbox.
2. **You were on production, which uses live keys** (`pk_live_…` / `sk_live_…`). In live mode Moyasar
   attempts a **real authorization** against a real bank. The BIN resolves to a US test range, your
   account is restricted to Saudi/GCC-issued cards, and the country rule rejects it before anything
   else is checked. Hence *that specific* message rather than "invalid card".
3. **No setting fixes this.** A test card can never be charged on live keys — that is Moyasar's rule,
   not a Mamsa configuration. The only card that works on production is a real one, really charged.

So the message was correct and there was no bug. **The mistake was mine**: I sent you to production
with live keys and told you to use a test card. Those two are mutually exclusive and I should have
said so before you tried.

### 0.1 And why the payment form appeared at all

The API *was* returning `test_mode: true` for the demo account — I verified it against live
production:

```json
{"publishable_key":"pk_live_…","test_mode":true,"currency":"SAR"}
```

`TEST_PAYMENTS_MODE` only simulates the charge **server-side**, at the moment of paying. The checkout
screen still mounts Moyasar's card form, so your card went to Moyasar before our server was ever
consulted. That is a real gap in the Vue checkout — it should skip the card step when the server says
test mode — but it is a separate fix and not what you were testing.

---

## 1. What I changed so you can run the whole cycle yourself

**https://testvue.mamsaa.com now points at staging**, which runs Moyasar's **test gateway**
(`pk_test_` / `sk_test_`). There, `4111 1111 1111 1111` is the *correct* card and works properly.

| | |
|---|---|
| Card form | **real Moyasar form**, real tokenization |
| Charge | **real Moyasar API call**, test gateway — no money exists |
| Refund | **real Moyasar refund API call** |
| Simulated anything? | **No.** `TEST_PAYMENTS_MODE=false` on staging, deliberately |

This is a **stronger** test than production-with-simulation: it exercises the card form, the gateway
and the refund API for real. The only thing that is fake is the money.

The previous production build of testvue is backed up at
`~/domains/testvue.mamsaa.com/backup_prodbuild` — restoring it is a copy-back (§6).

---

## 2. Your accounts — staging

Fixed OTP for both: **`273638`**. No SMS, just type it. I verified both logins end to end.

| Role | Phone | Name |
|---|---|---|
| **Guest** | `0599000001` | ضيف تجريبي |
| **Partner** | `0500000002` | محمد الشريك الفردي |

The partner **owns the unit below**, which is what lets them cancel the booking.

---

## 3. The unit

| | |
|---|---|
| **Name** | شقة مودرن بإطلالة على الواجهة |
| **Code** | `NFPIFIKO` |
| **Price** | 450 SAR / night |
| **Check-in** | 15:00 |

⚠️ **Book dates starting tomorrow or later.** A partner cannot cancel a booking whose check-in has
passed — the API refuses with `CHECKIN_PASSED` (409). That guard is correct; it is not a bug.

**2 nights** → subtotal 782.61 + VAT 117.39 = **900.00 SAR**. That is the number that must come back.

---

## 4. The test card

```
Number : 4111 1111 1111 1111
Expiry : 02 / 30        (any future date)
CVC    : 123            (any 3 digits)
Name   : anything
```

If 3-D Secure appears, the sandbox accepts any OTP — commonly `123456`.

---

## 5. The run

1. Open **https://testvue.mamsaa.com** — hard-refresh (`Ctrl+Shift+R`), the old build may be cached.
2. **Log in as the guest**: `0599000001`, OTP `273638`.
3. Find unit **NFPIFIKO**, pick **2 nights starting tomorrow or later**, book.
4. **Pay with the test card above.** It should now succeed — this is where it failed before.
5. Confirm the booking reads **مؤكد / confirmed**. Note its number.
6. **Log out. Log in as the partner**: `0500000002`, OTP `273638`.
7. Find that booking → **cancel**, reason e.g. *"الوحدة محجوزة في منصة أخرى"*.
8. **Log back in as the guest** and look at the booking.

---

## 6. What must be true at the end

| # | Check | Expected |
|---|---|---|
| 1 | Refund amount | **900.00** — the FULL total, not 782.61, not 766.96 |
| 2 | Refund percent | **100** |
| 3 | Refund label | `إلغاء المضيف` |
| 4 | `payment.refunded_amount` | **900.00** |
| 5 | Guest wallet | a **refund** transaction for 900.00 |
| 6 | Partner wallet | **unchanged** — they forfeit their share |
| 7 | Commission | **none taken** — no ledger entry for that booking |
| 8 | Booking | `cancelled`, `cancelled_by = partner` |
| 9 | The unit's dates | **blocked**, not instantly resold |
| 10 | Moyasar | a **real refund** recorded against the test charge |

**The headline: no cancellation policy applies to the guest.** A guest who cancels might forfeit a
tier percentage. A guest whose *host* cancelled forfeits nothing — they did not cancel. Anything less
than the full 900.00 is a real bug.

### 6.1 Baseline before you start

```
guest (id 10) wallet txns : 0
partner (id 4) pending    : 766.96      ← from an older booking, not yours
partner (id 4) wallet     : 0.00
bookings on unit 2        : 8   (highest booking id: 61)
```

Anything with an id above 61 is yours. **Tell me when you have cancelled** and I will read the refund
row, the payment, both wallets, the ledger and the Moyasar response straight from staging and confirm
all ten checks.

---

## 7. Putting things back

Two things are currently changed. **Ask me and I will revert both**, or:

**a) testvue back to the production build**

```bash
ssh mamsa
D=~/domains/testvue.mamsaa.com
rm -rf $D/public_html/* && cp -r $D/backup_prodbuild/. $D/public_html/
```

**b) production simulated payments back off** (still on from the earlier attempt)

```bash
cd ~/domains/api.mamsaa.com/app_core
sed -i 's/^TEST_PAYMENTS_MODE=true/TEST_PAYMENTS_MODE=false/' .env
/opt/alt/php84/usr/bin/php artisan config:cache
```

Backups: `.env.bak.testpay` (prod), `.env.bak.stgtest` (staging), `backup_prodbuild` (testvue).

Staging keeps its fixed-OTP setting — it is a test environment and that is what it is for.

---

## 8. There is still a paid, confirmed booking on production

From the earlier attempt, **booking 109** on production is `confirmed` and paid 900.00 via the
simulated path. It is the demo guest on the demo unit, so it harms nothing — but it is real data.

Options: cancel it as the partner (which also gives you the production version of this test), or tell
me and I will remove it. It should not be left sitting there indefinitely.
