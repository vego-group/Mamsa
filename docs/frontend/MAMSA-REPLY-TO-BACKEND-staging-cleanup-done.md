# staging: (أ) اتعمل — `APP_DEBUG` اتقفل و١٦ حساب اتعملهم تعمية · البيانات ماجاتش من الإنتاج · ولقينا نسخة من الإنتاج على جهاز

**التاريخ:** 01/10/2026
**لمين:** فريق Next.js، وأحمد
**المرفقات: ٢** — **ملصوقين كاملين في آخر الملف ده**
1. `docs/ops/staging-exposure-debug-otp.md`: بعد التحديث، فيه الحالة وإجابة السؤالين
2. `docs/ops/known-risks-local-copies.md`: جديد، الخطر المعروف وخطة لو الجهاز ضاع

**مافيش ولا رمز ولا باسورد ولا رقم ولا إيميل حقيقي في الملف ده ولا في المرفقات.**

---

## ١. (أ) — ✅ **اتنفّذ دلوقتي، على staging بس** (قرار أحمد المكتوب)

### `APP_DEBUG=false` — ✅

**اتأكدنا بنفس الطلب قبل وبعد:** طلب واقع عليه rate limit، **وده بالظبط الطلب اللي كان بيطلّع الـstack trace**:

| | الرد |
|---|---|
| **قبل** | فيه `trace` و`file` و`exception` |
| **بعد** | رسالة نضيفة بس: `"Too Many Attempts."` |

وstaging شغّال: `/api/v1/config` و`/up` بيرجّعوا **200**. ومعمول نسخة احتياطية من `.env` قبل التعديل.

### تعمية الحسابات الحقيقية — ✅ **١٦ حساب**

**المعيار:** أي حساب رقمه **أو** إيميله مش من قيم الاختبار المعروفة (`@mamsa.test` و`@mamsaa.sa` المزروعين،
وأرقام الاختبار) **اتعامل كإنه حقيقي.** وماخدناش بالشبه: أي شك اتحسب حقيقي.

| الحقل | اللي اتغيّر |
|---|---|
| **الاسم** | **١٦**، بقى «حساب اختبار <id>» |
| **الإيميل** | **١٣**، بقى `user<id>@anon.mamsa.test` |
| **الرقم** | **٩**، بقى رقم اصطناعي (`+96659909xxxx`) |

**وكمان اتأكدنا من:**
- **الرقم والإيميل الأصليين الحقيقيين اللي فاضلين:** **٠**. ولا الرقمين الحقيقيين المعروفين فاضلين على staging.
- **الحجوزات (٨٦) والمدفوعات (٣٧): ماتلمستش**، زي ما طلبتوا.
- **أرقام UAT التسعة لسه موجودة:** **٤ حسابات** من الـ١٦ أرقامهم أرقام UAT، فاتغيّر الإيميل والاسم بس **والرقم
  ماتغيّرش**، عشان دخول UAT مايتكسرش.
- **نسخة احتياطية** من الـ١٦ صف الأصليين **على سيرفر staging بس** (`~/backups/`، chmod 600). **فيها البيانات
  الأصلية،** فـ**أحمد يقرر نمسحها ولا لأ.**

**ولقينا حاجة برّه نطاق الطلب ومالمسناهاش:** حسابين من حسابات اختبار UAT عندهم `national_id` شكله حقيقي في بيانات
الشريك. **مافيش IBAN ولا صور هويات ولا سجلات تجارية** في الـ١٦. **ماغيّرناهوش** لأن الطلب كان الرقم والإيميل
والاسم. **قولولنا لو عايزينه يتعمله تعمية هو كمان.**

---

## ٢. السؤالين

### إزاي وصلت الأرقام والإيميلات دي staging؟ — **حد سجّل بنفسه على staging، مش نسخة من الإنتاج**

| الدليل | |
|---|---|
| **تواريخ الإنشاء على staging** | ١٦–١٩ يوليو (وواحد ١٩ أغسطس)، **بعد** ما staging اتعمله seed من جديد يوم ١٥/٠٧. **ده تسجيل من الواجهة وقت الاختبار** |
| **الرقمين الحقيقيين** | على staging الـids **٢٣ و٢٤**، اتعملوا ١٦–١٧/٠٧. **على الإنتاج** الـids **١٩ و٢٠**، اتعملوا **١٤/٠٨، بعدها بشهر**، والإيميلات **مختلفة**. لو كانوا اتنسخوا، كان الـid والتاريخ هيبقوا زي بعض |
| **النسخة الوحيدة اللي اتعملت من الإنتاج** | بروفة ٢٧/٠٩، **ودي راحت container محلي على جهاز، مش staging.** (§٣) |

### هل أي حساب منهم موجود على الإنتاج؟ — **اتنين بس، بالرقم**

قارنّا بالـhash على السيرفرين (مافيش رقم ولا إيميل اتنقل بينهم):

| | |
|---|---|
| **الرقمين الحقيقيين** | **موجودين على الإنتاج** (حسابين مختلفين، ids ١٩ و٢٠). **ودول أصلاً حسابين حقيقيين على الإنتاج** ومحدش بيلمسهم |
| **الـ١٤ التانيين** | **مش موجودين على الإنتاج** |

---

## ٣. 🔴 لقيناها وإحنا بنجاوب: **نسخة كاملة من قاعدة بيانات الإنتاج على جهاز**

عشان نتأكد إن staging ماجاش من الإنتاج، تتبّعنا نسخة البروفة. **ولقيناها لسه موجودة على جهاز المطوّر:**

| | |
|---|---|
| `/root/rehearsal/prod-2026-09-27.sql` | 🔴 **dump كامل لقاعدة بيانات الإنتاج** (٢٧/٠٩)، **فيه مستخدمين الإنتاج الحقيقيين** |
| `/root/rehearsal/app_core/.env` | 🔴 `.env` نسخة الإنتاج، **غالباً فيه أسرار الإنتاج الحية** (مافتحناهوش) |
| container `mamsa_rehearsal_db` + الـvolume بتاعه | نفس البيانات، متوقف |

**وجنبهم، اللي ذكرتوه:** `backend/.env` و`backend/env.prod`. **وكمان:** مفتاح SSH للسيرفرين، وtoken بتاع GitHub.
**الملفات دي كلها أي مستخدم على الجهاز يقدر يقراها.** ومافيش حاجة منها متاحة من الإنترنت.

**ماحذفناش ولا حاجة،** لأن الحذف مش بيرجع. **اتكتب في `docs/ops/known-risks-local-copies.md`** (المرفق ٢):
- اللي موجود بالظبط.
- **خطة لو الجهاز ضاع، بالترتيب:** مفتاح SSH، بعدين GitHub، بعدين Moyasar، بعدين SMS وResend، بعدين قواعد البيانات،
  بعدين رمز الاختبار، و`APP_KEY` في الآخر. **ونسخة الإنتاج معناها إن بيانات مستخدمين خرجت من السيرفر**، وده ممكن
  يكون محتاج إخطار، والقرار عند أحمد.
- **رأينا:** نمسح نسخة البروفة كلها (الـdump، و`app_core` ومعاه الـ`.env`، والـcontainer، والـvolume)، و`chmod 600`
  لحد ما ده يحصل. **وقاعدة من هنا:** أي نسخة إنتاج لبروفة تتمسح لما البروفة تخلص، والتقرير يقول إمتى.

---

## ٤. (ب) — ⏳ **قبل UAT**

`debug_otp` يبقى ورا متغيّر بيئة، **والافتراضي مقفول**، بدل `! app()->isProduction()`. معاكم حق: القاعدة المكتوبة
مش بتقفل باب، والبيئة الجديدة لازم تبدأ مقفولة. **هيتعمل قبل UAT**، ومعاه اختبار يتشاف فاشل الأول.

---

## ٥. التسمية — ✅ **اتسجّلت ونمشي عليها من الرد ده**

- **«أحمد» بالاسم:** قرار وصلنا منكم في رسالة مكتوبة.
- **أي طلب تاني بمصدره:** «طلب من جلسة الباك اند»، أو اسم الشخص.
- **أي حاجة مش بترجع** (تعديل تاريخ، أو تغيير بيانات، أو نشر على الإنتاج) **تعدّي على أحمد مهما كان مصدر الطلب.**

**في الرد ده:** كل اللي اتعمل (`APP_DEBUG` والتعمية) **قرار أحمد المكتوب.** ومافيش حاجة اتعملت على طلب من جلسة
الباك اند.

---

**مطلوب:**
- **منكم:** عدّوا المرفقات (**٢**).
- **من أحمد:** (أ) نمسح نسخة البروفة؟ (ب) نمسح النسخة الاحتياطية للـ١٦ حساب على staging؟ (ج) `national_id` الاتنين؟ (د) هل محتاجين إخطار عشان الـdump؟


---
---

# مرفق ١ من ٢ — `docs/ops/staging-exposure-debug-otp.md`

# Staging exposure: `debug_otp` and `APP_DEBUG` — decision record

**Written:** 2026-10-01 · **Ahmed's decision (written, 2026-10-01):** do **A now** and **B before UAT**, both of them.

| | Status |
|---|---|
| `APP_DEBUG=false` on staging | ✅ **done 2026-10-01**. A rate-limited request showed `trace`/`file`/`exception` before and a clean message after |
| Anonymise every real person's account on staging (identity only; bookings and payments kept) | ✅ **done 2026-10-01: 16 accounts.** 16 names, 13 emails and 9 phones replaced; 0 original real phones or emails left; 86 bookings and 37 payments untouched; all 9 UAT allowlist phones intact |
| **B:** `debug_otp` behind an env flag, default OFF | ⏳ **before UAT**, not yet built |

## What is true today (verified by real calls, 2026-10-01)

| Surface on `staging.mamsaa.com` | Returns the one-time code in the response? |
|---|---|
| Guest phone login: `POST /api/v1/auth/request-otp` | ✅ **yes**, `data.debug_otp`, **for any phone number** |
| Guest email verification: `POST /api/v1/user/email`, `/email/resend` | ✅ **yes**, `data.debug_otp` |
| Partner dashboard: `POST /auth/otp/request` | ❌ no. A fixed code works for allowlisted test phones only |
| Admin panel: `POST /admin/auth/request-otp` | ❌ no. Same allowlist rule |

**Consequence:** anyone who can reach `staging.mamsaa.com` can sign in as **any guest account on staging**.
No leaked code is needed: the server hands out the real code. The code path is `! app()->isProduction()`
in `OtpAuthController` and `User\EmailController`, so it's on in every non-production environment.

**Also on:** `APP_DEBUG=true` on staging. An error response (seen on a rate-limited request) returns the
**full stack trace with server file paths**. Production refuses to boot with debug on, but staging doesn't.

## The rule

> **While `debug_otp` is on in an environment, no real person's data lives in that environment.**
> That covers phone numbers, email addresses, names, identity or commercial-registration documents, bank
> details, and real bookings or payments. Test data only.

### The rule was broken on staging until 2026-10-01 (fixed: see Status)

- **Both real phone numbers on record** (`+96653*****67`, `+96650*****80`) have accounts on staging.
- **9 accounts on staging use `gmail.com` addresses,** which may belong to real people. They haven't been
  checked one by one.
- **Staging totals:** 28 users, 86 bookings, 37 payments (test-mode Moyasar).

Until the anonymisation, anyone could request a code for one of those real numbers on staging and sign in
as that person. **This was never tested against the real accounts.**

**How they got there:** self-registration on staging during testing, 2026-07-16 to 07-19 (plus one on
2026-08-19). It was **not** a copy of production. The two real phones are staging ids 23/24, created
2026-07-16/17. On production they're different ids (19/20), created **later** (2026-08-14), and their
emails differ. The other 14 don't exist on production. The one production copy ever made (the
2026-09-27 rehearsal) went into a **local** container, not staging; see `known-risks-local-copies.md`.

**Kept:** a backup of the 16 original rows is on the staging server only (`~/backups/`, chmod 600).
Ahmed decides whether to delete it, since it holds the original personal data.

## Options, before UAT (Ahmed decides)

| | What | UAT impact |
|---|---|---|
| **A** | Keep `debug_otp` through UAT. **Remove or anonymise every real person's account on staging now**, and keep it that way | None. Testers sign in with any synthetic number (`05971xxxxx`) |
| **B** | Turn `debug_otp` off on staging (code change: gate it behind an env flag, default off) | Guest testers can only sign in with allowlisted test phones. Fresh-registration tests need allowlisted numbers |
| **C** | Do nothing | ❌ Leaves a real person's staging account open to anyone |

**Separately, whichever option is chosen:** set `APP_DEBUG=false` on staging. UAT doesn't need stack
traces in HTTP responses, and errors are still in `storage/logs/laravel.log`.

**Recommendation:** **A**, plus `APP_DEBUG=false` on staging, with the clean-up of real accounts done
before UAT starts. Production is unaffected either way: `debug_otp` is never returned there, and
production doesn't boot with debug on.


---
---

# مرفق ٢ من ٢ — `docs/ops/known-risks-local-copies.md`

# Known risk: live secrets and a production copy on one developer machine

**Recorded:** 2026-10-01 · **Machine:** the backend developer's Windows/WSL2 machine (`/root/...`)
**Nothing here was changed or deleted.** Deleting anything is irreversible, so Ahmed decides.

## What is on the machine

| Path | What it holds | In any git repo? |
|---|---|---|
| `/root/Mamsaa/backend/.env` | Keys for the local Docker setup | No (git-ignored, never committed) |
| `/root/Mamsaa/backend/env.prod` | **Production-style keys** (payment gateway publishable/secret among them) | No (never committed) |
| `/root/rehearsal/prod-2026-09-27.sql` | 🔴 **Full production database dump** (2026-09-27, 45 tables), including production's real users | No |
| `/root/rehearsal/app_core/.env` | 🔴 The `.env` from the rehearsal copy of production's `app_core`, **likely production's live secrets** (not opened) | No |
| Docker: `mamsa_rehearsal_db` + its volume | The same production data, restored into MariaDB (container stopped) | No |
| `~/.ssh/mamsa_deploy` | 🔴 **SSH key with shell access to both servers** (staging + production) | No |
| `~/.config/gh/hosts.yml` | GitHub token for `mohamedashrafdeve-arch` (push access to vego-group repos) | No |
| `/root/claude-strip-backups-2026-10-01/` | Pre-rewrite git bundles of five repos (source code only) | No |

These files are readable by any local user (`-rw-r--r--`). None of them is reachable from the internet.

## If the machine is lost or compromised: rotate in this order

1. **SSH:** remove `mamsa_deploy`'s public key from the Hostinger account (hPanel → SSH keys). It opens a
   shell on staging and production.
2. **GitHub:** revoke the `gh` token (GitHub → Settings → Applications), and check the org's audit log.
3. **Payments (Moyasar):** roll the secret key and the webhook secret, then update production `.env` +
   `config:cache`.
4. **SMS gateway (FGC) password, Resend API key:** roll, then update both servers' `.env`.
5. **Database passwords** for production and staging (hPanel), then `.env` + `config:cache`.
6. **`TEST_OTP_CODE`** on staging (test-mode code).
7. **`APP_KEY`:** last, and only with a plan. It invalidates sessions and signed URLs, and any value
   encrypted with it.
8. **Personal data:** the production dump means production users' data left the server, so a data-protection
   notification may be required. That's Ahmed's decision.

## Recommendation (pending Ahmed)

- **Delete the rehearsal production copy**: `/root/rehearsal/` (dump + `app_core` with its `.env`), and the
  `mamsa_rehearsal_db` container and its volume. The rehearsal was 2026-09-27 and its report is written.
- **Until then:** `chmod 600` on the dump and both `.env` files, so only the owner can read them.
- **Rule from here on:** a production copy for a rehearsal is deleted when the rehearsal ends, and the
  report says when.
