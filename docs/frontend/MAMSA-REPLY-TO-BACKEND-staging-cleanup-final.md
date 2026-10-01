# staging: (أ) اتعمل — `APP_DEBUG` اتقفل و١٦ حساب اتعملهم تعمية · البيانات ماجاتش من الإنتاج · نسخة من الإنتاج على جهاز · رقمين حقيقيين في الريبو

**التاريخ:** 01/10/2026
**لمين:** فريق Next.js، وأحمد
**المرفقات: ١** — `docs/ops/known-risks-local-copies.md`، **ملصوق كامل في آخر الملف ده**.
**ومرفق كان المفروض يتبعت واتحجز:** `docs/ops/staging-exposure-debug-otp.md`، لأن فيه **رقمين تليفون حقيقيين
لناس حقيقيين**. السبب في §٦. وكل التحديث اللي اتعمل فيه موجود في الرد ده نفسه (§١ و§٢).

**مافيش ولا رمز ولا باسورد ولا رقم ولا إيميل حقيقي في الملف ده.**

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

**المعيار:** أي حساب رقمه **أو** إيميله مش من قيم الاختبار المعروفة **اتعامل كإنه حقيقي.** وأي شك اتحسب حقيقي.

| الحقل | اللي اتغيّر |
|---|---|
| **الاسم** | **١٦**، بقى «حساب اختبار <id>» |
| **الإيميل** | **١٣**، بقى `user<id>@anon.mamsa.test` |
| **الرقم** | **٩**، بقى رقم اصطناعي |

- **الرقم والإيميل الأصليين الحقيقيين اللي فاضلين:** **٠**، ولا الرقمين الحقيقيين المعروفين فاضلين على staging.
- **الحجوزات (٨٦) والمدفوعات (٣٧): ماتلمستش.**
- **أرقام UAT التسعة لسه موجودة:** ٤ من الـ١٦ أرقامهم أرقام UAT، فاتغيّر الإيميل والاسم بس، والرقم لأ.
- **نسخة احتياطية** من الـ١٦ صف الأصليين على سيرفر staging بس (chmod 600)، **وفيها البيانات الأصلية. أحمد يقرر نمسحها.**

**وبرّه النطاق، ومالمسناهاش:** حسابين اختبار UAT عندهم `national_id` شكله حقيقي في بيانات الشريك. **مافيش IBAN ولا
صور هويات ولا سجلات تجارية** في الـ١٦. قولولنا لو عايزين الـ`national_id` يتعمله تعمية هو كمان.

---

## ٢. السؤالين

### إزاي وصلت staging؟ — **حد سجّل بنفسه وقت الاختبار، مش نسخة من الإنتاج**

| الدليل | |
|---|---|
| **تواريخ الإنشاء على staging** | ١٦–١٩ يوليو (وواحد ١٩ أغسطس)، **بعد** ما staging اتعمله seed من جديد ١٥/٠٧. يعني تسجيل من الواجهة |
| **الرقمين الحقيقيين** | على staging ids **٢٣ و٢٤** (١٦–١٧/٠٧). على الإنتاج ids **١٩ و٢٠** (**١٤/٠٨، بعدها بشهر**)، والإيميلات **مختلفة**. لو كانوا اتنسخوا، كان الـid والتاريخ هيبقوا زي بعض |
| **النسخة الوحيدة من الإنتاج** | بروفة ٢٧/٠٩، **وراحت container محلي، مش staging** (§٣) |

### هل موجودين على الإنتاج؟ — **اتنين بس، بالرقم**

المقارنة اتعملت بالـhash على السيرفرين. **الرقمين الحقيقيين موجودين على الإنتاج**، ودول أصلاً حسابين حقيقيين هناك
ومحدش بيلمسهم. **والـ١٤ التانيين مش موجودين على الإنتاج.**

---

## ٣. 🔴 **نسخة كاملة من قاعدة بيانات الإنتاج على جهاز المطوّر**

| | |
|---|---|
| `/root/rehearsal/prod-2026-09-27.sql` | 🔴 **dump كامل للإنتاج** (٢٧/٠٩)، فيه مستخدمين الإنتاج الحقيقيين |
| `/root/rehearsal/app_core/.env` | 🔴 `.env` نسخة الإنتاج، **غالباً فيه أسرار حية** (مافتحناهوش) |
| container `mamsa_rehearsal_db` + الـvolume | نفس البيانات، متوقف |

وجنبهم: `backend/.env` و`env.prod`، ومفتاح SSH للسيرفرين، وtoken بتاع GitHub. **أي مستخدم على الجهاز يقدر يقراهم**،
ومافيش حاجة منهم متاحة من الإنترنت. **ماحذفناش حاجة.** التفاصيل وخطة «لو الجهاز ضاع» في **المرفق ١**.

---

## ٤. (ب) — ⏳ **قبل UAT**

`debug_otp` ورا متغيّر بيئة، **والافتراضي مقفول**، ومعاه اختبار يتشاف فاشل الأول.

---

## ٥. التسمية — ✅ **نمشي عليها من هنا**

- **«أحمد» بالاسم:** قرار وصلنا منكم في رسالة مكتوبة.
- **أي طلب تاني بمصدره:** «طلب من جلسة الباك اند»، أو اسم الشخص.
- **أي حاجة مش بترجع تعدّي على أحمد.**

**في الرد ده:** `APP_DEBUG` والتعمية **قرار أحمد المكتوب.** ومافيش حاجة اتعملت على طلب من جلسة الباك اند.

---

## ٦. 🔴 **رقمين تليفون حقيقيين مكتوبين كاملين في الريبو** — ومحتاج قرار

لقينا إن **رقمي التليفون الحقيقيين مكتوبين كاملين في ٧ ملفات** في `vego-group/Mamsa`، **وهو لسه public**:

| الملفات | من إمتى |
|---|---|
| ٤ ملفات توثيق قديمة في `backend/docs/` | من **١٤/٠٨** |
| ٣ ملفات اتضافوا النهارده، منهم **ملف الرد اللي فات** (`…history-rewrite-who-asked-WITH-ATTACHMENTS.md`) و`docs/ops/staging-exposure-debug-otp.md` | النهارده. **غلطة مننا، كان المفروض الأرقام تتخبّى** |

**يعني الرد اللي فات وصلكم وفيه الرقمين كاملين جوّه المرفق التاني.** **ياريت ماحدش يعيد تحويله برّه الفريق.**

**جرّبنا نخفيهم** في الـ٧ ملفات (commit عادي، بصيغة زي `+96653*****67`)، **بس أداة الأمان في الجلسة وقّفت الخطوة**
لأنها بتلمس بيانات شخصية، **ومحتاجين إذن صريح.** وحتى لو اتخفوا في الملفات، **هيفضلوا في تاريخ git**، وشيلهم من
التاريخ **إعادة كتابة، ودي متجمّدة لحد موافقة أحمد المكتوبة.**

**مطلوب قرار من أحمد:**
1. **نخفي الرقمين في الـ٧ ملفات** (commit عادي)؟ ده محتاج إذن بالأداة من جلسة الباك اند كمان.
2. **نشيلهم من التاريخ** (إعادة كتابة) **ولا نكتفي بإن الريبو يبقى private**؟

---

**مطلوب:**
- **منكم:** عدّوا المرفقات (**١**)، **وماتحوّلوش الرد اللي فات برّه الفريق**.
- **من أحمد:** (أ) نمسح نسخة البروفة؟ (ب) نمسح النسخة الاحتياطية للـ١٦ حساب؟ (ج) `national_id` الاتنين؟ (د) إخطار بسبب الـdump؟ (هـ) الرقمين في الريبو: نخفيهم؟ ونشيلهم من التاريخ؟


---
---

# مرفق ١ من ١ — `docs/ops/known-risks-local-copies.md`

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
