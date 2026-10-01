# Runbook — Fix production SMS / OTP delivery (FGC · Ethabah)

**Audience:** a Claude Code agent (or engineer) with SSH to the production API host.
**Symptom:** login OTPs are not delivered on production → users, partners, and admins
cannot receive a code, so they cannot log in or book on any surface wired to
`api.mamsaa.com`.
**Diagnosed:** 2026-08-04 from live prod config + `storage/logs`.

> ⚠️ There are **TWO** stacked failures, and the one everyone remembers ("IP whitelist")
> is not even the current blocker. Fix them **in order** — creds first, then IP.

---

## 0. TL;DR

| # | Failure | Where | What FGC returns | Fix |
|---|---------|-------|------------------|-----|
| 1 | **Invalid credentials** (current top blocker) | `/authenticate` | `200 "Invalid Credentials"` | Put valid `FGC_SMS_USERNAME` / `FGC_SMS_PASSWORD` in prod `.env`, re-cache config |
| 2 | **IP not whitelisted** | `/sendSmsNotifications` | `400 {"E028":"User IP not allowed"}` | Have FGC whitelist the prod egress IP **`217.196.54.81`** on the **send** endpoint |

Both must be green before any OTP is delivered. Auth throws before send even runs, so
right now you never reach the E028 — but you will the moment creds are fixed.

---

## 1. How OTP sending works (for reference)

- Driver selected by `SMS_DRIVER` → prod is **`fgc`** (`config/sms.php`, provider
  `app/Services/Sms/FgcSmsProvider.php`).
- Per send: **authenticate** (`POST https://cnc.fgc.sa/authenticate` with
  `{username,password}`, token lives ~1 min) → **send**
  (`POST https://cnc.fgc.sa/sendSmsNotifications`). Success = response body has key
  **`E001`**; anything else is an error code (e.g. `E028`).
- Prod config confirmed 2026-08-04: `driver=fgc`, `sender_id=Mamsa`,
  `fgc.username=SET`, `fgc.password=SET`, `fgc.sender_name=Mamsa`.
- OTP codes are stored in cache (`config('otp.store')`, **database** on prod) under key
  `otp:{purpose}:{phone}` — used for the interim workaround in §5.

---

## 2. Evidence from the logs (`storage/logs/*.log`)

```
[2026-07-05 …] FGC SMS send HTTP error {"to":"+9665…","status":400,"body":"{\"E028\":\"User IP not allowed\"}"}
[2026-07-12 …] FGC SMS authentication failed {"status":200,"body":"Invalid Credentials"}
[2026-07-12 …] FGC SMS: authentication failed (RuntimeException @ FgcSmsProvider.php:81)
```

- **Jul 5:** auth *succeeded*, **send** was rejected → `E028` (IP not whitelisted).
- **Jul 12 → now:** **auth** itself fails with `Invalid Credentials`. Since
  `authenticate()` throws, `send()` is never reached — so creds are the current blocker
  and the whitelist problem is hidden behind it.

**IPv6 red herring — ruled out.** The host is dual-stack (IPv6 egress
`2a02:4780:b:…` exists), but `cnc.fgc.sa` is **IPv4-only** (`81.208.166.151`, no AAAA),
so every FGC call goes out over **IPv4 `217.196.54.81`**. That single IPv4 is the only
address FGC ever sees, and the only one that needs whitelisting.

---

## 3. Fix #1 — Credentials (do this first)

1. Get **valid** FGC/Ethabah API credentials (the account owner logs into the FGC/Ethabah
   portal, or asks the FGC account manager). The current username/password in prod
   `.env` are rejected as `Invalid Credentials` — they were rotated, mistyped, or the
   account was reset.
2. On the prod host, edit the API `.env`:
   ```bash
   ssh mamsa
   cd ~/domains/api.mamsaa.com/app_core
   # edit these two keys (keep them secret; do not paste into logs/PRs/chat):
   #   FGC_SMS_USERNAME=...
   #   FGC_SMS_PASSWORD=...
   nano .env
   ```
   Leave `SMS_DRIVER=fgc` and `FGC_SMS_SENDER=Mamsa` as-is (sender header is already
   correct). Note `SMS_SENDER_ID`/`FGC_SMS_SENDER` must be a **sender name registered
   with FGC** for account "Mamsa" — confirm with FGC if messages are rejected on sender.
3. Rebuild the config cache (prod caches config — a bare `.env` edit is not picked up):
   ```bash
   /opt/alt/php84/usr/bin/php artisan config:clear
   /opt/alt/php84/usr/bin/php artisan config:cache
   ```
4. **Verify auth** without sending an SMS (creds are entered inline, not printed):
   ```bash
   cd ~/domains/api.mamsaa.com/app_core
   /opt/alt/php84/usr/bin/php artisan tinker --execute='
     $u=config("sms.fgc.username"); $p=config("sms.fgc.password");
     $r=\Illuminate\Support\Facades\Http::post("https://cnc.fgc.sa/authenticate",["username"=>$u,"password"=>$p]);
     echo "status=".$r->status()." token=".($r->json("token") ? "OK" : "MISSING")." body=".substr($r->body(),0,60).PHP_EOL;
   '
   ```
   **PASS:** `status=200 token=OK`. **FAIL:** body `Invalid Credentials` → creds still wrong.

---

## 4. Fix #2 — IP whitelist (needed for the send to succeed)

Once auth returns a token, the **send** endpoint will still reject with
`E028 "User IP not allowed"` until FGC whitelists the server's outbound IP.

1. Give FGC/Ethabah the prod egress IP to whitelist for the **send** endpoint:
   **`217.196.54.81`** (IPv4). Confirmed live:
   ```bash
   ssh mamsa 'curl -s https://api.ipify.org; echo; curl -s https://checkip.amazonaws.com'
   # → 217.196.54.81
   ```
2. Caveat — **shared hosting (Hostinger)**: the egress IP *can* change. Ask Hostinger to
   confirm it is static / dedicated for this account; if it ever changes, FGC must
   re-whitelist. If FGC supports a CIDR range, request the range Hostinger provides.
3. After FGC confirms the whitelist is active, run the end-to-end test in §6.

---

## 5. Interim workaround (while SMS is down)

Do **not** block logins on the broken gateway. Two options:

**A. Read the generated OTP straight from prod (no SMS needed).** Trigger the OTP from
the app as normal, then:
```bash
ssh mamsa
cd ~/domains/api.mamsaa.com/app_core
/opt/alt/php84/usr/bin/php artisan tinker --execute='
  $phone="+966537486167";                 // the number logging in
  foreach (["admin-login","login","change-phone"] as $purpose) {
    $v=\Illuminate\Support\Facades\Cache::store(config("otp.store"))->get("otp:$purpose:$phone");
    if ($v) echo "$purpose => ".$v["code"]." (sent_at ".date("H:i:s",$v["sent_at"]).")".PHP_EOL;
  }
'
```
Purpose is `admin-login` for the admin BFF (admin.mamsaa.com), `login` for the main
`/api/v1` app. Give that code to the person logging in. The value is
`['code','attempts','sent_at','ip']`; codes expire in `otp.exp_minutes` (5 min).

**B. Keep the consumer/partner frontends on staging** (fixed OTP `<fixed OTP — request privately>`) until SMS is
fixed — see the frontend switch runbook. Staging is the safe fallback precisely because
it does not depend on FGC.

---

## 6. End-to-end verification (after both fixes)

1. Send **one** real OTP to a number you control (the account owner's phone):
   ```bash
   curl -s -X POST https://api.mamsaa.com/api/v1/auth/request-otp \
     -H 'Content-Type: application/json' -H 'Origin: https://www.mamsaa.com' \
     -d '{"phone":"+9665XXXXXXXX"}'
   ```
2. Confirm the SMS arrives on the handset, **and** check the log shows success (key
   `E001`, no `E028`/auth error):
   ```bash
   ssh mamsa "cd ~/domains/api.mamsaa.com/app_core && tail -n 30 storage/logs/laravel.log | grep -iE 'FGC|E0[0-9][0-9]' || echo 'no FGC errors logged — good'"
   ```
   **PASS:** SMS received + no new `FGC SMS …error` lines. A silent send with no error
   line means the provider got `E001`.
3. Only after this passes should the **consumer** site (`mamsaa.com`) be flipped to
   production (see the frontend switch runbook) — otherwise real users hit a locked
   login.

---

## 7. Rollback / fallback

- If the new creds misbehave, revert the two `.env` keys, `config:cache` again.
- Emergency: set `SMS_DRIVER=log` (writes the OTP to `storage/logs` instead of sending)
  and use §5-A to hand out codes — logins keep working, nothing is texted. Re-enable
  `fgc` once fixed.

---

## 8. Definition of done

- [ ] Auth probe returns `status=200 token=OK` (§3.4).
- [ ] FGC has whitelisted `217.196.54.81` for the send endpoint (§4).
- [ ] A real request-otp delivers an SMS and logs no error (§6).
- [ ] Interim workaround documented/known so no one is locked out meanwhile (§5).
- [ ] Only then: consumer frontend switched to prod.
