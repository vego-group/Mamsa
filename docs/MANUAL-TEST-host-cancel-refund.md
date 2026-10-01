# Manual test — partner cancels a paid booking, guest is refunded in full

**Prepared:** 2026-08-19 · **Environment:** **production** (`api.mamsaa.com`)
**Status:** ⚠️ **`TEST_PAYMENTS_MODE` is currently ON.** Turn it off when you finish — §6.

---

## 0. ⚠️ Read first — what is switched on, and what is not

`TEST_PAYMENTS_MODE=true` is enabled on production **right now**. It makes payments **simulated
(no Moyasar charge)** — but **only for three allowlisted phone numbers**. Verified live just now:

```
allowlist:                  +966555000001, +966555000002, +966555000003
bypass for demo user:       true      ← simulated, no card charged
bypass for a REAL customer: false     ← still charged live on Moyasar
```

So a real customer booking during your test is charged normally. Nothing about their flow changes.

**What is still live and untouched:** the live Moyasar keys, every real user's payments, SMS to real
numbers, and every other production behaviour.

**Turn it back off when done — §6.** Leaving it on is not dangerous, but it is a switch that should
not be on by default.

---

## 1. Your accounts

Fixed OTP for all three: **`273638`** — no SMS is sent, just type it.

| Role | Phone | Name | Use it for |
|---|---|---|---|
| **Guest** | `+966555000001` (or `0555000001`) | مستخدم تجريبي Ahmed Reda | booking + paying |
| **Partner** | `+966555000002` | شريك تجريبي | cancelling the booking |
| **Admin** | `+966555000003` | مشرف تجريبي | checking the refund landed |

---

## 2. The unit to book

The demo partner owns exactly one approved, bookable unit — book **this** one, so the cancellation is
performed by an account you control:

| | |
|---|---|
| **Name** | شقة مودرن بإطلالة على الواجهة |
| **Code** | `E6CDM1UX` |
| **Unit id** | `1` |
| **City** | الرياض |
| **Price** | 450 SAR / night |
| **Check-in** | 15:00 |
| **Owner** | the demo partner (`+966555000002`) ✅ |

⚠️ **Pick dates starting tomorrow or later.** A partner cannot host-cancel a booking whose check-in
time has already passed — the API refuses with `CHECKIN_PASSED` (409). That guard is deliberate, not
a bug you have found.

**Suggested:** 2 nights → subtotal 900 + 15% VAT 135 = **1,035 SAR total**. That is the number that
must come back.

---

## 3. Where to click

| Step | Site |
|---|---|
| Book + pay as the guest | **https://testvue.mamsaa.com** (the internal test bench, points at the production API) |
| Cancel as the partner | **https://partner.mamsaa.com** |
| Verify as the admin | the admin panel, or ask me to read the database |

`www.mamsaa.com` is the live customer site and also works — testvue is the safer habit.

---

## 4. The run

1. **Guest** — log in on testvue with `0555000001`, OTP `273638`.
2. Open unit `E6CDM1UX`, pick 2 nights **starting tomorrow or later**, book it.
3. **Pay.** The payment is simulated — you will not be asked for a card and no money moves. The
   booking should land as **مؤكد / confirmed**.
4. **Note the booking number and the total** (should be 1,035 SAR).
5. **Partner** — log in on partner.mamsaa.com with `0555000002`, OTP `273638`.
6. Find that booking → **cancel it**, reason e.g. *"الوحدة محجوزة في منصة أخرى"*.
7. **Guest** — reload. The booking reads cancelled and the refund should show.

---

## 5. What must be true afterwards

| # | Check | Expected |
|---|---|---|
| 1 | Refund amount | **1,035.00** — the FULL total, not the base, not base+VAT |
| 2 | Refund percent | **100** |
| 3 | Refund label | `إلغاء المضيف` |
| 4 | `payment.refunded_amount` | **1,035.00** |
| 5 | Guest wallet | a **refund** transaction for 1,035.00 |
| 6 | Partner wallet | **unchanged (0.00)** — they forfeit their share |
| 7 | Mamsa commission | **none taken** — no ledger entry for that booking |
| 8 | Booking status | `cancelled`, `cancelled_by = partner` |
| 9 | The unit's dates | **blocked**, so it is not instantly resold |

**The headline: no cancellation policy applies to the guest.** A guest who cancels 2 days out might
forfeit a tier percentage. A guest whose *host* cancelled forfeits nothing, because they did not
cancel. If you see anything less than the full 1,035, that is a real bug.

### 5.1 Baseline before you start (so a change is unambiguous)

```
partner wallet balance : 0.00
guest wallet txns      : 0
bookings on unit 1     : 2  (bk 107 cancelled, bk 108 cancelled)
```

Any new booking is yours. **Ask me to verify** after the run and I will read the refund row, the
payment, both wallets and the ledger straight from production and tell you whether all nine checks
passed.

---

## 6. 🔴 Turning it back to live

**Ask me and I will do it**, or run it yourself:

```bash
ssh mamsa
cd ~/domains/api.mamsaa.com/app_core
sed -i 's/^TEST_PAYMENTS_MODE=true/TEST_PAYMENTS_MODE=false/' .env
/opt/alt/php84/usr/bin/php artisan config:cache
```

Verify it is off:

```bash
/opt/alt/php84/usr/bin/php artisan tinker --execute='echo var_export(config("test_mode.payments"), true);'
# must print: false
```

A backup of the original file is at `.env.bak.testpay` if anything needs restoring.

**Note `TEST_OTP_MODE` stays on** — that is the pre-existing demo-login setup and is separate from
payments. It should be closed before launch, but it is not part of this test.

---

## 7. What the automated tests already cover

This scenario is pinned by **10 tests** (`HostCancelRefundTest`), so the browser run is confirming the
UI wiring rather than the logic:

- the guest is refunded every riyal they paid
- no policy tier is applied to the guest
- a legacy booking **with fees** is refunded in full (fees included)
- the guest gets a wallet record
- the partner earns nothing and Mamsa takes no commission
- the freed dates are blocked
- a double-click does not refund twice
- **an existing partial refund is not paid out twice** ← a real bug this found and fixed
- another partner cannot cancel the booking (404)
- a stay whose check-in has passed cannot be cancelled (409)

That eighth one was a genuine defect: the refund was always the full `total_amount` regardless of what
had already been returned, so a payment with an earlier partial refund would have been paid back
beyond what the guest handed over. Now capped at what is still owed.

### 7.1 One thing this browser test does **not** prove

With simulated payments there is **no Moyasar call**, so the refund is recorded directly rather than
posted to the gateway. This run proves the **accounting** — amounts, wallets, ledger, statuses.

It does **not** prove a real Moyasar refund succeeds. For that we need a sandbox card payment on
staging (`staging.mamsaa.com` runs `sk_test_…` keys, so the gateway is real but the money is not).
Say the word and I will set that up separately.
